# ai-service (YAMNet, gratis)

Layanan Python kecil untuk klasifikasi suara lapangan: tembakan, gergaji mesin, api, kayu patah,
mesin, kendaraan, anjing, suara manusia, suara satwa, dan suara alam. Memakai model **YAMNet**
(Google, Apache-2.0, 521 kelas AudioSet). Berjalan di CPU, sekitar 0,1–0,2 detik per rekaman 5 detik.

Backend Laravel memanggilnya lewat `AI_AUDIO_SERVICE_URL`. Bila layanan ini mati, backend otomatis
kembali ke Groq / heuristik, jadi upload tidak pernah gagal karena ai-service.

## Setup (sekali saja, Windows)

Butuh Python 3.10–3.12 (64-bit). Dari folder `ai-service`:

```powershell
py -3.12 -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
python download_model.py
```

`download_model.py` mengunduh `models/yamnet.h5` (15 MB) dan `models/yamnet_class_map.csv` dari Google.
Folder `models/` tidak ikut di-commit.

Linux/macOS: ganti dua baris pertama dengan `python3 -m venv .venv` dan `source .venv/bin/activate`.

## Menjalankan

```powershell
.\.venv\Scripts\Activate.ps1
uvicorn app:app --host 127.0.0.1 --port 8001
```

Lalu di `.env` Laravel:

```env
AI_AUDIO_SERVICE_URL=http://127.0.0.1:8001
AI_AUDIO_SERVICE_MODEL=yamnet-audioset-v1
```

Cek: `GET http://127.0.0.1:8001/health` harus `{"status":"ok"}`, dan `GET /api/ai/health` di backend
menampilkan `audio_service.reachable: true`.

## Kontrak

`POST /classify-audio` (multipart `file`: wav, mp3, m4a, ogg, flac, webm, aac; maks. 50 MB, 10 menit pertama):

```json
{
  "label": "gergaji mesin (Chainsaw)",
  "category": "chainsaw",
  "confidence": 0.9038,
  "species": null,
  "summary": "YAMNet mendeteksi gergaji mesin sekitar detik ke-3 ...",
  "detected_at_seconds": 2.88,
  "duration_seconds": 5.0,
  "predictions": [{"label": "Sawing", "score": 0.9038}],
  "category_scores": {"gunshot": 0.01, "chainsaw": 0.9038},
  "model": "yamnet-audioset-v1",
  "processing_ms": 122
}
```

## Cara memutuskan kategori

Skor tiap kelas diambil dari puncaknya di sepanjang rekaman, karena tembakan hanya terdengar sesaat.
Kategori ancaman dicek dulu sesuai prioritas (tembakan → gergaji → api → kayu patah → mesin → anjing →
kendaraan → suara manusia) dan dipilih bila melewati ambangnya, walau suara hujan/serangga lebih keras.
Bila tidak ada ancaman, dipilih kategori dengan skor tertinggi (suara satwa atau suara alam).

Ambang bisa diatur lewat variabel lingkungan sebelum menjalankan uvicorn, misalnya
`AI_THRESHOLD_GUNSHOT=0.2`. Default: tembakan 0,25, gergaji 0,25, api 0,35, kayu patah 0,4,
mesin/anjing/kendaraan 0,3, suara manusia 0,35, minimum 0,1.

## Variabel lingkungan

| Variabel | Default | Fungsi |
|---|---|---|
| `AI_SERVICE_TOKEN` | kosong | Bila diisi, request wajib header `X-Api-Key` (isi juga `AI_AUDIO_SERVICE_TOKEN` di Laravel) |
| `AI_MODEL_DIR` | `./models` | Lokasi `yamnet.h5` dan class map |
| `AI_MAX_UPLOAD_MB` | `50` | Batas ukuran berkas |
| `AI_MAX_AUDIO_SECONDS` | `600` | Hanya 10 menit pertama yang dianalisis |
| `FFMPEG_PATH` | ffmpeg bawaan `imageio-ffmpeg` | Pakai ffmpeg sistem bila perlu |

## Hasil uji

Diuji dengan bobot YAMNet asli pada klip ESC-50 dan sampel resmi YAMNet:

| Rekaman | Hasil |
|---|---|
| Gergaji tangan | `chainsaw` 0,90 |
| Gergaji mesin | `chainsaw` 0,41 |
| Gergaji mesin tertutup hujan deras | `engine` 0,33 (tetap tertandai ancaman) |
| Api berderak | `fire` 0,38 |
| Anjing | `dog_bark` 0,72 |
| Mesin mobil | `vehicle` 0,88 |
| Hujan, angin | `environment` 0,95 / 0,92 |
| Ayam jago (WAV & MP3), kucing | `animal_call` 0,90 / 0,97 |

Tembakan belum diuji dengan rekaman nyata karena sampelnya tidak tersedia saat pengujian; uji dengan
rekaman lapangan sebelum dipakai untuk patroli.
