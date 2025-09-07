from fastapi import APIRouter
from uuid import uuid4

# Import the Celery task
from .tasks_images import process_pdf_images_job

router = APIRouter()

@router.post("/api/v1/images/process/{courseid}")
async def process_images_async(courseid: int):
    """
    Endpoint to trigger asynchronous PDF image processing.
    Extracts images from PDFs and creates searchable descriptions.
    """
    job_id = str(uuid4())

    # Send the task to the Celery queue
    process_pdf_images_job.delay(job_id, courseid)

    return {
        "job_id": job_id, 
        "status": "queued",
        "message": f"PDF image processing started for course {courseid}"
    }