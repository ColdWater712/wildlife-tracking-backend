<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Services\Tourism\BookingService;
use App\Services\Tourism\TourismClock;
use Illuminate\Http\Request;

/** Pemeriksaan tiket di pintu masuk (izin validate_tickets). */
class TicketCheckInController extends ApiController
{
    public function __construct(private BookingService $bookings) {}

    /** Isi QR dari tiket pengunjung: "KODE.TOKEN". */
    public function checkIn(Request $request)
    {
        $data = $request->validate(['ticket' => 'required|string|max:80']);
        $booking = $this->bookings->checkIn($data['ticket'], $request->user());

        return $this->success(
            $booking->load(['route:route_id,name', 'shift.guide:guide_id,display_name', 'participants']),
            "Check-in berhasil: {$booking->participant_count} peserta.",
        );
    }

    /** Daftar kunjungan hari ini untuk petugas loket. */
    public function today()
    {
        return $this->list(Booking::with(['route:route_id,name', 'shift.guide:guide_id,display_name', 'participants'])
            ->whereDate('visit_date', TourismClock::today())
            ->whereIn('status', ['paid', 'checked_in'])
            ->orderBy('shift_id'));
    }
}
