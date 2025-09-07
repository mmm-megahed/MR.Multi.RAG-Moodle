from fastapi import APIRouter
from uuid import uuid4

# Import the Celery task you just created
from .tasks import process_video_job

router = APIRouter()

@router.post("/api/v1/video/process/{courseid}")
async def process_video_async(courseid: int):
    job_id = str(uuid4())

    # Send the task to the Celery queue using .delay()
    # The API returns immediately, and the job runs in the worker.
    process_video_job.delay(job_id, courseid)

    return {"job_id": job_id, "status": "queued"}