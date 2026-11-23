# Setup + perbaikan ai-service sekali jalan (Windows).
# Membuat .venv (Python 3.12), memasang paket yang kurang, mengunduh model yang belum ada,
# lalu memastikan ketiga model (audio, vision, reid) benar-benar bisa dimuat.
#
# Pemakaian (dari root repo):   powershell -ExecutionPolicy Bypass -File scripts\setup-ai-service.ps1
# Aman dijalankan berulang: yang sudah terpasang/terunduh dilewati.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$svc = Join-Path $root 'ai-service'
Set-Location $svc

function Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }
function Fail($text) { Write-Host "[x] $text" -ForegroundColor Red; exit 1 }

Step 'Python 3.12'
if (-not (Get-Command py -ErrorAction SilentlyContinue)) { Fail "Peluncur 'py' tidak ada. Pasang Python 3.12: winget install --id Python.Python.3.12 -e" }
& py -3.12 --version 2>$null
if ($LASTEXITCODE -ne 0) { Fail "Python 3.12 belum terpasang. Jalankan: winget install --id Python.Python.3.12 -e  lalu buka PowerShell baru." }

$py = Join-Path $svc '.venv\Scripts\python.exe'
if (-not (Test-Path $py)) {
    Step 'Membuat .venv'
    & py -3.12 -m venv .venv
    if ($LASTEXITCODE -ne 0) { Fail 'Gagal membuat .venv' }
}
$ver = & $py -c "import sys;print('%d.%d' % sys.version_info[:2])"
if ($ver -ne '3.12') { Fail ".venv memakai Python $ver, bukan 3.12. Hapus folder ai-service\.venv lalu jalankan skrip ini lagi." }

Step 'Paket Python (bisa lama saat pertama kali, total 2-3 GB)'
& $py -m pip install --upgrade pip --quiet
& $py -c "import torch" 2>$null
if ($LASTEXITCODE -ne 0) {
    & $py -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
    if ($LASTEXITCODE -ne 0) { Fail 'Gagal memasang PyTorch CPU.' }
}
& $py -m pip install -r requirements.txt
if ($LASTEXITCODE -ne 0) {
    Write-Host '[!] pip install -r requirements.txt gagal, mencoba ulang paket satu per satu...' -ForegroundColor Yellow
    Get-Content requirements.txt | Where-Object { $_ -and -not $_.StartsWith('#') } | ForEach-Object { & $py -m pip install $_ }
}
& $py -c "import reverse_geocoder" 2>$null
if ($LASTEXITCODE -ne 0) {
    & $py -m pip install reverse_geocoder --no-build-isolation
    if ($LASTEXITCODE -ne 0) { Fail 'Gagal memasang reverse_geocoder (lihat pesan pip di atas).' }
}

Step 'Unduh model (yang sudah ada dilewati)'
& $py download_model.py
if ($LASTEXITCODE -ne 0) { Fail 'Ada model yang gagal diunduh/dimuat (lihat daftar GAGAL di atas). Kirim pesan error-nya.' }

Step 'Cek akhir: memuat ketiga model'
& $py -c "import os,sys; os.environ.setdefault('TF_CPP_MIN_LOG_LEVEL','3'); sys.argv=['x']; import app; [app._load(n) for n in ('audio','vision','reid')]; print('audio, vision, reid: OK')"
if ($LASTEXITCODE -ne 0) { Fail 'Model belum bisa dimuat (lihat error di atas).' }

Write-Host "`nai-service siap. Tutup jendela uvicorn lama (bila ada), lalu jalankan dari root repo:" -ForegroundColor Green
Write-Host '  powershell -ExecutionPolicy Bypass -File scripts\start-server.ps1' -ForegroundColor Green
