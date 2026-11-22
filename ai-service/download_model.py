"""Unduh bobot resmi YAMNet (Google, Apache-2.0) ke folder models/. Cukup dijalankan sekali.

    python download_model.py
"""

from __future__ import annotations

import hashlib
import sys
import urllib.request
from pathlib import Path

FILES = {
    "yamnet.h5": "https://storage.googleapis.com/audioset/yamnet.h5",
    "yamnet_class_map.csv": "https://raw.githubusercontent.com/tensorflow/models/master/research/audioset/yamnet/yamnet_class_map.csv",
}


def main() -> int:
    target = Path(__file__).resolve().parent / "models"
    target.mkdir(exist_ok=True)
    for name, url in FILES.items():
        dest = target / name
        if dest.exists() and dest.stat().st_size > 0:
            print(f"[lewati] {name} sudah ada")
            continue
        print(f"[unduh] {name} dari {url}")
        tmp = dest.with_suffix(dest.suffix + ".part")
        urllib.request.urlretrieve(url, tmp)
        tmp.replace(dest)
        print(f"        {dest.stat().st_size / 1e6:.1f} MB, sha256 {hashlib.sha256(dest.read_bytes()).hexdigest()[:16]}...")
    print("Selesai. Jalankan: uvicorn app:app --host 127.0.0.1 --port 8001")
    return 0


if __name__ == "__main__":
    sys.exit(main())
