from celery import Celery
import os
import subprocess
import tempfile
import shutil
import whisper
import psycopg2
import hashlib
import time
import requests
import json

# ==============================================================================
# Celery App Configuration
# ==============================================================================
# This remains the same, using the Docker service name 'redis'
celery_app = Celery('tasks', broker='redis://redis:6379/0')


# ==============================================================================
# Database and File Helper Functions (Formerly in upload_course_files.py)
# ==============================================================================

def _get_db_connection():
    """Establishes a new database connection using environment variables."""
    try:
        # UPDATED: Using the new environment variable names
        conn = psycopg2.connect(
            dbname=os.getenv("POSTGRES_MAIN_DATABASE"),
            user=os.getenv("POSTGRES_USERNAME"),
            password=os.getenv("POSTGRES_PASSWORD"),
            host=os.getenv("POSTGRES_HOST"), # Should be 'pgvector' in Docker
            port=os.getenv("POSTGRES_PORT")
        )
        return conn
    except psycopg2.OperationalError as e:
        print(f"[ERROR] Could not connect to the database: {e}")
        return None

def get_course_resources(courseid: int):
    """
    Connects to the database and fetches video file resources for a given course.
    """
    print(f"[DB] Fetching video resources for course_id: {courseid}")
    conn = _get_db_connection()
    if not conn:
        return []

    # This query is an example for a Moodle-like schema. Adjust if yours differs.
    query = """
    SELECT cm.id, cm.course, cm.module, mdl.name AS type,
       CASE
            WHEN mf.name IS NOT NULL THEN mf.name
            WHEN mb.name IS NOT NULL THEN mb.name
            WHEN mr.name IS NOT NULL THEN mr.name
            WHEN mu.name IS NOT NULL THEN mu.name
            WHEN mq.name IS NOT NULL THEN mq.name
            WHEN mp.name IS NOT NULL THEN mp.name
            WHEN ml.name IS NOT NULL THEN ml.name
            ELSE NULL
       END AS activityname,
       CASE
            WHEN mf.name IS NOT NULL THEN CONCAT('/mod/forum/view.php?id=', cm.id)
            WHEN mb.name IS NOT NULL THEN CONCAT('/mod/book/view.php?id=', cm.id)
            WHEN mr.name IS NOT NULL THEN CONCAT('/mod/resource/view.php?id=', cm.id)
            WHEN mu.name IS NOT NULL THEN CONCAT('/mod/url/view.php?id=', cm.id)
            WHEN mq.name IS NOT NULL THEN CONCAT('/mod/quiz/view.php?id=', cm.id)
            WHEN mp.name IS NOT NULL THEN CONCAT('/mod/page/view.php?id=', cm.id)
            WHEN ml.name IS NOT NULL THEN CONCAT('/mod/lesson/view.php?id=', cm.id)
            ELSE NULL
       END AS linkurl, 
       f.id AS fileid, 
       f.filepath, 
       f.filename,
       CONCAT(SUBSTRING(f.contenthash, 1, 2), '/', SUBSTRING(f.contenthash, 3, 2), '/', f.contenthash) AS relpath,
       f.userid AS fileuserid, 
       f.filesize, 
       f.mimetype, 
       f.author AS fileauthor,
       f.timecreated, 
       f.timemodified
    FROM mdl_course_modules AS cm
    INNER JOIN mdl_context AS ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
    INNER JOIN mdl_modules AS mdl ON cm.module = mdl.id
    LEFT JOIN mdl_forum AS mf ON mdl.name = 'forum' AND cm.instance = mf.id
    LEFT JOIN mdl_book AS mb ON mdl.name = 'book' AND cm.instance = mb.id
    LEFT JOIN mdl_resource AS mr ON mdl.name = 'resource' AND cm.instance = mr.id
    LEFT JOIN mdl_url AS mu ON mdl.name = 'url' AND cm.instance = mu.id
    LEFT JOIN mdl_quiz AS mq ON mdl.name = 'quiz' AND cm.instance = mq.id
    LEFT JOIN mdl_page AS mp ON mdl.name = 'page' AND cm.instance = mp.id
    LEFT JOIN mdl_lesson AS ml ON mdl.name = 'lesson' AND cm.instance = ml.id
    LEFT JOIN mdl_files AS f ON f.contextid = ctx.id
    WHERE cm.course = %s
    AND mdl.name = 'resource'
    AND ((f.mimetype LIKE 'video/%%') OR (f.id IS NULL))
    """
    results = []
    try:
        with conn.cursor() as cur:
            cur.execute(query, (courseid,))
            results = cur.fetchall()
            print(f"[DB] Found {len(results)} video files.")
    except Exception as e:
        print(f"[DB ERROR] Failed to fetch resources: {e}")
    finally:
        if conn:
            conn.close()
    return results

def get_transcript_files(courseid: int, file_ids: list):
    """
    Fetches transcript files by their IDs from the database.
    """
    print(f"[DB] Fetching transcript files for course_id: {courseid}, file_ids: {file_ids}")
    conn = _get_db_connection()
    if not conn:
        return []

    if not file_ids:
        return []

    placeholders = ','.join(['%s'] * len(file_ids))
    query = f"""
    SELECT f.filename, f.mimetype,
           CONCAT(SUBSTRING(f.contenthash, 1, 2), '/', 
                  SUBSTRING(f.contenthash, 3, 2), '/', 
                  f.contenthash) AS relpath
    FROM mdl_files f
    WHERE f.id IN ({placeholders})
      AND f.mimetype = 'text/plain'
      AND f.filename != '.'
      AND f.filesize > 0
    """
    
    results = []
    try:
        with conn.cursor() as cur:
            cur.execute(query, file_ids)
            results = cur.fetchall()
            print(f"[DB] Found {len(results)} transcript files.")
    except Exception as e:
        print(f"[DB ERROR] Failed to fetch transcript files: {e}")
    finally:
        if conn:
            conn.close()
    return results

def upload_transcript_file(courseid: int, transcript_path: str, original_filename: str):
    """
    Saves a transcript file to the moodledata directory and creates a
    corresponding record in the database. If a transcript already exists,
    it updates the existing record instead of creating a duplicate.
    """
    print(f"[UPLOAD] Uploading transcript for {original_filename}")
    conn = _get_db_connection()
    if not conn:
        return None

    try:
        with open(transcript_path, 'rb') as f:
            transcript_content = f.read()

        # 1. Calculate hashes
        contenthash = hashlib.sha1(transcript_content).hexdigest()
        pathname = f"/{original_filename}.txt"
        pathnamehash = hashlib.sha1(pathname.encode('utf-8')).hexdigest()
        
        # 2. Get contextid for the course
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM mdl_context WHERE instanceid = %s AND contextlevel = 50", (courseid,))
            context_id_row = cur.fetchone()
            if not context_id_row:
                raise Exception(f"Could not find context for course {courseid}")
            context_id = context_id_row[0]

            # 3. Check if a file with the same pathnamehash already exists
            cur.execute("""
                SELECT id, contenthash FROM mdl_files 
                WHERE pathnamehash = %s AND contextid = %s AND component = 'user' AND filearea = 'draft'
            """, (pathnamehash, context_id))
            existing_file = cur.fetchone()
            
            if existing_file:
                existing_file_id, existing_contenthash = existing_file
                print(f"[UPLOAD] Transcript already exists with ID: {existing_file_id}")
                
                # Check if content has changed
                if existing_contenthash == contenthash:
                    print(f"[UPLOAD] Content unchanged, returning existing file ID: {existing_file_id}")
                    return existing_file_id
                else:
                    print(f"[UPLOAD] Content changed, updating existing transcript...")
                    # Update the existing record
                    current_time = int(time.time())
                    cur.execute("""
                        UPDATE mdl_files 
                        SET contenthash = %s, filesize = %s, timemodified = %s
                        WHERE id = %s
                    """, (contenthash, len(transcript_content), current_time, existing_file_id))
                    
                    # Save the new physical file
                    moodledata_path = os.getenv("MOODLEDATA", "/moodledata")
                    file_dir = os.path.join(moodledata_path, 'filedir', contenthash[:2], contenthash[2:4])
                    os.makedirs(file_dir, exist_ok=True)
                    final_path = os.path.join(file_dir, contenthash)
                    
                    with open(final_path, 'wb') as f:
                        f.write(transcript_content)
                    print(f"[UPLOAD] Updated transcript saved to {final_path}")
                    
                    conn.commit()
                    print(f"[UPLOAD] Updated file record with ID: {existing_file_id}")
                    return existing_file_id
            
            # 4. Create new file record (only if it doesn't exist)
            moodledata_path = os.getenv("MOODLEDATA", "/moodledata")
            file_dir = os.path.join(moodledata_path, 'filedir', contenthash[:2], contenthash[2:4])
            os.makedirs(file_dir, exist_ok=True)
            final_path = os.path.join(file_dir, contenthash)

            # Save the physical file
            with open(final_path, 'wb') as f:
                f.write(transcript_content)
            print(f"[UPLOAD] Saved new transcript to {final_path}")

            # Insert file record
            insert_query = """
            INSERT INTO mdl_files (
                contenthash, pathnamehash, contextid, component, filearea, itemid,
                filepath, filename, userid, filesize, mimetype, status, timecreated, timemodified
            ) VALUES (%s, %s, %s, 'user', 'draft', 0, '/', %s, 2, %s, 'text/plain', 1, %s, %s)
            RETURNING id;
            """
            current_time = int(time.time())
            cur.execute(insert_query, (
                contenthash, pathnamehash, context_id, f"{original_filename}.txt",
                len(transcript_content), current_time, current_time
            ))
            file_id = cur.fetchone()[0]
            conn.commit()
            print(f"[UPLOAD] Created new file record with ID: {file_id}")
            return file_id

    except Exception as e:
        print(f"[UPLOAD ERROR] Failed to upload transcript: {e}")
        if conn:
            conn.rollback()
        return None
    finally:
        if conn:
            conn.close()

def process_files(courseid: int, file_ids: list):
    """Placeholder for your file processing/indexing logic."""
    print(f"[PROCESS] Starting post-processing for course {courseid} on file IDs: {file_ids}")
    # Add your logic here to index the new text files for your RAG application.
    # This might involve reading the files and adding them to a vector database.
    pass

# ==============================================================================
# FastAPI Helper Functions
# ==============================================================================

def upload_file_to_fastapi(fastapi_url: str, courseid: int, file_path: str, filename: str, mimetype: str):
    """
    Upload a file to the FastAPI service.
    """
    try:
        if not os.path.exists(file_path):
            print(f"[FASTAPI ERROR] File not found: {file_path}")
            return False
        
        url = f"{fastapi_url}/api/v1/data/upload/{courseid}"
        
        with open(file_path, 'rb') as f:
            files = {'file': (filename, f, mimetype)}
            response = requests.post(url, files=files, timeout=30)
        
        if response.status_code == 200:
            print(f"[FASTAPI] Successfully uploaded: {filename}")
            return True
        else:
            print(f"[FASTAPI ERROR] Upload failed for {filename}: {response.status_code} - {response.text}")
            return False
            
    except Exception as e:
        print(f"[FASTAPI ERROR] Exception during upload of {filename}: {e}")
        return False

def process_files_fastapi(fastapi_url: str, courseid: int, chunk_size: int = 200, overlap_size: int = 20, do_reset: int = 1): #1 = delete table
    """
    Process files through the FastAPI service.
    """
    try:
        url = f"{fastapi_url}/api/v1/data/process/{courseid}"
        data = {
            'chunk_size': chunk_size,
            'overlap_size': overlap_size,
            'do_reset': do_reset
        }
        
        response = requests.post(
            url, 
            json=data, 
            headers={'Content-Type': 'application/json'},
            timeout=60
        )
        
        if response.status_code == 200:
            print(f"[FASTAPI] Successfully processed files for course {courseid}")
            return True
        else:
            print(f"[FASTAPI ERROR] File processing failed: {response.status_code} - {response.text}")
            return False
            
    except Exception as e:
        print(f"[FASTAPI ERROR] Exception during file processing: {e}")
        return False

def push_to_index_fastapi(fastapi_url: str, courseid: int, do_reset: int = 1): #1 = delete table
    """
    Build search index through the FastAPI service.
    """
    try:
        url = f"{fastapi_url}/api/v1/nlp/index/push/{courseid}"
        data = {'do_reset': do_reset}
        
        response = requests.post(
            url,
            json=data,
            headers={'Content-Type': 'application/json'},
            timeout=90
        )
        
        if response.status_code == 200:
            print(f"[FASTAPI] Successfully built search index for course {courseid}")
            return True
        else:
            print(f"[FASTAPI ERROR] Index building failed: {response.status_code} - {response.text}")
            return False
            
    except Exception as e:
        print(f"[FASTAPI ERROR] Exception during index building: {e}")
        return False

# ==============================================================================
# Celery Task Definitions
# ==============================================================================

@celery_app.task
def process_transcripts_through_fastapi_direct(job_id: str, courseid: int, transcript_files: list):
    """
    Process transcript files through the FastAPI pipeline using direct file paths.
    This works even if database insertion failed.
    
    Args:
        transcript_files: List of dicts with keys: 'filename', 'fullpath', 'mimetype'
    """
    print(f"[JOB {job_id}] Starting direct FastAPI processing for course {courseid}")
    print(f"[JOB {job_id}] Processing {len(transcript_files)} transcript files")
    
    if not transcript_files:
        print(f"[JOB {job_id}] No transcript files provided for processing")
        return False
    
    fastapi_url = "http://fastapi:8000"
    
    # Step 1: Upload files to FastAPI
    print(f"[JOB {job_id}] Step 1: Uploading transcript files to FastAPI")
    uploaded_count = 0
    
    for transcript_file in transcript_files:
        filename = transcript_file['filename']
        fullpath = transcript_file['fullpath']
        mimetype = transcript_file['mimetype']
        
        if not os.path.exists(fullpath):
            print(f"[JOB {job_id}] Transcript file not found: {fullpath}")
            continue
            
        if upload_file_to_fastapi(fastapi_url, courseid, fullpath, filename, mimetype):
            uploaded_count += 1
        else:
            print(f"[JOB {job_id}] Failed to upload transcript: {filename}")
    
    if uploaded_count == 0:
        print(f"[JOB {job_id}] No transcript files were uploaded successfully")
        return False
    
    print(f"[JOB {job_id}] Successfully uploaded {uploaded_count}/{len(transcript_files)} transcript files")
    
    # Step 2: Process files
    print(f"[JOB {job_id}] Step 2: Processing files through FastAPI")
    if not process_files_fastapi(fastapi_url, courseid):
        print(f"[JOB {job_id}] File processing failed")
        return False
    
    # Step 3: Build search index
    print(f"[JOB {job_id}] Step 3: Building search index")
    if not push_to_index_fastapi(fastapi_url, courseid):
        print(f"[JOB {job_id}] Search index building failed")
        return False
    
    print(f"[JOB {job_id}] Direct FastAPI processing complete for course {courseid}")
    return True

@celery_app.task
def process_transcripts_through_fastapi(job_id: str, courseid: int, file_ids: list):
    """
    Process transcript files through the FastAPI pipeline using database file IDs.
    This is the original method that requires successful database insertion.
    """
    print(f"[JOB {job_id}] Starting FastAPI processing for course {courseid}, file_ids: {file_ids}")
    
    if not file_ids:
        print(f"[JOB {job_id}] No file IDs provided for processing")
        return False
    
    fastapi_url = "http://fastapi:8000"
    moodledata_path = os.getenv("MOODLEDATA", "/moodledata")
    
    # Step 1: Get transcript files from database
    transcript_files = get_transcript_files(courseid, file_ids)
    
    if not transcript_files:
        print(f"[JOB {job_id}] No transcript files found for processing")
        return False
    
    # Step 2: Upload files to FastAPI
    print(f"[JOB {job_id}] Step 1: Uploading {len(transcript_files)} files to FastAPI")
    uploaded_count = 0
    
    for file_row in transcript_files:
        filename = file_row[0]
        mimetype = file_row[1]
        relpath = file_row[2]
        
        full_path = os.path.join(moodledata_path, 'filedir', relpath)
        
        if upload_file_to_fastapi(fastapi_url, courseid, full_path, filename, mimetype):
            uploaded_count += 1
        else:
            print(f"[JOB {job_id}] Failed to upload: {filename}")
    
    if uploaded_count == 0:
        print(f"[JOB {job_id}] No files were uploaded successfully")
        return False
    
    print(f"[JOB {job_id}] Successfully uploaded {uploaded_count}/{len(transcript_files)} files")
    
    # Step 3: Process files
    print(f"[JOB {job_id}] Step 2: Processing files through FastAPI")
    if not process_files_fastapi(fastapi_url, courseid):
        print(f"[JOB {job_id}] File processing failed")
        return False
    
    # Step 4: Build search index
    print(f"[JOB {job_id}] Step 3: Building search index")
    if not push_to_index_fastapi(fastapi_url, courseid):
        print(f"[JOB {job_id}] Search index building failed")
        return False
    
    print(f"[JOB {job_id}] FastAPI processing complete for course {courseid}")
    return True

@celery_app.task
def process_video_job(job_id: str, courseid: int):
    """
    This self-contained job runs in a separate Celery worker process.
    It fetches video info from the DB, transcribes, and saves the transcript.
    """
    print(f"[JOB {job_id}] Starting video processing for course {courseid}")

    # This now calls the local helper function
    video_rows = get_course_resources(courseid)

    if not video_rows:
        print(f"[JOB {job_id}] No video files found for course {courseid}")
        return

    # Load the model once per task execution
    model = whisper.load_model("base")
    uploaded_file_ids = []
    transcript_files_for_fastapi = []  # Store transcript file info for FastAPI processing
    MOODLEDATA = os.getenv("MOODLEDATA", "/moodledata")

    for row in video_rows:
        # Column mapping based on SQL query
        filename = row[8]
        relpath = row[9]
        mimetype = row[12]

        if not filename or filename.strip() == '.' or not filename.strip():
            print(f"[!] Skipping file with invalid filename: {filename}")
            continue
        
        if not mimetype:
            print(f"[!] Skipping file '{filename}' due to missing mimetype.")
            continue

        full_path = os.path.join(MOODLEDATA, 'filedir', relpath)

        if not os.path.exists(full_path):
            print(f"[JOB {job_id}] File not found, skipping: {full_path}")
            continue

        print(f"[JOB {job_id}] Extracting audio from: {filename}")

        with tempfile.TemporaryDirectory() as tmpdir:
            audio_path = os.path.join(tmpdir, "audio.wav")

            # FFmpeg: extract audio
            ffmpeg_cmd = [
                "ffmpeg", "-i", full_path,
                "-vn", "-acodec", "pcm_s16le",
                "-ar", "16000", "-ac", "1", audio_path, "-y"
            ]
            subprocess.run(ffmpeg_cmd, check=True, capture_output=True)

            print(f"[JOB {job_id}] Transcribing audio...")
            result = model.transcribe(audio_path)
            transcript_text = result["text"]

            # Save transcript to a temporary file
            transcript_file_path = os.path.join(tmpdir, "transcript.txt")
            with open(transcript_file_path, "w", encoding="utf-8") as f:
                f.write(transcript_text)

            print(f"[JOB {job_id}] Uploading transcript...")
            # This now calls the local helper function
            file_id = upload_transcript_file(courseid, transcript_file_path, filename)
            if file_id:
                uploaded_file_ids.append(file_id)
            
            # Calculate where the transcript was/will be saved for FastAPI processing
            with open(transcript_file_path, 'rb') as f:
                transcript_content = f.read()
            contenthash = hashlib.sha1(transcript_content).hexdigest()
            transcript_filename = f"{filename}.txt"
            transcript_relpath = f"{contenthash[:2]}/{contenthash[2:4]}/{contenthash}"
            transcript_fullpath = os.path.join(MOODLEDATA, 'filedir', transcript_relpath)
            
            # Store transcript info for FastAPI processing (regardless of DB success)
            transcript_files_for_fastapi.append({
                'filename': transcript_filename,
                'fullpath': transcript_fullpath,
                'mimetype': 'text/plain'
            })
            
            print(f"[JOB {job_id}] Transcript saved at: {transcript_fullpath}")

    # Always try FastAPI processing if we have transcript files, even if DB insertion failed
    if transcript_files_for_fastapi:
        print(f"[JOB {job_id}] Processing transcripts...")
        # This now calls the local placeholder function
        process_files(courseid, file_ids=uploaded_file_ids)
        
        # NEW: Trigger FastAPI processing for the transcript files
        print(f"[JOB {job_id}] Triggering FastAPI processing for {len(transcript_files_for_fastapi)} transcript files...")
        fastapi_job_id = f"{job_id}_fastapi"
        process_transcripts_through_fastapi_direct.delay(fastapi_job_id, courseid, transcript_files_for_fastapi)
        print(f"[JOB {job_id}] FastAPI processing job queued with ID: {fastapi_job_id}")

    print(f"[JOB {job_id}] Video processing complete for course {courseid}")