"""Unduh semua model terlatih ke folder models/ (sekali saja, butuh internet, total sekitar 800 MB).

    python download_model.py            # semua model
    python download_model.py audio      # hanya YAMNet (15 MB)

Sumber resmi:
  - YAMNet (Google, Apache-2.0)                 storage.googleapis.com/audioset
  - SpeciesNet v4.0.3a (Google, Apache-2.0)     kaggle.com/models/google/speciesnet
  - MegaDetector v5a (Microsoft/agentmorris, MIT) github.com/agentmorris/MegaDetector/releases
  - MegaDescriptor-T-224 (WildlifeDatasets, CC-BY-NC-4.0) huggingface.co/BVRA/MegaDescriptor-T-224
"""

from __future__ import annotations

import hashlib
import os
import sys
import urllib.request
from pathlib import Path

BASE = Path(__file__).resolve().parent
MODEL_DIR = Path(os.getenv("AI_MODEL_DIR", BASE / "models"))
os.environ.setdefault("KAGGLEHUB_CACHE", str(MODEL_DIR / "kaggle"))
os.environ.setdefault("HF_HOME", str(MODEL_DIR / "huggingface"))
os.environ.setdefault("TORCH_HOME", str(MODEL_DIR / "torch"))

AUDIO_FILES = {
    "yamnet.h5": "https://storage.googleapis.com/audioset/yamnet.h5",
    "yamnet_class_map.csv": "https://raw.githubusercontent.com/tensorflow/models/master/research/audioset/yamnet/yamnet_class_map.csv",
}


def audio() -> None:
    MODEL_DIR.mkdir(exist_ok=True)
    for name, url in AUDIO_FILES.items():
        dest = MODEL_DIR / name
        if dest.exists() and dest.stat().st_size > 0:
            print(f"[lewati] {name} sudah ada")
            continue
        print(f"[unduh] {name}")
        tmp = dest.with_suffix(dest.suffix + ".part")
        urllib.request.urlretrieve(url, tmp)
        tmp.replace(dest)
        print(f"        {dest.stat().st_size / 1e6:.1f} MB, sha256 {hashlib.sha256(dest.read_bytes()).hexdigest()[:16]}...")


def vision() -> None:
    print("[unduh] SpeciesNet + MegaDetector (sekitar 500 MB, pertama kali saja)")
    sys.path.insert(0, str(BASE))
    from vision import VisionModel

    VisionModel()
    print("        SpeciesNet siap")


def reid() -> None:
    print("[unduh] MegaDescriptor-T-224 (sekitar 110 MB)")
    sys.path.insert(0, str(BASE))
    from reid import ReidModel

    ReidModel()
    print("        MegaDescriptor siap")


def main(argv: list[str]) -> int:
    targets = argv or ["audio", "vision", "reid"]
    for t in targets:
        {"audio": audio, "vision": vision, "reid": reid}[t]()
    print("Selesai. Jalankan: uvicorn app:app --host 127.0.0.1 --port 8001")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
