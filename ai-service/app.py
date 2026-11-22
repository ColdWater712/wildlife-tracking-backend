"""ai-service Wildlife Tracking: klasifikasi suara lapangan dengan YAMNet (gratis, CPU).

Jalankan:  uvicorn app:app --host 127.0.0.1 --port 8001
Kontrak (dipakai backend Laravel lewat AI_AUDIO_SERVICE_URL):
  POST /classify-audio  multipart `file`
    -> {label, category, confidence, species, summary, detected_at_seconds, duration_seconds,
        predictions: [{label, score}], category_scores}
  GET  /health
"""

from __future__ import annotations

import os
import tempfile
import threading
import time
from pathlib import Path

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import numpy as np
from fastapi import Depends, FastAPI, File, Header, HTTPException, UploadFile

import audio_io
import categories

BASE = Path(__file__).resolve().parent
MODEL_DIR = Path(os.getenv("AI_MODEL_DIR", BASE / "models"))
MAX_UPLOAD_MB = float(os.getenv("AI_MAX_UPLOAD_MB", "50"))
MAX_SECONDS = float(os.getenv("AI_MAX_AUDIO_SECONDS", "600"))
TOKEN = os.getenv("AI_SERVICE_TOKEN", "")
MODEL_NAME = "yamnet-audioset-v1"

app = FastAPI(title="Wildlife Tracking AI Service", version="1.0.0")

_model = None
_model_error: str | None = None
_lock = threading.Lock()


def get_model():
    """Muat YAMNet sekali (lazy) agar /health tetap bisa menjawab saat model belum diunduh."""
    global _model, _model_error
    if _model is None:
        with _lock:
            if _model is None:
                try:
                    from yamnet_model import YamnetModel

                    _model = YamnetModel(str(MODEL_DIR / "yamnet.h5"), str(MODEL_DIR / "yamnet_class_map.csv"))
                    _model_error = None
                except Exception as e:  # noqa: BLE001 - dilaporkan lewat /health
                    _model_error = f"{type(e).__name__}: {e}"
                    raise
    return _model


def check_token(x_api_key: str | None = Header(default=None)):
    if TOKEN and x_api_key != TOKEN:
        raise HTTPException(status_code=401, detail="Token ai-service tidak valid.")


@app.on_event("startup")
def warm_up():
    try:
        get_model().scores(np.zeros(16000, dtype=np.float32))
    except Exception:
        pass  # lihat /health


@app.get("/health")
def health():
    ready = _model is not None
    return {
        "status": "ok" if ready else "model_not_loaded",
        "model": MODEL_NAME,
        "error": _model_error,
        "model_dir": str(MODEL_DIR),
    }


@app.post("/classify-audio", dependencies=[Depends(check_token)])
async def classify_audio(file: UploadFile = File(...)):
    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Berkas kosong.")
    if len(data) > MAX_UPLOAD_MB * 1024 * 1024:
        raise HTTPException(status_code=413, detail=f"Berkas melebihi {MAX_UPLOAD_MB:.0f} MB.")

    try:
        model = get_model()
    except Exception:
        raise HTTPException(status_code=503, detail=f"Model belum siap: {_model_error}. Cek requirements.txt dan jalankan download_model.py.")

    suffix = Path(file.filename or "audio").suffix[:10]
    started = time.perf_counter()
    with tempfile.TemporaryDirectory() as tmp:
        path = Path(tmp) / f"input{suffix}"
        path.write_bytes(data)
        try:
            waveform = audio_io.decode(str(path), model.sample_rate, MAX_SECONDS)
        except ValueError as e:
            raise HTTPException(status_code=422, detail=str(e))

    with _lock:
        scores = model.scores(waveform)

    clip = scores.max(axis=0)
    peaks = scores.argmax(axis=0) * model.frame_hop_seconds
    result = categories.classify(model.class_names, clip.tolist(), peaks.tolist())
    duration = waveform.size / model.sample_rate

    top = np.argsort(clip)[::-1][:5]
    label_id = categories.LABELS_ID.get(result["category"], result["category"])
    label = f"{label_id} ({result['audioset_label']})" if result["audioset_label"] else label_id

    return {
        "label": label[:100],
        "category": result["category"],
        "confidence": result["confidence"],
        "species": result["species"],
        "summary": categories.summary(result, duration),
        "detected_at_seconds": result["detected_at_seconds"],
        "duration_seconds": round(duration, 2),
        "predictions": [{"label": model.class_names[i], "score": round(float(clip[i]), 4)} for i in top],
        "category_scores": result["category_scores"],
        "model": MODEL_NAME,
        "processing_ms": int((time.perf_counter() - started) * 1000),
    }
