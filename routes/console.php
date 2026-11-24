<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Modul wisata: lepas kuota pemesanan yang tidak dibayar. Jalankan `php artisan schedule:work` di server.
\Illuminate\Support\Facades\Schedule::command('tourism:expire-bookings')->everyFiveMinutes();
