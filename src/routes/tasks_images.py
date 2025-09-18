from celery import Celery
import os
import fitz  # PyMuPDF
import hashlib
import time
import requests
import json
import tempfile
from pathlib import Path
import psycopg2

# ==============================================================================
# Celery App Configuration
# ==============================================================================
celery_app = Celery('tasks_images', broker='redis://redis:6379/1')

# ==============================================================================
# Database Helper Functions
# ==============================================================================

def _get_db_connection():
    """Establishes a new database connection using environment variables."""
    try:
        conn = psycopg2.connect(
            dbname=os.getenv("POSTGRES_MAIN_DATABASE"),
            user=os.getenv("POSTGRES_USERNAME"),
            password=os.getenv("POSTGRES_PASSWORD"),
            host=os.getenv("POSTGRES_HOST"),
            port=os.getenv("POSTGRES_PORT")
        )
        return conn
    except psycopg2.OperationalError as e:
        print(f"[ERROR] Could not connect to the database: {e}")
        return None

def get_pdf_files(courseid: int):
    """Fetches PDF files for a given course."""
    print(f"[DB] Fetching PDF files for course_id: {courseid}")
    conn = _get_db_connection()
    if not conn:
        return []

    query = """
    SELECT cm.id, f.id AS fileid, f.filename, f.filepath,
           CONCAT(SUBSTRING(f.contenthash, 1, 2), '/', 
                  SUBSTRING(f.contenthash, 3, 2), '/', 
                  f.contenthash) AS relpath,
           f.filesize, f.mimetype
    FROM mdl_course_modules AS cm
    INNER JOIN mdl_context AS ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
    INNER JOIN mdl_modules AS mdl ON cm.module = mdl.id
    LEFT JOIN mdl_resource AS mr ON mdl.name = 'resource' AND cm.instance = mr.id
    LEFT JOIN mdl_files AS f ON f.contextid = ctx.id
    WHERE cm.course = %s
    AND mdl.name = 'resource'
    AND f.mimetype = 'application/pdf'
    AND f.filename != '.'
    AND f.filesize > 0
    """
    
    results = []
    try:
        with conn.cursor() as cur:
            cur.execute(query, (courseid,))
            results = cur.fetchall()
            print(f"[DB] Found {len(results)} PDF files.")
    except Exception as e:
        print(f"[DB ERROR] Failed to fetch PDF files: {e}")
    finally:
        if conn:
            conn.close()
    return results

def upload_image_description(courseid: int, image_info: dict):
    """
    Saves image description and metadata to the database.
    """
    print(f"[UPLOAD] Uploading image description for {image_info['filename']}")
    conn = _get_db_connection()
    if not conn:
        return None

    try:
        # Get contextid for the course
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM mdl_context WHERE instanceid = %s AND contextlevel = 50", (courseid,))
            context_id_row = cur.fetchone()
            if not context_id_row:
                raise Exception(f"Could not find context for course {courseid}")
            context_id = context_id_row[0]

            # Calculate hashes
            description_content = image_info['description'].encode('utf-8')
            contenthash = hashlib.sha1(description_content).hexdigest()
            pathname = f"/{image_info['filename']}.txt"
            pathnamehash = hashlib.sha1(pathname.encode('utf-8')).hexdigest()
            
            # Check if image description already exists
            cur.execute("""
                SELECT id FROM mdl_files 
                WHERE pathnamehash = %s AND contextid = %s AND component = 'user' AND filearea = 'draft'
            """, (pathnamehash, context_id))
            existing_file = cur.fetchone()
            
            if existing_file:
                print(f"[UPLOAD] Image description already exists with ID: {existing_file[0]}")
                return existing_file[0]

            # Create description file on disk
            moodledata_path = os.getenv("MOODLEDATA", "/moodledata")
            file_dir = os.path.join(moodledata_path, 'filedir', contenthash[:2], contenthash[2:4])
            os.makedirs(file_dir, exist_ok=True)
            final_path = os.path.join(file_dir, contenthash)

            # Write description with metadata
            full_content = f"Image: {image_info['filename']}\n"
            full_content += f"Source PDF: {image_info['source_pdf']}\n"
            full_content += f"Page: {image_info['page_num']}\n"
            full_content += f"Context: {image_info['context']}\n"
            full_content += f"Description: {image_info['description']}\n"
            
            with open(final_path, 'w', encoding='utf-8') as f:
                f.write(full_content)
            print(f"[UPLOAD] Saved image description to {final_path}")

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
                contenthash, pathnamehash, context_id, f"{image_info['filename']}.txt",
                len(full_content.encode('utf-8')), current_time, current_time
            ))
            file_id = cur.fetchone()[0]
            conn.commit()
            print(f"[UPLOAD] Created image description record with ID: {file_id}")
            return file_id

    except Exception as e:
        print(f"[UPLOAD ERROR] Failed to upload image description: {e}")
        if conn:
            conn.rollback()
        return None
    finally:
        if conn:
            conn.close()

# ==============================================================================
# Image Processing Functions
# ==============================================================================

def extract_images_from_pdf(pdf_path: str, output_dir: str):
    """Extract images from PDF and return image info with context."""
    print(f"[IMAGE] Extracting images from PDF: {pdf_path}")
    
    if not os.path.exists(pdf_path):
        print(f"[ERROR] PDF file not found: {pdf_path}")
        return []

    images_info = []
    
    try:
        doc = fitz.open(pdf_path)
        
        for page_num in range(len(doc)):
            page = doc.load_page(page_num)
            image_list = page.get_images()
            
            # Get text content from the page for context
            page_text = page.get_text()
            
            for img_index, img in enumerate(image_list):
                xref = img[0]
                
                # Extract image
                pix = fitz.Pixmap(doc, xref)
                
                # Skip if image is too small (likely icons or decorative elements)
                if pix.width < 100 or pix.height < 100:
                    pix = None
                    continue
                
                # Convert CMYK to RGB if necessary
                if pix.n - pix.alpha < 4:  # GRAY or RGB
                    img_data = pix.tobytes("png")
                else:  # CMYK: convert to RGB first
                    pix1 = fitz.Pixmap(fitz.csRGB, pix)
                    img_data = pix1.tobytes("png")
                    pix1 = None
                
                # Generate filename
                base_name = Path(pdf_path).stem
                img_filename = f"{base_name}_page{page_num+1}_img{img_index+1}.png"
                img_path = os.path.join(output_dir, img_filename)
                
                # Save image
                with open(img_path, "wb") as img_file:
                    img_file.write(img_data)
                
                # Extract context (text around image area)
                context = extract_image_context(page, page_text, img, page_num)
                
                images_info.append({
                    'filename': img_filename,
                    'path': img_path,
                    'page_num': page_num + 1,
                    'width': pix.width,
                    'height': pix.height,
                    'context': context,
                    'source_pdf': os.path.basename(pdf_path)
                })
                
                pix = None
                print(f"[IMAGE] Extracted: {img_filename}")
        
        doc.close()
        print(f"[IMAGE] Extracted {len(images_info)} images from PDF")
        return images_info
        
    except Exception as e:
        print(f"[ERROR] Failed to extract images from PDF: {e}")
        return []

def extract_image_context(page, page_text, img_info, page_num):
    """Extract textual context around an image."""
    try:
        # Get image rectangle
        img_rect = page.get_image_rects(img_info[0])[0] if page.get_image_rects(img_info[0]) else None
        
        if img_rect:
            # Expand the area around the image to capture context
            expanded_rect = fitz.Rect(
                max(0, img_rect.x0 - 50),
                max(0, img_rect.y0 - 100),
                min(page.rect.width, img_rect.x1 + 50),
                min(page.rect.height, img_rect.y1 + 100)
            )
            
            # Get text in the expanded area
            context_text = page.get_text(clip=expanded_rect)
            
            if context_text.strip():
                return context_text.strip()[:500]  # Limit context length
        
        # Fallback: get first 200 chars of page text
        return page_text.strip()[:200] if page_text.strip() else f"Image from page {page_num + 1}"
        
    except Exception as e:
        print(f"[WARNING] Could not extract context for image: {e}")
        return f"Image from page {page_num + 1}"

def generate_image_description(image_path: str, context: str):
    """Generate a searchable description for an image based on context."""
    try:
        # Basic description based on file properties and context
        img_name = Path(image_path).stem
        
        # Extract meaningful terms from context
        context_words = context.lower().split()
        meaningful_words = [word for word in context_words 
                          if len(word) > 3 and word.isalpha()][:10]
        
        description = f"Image {img_name}"
        if meaningful_words:
            description += f" related to: {', '.join(meaningful_words)}"
        
        description += f". Context: {context[:200]}"
        
        return description
        
    except Exception as e:
        print(f"[ERROR] Failed to generate description for {image_path}: {e}")
        return f"Image from document"

# ==============================================================================
# FastAPI Helper Functions
# ==============================================================================

def upload_description_to_fastapi(fastapi_url: str, courseid: int, description_file: str, filename: str):
    """Upload image description file to FastAPI."""
    try:
        if not os.path.exists(description_file):
            print(f"[FASTAPI ERROR] Description file not found: {description_file}")
            return False
        
        url = f"{fastapi_url}/api/v1/data/upload/{courseid}"
        
        with open(description_file, 'rb') as f:
            files = {'file': (filename, f, 'text/plain')}
            response = requests.post(url, files=files, timeout=30)
        
        if response.status_code == 200:
            print(f"[FASTAPI] Successfully uploaded description: {filename}")
            return True
        else:
            print(f"[FASTAPI ERROR] Upload failed for {filename}: {response.status_code} - {response.text}")
            return False
            
    except Exception as e:
        print(f"[FASTAPI ERROR] Exception during upload of {filename}: {e}")
        return False

# ==============================================================================
# Celery Task Definitions
# ==============================================================================

@celery_app.task
def process_pdf_images_job(job_id: str, courseid: int):
    print(f"[JOB {job_id}] Starting PDF image processing for course {courseid}")

    pdf_rows = get_pdf_files(courseid)
    if not pdf_rows:
        print(f"[JOB {job_id}] No PDF files found for course {courseid}")
        return

    MOODLEDATA = os.getenv("MOODLEDATA", "/moodledata")
    uploaded_descriptions = []
    description_files_for_fastapi = []

    # Use fixed directory instead of temp
    images_dir = "/moodledata/temp/extracted_images"
    os.makedirs(images_dir, exist_ok=True)

    with tempfile.TemporaryDirectory() as tmpdir:
        for row in pdf_rows:
            filename = row[2]
            relpath = row[4]

            if not filename or filename.strip() == '.' or not filename.strip():
                print(f"[!] Skipping file with invalid filename: {filename}")
                continue

            full_path = os.path.join(MOODLEDATA, 'filedir', relpath)
            if not os.path.exists(full_path):
                print(f"[JOB {job_id}] PDF file not found, skipping: {full_path}")
                continue

            print(f"[JOB {job_id}] Processing PDF: {filename}")

            # Extract images from PDF
            images_info = extract_images_from_pdf(full_path, images_dir)

            for image_info in images_info:
                # Generate description
                description = generate_image_description(
                    image_info['path'], 
                    image_info['context']
                )
                
                image_info['description'] = description

                # Upload description to database
                description_id = upload_image_description(courseid, image_info)
                if description_id:
                    uploaded_descriptions.append(description_id)

                # Prepare for FastAPI upload
                description_filename = f"{image_info['filename']}.txt"
                description_content = f"Image: {image_info['filename']}\n"
                description_content += f"Source PDF: {image_info['source_pdf']}\n"
                description_content += f"Page: {image_info['page_num']}\n"
                description_content += f"Context: {image_info['context']}\n"
                #description_content += f"Description: {description}\n"
                
                # Save description file temporarily for FastAPI
                temp_desc_file = os.path.join(tmpdir, description_filename)
                with open(temp_desc_file, 'w', encoding='utf-8') as f:
                    f.write(description_content)
                
                description_files_for_fastapi.append({
                    'filename': description_filename,
                    'fullpath': temp_desc_file,
                    'mimetype': 'text/plain'
                })

                print(f"[JOB {job_id}] Processed image: {image_info['filename']}")

        # Upload descriptions to FastAPI if any were created
        if description_files_for_fastapi:
            fastapi_url = "http://fastapi:8000"
            print(f"[JOB {job_id}] Uploading {len(description_files_for_fastapi)} descriptions to FastAPI")
            
            uploaded_count = 0
            for desc_file in description_files_for_fastapi:
                if upload_description_to_fastapi(fastapi_url, courseid, desc_file['fullpath'], desc_file['filename']):
                    uploaded_count += 1
            
            print(f"[JOB {job_id}] Uploaded {uploaded_count}/{len(description_files_for_fastapi)} descriptions to FastAPI")

    print(f"[JOB {job_id}] PDF image processing complete for course {courseid}")
    print(f"[JOB {job_id}] Processed {len(uploaded_descriptions)} image descriptions")
    
    return {
        'processed_images': len(uploaded_descriptions),
        'uploaded_descriptions': len(description_files_for_fastapi)
    }