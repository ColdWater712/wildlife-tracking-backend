# Postman API Test Suite — Wildlife Tracking System (BTNGL)

Folder ini berisi koleksi dan environment Postman siap pakai untuk menguji seluruh 71 endpoint backend Laravel (12 modul).

## File yang Tersedia
- `Wildlife-Tracking-API.postman_collection.json` — 12 folder, 71 request lengkap dengan script `pm.test()` dan auto-chaining token & ID.
- `Wildlife-Tracking-Local.postman_environment.json` — Environment lokal (`http://127.0.0.1:8000/api`) dengan akun seed default (`admin@wildlife.id` / `password123`).

## Cara Import & Uji di Postman
1. Pastikan backend Laravel berjalan (`php artisan serve`). Jika ingin menguji AI Vision lengkap, jalankan juga `ai-service` di port `8001`.
2. Buka aplikasi **Postman** → klik **Import** (pojok kiri atas).
3. Drag & drop **kedua file JSON** di folder ini:
   - `Wildlife-Tracking-API.postman_collection.json`
   - `Wildlife-Tracking-Local.postman_environment.json`
4. Di pojok kanan atas Postman, pilih Environment **`Wildlife Tracking - Local (127.0.0.1:8000)`**.
5. Jalankan request **`01. Auth & Profile` → `Login (Admin) - Auto Save Token`**:
   - Token Sanctum otomatis tersimpan ke variabel `{{token}}` dan langsung dipakai oleh seluruh request lainnya.
6. Untuk menguji **semua endpoint sekaligus**:
   - Klik ikon titik tiga (`...`) pada koleksi **Wildlife Tracking System (BTNGL)** → pilih **Run collection** → klik **Run**.
   - *(Catatan: untuk request `05. AI Vision > Upload Photo/Audio to AI Vision`, pilih file foto/audio secara manual di tab Body → form-data → key `file` bila ingin menguji unggahan media.)*
