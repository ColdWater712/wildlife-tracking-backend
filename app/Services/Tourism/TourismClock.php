<?php

namespace App\Services\Tourism;

use Illuminate\Support\Carbon;

/**
 * Waktu lokal kawasan (default Asia/Jakarta / WIB).
 *
 * APP_TIMEZONE aplikasi tetap UTC (data GPS & log tersimpan UTC), tetapi jam shift, tanggal kunjungan,
 * dan "hari ini" di loket mengikuti jam dinding di TNGL. Semua perbandingan tanggal/jam wisata lewat kelas ini.
 */
class TourismClock
{
    public static function timezone(): string
    {
        return (string) config('services.tourism.timezone', 'Asia/Jakarta');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** Tanggal hari ini (YYYY-MM-DD) menurut jam lokal kawasan. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Waktu mulai/selesai shift sebagai momen absolut (dibandingkan dengan now() UTC dengan benar). */
    public static function at(string $date, string $time): Carbon
    {
        return Carbon::parse($date.' '.substr($time, 0, 5), self::timezone());
    }
}
