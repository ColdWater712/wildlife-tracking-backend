"""ai-service Wildlife Tracking: model AI terlatih, gratis, berjalan di CPU.

  POST /classify-audio  suara lapangan   -> YAMNet + classifier terlatih (tembakan, gergaji, api, ...)
  POST /analyze-image   foto kamera      -> SpeciesNet (MegaDetector + classifier spesies) [+ embedding re-ID]
  POST /analyze-video   video kamera     -> beberapa frame dianalisis seperti foto lalu digabung
  POST /embed           foto satwa       -> embedding MegaDescriptor untuk re-identifikasi individu
  GET  /health

Jalankan: uvicorn app:app --host 127.0.0.1 --port 8001
"""

from __future__ import annotations

import os
import subprocess
import tempfile
import threading
import time
from pathlib import Path

BASE = Path(__file__).resolve().parent
MODEL_DIR = Path(os.getenv("AI_MODEL_DIR", BASE / "models"))
# Semua unduhan model (Kaggle, Hugging Face, torch hub) disimpan di folder models/ proyek.
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")
os.environ.setdefault("KAGGLEHUB_CACHE", str(MODEL_DIR / "kaggle"))
os.environ.setdefault("HF_HOME", str(MODEL_DIR / "huggingface"))
os.environ.setdefault("TORCH_HOME", str(MODEL_DIR / "torch"))

import numpy as np
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from PIL import Image

import audio_io
import audio_pipeline

MAX_UPLOAD_MB = float(os.getenv("AI_MAX_UPLOAD_MB", "50"))
MAX_SECONDS = float(os.getenv("AI_MAX_AUDIO_SECONDS", "600"))
VIDEO_FRAME_INTERVAL = float(os.getenv("AI_VIDEO_FRAME_INTERVAL", "2"))
VIDEO_MAX_FRAMES = int(os.getenv("AI_VIDEO_MAX_FRAMES", "8"))
REID_MIN_CONFIDENCE = float(os.getenv("AI_REID_MIN_DETECTION", "0.5"))
TOKEN = os.getenv("AI_SERVICE_TOKEN", "")
AUDIO_MODEL_NAME = "yamnet-audioset-v1+wt-heads-v1"

app = FastAPI(title="Wildlife Tracking AI Service", version="2.0.0")

_models: dict[str, object] = {}
_errors: dict[str, str | None] = {"audio": None, "vision": None, "reid": None}
_load_lock = threading.Lock()


def _load(name: str):
    if name in _models:
        return _models[name]
    with _load_lock:
        if name not in _models:
            try:
                if name == "audio":
                    from audio_heads import AudioHeads
                    from yamnet_model import YamnetModel

                    yam = YamnetModel(str(MODEL_DIR / "yamnet.h5"), str(MODEL_DIR / "yamnet_class_map.csv"))
                    _models[name] = (yam, AudioHeads(), threading.Lock())
                elif name == "vision":
                    from vision import VisionModel

                    _models[name] = VisionModel()
                elif name == "reid":
                    from reid import ReidModel

                    _models[name] = ReidModel()
                _errors[name] = None
            except Exception as e:  # noqa: BLE001 - dilaporkan lewat /health
                _errors[name] = f"{type(e).__name__}: {e}"
                raise
    return _models[name]


def _require(name: str):
    try:
        return _load(name)
    except Exception:
        raise HTTPException(status_code=503, detail=f"Model {name} belum siap: {_errors[name]}. Jalankan download_model.py.")


def check_token(x_api_key: str | None = Header(default=None)):
    if TOKEN and x_api_key != TOKEN:
        raise HTTPException(status_code=401, detail="Token ai-service tidak valid.")


async def _save_upload(file: UploadFile, tmp: str) -> Path:
    data = await file.read()
    if not data:
        raise HTTPException(status_code=422, detail="Berkas kosong.")
    if len(data) > MAX_UPLOAD_MB * 1024 * 1024:
        raise HTTPException(status_code=413, detail=f"Berkas melebihi {MAX_UPLOAD_MB:.0f} MB.")
    path = Path(tmp) / ("input" + Path(file.filename or "file").suffix[:10].lower())
    path.write_bytes(data)
    return path


@app.on_event("startup")
def warm_up():
    try:
        yam, _, _ = _load("audio")
        yam.scores(np.zeros(16000, dtype=np.float32))
    except Exception:
        pass
    if os.getenv("AI_PRELOAD_VISION", "1") == "1":
        for name in ("vision", "reid"):
            try:
                _load(name)
            except Exception:
                pass


@app.get("/health")
def health():
    return {
        "status": "ok" if "audio" in _models else "degraded",
        "models": {
            "audio": {"loaded": "audio" in _models, "model": AUDIO_MODEL_NAME, "error": _errors["audio"]},
            "vision": {"loaded": "vision" in _models, "model": getattr(_models.get("vision"), "version", None), "error": _errors["vision"]},
            "reid": {"loaded": "reid" in _models, "model": getattr(_models.get("reid"), "version", None), "error": _errors["reid"]},
        },
        # kompatibel dengan versi lama
        "model": AUDIO_MODEL_NAME,
    }


@app.post("/classify-audio", dependencies=[Depends(check_token)])
async def classify_audio(file: UploadFile = File(...)):
    yam, heads, lock = _require("audio")
    started = time.perf_counter()
    with tempfile.TemporaryDirectory() as tmp:
        path = await _save_upload(file, tmp)
        try:
            with lock:
                result = audio_pipeline.classify(yam, heads, str(path), MAX_SECONDS)
        except ValueError as e:
            raise HTTPException(status_code=422, detail=str(e))
    return {**result, "model": AUDIO_MODEL_NAME, "processing_ms": int((time.perf_counter() - started) * 1000)}


def _analyze_image_file(path: Path, country: str | None, embed: bool) -> dict:
    vision = _require("vision")
    try:
        result = vision.analyze(str(path), country)
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=422, detail=f"Gambar tidak dapat dianalisis: {type(e).__name__}")
    result["model"] = vision.version

    if embed:
        animals = [d for d in result["detections"] if d["category"] == "animal" and d["confidence"] >= REID_MIN_CONFIDENCE][:3]
        if animals:
            from reid import crop

            reid = _require("reid")
            with Image.open(path) as im:
                im = im.convert("RGB")
                vectors = reid.embed([crop(im, d["bbox"]) for d in animals])
            for d, v in zip(animals, vectors):
                d["embedding"] = [round(float(x), 6) for x in v]
            result["embedding_model"] = reid.version
    return result


@app.post("/analyze-image", dependencies=[Depends(check_token)])
async def analyze_image(file: UploadFile = File(...), country: str | None = Form(default=None), embed: bool = Form(default=True)):
    started = time.perf_counter()
    with tempfile.TemporaryDirectory() as tmp:
        path = await _save_upload(file, tmp)
        result = _analyze_image_file(path, country, embed)
    result["processing_ms"] = int((time.perf_counter() - started) * 1000)
    return result


@app.post("/analyze-video", dependencies=[Depends(check_token)])
async def analyze_video(file: UploadFile = File(...), country: str | None = Form(default=None), embed: bool = Form(default=True)):
    started = time.perf_counter()
    with tempfile.TemporaryDirectory() as tmp:
        path = await _save_upload(file, tmp)
        frames_dir = Path(tmp) / "frames"
        frames_dir.mkdir()
        cmd = [audio_io.ffmpeg_path(), "-nostdin", "-hide_banner", "-loglevel", "error", "-i", str(path),
               "-vf", f"fps=1/{VIDEO_FRAME_INTERVAL},scale='min(1920,iw)':-2", "-frames:v", str(VIDEO_MAX_FRAMES),
               str(frames_dir / "f_%03d.jpg")]
        proc = subprocess.run(cmd, capture_output=True, timeout=300)
        frames = sorted(frames_dir.glob("f_*.jpg"))
        if proc.returncode != 0 or not frames:
            raise HTTPException(status_code=422, detail="Video tidak dapat dibaca (format tidak dikenali atau berkas rusak).")

        per_frame = []
        for i, f in enumerate(frames):
            r = _analyze_image_file(f, country, embed)
            r["time_seconds"] = round(i * VIDEO_FRAME_INTERVAL, 2)
            per_frame.append(r)

    from vision import species_rank_key

    best = max(per_frame, key=lambda r: (r["animal_count"] + r["person_count"] + r["vehicle_count"],
                                         species_rank_key(r["top_species"]) if r["top_species"] else (0, 0)))
    species = [r["top_species"] for r in per_frame if r["top_species"]]
    return {
        **best,
        "animal_count": max(r["animal_count"] for r in per_frame),
        "person_count": max(r["person_count"] for r in per_frame),
        "vehicle_count": max(r["vehicle_count"] for r in per_frame),
        "top_species": max(species, key=species_rank_key) if species else None,
        "best_frame_seconds": best["time_seconds"],
        "frames": [{"time_seconds": r["time_seconds"], "animal_count": r["animal_count"], "person_count": r["person_count"],
                    "vehicle_count": r["vehicle_count"], "top_species": r["top_species"]} for r in per_frame],
        "processing_ms": int((time.perf_counter() - started) * 1000),
    }


@app.post("/embed", dependencies=[Depends(check_token)])
async def embed(file: UploadFile = File(...), country: str | None = Form(default=None)):
    """Embedding untuk foto referensi individu: satwa terbesar yang terdeteksi, atau seluruh foto bila tidak ada deteksi."""
    started = time.perf_counter()
    with tempfile.TemporaryDirectory() as tmp:
        path = await _save_upload(file, tmp)
        result = _analyze_image_file(path, country, embed=False)
        animals = [d for d in result["detections"] if d["category"] == "animal"]
        target = max(animals, key=lambda d: d["bbox"][2] * d["bbox"][3]) if animals else None
        from reid import crop

        reid = _require("reid")
        with Image.open(path) as im:
            vector = reid.embed([crop(im.convert("RGB"), target["bbox"] if target else None)])[0]
    return {
        "embedding": [round(float(x), 6) for x in vector],
        "embedding_model": reid.version,
        "bbox": target["bbox"] if target else None,
        "detection_confidence": target["confidence"] if target else None,
        "species": target.get("species") if target else None,
        "processing_ms": int((time.perf_counter() - started) * 1000),
    }
