import os
import psycopg2
import requests
import time
import mimetypes
import json

FASTAPI_URL = f"http://{os.getenv('FASTAPI_HOST')}:{os.getenv('FASTAPI_PORT')}"

print(f"Waiting for FastAPI at {FASTAPI_URL}...")

for i in range(10):  # retry 10 times
    try:
        resp = requests.get(f"{FASTAPI_URL}/docs", timeout=3)
        if resp.status_code == 200:
            print("[INFO] FastAPI is ready.")
            break
    except Exception as e:
        print(f"[WAIT] FastAPI not ready yet ({i+1}/10): {e}")
        time.sleep(5)
else:
    raise RuntimeError("FastAPI not available after waiting")

# Config from env
DB_HOST = os.getenv("DB_HOST", "pgvector")
DB_PORT = os.getenv("DB_PORT", "5432")
DB_NAME = os.getenv("DB_NAME", "moodle")
DB_USER = os.getenv("DB_USER", "youruser")
DB_PASS = os.getenv("DB_PASS", "yourpass")
COURSE_ID = int(os.getenv("COURSE_ID", "2"))
MOODLEDATA = os.getenv("MOODLEDATA", "/moodledata")
FASTAPI_HOST = os.getenv("FASTAPI_HOST", "fastapi")
FASTAPI_PORT = os.getenv("FASTAPI_PORT", "8000")

# Processing configuration from env vars with defaults
CHUNK_SIZE = int(os.getenv("CHUNK_SIZE", "1000"))
OVERLAP_SIZE = int(os.getenv("OVERLAP_SIZE", "200"))
DO_RESET = int(os.getenv("DO_RESET", "1"))  # 0 = false, 1 = true

UPLOAD_ENDPOINT = f"http://{FASTAPI_HOST}:{FASTAPI_PORT}/api/v1/data/upload/{{course_id}}"
PROCESS_ENDPOINT = f"http://{FASTAPI_HOST}:{FASTAPI_PORT}/api/v1/data/process/{{course_id}}"

DB_DSN = f"dbname={DB_NAME} user={DB_USER} password={DB_PASS} host={DB_HOST} port={DB_PORT}"

SQL = """
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
  AND ((f.mimetype IN ('text/plain', 'application/pdf')) OR (f.id IS NULL))
"""

def get_course_resources(course_id):
    with psycopg2.connect(DB_DSN) as conn:
        with conn.cursor() as cur:
            cur.execute(SQL, (course_id,))
            results = cur.fetchall()
            # Debug: Print column info
            if results:
                print(f"[DEBUG] Column count: {len(results[0])}")
                print(f"[DEBUG] First row: {results[0]}")
            return results

def upload_file(course_id, full_path, filename, mimetype=None):
    print(f"[DEBUG] Uploading {filename} as {mimetype}")

    if not os.path.exists(full_path):
        print(f"[!] File not found: {full_path}")
        return None

    url = UPLOAD_ENDPOINT.format(course_id=course_id)

    if not mimetype:
        mimetype, _ = mimetypes.guess_type(filename)
        if not mimetype:
            mimetype = "application/octet-stream"

    with open(full_path, "rb") as f:
        files = {'file': (filename, f, mimetype)}
        resp = requests.post(url, files=files)

    if resp.ok:
        response_data = resp.json()
        file_id = response_data.get('file_id')
        print(f"[✓] Uploaded: {filename} -> file_id: {file_id}")
        return file_id
    else:
        print(f"[✗] Failed to upload {filename}: {resp.status_code} {resp.text}")
        return None

def process_files(course_id, file_ids=None, chunk_size=CHUNK_SIZE, overlap_size=OVERLAP_SIZE, do_reset=DO_RESET):
    """
    Process uploaded files for chunking and vectorization
    
    Args:
        course_id: The course/project ID
        file_ids: List of specific file IDs to process (optional)
        chunk_size: Size of each chunk
        overlap_size: Overlap between chunks
        do_reset: Whether to reset existing chunks (1) or append (0)
    """
    print(f"[INFO] Starting processing for course ID: {course_id}")
    
    url = PROCESS_ENDPOINT.format(course_id=course_id)
    
    # Prepare the request payload
    payload = {
        "chunk_size": chunk_size,
        "overlap_size": overlap_size,
        "do_reset": do_reset
    }
    
    # If specific file IDs provided, process them individually
    if file_ids:
        total_chunks = 0
        total_files = 0
        
        for file_id in file_ids:
            if file_id is None:
                continue
                
            print(f"[INFO] Processing file: {file_id}")
            payload["file_id"] = file_id
            
            headers = {'Content-Type': 'application/json'}
            resp = requests.post(url, json=payload, headers=headers)
            
            if resp.ok:
                response_data = resp.json()
                chunks = response_data.get('inserted_chunks', 0)
                files = response_data.get('processed_files', 0)
                total_chunks += chunks
                total_files += files
                print(f"[✓] Processed file {file_id}: {chunks} chunks created")
            else:
                print(f"[✗] Failed to process file {file_id}: {resp.status_code} {resp.text}")
        
        print(f"[INFO] Processing complete: {total_files} files, {total_chunks} total chunks")
    
    else:
        # Process all files for the project
        print(f"[INFO] Processing all files for course {course_id}")
        
        # Don't include file_id in payload to process all files
        headers = {'Content-Type': 'application/json'}
        resp = requests.post(url, json=payload, headers=headers)
        
        if resp.ok:
            response_data = resp.json()
            chunks = response_data.get('inserted_chunks', 0)
            files = response_data.get('processed_files', 0)
            print(f"[✓] Processing complete: {files} files, {chunks} chunks created")
        else:
            print(f"[✗] Failed to process files: {resp.status_code} {resp.text}")

def main(course_id):
    print(f"[INFO] Fetching files for course ID: {course_id}")
    results = get_course_resources(course_id)
    print(f"[INFO] Found {len(results)} resource(s)")

    uploaded_file_ids = []
    
    # Step 1: Upload all files
    print(f"\n=== UPLOAD PHASE ===")
    for row in results:
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
        
        print(f"[DEBUG] Processing: filename='{filename}', mimetype='{mimetype}', path='{full_path}'")
        file_id = upload_file(course_id, full_path, filename, mimetype)
        
        if file_id:
            uploaded_file_ids.append(file_id)
    
    # Step 2: Process uploaded files
    if uploaded_file_ids:
        print(f"\n=== PROCESSING PHASE ===")
        print(f"[INFO] Successfully uploaded {len(uploaded_file_ids)} files")
        print(f"[INFO] Processing configuration: chunk_size={CHUNK_SIZE}, overlap_size={OVERLAP_SIZE}, do_reset={DO_RESET}")
        
        # Process all files at once (more efficient)
        process_files(course_id, file_ids=None)  # None means process all files for the project
        
        # Alternative: Process files individually (uncomment if needed)
        # process_files(course_id, file_ids=uploaded_file_ids)
    else:
        print(f"[!] No files were successfully uploaded, skipping processing phase")

if __name__ == "__main__":
    main(COURSE_ID)