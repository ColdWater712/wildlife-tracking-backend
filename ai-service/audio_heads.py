"""Classifier suara terlatih di atas embedding YAMNet (lihat training/README.md untuk data & hasil uji).

- gunshot_head.npz : detektor tembakan biner, input pita 0-4 kHz (rekaman 8/16 kHz diperlakukan sama).
  Dilatih pada tembakan dari hutan Vietnam, Belize, dan Afrika Selatan (10-350 m) + negatif beragam.
- category_head.npz: classifier 8 kategori (chainsaw, fire, engine, vehicle, dog_bark, human_voice,
  animal_call, environment), input pita penuh.

Fitur untuk keduanya: [mean embedding (1024), max embedding (1024), max skor kelas AudioSet (521)].
"""

from __future__ import annotations

from pathlib import Path

import numpy as np

HEADS_DIR = Path(__file__).resolve().parent / "heads"

# Bila classifier kategori yakin >= nilai ini bahwa suara adalah suara manusia (tepuk tangan, langkah, batuk),
# detektor tembakan tidak dipakai. Dipilih pada data validasi (lihat training/README.md).
HUMAN_VOICE_VETO = 0.5
# Aturan zero-shot YAMNet untuk tembakan (kelas Gunshot/Explosion) hanya dipakai bila classifier kategori
# tidak yakin >= nilai ini bahwa suara adalah suara alam (mencegah petir terbaca tembakan).
ENVIRONMENT_VETO = 0.7


def features(scores: np.ndarray, embeddings: np.ndarray) -> np.ndarray:
    return np.concatenate([embeddings.mean(0), embeddings.max(0), scores.max(0)]).astype(np.float32)


class AudioHeads:
    def __init__(self, directory: Path = HEADS_DIR):
        g = np.load(directory / "gunshot_head.npz")
        self.g_mean, self.g_scale, self.g_coef = g["mean"], g["scale"], g["coef"]
        self.g_intercept = float(g["intercept"])
        self.gunshot_threshold = float(g["threshold"])
        self.band_limit_hz = int(g["band_limit_hz"])

        c = np.load(directory / "category_head.npz")
        self.c_mean, self.c_scale, self.c_coef, self.c_intercept = c["mean"], c["scale"], c["coef"], c["intercept"]
        self.classes = [str(x) for x in c["classes"]]

    def gunshot_probability(self, narrowband_features: np.ndarray) -> float:
        z = (narrowband_features - self.g_mean) / self.g_scale
        logit = float(np.clip(z @ self.g_coef + self.g_intercept, -50, 50))
        return 1.0 / (1.0 + np.exp(-logit))

    def category_probabilities(self, fullband_features: np.ndarray) -> dict[str, float]:
        z = (fullband_features - self.c_mean) / self.c_scale
        logits = self.c_coef @ z + self.c_intercept
        p = np.exp(logits - logits.max())
        p /= p.sum()
        return {c: float(v) for c, v in zip(self.classes, p)}
