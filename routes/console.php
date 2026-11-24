<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Modul wisata: lepas kuota pemesanan yang tidak dibayar. Jalankan `php artisan schedule:work` di server.
Schedule::command('tourism:expire-bookings')->everyFiveMinutes();

// Bersihkan token API yang sudah lewat batas idle (tabel personal_access_tokens tidak menumpuk).
Schedule::command('tokens:prune-idle')->hourly();
