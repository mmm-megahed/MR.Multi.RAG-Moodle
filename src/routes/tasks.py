from celery import Celery
import os
import subprocess
import tempfile
import shutil
import whisper
import psycopg2
import hashlib
import time

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

def upload_transcript_file(courseid: int, transcript_path: str, original_filename: str):
    """
    Saves a transcript file to the moodledata directory and creates a
    corresponding record in the database.
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
        
        # 2. Determine file path in moodledata
        moodledata_path = os.getenv("MOODLEDATA", "/moodledata")
        file_dir = os.path.join(moodledata_path, 'filedir', contenthash[:2], contenthash[2:4])
        os.makedirs(file_dir, exist_ok=True)
        final_path = os.path.join(file_dir, contenthash)

        # 3. Save the physical file
        with open(final_path, 'wb') as f:
            f.write(transcript_content)
        print(f"[UPLOAD] Saved transcript to {final_path}")

        # 4. Create database record
        with conn.cursor() as cur:
            # Get contextid for the course
            cur.execute("SELECT id FROM mdl_context WHERE instanceid = %s AND contextlevel = 50", (courseid,))
            context_id_row = cur.fetchone()
            if not context_id_row:
                raise Exception(f"Could not find context for course {courseid}")
            context_id = context_id_row[0]

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
            print(f"[UPLOAD] Created file record with ID: {file_id}")
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
# Celery Task Definition
# ==============================================================================

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

    if uploaded_file_ids:
        print(f"[JOB {job_id}] Processing transcripts...")
        # This now calls the local placeholder function
        process_files(courseid, file_ids=uploaded_file_ids)

    print(f"[JOB {job_id}] Video processing complete for course {courseid}")
