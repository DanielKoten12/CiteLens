import os

GROBID_URL = os.getenv("GROBID_URL", "http://localhost:8070/").rstrip("/")
GROBID_TIMEOUT_SECONDS = float(os.getenv("GROBID_TIMEOUT_SECONDS", "300"))
MAX_UPLOAD_BYTES = int(os.getenv("MAX_UPLOAD_MB", "20")) * 1024 * 1024
