"""Dekode berkas audio apa pun (wav, mp3, m4a, ogg, flac, webm, aac) menjadi 16 kHz mono float32.

Memakai ffmpeg bawaan paket `imageio-ffmpeg`, jadi tidak perlu memasang ffmpeg terpisah
(set FFMPEG_PATH untuk memakai ffmpeg sistem).
"""

from __future__ import annotations

import os
import subprocess

import numpy as np


def ffmpeg_path() -> str:
    custom = os.getenv("FFMPEG_PATH")
    if custom:
        return custom
    import imageio_ffmpeg

    return imageio_ffmpeg.get_ffmpeg_exe()


def decode(path: str, sample_rate: int = 16000, max_seconds: float = 600.0) -> np.ndarray:
    cmd = [
        ffmpeg_path(), "-nostdin", "-hide_banner", "-loglevel", "error",
        "-i", path,
        "-t", str(max_seconds),
        "-ac", "1", "-ar", str(sample_rate),
        "-f", "f32le", "-acodec", "pcm_f32le", "pipe:1",
    ]
    proc = subprocess.run(cmd, capture_output=True, timeout=max(60.0, max_seconds / 2))
    if proc.returncode != 0:
        raise ValueError("Audio tidak dapat dibaca (format tidak dikenali atau berkas rusak).")

    waveform = np.frombuffer(proc.stdout, dtype=np.float32)
    if waveform.size == 0:
        raise ValueError("Audio kosong atau tidak berisi trek suara.")
    return np.clip(waveform, -1.0, 1.0)
