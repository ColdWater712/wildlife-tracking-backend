# Dokumentasi Backend Wildlife Tracking

Diperbarui: 2026-11-22 · Laravel 13 + Sanctum · 168 endpoint REST (juga di `/api/v1`) · 74 test lulus.

Dokumen ini adalah rujukan utama untuk tim frontend dan backend: fitur yang sudah siap, cara memanggilnya,
apa yang berubah di setiap rilis, fitur yang masih dikembangkan, dan hasil audit terakhir.
Daftar endpoint ringkas ada di [`API.md`](API.md); dokumentasi interaktif di `GET /docs/api` (mode lokal).
Menjadikan PC sebagai server tim: [`DEPLOY.md`](DEPLOY.md). Layanan AI suara: [`../ai-service/README.md`](../ai-service/README.md).

Isi:

1. [Cara memanggil API](#1-cara-memanggil-api)
2. [Apa yang diubah](#2-apa-yang-diubah)
3. [Hak akses per role](#3-hak-akses-per-role)
4. [Fitur siap pakai](#4-fitur-siap-pakai)
5. [Fitur AI](#5-fitur-ai)
6. [Fitur yang masih akan dikembangkan](#6-fitur-yang-masih-akan-dikembangkan)
7. [Hasil audit](#7-hasil-audit)
8. [Setup dan update](#8-setup-dan-update)

---

## 1. Cara memanggil API

| Hal | Nilai |
|---|---|
| Base URL | `http://127.0.0.1:8000/api` (juga `/api/v1/...`) |
| Autentikasi | Header `Authorization: Bearer <token>` dari `POST /login` |
| Header wajib | `Accept: application/json`; `Content-Type: application/json` kecuali upload file (multipart) |
| Sesi | Token dicabut jika tidak dipakai lebih dari 2 jam (`API_IDLE_TIMEOUT`, menit). Respons `401` |
| CORS | Origin frontend diatur lewat `FRONTEND_URLS` (default `http://localhost:3000,http://127.0.0.1:3000`) |
| Akun uji | `admin@`, `peneliti@`, `lapangan@`, `kebijakan@wildlife.id`, password `password123` |

Format respons selalu sama:

```json
{ "success": true, "message": "OK", "data": ..., "errors": null }
```

- List mengembalikan array polos di `data`. Pagination opsional `?page=1&per_page=25` (maks. 200);
  total ada di header `X-Total-Count`, `X-Current-Page`, `X-Per-Page`, `X-Last-Page`.
- Sort opsional `?orderBy=<kolom>&order=asc|desc` (kolom dibatasi per endpoint).
- Error: `401` belum login / sesi habis / akun nonaktif, `403` tidak punya izin, `404` data tidak ada,
  `409` bentrok relasi/duplikat, `422` validasi (detail per field di `errors`), `429` terlalu banyak percobaan.
- Relasi dikirim camelCase: `trackingDevice`, `predictedSpecies`, `predictedAnimal`, `verifiedSpecies`, `weatherData`.

Login dari frontend (sesuai `laravel-auth.service.ts`):

```ts
const res = await fetch(`${API_URL}/login`, {
  method: "POST",
  headers: { Accept: "application/json", "Content-Type": "application/json" },
  body: JSON.stringify({ email, password }),
});
const { data } = await res.json(); // data.token, data.user.role.role_name, data.user.permissions
```

Setiap respons `401` berarti token sudah tidak berlaku (sesi habis, logout, akun dinonaktifkan, atau
password direset admin). Frontend cukup menghapus token lalu mengarahkan ke halaman login:

```ts
if (res.status === 401) {
  clearToken();            // fungsi penyimpanan token milik FE
  router.push("/login");   // message: "Sesi Anda telah berakhir ..." atau "Unauthenticated ..."
}
```

Alias agar frontend tidak perlu diubah — backend tetap menerima dan mengirim nama lama:

| Dipakai frontend | Kolom asli |
|---|---|
| `animal.name` (baca & kirim) | `name_animal` |
| `role.role_name` | `name_role` |
| `permission_name` | `name_permission` |
| `incident.reported_by` | `user_id` |
| `species.animal_count` | jumlah satwa per spesies |
| `role` (nama) saat membuat user | dikonversi ke `role_id` |
| path `/devices`, `/incidents`, `/login`, `/me` | juga `/tracking-devices`, `/incident-reports`, `/auth/login`, `/auth/me` |

---

## 2. Apa yang diubah

Riwayat terbaru di atas. Setiap rilis wajib diikuti `php artisan migrate:fresh --seed` di database lokal/server.

### Setup ai-service sekali jalan (2026-11-23)

`scripts/setup-ai-service.ps1` memastikan Python 3.12, membuat `ai-service\.venv`, memasang paket yang kurang, mengunduh
model yang belum ada, lalu mengecek ketiga model (audio, vision, reid) bisa dimuat. `reverse_geocoder` (dependensi
SpeciesNet yang terlewat di Windows) kini tercantum di `requirements.txt`, dan `download_model.py` tetap mengunduh model
lain bila satu gagal lalu menampilkan ringkasan OK/GAGAL. Tidak ada perubahan API atau database.

### Perbaikan skrip server tim (2026-11-23)

`scripts/start-server.ps1` sekarang menemukan `cloudflared` yang dipasang winget di `Program Files` walau tidak ada di PATH
(sebelumnya skrip berhenti dengan "'cloudflared' tidak ditemukan"). Tidak ada perubahan API atau database.

### Rilis model AI terlatih: suara, kamera jebak, identifikasi individu (2026-11-22)

Semua fitur AI utama kini memakai model yang **sudah terlatih dan teruji pada data nyata**, bukan tebakan LLM.
Detail data, metode, dan angka uji: [`ai-service/training/README.md`](../ai-service/training/README.md).

| Area | Sebelum | Sesudah | Hasil uji (data yang tidak dipakai latihan) |
|---|---|---|---|
| Klasifikasi suara | Aturan zero-shot YAMNet | YAMNet + **classifier yang dilatih proyek ini** (ESC-50, rekaman hutan Vietnam, Lund) | Akurasi 96,1% (2.548 klip) |
| Tembakan | Zero-shot: 11% tertangkap di hutan, petir 72% terbaca tembakan | **Detektor tembakan terlatih** (pita 0–4 kHz) + veto suara alam/manusia | 85% tertangkap, alarm palsu 1,3%; 97% pada 10–350 m (Lund) |
| Deteksi satwa/manusia/kendaraan + jumlah individu | Tebakan Groq vision | **MegaDetector v5a** | 79/90 (88%) foto bersatwa terdeteksi, 0/20 foto kosong salah |
| Spesies | Tebakan Groq vision | **SpeciesNet v4.0.3a** (Google), geofence Indonesia | Sambar 14/15, babi hutan 13/15, kijang 17/20 (foto Laos/Vietnam) |
| Identifikasi individu | GPS/zona/kata ciri fisik | + **re-identifikasi penampilan MegaDescriptor** dengan galeri foto per satwa | Top-1 97,9% (107 harimau); individu baru 86,5% tidak dipaksa cocok |
| Video | 1 frame | 1 frame tiap 2 detik (maks. 8), hasil paling spesifik dipakai | – |
| Kondisi fisik, perilaku, senjata/jerat/penebangan/api | Groq vision | Tetap Groq vision, kini dengan konteks hasil deteksi (belum ada model terlatih publik) | Belum diukur |

Endpoint baru: `GET/POST /animals/{id}/reference-photos`, `GET /animals/{id}/reference-photos/{embeddingId}/file`,
`DELETE /animals/{id}/reference-photos/{embeddingId}`. Verifikasi prediksi dengan `verified_animal_id` otomatis
menambah foto ke galeri individu. `animal_match_method` bisa bernilai `appearance_reid`.

Perbaikan ikut ditemukan saat pengujian: MP4 yang dikenali sistem sebagai `application/mp4` dulu ditolak (kini
diterima); berkas yang isinya bukan gambar/video/audio dulu `500` (kini `422`); urutan kandidat individu tidak lagi
terpotong batas 0,99.

Migrasi baru: `2026_11_22_000002_create_animal_embeddings_table`. Variabel baru: `AI_VISION_SERVICE_URL`,
`AI_COUNTRY`, `AI_REID_THRESHOLD`. Test baru: `VisionModelsIntegrationTest` (11 test).

### Rilis AI suara nyata (YAMNet) & antrean AI (2026-11-22)

| Area | Sebelum | Sesudah | Dampak ke FE |
|---|---|---|---|
| Klasifikasi suara | Groq Whisper + LLM, atau aturan WAV (akurasi rendah) | **ai-service YAMNet** (gratis, CPU, ~0,2 detik/rekaman) lewat `AI_AUDIO_SERVICE_URL`; Groq/heuristik tetap jadi cadangan bila ai-service mati | Tidak ada; `model_version` menjadi `service:yamnet-audioset-v1` |
| Antrean analisis | Analisis selalu langsung saat upload | `AI_QUEUE=true` (opsional): upload langsung dibalas `processing_status: "queued"`, worker `queue:work` mengisi hasilnya | Bila diaktifkan, FE polling `GET /ai-predictions/{id}` |
| Tabel antrean | Tidak ada (antrean `database` tidak bisa dipakai) | Migrasi baru `2026_11_22_000001_create_jobs_table` (`jobs`, `job_batches`, `failed_jobs`) | — |
| `GET /ai/health` | `audio_service: configured` | Mengecek ai-service sungguhan (`reachable`, `model`) dan status antrean | Field baru `queue` |
| Retry/antrean berganti model | Bisa bentrok unique (`media_id`, `model_version`) | Baris prediksi dipakai ulang, tidak ada duplikat | — |
| Server tim | Tiap orang setup sendiri | `scripts/start-server.ps1` + Cloudflare Tunnel, panduan di `docs/DEPLOY.md` | FE cukup mengganti `API_URL` |

Variabel baru di `.env`: `AI_AUDIO_SERVICE_URL`, `AI_AUDIO_SERVICE_MODEL`, `AI_AUDIO_SERVICE_TOKEN`, `AI_QUEUE`.
Test baru: `tests/Feature/AiServiceIntegrationTest.php` (6 test) + uji end-to-end dengan YAMNet asli.

### Rilis audit keamanan & logika (2026-11-22)

| Area | Sebelum | Sesudah | Dampak ke FE |
|---|---|---|---|
| Nonaktifkan user lewat `PUT /users/{id}` | Token user tetap aktif | Semua token dicabut; token milik user `nonaktif` selalu ditolak | User nonaktif mendapat `401` |
| Admin menonaktifkan diri lewat `PUT /users/{id}` | Bisa | Ditolak `422` | — |
| Admin mereset password user | Sesi lama user tetap jalan | Semua token user itu dicabut | User harus login ulang |
| `POST /media-uploads/{id}/predictions` | Bisa menimpa prediksi yang sudah diverifikasi (status kembali `pending`) | Ditolak `409` bila media punya prediksi terverifikasi | Tampilkan pesan `409` |
| Role bawaan | Bisa diganti nama/dihapus; admin bisa mencabut `manage_roles` dari role sendiri | Nama role bawaan dikunci (`422`), hapus ditolak (`409`), admin tidak bisa mengunci dirinya (`422`) | Deskripsi role tetap bisa diubah |
| `PATCH /incidents/{id}/sync-status` | Dicek nama role `administrator_sistem` | Dicek permission `delete_data` (hanya admin) | Tidak berubah untuk role bawaan |
| `PUT /incidents/{id}`, `PATCH /animal-geo-zone-visits/{id}/exit` | Cukup `create_data` | Butuh `update_data` | Tidak berubah untuk role bawaan |
| `POST /gps-readings` & `/bulk` | Menerima perangkat kamera/audio | Hanya perangkat `device_type=gps` (`422`) | — |
| Baterai/sinyal dari GPS & `/devices/{id}/captures` | Tidak memicu notifikasi | Memicu `device_alert` seperti `/telemetry` | Notifikasi baru muncul |
| `GET /gps-readings?limit=-1` | Batas 2.000 titik hilang | Batas minimal 1, maks. 5.000 | — |
| Ekspor CSV | Teks `=...` dijalankan sebagai formula di Excel | Diberi awalan `'` | Isi sel diawali `'` untuk teks semacam itu |
| `GET /media-uploads/{id}/file` | — | Header `X-Content-Type-Options: nosniff` | — |
| Upload media insiden | `.flac` ditolak | `.flac` diterima (sama dengan `/ai/upload`) | — |
| CORS | Origin tertulis di kode (`localhost:3000`) | Dari `.env` `FRONTEND_URLS` | Isi domain FE produksi di server |

Test baru: `tests/Feature/SecurityAuditTest.php` (14 test).

### Rilis sesi 2 jam (commit `970a24f`)

| Sebelum | Sesudah |
|---|---|
| Middleware timeout tidak pernah berjalan karena Sanctum menimpa `last_used_at` lebih dulu; token idle tetap diterima | Pengecekan di `Sanctum::authenticateAccessTokensUsing`; aktivitas terakhir dicatat di cache (`CACHE_STORE`) dengan cadangan `last_used_at`; idle > 2 jam = `401` dan token dihapus |

Opsi baru: `API_IDLE_TIMEOUT` (menit, default 120). Test: `tests/Feature/SessionTimeoutTest.php` (7 test).

### Rilis audit skema & fitur AI (commit `4a89333`–`37707b4`)

Kolom nama memakai pola `name_<entitas>`, semua relasi memakai ID, hak akses berbasis permission,
perangkat kamera/audio, analisis gambar & suara, identifikasi individu satwa, insiden & notifikasi otomatis,
laporan agregat, ekspor, dan dokumentasi API.

| Tabel | Sebelum | Sesudah |
|---|---|---|
| `users` | `name`, `role` (enum teks) | `user_id`, `role_id` → roles, `username`, `full_name`, `address`, `phone`, `status` |
| `roles` | `role_name` | `role_id`, `name_role` |
| `permissions` | `permission_name` | `permission_id`, `name_permission` |
| `role_permissions` | kunci gabungan | `role_permission_id` + unik (`role_id`, `permission_id`) |
| `animals` | `name` | `animal_id`, `name_animal` (unik), `distinctive_marks` |
| `incident_reports` | `animal_code` & `location` (teks) | `user_id`, `animal_id`, `species_id`, `zone_id` (FK), `latitude`, `longitude`, `status`, `source` |
| `tracking_devices` | hanya GPS | `device_type` (gps/camera/audio), `zone_id`, `latitude`, `longitude`, `serial_number` |
| `ai_media_uploads` | gambar/video | + audio, `device_id`, `zone_id`, koordinat, `captured_at`, `mime_type`, `file_size` |
| `ai_predictions` | spesies + confidence | + individu satwa, kondisi fisik, perilaku, kategori suara, tingkat ancaman |
| `notifications` | — | + `report_id`, `prediction_id` |
| `aggregate_reports` | — | + `report_data` (JSON statistik) |

---

## 3. Hak akses per role

Akses dicek dari tabel `role_permissions` (middleware `permission:`). Frontend menyembunyikan tombol
berdasarkan `data.user.permissions` dari `/login` atau `/me`; akses yang ditolak menghasilkan `403`.

| Permission | Untuk apa | Konservasionis | Peneliti | Admin | Kebijakan |
|---|---|:-:|:-:|:-:|:-:|
| `view_data` | Baca semua data, dashboard, AI | ✓ | ✓ | ✓ | ✓ |
| `create_data` | Tambah insiden, GPS, kunjungan zona, cuaca, telemetri, rekaman perangkat | ✓ | ✓ | ✓ | |
| `update_data` | Ubah insiden, tutup kunjungan zona | ✓ | ✓ | ✓ | |
| `upload_media` | Upload ke AI | ✓ | ✓ | ✓ | ✓ |
| `verify_ai` | Verifikasi & retry prediksi AI | ✓ | ✓ | ✓ | |
| `manage_master_data` | Spesies, satwa, perangkat, zona | | ✓ | ✓ | |
| `view_reports` | Laporan agregat | | ✓ | ✓ | ✓ |
| `export_data` | Ekspor CSV/GeoJSON | | ✓ | ✓ | ✓ |
| `delete_data` | Hapus insiden/prediksi/media/laporan, ubah `sync-status` | | | ✓ | |
| `manage_users` | User, token, activity log | | | ✓ | |
| `manage_roles` | Role & permission | | | ✓ | |

Aturan tambahan:

- Insiden hanya bisa diubah oleh pelapornya atau admin.
- Admin tidak bisa menonaktifkan, menghapus, atau mengganti role akunnya sendiri, dan tidak bisa
  mencabut `manage_roles` dari role-nya sendiri.
- Empat role bawaan tidak bisa diganti nama atau dihapus (namanya dipakai untuk target notifikasi).
- Endpoint publik `/register` tidak ada; akun baru dibuat admin lewat `POST /users`.

---

## 4. Fitur siap pakai

Semua fitur di bawah aktif dan teruji otomatis. `{id}` selalu angka.

### Autentikasi dan profil

| Method | Path | Body / query | Keterangan |
|---|---|---|---|
| POST | `/login` | `{email, password}` | Token + user (role, permissions). Maks. 5 gagal/menit |
| POST | `/logout` | — | Mencabut token aktif |
| POST | `/auth/refresh` | — | Token baru, token lama dicabut |
| GET | `/me` | — | Profil, role, permission |
| PATCH | `/me` | `{full_name?, username?, email?, address?, phone?}` | Role tidak bisa diubah sendiri |
| PUT | `/me/password` | `{old_password, new_password, new_password_confirmation}` | Token lain milik user dicabut |

### Pengguna, role, dan audit (admin)

| Method | Path | Keterangan |
|---|---|---|
| GET, POST | `/users` | Filter `search, role_id, role, status`. Buat: `{username, full_name, email, password, role_id \| role, address?, phone?}` |
| GET, PUT, DELETE | `/users/{id}` | PUT `status: nonaktif` atau `password` baru mencabut semua token user. DELETE = soft delete |
| PATCH | `/users/{id}/status` | `{status: aktif \| nonaktif}` |
| GET | `/users/{id}/activity`, `/users/{id}/tokens` | Riwayat aktivitas, daftar token (tanpa nilai token) |
| DELETE | `/users/{id}/tokens/{tokenId}` | Cabut satu token |
| GET, POST | `/roles` | `{name_role, description?, permission_ids?}` |
| GET, PUT, DELETE | `/roles/{id}` | Role bawaan: nama dikunci, tidak bisa dihapus. Role lain: hapus ditolak `409` jika masih dipakai |
| GET, PUT | `/roles/{id}/permissions` | PUT `{permission_ids: [..]}` mengganti seluruh set |
| POST, DELETE | `/roles/{id}/permissions/{permissionId}` | Tambah / cabut satu permission |
| GET | `/permissions`, `/permissions/{id}` | Hanya baca |
| GET | `/activity-logs`, `/activity-logs/{id}` | Filter `user_id, action, start_date, end_date` |

### Master data

| Method | Path | Izin tulis | Keterangan |
|---|---|---|---|
| GET, POST | `/species` | `manage_master_data` | `{common_name, scientific_name, conservation_status}`; list memuat `animal_count` |
| GET, PUT, DELETE | `/species/{id}` | `manage_master_data` | Hapus ditolak `409` jika masih ada satwa |
| GET, POST | `/animals` | `manage_master_data` | `{species_id, name, gender, birth_date?, distinctive_marks?, is_active?}`; filter `species_id, gender, is_active, device_id, search` |
| GET, PUT, DELETE | `/animals/{id}` | `manage_master_data` | Detail memuat `latest_ai_observation`; hapus = soft delete + lepas kalung |
| PATCH | `/animals/{id}/status` | `manage_master_data` | `{is_active}` |
| GET | `/animals/{id}/track`, `/zone-visits`, `/incidents`, `/ai-observations` | — | Jejak GPS, riwayat zona, insiden, kondisi & perilaku dari AI |
| GET, POST | `/devices` | `manage_master_data` | `{model, device_type?: gps\|camera\|audio, serial_number?, animal_id? (gps), zone_id?, latitude?, longitude?, ...}`; filter `device_type, is_active, device_condition, animal_id, zone_id, battery_low` |
| GET, PUT, DELETE | `/devices/{id}` | `manage_master_data` | Hapus ditolak `409` jika sudah punya data |
| PATCH, DELETE | `/devices/{id}/assign-animal` | `manage_master_data` | `{animal_id, force?}`; `409` jika satwa sudah berkalung |
| PATCH | `/devices/{id}/condition`, `/devices/{id}/status` | `manage_master_data` | `{device_condition}`, `{is_active}` |
| GET | `/devices/{id}/latest-reading`, `/gps-readings`, `/battery-history`, `/captures` | — | `captures` = rekaman kamera/audio + hasil AI |
| GET, POST | `/geo-zones` | `manage_master_data` | `{zone_name, zone_type, center_lat + center_lon + radius_km, atau polygon_geojson}` |
| GET, PUT, DELETE | `/geo-zones/{id}` | `manage_master_data` | Hapus = soft delete |
| GET | `/geo-zones/{id}/animals` (`?history=1`), `/visits`, `/devices`; `/geo-zones/locate?lat=&lon=` | — | Satwa, kunjungan, perangkat di zona; zona dari koordinat |

### Telemetri, insiden, dan notifikasi

| Method | Path | Izin tulis | Keterangan |
|---|---|---|---|
| GET | `/gps-readings` | — | Filter `device_id, animal_id, start_date, end_date, min_lat..max_lon, latest_only=1`. Tanpa pagination: 2.000 terbaru (`limit` 1–5.000) |
| POST | `/gps-readings` | `create_data` | Hanya perangkat GPS. `{device_id, latitude, longitude, recorded_at, altitude_meters?, accuracy_meters?, battery_level?, signal_strength?}`; duplikat waktu = `409` |
| POST | `/gps-readings/bulk` | `create_data` | `{readings: [...]}` maks. 1.000; duplikat dilewati |
| GET | `/gps-readings/{id}`, `/gps-readings/{id}/weather` | — | Data GPS append-only |
| PATCH | `/devices/{id}/telemetry` | `create_data` | Heartbeat `{battery_level?, signal_strength?, recorded_at?}` |
| GET, POST | `/animal-geo-zone-visits` | `create_data` | `{animal_id, zone_id, entered_at, entry_reading_id?}`; `409` jika masih ada kunjungan aktif |
| PATCH | `/animal-geo-zone-visits/{id}/exit` | `update_data` | `{exited_at?}` |
| GET, POST | `/incidents` | `create_data` | `{incident_type, description, timestamp, latitude, longitude, location?, animal_id?, species_id?, zone_id?, status?}`; zona terisi otomatis |
| GET, PUT | `/incidents/{id}` | `update_data` | Ubah hanya oleh pelapor atau admin |
| DELETE | `/incidents/{id}` | `delete_data` | Soft delete |
| PATCH | `/incidents/{id}/sync-status` | `delete_data` | `{sync_status: pending_sync\|synced\|failed}` |
| GET, POST | `/incidents/{id}/media` | `create_data` | Bukti foto/video/audio (`file`, `analyze=1` untuk analisis AI) |
| GET | `/weather-data`, `/weather-data/{id}` | — | Filter `reading_id, source, start_date, end_date` |
| POST | `/weather-data/ingest`, `/weather-data/sync` | `create_data` | Manual / tarik Open-Meteo |
| GET | `/notifications`, `/notifications/unread-count`, `/notifications/{id}` | — | Hanya milik user aktif |
| PATCH | `/notifications/{id}/read`, `/notifications/read-all` | — | |
| DELETE | `/notifications/{id}`, `/notifications` | — | Tanpa id = hapus semua yang sudah dibaca |

Otomatis: setiap titik GPS mencatat masuk/keluar zona dan mengirim `risk_zone` bila satwa masuk permukiman,
perkebunan, atau jalan raya. Baterai < 20%, sinyal lemah, atau perangkat rusak/hilang (dari GPS, rekaman
perangkat, atau telemetri) memicu `device_alert`.

### Dashboard, laporan, dan ekspor

| Method | Path | Izin | Keterangan |
|---|---|---|---|
| GET | `/dashboard`, `/dashboard/stats?period=7`, `/dashboard/charts?period=30` | `view_data` | Statistik, alert perangkat, ancaman & insiden terbaru |
| GET, POST | `/aggregate-reports`, `/aggregate-reports/generate` | `view_reports` | `{report_type: daily\|weekly\|monthly, period_start?, period_end?, overwrite?}` |
| GET | `/aggregate-reports/{id}`, `/aggregate-reports/{id}/download` | `view_reports` | Download CSV |
| DELETE | `/aggregate-reports/{id}` | `delete_data` | |
| GET | `/export/{jenis}` | `export_data` | `gps/csv`, `gps/geojson`, `animals/csv`, `species/csv`, `devices/csv`, `incidents/csv`, `weather/csv`, `ai-predictions/csv`, `geo-zones/csv`, `geo-zones/geojson`, `zone-visits/csv`, `aggregate-report/{id}`; admin juga `users/csv`, `activity-logs/csv` |

Unduhan diambil sebagai blob:

```ts
const res = await fetch(`${API_URL}/export/incidents/csv?start_date=2026-11-01`, {
  headers: { Authorization: `Bearer ${token}` },
});
const url = URL.createObjectURL(await res.blob());
```

---

## 5. Fitur AI

AI mengenali spesies, menebak individu satwa, menilai kondisi fisik dan perilaku, serta mengklasifikasi suara
untuk mendeteksi perburuan dan penebangan liar. Ancaman tingkat sedang ke atas atau satwa terluka/sakit
langsung membuat insiden dan notifikasi; semua hasil tetap menunggu verifikasi petugas.

| `device_type` | Perangkat | Dipasang di | Data yang dikirim |
|---|---|---|---|
| `gps` | Kalung pelacak | Satwa (`animal_id`) | `POST /gps-readings` |
| `camera` | Kamera jebak | Titik tetap (`zone_id`, koordinat) | `POST /devices/{id}/captures` (foto/video) |
| `audio` | Perekam suara | Titik tetap | `POST /devices/{id}/captures` (rekaman, maks. 25 MB) |

| Field prediksi | Isi |
|---|---|
| `predicted_species_id`, `confidence` | Spesies + keyakinan 0–1 |
| `predicted_animal_id`, `animal_match_confidence`, `animal_match_method` | Individu: `appearance_reid`, `gps_proximity`, `zone_presence`, `distinctive_marks`, `single_candidate` |
| `individual_count`, `detected_objects` | Jumlah satwa & kotak deteksi MegaDetector (persen gambar) |
| `physical_condition`, `condition_notes` | `healthy`, `injured`, `sick`, `emaciated`, `unknown` |
| `behavior`, `behavior_notes` | `foraging`, `resting`, `moving`, `running`, `drinking`, `social`, `parenting`, `mating`, `alert`, `aggressive`, `unknown` |
| `sound_category`, `sound_label` | `animal_call`, `gunshot`, `chainsaw`, `tree_falling`, `fire`, `engine`, `vehicle`, `human_voice`, `dog_bark`, `environment`, `unknown` |
| `threat_level` | `none`, `low`, `medium`, `high`, `critical` |
| `processing_status` | `completed`, `unrecognized`, `failed` |

Label bahasa Indonesia tersedia di `GET /ai/vocabulary`. Individu dipilih bila skornya ≥ 0,35 dan unggul ≥ 0,1
dari kandidat kedua. Bukti: kemiripan penampilan dengan galeri satwa (MegaDescriptor; ≥ 0,6 menambah 0,5–1,0,
di bawah 0,6 dengan galeri yang ada membagi dua skor), jarak kalung GPS ±6 jam, zona yang sama, ciri fisik,
kandidat tunggal. Daftar kandidat & bukti ada di `raw_response.identification_candidates`.

Galeri re-identifikasi tiap satwa (perlu `AI_VISION_SERVICE_URL`):

| Method | Path | Izin | Keterangan |
|---|---|---|---|
| GET | `/animals/{id}/reference-photos` | `view_data` | Foto referensi + deteksi terverifikasi (tanpa vektor) |
| POST | `/animals/{id}/reference-photos` | `manage_master_data` | Multipart `file` (jpg/png/webp, maks. 20 MB). Satwa terbesar dipotong otomatis |
| GET | `/animals/{id}/reference-photos/{embeddingId}/file` | `view_data` | Berkas foto referensi |
| DELETE | `/animals/{id}/reference-photos/{embeddingId}` | `manage_master_data` | Keluarkan dari galeri |

```ts
// daftarkan 3-5 foto jelas tiap individu (mis. saat pemasangan kalung)
const form = new FormData();
form.append("file", photo);
await api.post(`/animals/${animalId}/reference-photos`, { formData: form, timeoutMs: 60_000 });
```

| Deteksi | Tingkat | Insiden dibuat |
|---|---|---|
| Tembakan, senjata, jerat | `critical` | `perburuan` |
| Gergaji mesin, pohon tumbang, penebangan | `high` | `perambahan` |
| Api / asap | `high` | `kebakaran_hutan` |
| Mesin berat | `medium` | `perambahan` |
| Kendaraan, anjing, orang di zona lindung | `low`–`medium` | tidak (notifikasi bila `medium`) |
| Satwa terluka atau sakit | `medium` (terluka), `none` (sakit) | `kesehatan_satwa` |

| Method | Path | Izin | Keterangan |
|---|---|---|---|
| POST | `/ai/upload` (= `/media-uploads`) | `upload_media` | Multipart `file` (jpg, png, webp, mp4, mov, webm, wav, mp3, m4a, ogg, flac; maks. 50 MB) + opsional `report_id, device_id, zone_id, latitude, longitude, captured_at`. Maks. 30 upload/menit |
| POST | `/devices/{id}/captures` | `create_data` | Kamera/perekam; opsional `captured_at, battery_level, signal_strength` |
| GET | `/ai-predictions`, `/ai-predictions/pending-verification`, `/ai-predictions/{id}` | `view_data` | Filter status, ancaman, kondisi, perilaku, suara, spesies, individu, perangkat, zona, tanggal |
| POST / PATCH | `/ai-predictions/{id}/verify` | `verify_ai` | `{verification_status: confirmed\|rejected, verified_species_id, verified_animal_id?}` |
| POST | `/ai-predictions/{id}/retry` | `verify_ai` | `409` jika sudah diverifikasi |
| POST | `/media-uploads/{id}/predictions` | `upload_media` | Analisis media yang ada; `409` jika media punya prediksi terverifikasi |
| DELETE | `/ai-predictions/{id}`, `/media-uploads/{id}` | `delete_data` | Bukti terverifikasi tidak bisa dihapus |
| GET | `/media-uploads`, `/media-uploads/{id}`, `/media-uploads/{id}/file`, `/media-uploads/{id}/predictions` | `view_data` | File dari storage privat (butuh token) |
| GET | `/ai/threats?min_level=medium`, `/ai/summary-report`, `/ai/health`, `/ai/vocabulary` | `view_data` | |
| POST | `/ai/detect-objects` | `upload_media` | Analisis cepat tanpa disimpan |

```ts
const form = new FormData();
form.append("file", file);
form.append("report_id", String(reportId)); // opsional
const res = await api.post("/ai/upload", { formData: form, timeoutMs: 120_000 });
// res.data.prediction.physical_condition, .behavior, .predictedAnimal?.name, .threat_level
```

Engine:

| Analisis | Engine utama (terlatih, ai-service) | Cadangan |
|---|---|---|
| Deteksi satwa/manusia/kendaraan, jumlah individu | MegaDetector v5a (`AI_VISION_SERVICE_URL`) | Groq vision |
| Spesies dari gambar | SpeciesNet v4.0.3a, geofence `AI_COUNTRY` | Groq vision |
| Identifikasi individu | MegaDescriptor-T-224 + GPS/zona | GPS/zona/ciri fisik saja |
| Kondisi fisik, perilaku, senjata/jerat/penebangan/api | Groq vision dengan konteks deteksi (belum ada model terlatih) | `unknown` bila Groq tidak dikonfigurasi |
| Suara | YAMNet + classifier & detektor tembakan terlatih (`AI_AUDIO_SERVICE_URL`) | Groq Whisper + LLM, lalu aturan ciri akustik WAV |
| Video | 1 frame tiap 2 detik (maks. 8) di ai-service | 1 frame via `ffmpeg` + Groq |
| Ringkasan laporan | Groq teks (`GROQ_TEXT_MODEL`) | Ringkasan statistik tanpa AI |

Mode antrean (`AI_QUEUE=true`): semua endpoint upload/analisis mengembalikan prediksi `queued`; jalankan
`php artisan queue:work --queue=ai,default`. Status berjalan `queued` → `processing` → `completed`/`unrecognized`/`failed`.

```ts
// polling hasil bila AI_QUEUE=true
let p = res.data.prediction;
while (p.processing_status === "queued" || p.processing_status === "processing") {
  await new Promise((r) => setTimeout(r, 2000));
  p = (await api.get(`/ai-predictions/${p.prediction_id}`)).data;
}
```

---

## 6. Fitur yang masih akan dikembangkan

| Fitur | Kondisi sekarang | Rencana |
|---|---|---|
| CRUD AI lanjutan | Upload, analisis, retry, verifikasi, hapus | Koreksi manual, riwayat versi prediksi, statistik akurasi per spesies |
| Kondisi fisik & perilaku terlatih | Groq vision (tebakan LLM, wajib diverifikasi) | Latih classifier dari prediksi yang diverifikasi petugas (data terkumpul di `ai_predictions`) |
| Spesies Sumatra spesifik | SpeciesNet: orangutan hanya *P. pygmaeus*, harimau tanpa subspesies; badak/gajah/orangutan belum diuji foto Sumatra | Fine-tune dengan foto kamera jebak Sumatra (mis. dari TNGL/WCS) |
| Tembakan jauh di hutan lebat | 63% tertangkap pada rekaman Vietnam | Tambah rekaman tembakan dari lokasi sendiri lalu latih ulang (`ai-service/training`) |
| Pohon tumbang | Aturan zero-shot YAMNet (tidak ada data latih) | Kumpulkan rekaman lalu latih |
| Suara burung per spesies | Kategori umum "suara satwa" | BirdNET (gratis, non-komersial) |
| Token khusus perangkat | Perangkat memakai token user biasa (ikut timeout 2 jam) | Token Sanctum ber-ability per perangkat tanpa timeout idle |
| Pembersihan token | Token idle dihapus saat dipakai lagi | Scheduled job pembersih |
| Notifikasi realtime | FE polling `/notifications` | WebSocket / Laravel Reverb |
| Analisis video penuh | 1 frame (detik ke-1) | Beberapa frame, deteksi gerakan |
| Riwayat baterai | `battery-history` = nilai terakhir | Tabel riwayat telemetri |
| Laporan PDF | CSV | PDF siap cetak |
| Halaman FE fitur baru | Belum ada halaman user, role, kamera/audio, ancaman AI, kondisi satwa, ekspor | Dikerjakan tim FE |

---

## 7. Hasil audit

Audit terakhir: 2026-11-22. Cakupan: keamanan, validasi, kesesuaian fitur dengan dokumen ini, logika backend,
integrasi frontend, endpoint, dan konsistensi. Setiap temuan yang diperbaiki dibuktikan dulu dengan test.

### Sudah diperbaiki

| # | Kategori | Temuan | Perbaikan |
|---|---|---|---|
| 1 | Keamanan | User dinonaktifkan lewat `PUT /users/{id}` tetap bisa memakai token lama | Token dicabut; token user nonaktif ditolak saat autentikasi |
| 2 | Keamanan | Admin bisa menonaktifkan dirinya lewat `PUT /users/{id}` | Ditolak `422` |
| 3 | Keamanan | Reset password oleh admin tidak memutus sesi lama | Token user dicabut |
| 4 | Integritas data | `POST /media-uploads/{id}/predictions` (termasuk pengambil kebijakan) bisa mengembalikan prediksi terverifikasi ke `pending` | `409` bila ada prediksi terverifikasi |
| 5 | Keamanan | Admin bisa mencabut `manage_roles` dari role sendiri (terkunci permanen) atau mengganti nama role bawaan sehingga `sync-status` dan target notifikasi rusak | Role bawaan dikunci, cegah self-lockout |
| 6 | Keamanan | Ekspor CSV rentan formula injection (`=HYPERLINK(...)` dari deskripsi insiden) | Sel teks berbahaya diberi awalan `'` |
| 7 | Keamanan | `?limit=-1` menghapus batas query GPS | Batas 1–5.000 |
| 8 | Konsistensi | `update_data` tidak pernah dicek; `sync-status` dicek nama role | Route memakai `permission:update_data` dan `permission:delete_data` |
| 9 | Logika | Titik GPS diterima untuk kamera/perekam | Hanya `device_type=gps` |
| 10 | Kesesuaian fitur | Baterai/sinyal dari GPS & rekaman tidak memicu `device_alert` (dokumen menyatakan memicu) | Health check dipanggil |
| 11 | Validasi | Media insiden menolak `.flac` | Disamakan dengan `/ai/upload` |
| 12 | Integrasi | Origin CORS tertulis di kode | `FRONTEND_URLS` di `.env` |
| 13 | Keamanan | File media tanpa `nosniff` | Header ditambahkan |
| 14 | Keamanan | Timeout sesi 2 jam tidak berjalan (rilis sebelumnya) | Diperbaiki di `970a24f` |

### Masih berisiko / perlu keputusan

| Area | Temuan | Dampak | Usulan |
|---|---|---|---|
| Integrasi FE | FE perlu menangani `401` (sesi habis, akun nonaktif, password direset) | User terlempar tanpa penjelasan | Hapus token + arahkan ke login |
| Sesi | Polling memperpanjang sesi | Sesi tidak habis selama tab terbuka | Putuskan kebijakan polling |
| Perangkat | Kamera/perekam/kalung memakai token user biasa | Token perangkat hilang bila idle > 2 jam | Token khusus perangkat |
| Konfigurasi | `.env` lokal memakai `APP_DEBUG=true` | Stack trace bocor bila terbawa ke server | `APP_DEBUG=false` di server |
| Konfigurasi | `GROQ_API_KEY` sempat terbagi di luar `.env` | Kunci bisa disalahgunakan | Rotasi kunci |
| Validasi | Status insiden teks bebas; `animal_id` vs `species_id` insiden tidak dicek kecocokannya; ubah koordinat insiden tidak menghitung ulang zona | Data tidak konsisten | Enum status, validasi silang, hitung ulang zona |
| Validasi | Rekaman perangkat menerima perangkat nonaktif/hilang; zona yang sudah dihapus (soft delete) masih bisa dipilih | Data dari perangkat/zona tidak aktif | Tolak atau tandai |
| Batas file | Audio dari perangkat maks. 25 MB (batas Groq Whisper), upload manual 50 MB | Rekaman panjang ditolak `422` | Potong rekaman di perangkat |
| AI | Test AI memakai respons Groq tiruan; model `qwen/qwen3.8-27b` belum diuji asli | Analisis bisa gagal di produksi | Uji dengan kunci asli |
| AI | Ambang identifikasi (0,35 / 0,1 / ±6 jam) dan aturan suara WAV belum dikalibrasi | Salah identifikasi, alarm palsu | Kalibrasi dengan data lapangan |
| Hak akses | `/users` khusus admin, peneliti kelola master data, kebijakan hanya baca | Menu FE bisa tidak cocok | Konfirmasi dengan tim FE |
| Integrasi FE | Proxy `backend-actions.ts` belum meneruskan `text/csv`, `application/geo+json`, audio | Unduhan gagal lewat proxy | Tambah tipe MIME di FE |
| Integrasi FE | `GET /devices` juga mengembalikan kamera/perekam | Halaman kalung bercampur | FE pakai `?device_type=gps` |
| Database | Migrasi lama digabung | `migrate:fresh --seed` menghapus data | Skrip migrasi data bila ada data produksi |
| Data | Token idle menumpuk; file media tanpa antivirus & kebijakan retensi | Tabel & storage membesar | Job pembersih, kebijakan retensi |
| Proses | Commit tidak ditandatangani; `test.md` (Apidog) belum diperbarui | Label "Unverified"; tester pakai endpoint lama | Signing bila wajib; perbarui `test.md` |
| AI suara | Tembakan jauh di hutan lebat: 63% (Vietnam) dan 1/6 pada rekaman malam kontinu; kembang api sering terbaca tembakan | Tembakan bisa terlewat | Latih ulang dengan rekaman lokasi sendiri (`ai-service/training`) |
| AI gambar | Spesies Sumatra (badak, gajah, orangutan) belum diuji dengan foto Sumatra; makaka & kancil sering hanya tingkat famili | Spesies bisa kosong/umum | Uji & fine-tune dengan foto kamera jebak Sumatra |
| AI gambar | Kondisi fisik, perilaku, senjata/jerat/penebangan/api masih dari Groq (LLM), bukan model terlatih | Bisa salah/halusinasi | Wajib verifikasi petugas; latih model dari data verifikasi |
| Re-identifikasi | Akurasi diukur pada harimau (ATRW, kemungkinan bagian data latih model); individu baru 13,5% masih bisa salah cocok | Salah individu | Daftarkan 3–5 foto jelas per satwa; naikkan `AI_REID_THRESHOLD` ke 0,7 bila salah cocok sering terjadi |
| Lisensi | MegaDescriptor & ESC-50 non-komersial (CC-BY-NC) | Tidak boleh untuk produk komersial | Ganti model bila proyek dikomersialkan |
| Sumber daya | ai-service dengan semua model butuh ±2,5 GB RAM dan ±3 GB disk; foto ±2,7 detik di CPU | PC lambat saat banyak upload | `AI_QUEUE=true` |
| Server tim (PC + tunnel) | URL `trycloudflare.com` berganti setiap tunnel dinyalakan ulang; server mati saat PC mati/tidur | Tim harus memperbarui `API_URL` | Domain sendiri (named tunnel) atau ngrok domain statis |
| Server tim (PC + tunnel) | Akun seed `password123` bisa dicoba siapa pun yang tahu URL | Akses tidak sah | Ganti password akun seed sebelum URL dibagikan |
| Server tim (PC + tunnel) | `php artisan serve` melayani satu request sekaligus | Request lain menunggu saat ada analisis AI | `AI_QUEUE=true` (FE polling) |
| Antrean | `AI_QUEUE=true` butuh FE polling | Hasil AI tidak tampil bila FE tidak polling | Aktifkan setelah FE siap, atau tetap `false` |
| Skrip Windows | `scripts/start-server.ps1` belum dicoba di Windows (lingkungan pengujian Linux) | Bisa gagal di PC | Laporkan error jendela PowerShell; perintah manual ada di `DEPLOY.md` |

---

## 8. Setup dan update

Setup pertama:

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi DB_*, GROQ_API_KEY, FRONTEND_URLS di .env
php artisan migrate:fresh --seed
php artisan serve
```

Setiap kali menarik perubahan baru di PC pengembangan (data contoh diisi ulang):

```bash
git pull origin main
composer install
php artisan migrate:fresh --seed
php artisan config:clear
```

`migrate:fresh --seed` menghapus seluruh data lalu mengisi ulang data contoh. Di PC yang menjadi server tim,
pakai `php artisan migrate` (data tim tetap ada) dan restart worker; lihat [`DEPLOY.md`](DEPLOY.md).
Perubahan skema berikutnya selalu ditulis sebagai file migrasi baru.

ai-service (sekali saja): lihat [`../ai-service/README.md`](../ai-service/README.md). Jalankan test dengan
`php vendor/bin/phpunit`.
