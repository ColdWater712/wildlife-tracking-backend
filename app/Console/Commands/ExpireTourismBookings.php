<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Tourism\BookingService;
use Illuminate\Console\Command;

/** Melepas kuota pemesanan yang lewat batas bayar dan menutup kunjungan yang sudah lewat. */
class ExpireTourismBookings extends Command
{
    protected $signature = 'tourism:expire-bookings';

    protected $description = 'Kedaluwarsakan pemesanan belum dibayar dan tandai kunjungan lampau sebagai selesai';

    public function handle(BookingService $bookings): int
    {
        $expired = $bookings->expireStale();
        $completed = Booking::where('status', 'checked_in')->whereDate('visit_date', '<', today())->update(['status' => 'completed']);

        $this->info("{$expired} pemesanan kedaluwarsa, {$completed} kunjungan selesai.");

        return self::SUCCESS;
    }
}
