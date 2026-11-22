"""Re-identifikasi individu satwa dengan MegaDescriptor (WildlifeDatasets, CC-BY-NC-4.0, non-komersial).

Menghasilkan embedding 768 dimensi (sudah dinormalisasi) dari potongan gambar satwa. Dua foto individu
yang sama menghasilkan kemiripan kosinus tinggi. Galeri individu disimpan di backend (database),
ai-service hanya menghitung embedding.

Uji pada ATRW (107 harimau Amur, 1.887 foto): top-1 97,9%; galeri 3 foto/individu + ambang 0,6:
92,7% individu terdaftar dikenali benar, 86,5% individu baru ditolak. Lihat training/README.md.
"""

from __future__ import annotations

import os
import threading

import numpy as np
from PIL import Image

DEFAULT_MODEL = os.getenv("AI_REID_MODEL", "BVRA/MegaDescriptor-T-224")
MATCH_THRESHOLD = float(os.getenv("AI_REID_THRESHOLD", "0.6"))


def crop(image: Image.Image, bbox: list[float] | None, margin: float = 0.08) -> Image.Image:
    """Potong bbox relatif [x, y, w, h] dengan sedikit margin."""
    if not bbox:
        return image
    W, H = image.size
    x, y, w, h = bbox
    x0, y0 = max(0.0, x - w * margin), max(0.0, y - h * margin)
    x1, y1 = min(1.0, x + w * (1 + margin)), min(1.0, y + h * (1 + margin))
    return image.crop((int(x0 * W), int(y0 * H), max(int(x1 * W), int(x0 * W) + 1), max(int(y1 * H), int(y0 * H) + 1)))


class ReidModel:
    def __init__(self, model_id: str = DEFAULT_MODEL):
        import timm
        import torch
        import torchvision.transforms as T

        self.model_id = model_id
        self.torch = torch
        self.model = timm.create_model(f"hf-hub:{model_id}", num_classes=0, pretrained=True).eval()
        size = int(model_id.rsplit("-", 1)[-1]) if model_id.rsplit("-", 1)[-1].isdigit() else 224
        self.transform = T.Compose([T.Resize((size, size)), T.ToTensor(), T.Normalize([0.5] * 3, [0.5] * 3)])
        self._lock = threading.Lock()

    @property
    def version(self) -> str:
        return self.model_id.split("/")[-1]

    def embed(self, images: list[Image.Image]) -> np.ndarray:
        if not images:
            return np.zeros((0, 768), dtype=np.float32)
        x = self.torch.stack([self.transform(im.convert("RGB")) for im in images])
        with self._lock, self.torch.inference_mode():
            e = self.torch.nn.functional.normalize(self.model(x), dim=-1)
        return e.numpy().astype(np.float32)
