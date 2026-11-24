<?php

namespace App\Services\Tourism;

use App\Models\Booking;
use App\Models\GuideShift;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\TourRoute;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Siklus pemesanan: pesan (kuota dikunci) -> bayar (bukti/gateway) -> diverifikasi -> tiket QR -> check-in.
 *
 * Keamanan:
 *  - Harga & status hanya ditetapkan server; kuota shift dicek di dalam transaksi dengan lockForUpdate
 *    sehingga dua pemesanan bersamaan tidak bisa melebihi kapasitas.
 *  - Kode pemesanan dan referensi pembayaran acak (tidak bisa ditebak berurutan).
 *  - Token tiket = HMAC(APP_KEY, id|kode): tidak disimpan polos, tidak bisa dipalsukan tanpa APP_KEY.
 */
class BookingService
{
    public const DISK = 'local';

    public function __construct(private PricingService $pricing) {}

    public function remainingCapacity(GuideShift $shift): int
    {
        $used = Booking::where('shift_id', $shift->shift_id)->holdingCapacity()->sum('participant_count');

        return max(0, (int) $shift->capacity - (int) $used);
    }

    /**
     * @param  array{route_id: int, shift_id: int, wni_count?: int, wna_count?: int, student_count?: int, drone_count?: int,
     *   contact_name: string, contact_phone: string, emergency_contact: string, notes?: ?string,
     *   participants: array<int, array{full_name: string, visitor_type: string, age?: ?int}>, payment_method: string}  $data
     */
    public function create(User $user, array $data): Booking
    {
        return DB::transaction(function () use ($user, $data) {
            /** @var GuideShift $shift */
            $shift = GuideShift::whereKey($data['shift_id'])->lockForUpdate()->firstOrFail();
            $route = TourRoute::whereKey($data['route_id'])->where('is_active', true)->firstOrFail();

            if ($shift->route_id !== $route->route_id || $shift->status !== 'scheduled') {
                throw ValidationException::withMessages(['shift_id' => 'Jadwal tidak tersedia untuk rute ini.']);
            }
            $shiftStart = Carbon::parse($shift->shift_date->toDateString().' '.$shift->start_time);
            if ($shiftStart->lte(now()->addHours(2))) {
                throw ValidationException::withMessages(['shift_id' => 'Pemesanan ditutup 2 jam sebelum jadwal dimulai.']);
            }

            $wni = (int) ($data['wni_count'] ?? 0);
            $wna = (int) ($data['wna_count'] ?? 0);
            $students = (int) ($data['student_count'] ?? 0);
            $participants = $wni + $wna + $students;

            $this->assertParticipants($route, $data['participants'], $wni, $wna, $students);

            if ($participants > $this->remainingCapacity($shift)) {
                throw new ConflictHttpException("Kuota jadwal ini tinggal {$this->remainingCapacity($shift)} orang.");
            }

            $quote = $this->pricing->quote($route, $shift->shift_date->toDateString(), $wni, $wna, $students, (int) ($data['drone_count'] ?? 0));

            $booking = Booking::create([
                'booking_code' => $this->uniqueCode(Booking::class, 'booking_code', 'TNGL-'),
                'user_id' => $user->user_id,
                'route_id' => $route->route_id,
                'shift_id' => $shift->shift_id,
                'visit_date' => $shift->shift_date->toDateString(),
                'day_type' => $quote['day_type'],
                'wni_count' => $wni,
                'wna_count' => $wna,
                'student_count' => $students,
                'drone_count' => (int) ($data['drone_count'] ?? 0),
                'participant_count' => $participants,
                'price_lines' => $quote['lines'],
                'total_amount' => $quote['total'],
                'status' => 'pending_payment',
                'expires_at' => now()->addMinutes((int) config('services.tourism.payment_window_minutes', 60)),
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'emergency_contact' => $data['emergency_contact'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['participants'] as $participant) {
                $booking->participants()->create([
                    'full_name' => $participant['full_name'],
                    'visitor_type' => $participant['visitor_type'],
                    'age' => $participant['age'] ?? null,
                ]);
            }

            $booking->payments()->create([
                'reference' => $this->uniqueCode(Payment::class, 'reference', 'PAY-', 12),
                'method' => $data['payment_method'],
                'amount' => $quote['total'],
                'status' => 'pending',
            ]);

            return $booking;
        });
    }

    /** @param array<int, array{full_name: string, visitor_type: string, age?: ?int}> $participants */
    private function assertParticipants(TourRoute $route, array $participants, int $wni, int $wna, int $students): void
    {
        $counts = ['wni' => 0, 'wna' => 0, 'pelajar' => 0];
        foreach ($participants as $index => $participant) {
            $counts[$participant['visitor_type']]++;
            if (isset($participant['age']) && $participant['age'] !== null && $participant['age'] < $route->min_age) {
                throw ValidationException::withMessages([
                    "participants.{$index}.age" => "Usia minimal untuk rute ini {$route->min_age} tahun.",
                ]);
            }
        }

        if ($counts['wni'] !== $wni || $counts['wna'] !== $wna || $counts['pelajar'] !== $students) {
            throw ValidationException::withMessages([
                'participants' => 'Jumlah nama peserta harus sama dengan jumlah tiket per jenis pengunjung.',
            ]);
        }
    }

    /** Pemesanan yang lewat batas bayar dilepas kuotanya. */
    public function expireStale(): int
    {
        $ids = Booking::where('status', 'pending_payment')->where('expires_at', '<=', now())->pluck('booking_id');
        if ($ids->isEmpty()) {
            return 0;
        }

        Payment::whereIn('booking_id', $ids)->where('status', 'pending')->update(['status' => 'expired']);

        return Booking::whereIn('booking_id', $ids)->update(['status' => 'expired']);
    }

    public function submitProof(Booking $booking, UploadedFile $file): Payment
    {
        return DB::transaction(function () use ($booking, $file) {
            $booking = Booking::whereKey($booking->booking_id)->lockForUpdate()->first();
            if ($booking->status !== 'pending_payment' || ($booking->expires_at && $booking->expires_at->isPast())) {
                throw new ConflictHttpException('Pemesanan tidak menunggu pembayaran atau batas bayar sudah lewat.');
            }

            $payment = $booking->payments()->whereIn('status', ['pending', 'rejected'])->latest('payment_id')->firstOrFail();
            if ($payment->proof_path) {
                Storage::disk(self::DISK)->delete($payment->proof_path);
            }

            $payment->proof_path = $file->store('payment-proofs/'.now()->format('Y/m'), self::DISK);
            $payment->status = 'awaiting_verification';
            $payment->rejection_reason = null;
            $payment->save();

            $booking->update(['status' => 'awaiting_verification']);

            return $payment;
        });
    }

    /** Dipanggil petugas (verifikasi bukti) atau webhook gateway yang tanda tangannya valid. */
    public function markPaid(Payment $payment, ?User $verifier = null, ?string $gatewayTransactionId = null): Booking
    {
        return DB::transaction(function () use ($payment, $verifier, $gatewayTransactionId) {
            $payment = Payment::whereKey($payment->payment_id)->lockForUpdate()->first();
            $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->first();

            if ($payment->status === 'paid') {
                return $booking; // idempoten: webhook bisa terkirim lebih dari sekali
            }
            if (! in_array($booking->status, ['pending_payment', 'awaiting_verification'], true)) {
                throw new ConflictHttpException('Pemesanan tidak dalam status menunggu pembayaran.');
            }

            $payment->status = 'paid';
            $payment->paid_at = now();
            $payment->verified_by = $verifier?->user_id;
            $payment->gateway_transaction_id = $gatewayTransactionId;
            $payment->save();

            $booking->status = 'paid';
            $booking->expires_at = null;
            $booking->ticket_token_hash = hash('sha256', $this->ticketToken($booking));
            $booking->save();

            $this->notifyOwner($booking, "Pembayaran {$booking->booking_code} diterima. Tiket elektronik sudah bisa dibuka di menu Pesanan Saya.");

            return $booking;
        });
    }

    public function rejectPayment(Payment $payment, User $verifier, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $verifier, $reason) {
            $payment = Payment::whereKey($payment->payment_id)->lockForUpdate()->first();
            if ($payment->status !== 'awaiting_verification') {
                throw new ConflictHttpException('Pembayaran tidak sedang menunggu verifikasi.');
            }

            $payment->status = 'rejected';
            $payment->verified_by = $verifier->user_id;
            $payment->rejection_reason = $reason;
            $payment->save();

            // Pengunjung diberi waktu bayar baru untuk mengunggah ulang bukti yang benar.
            $booking = $payment->booking;
            $booking->update([
                'status' => 'pending_payment',
                'expires_at' => now()->addMinutes((int) config('services.tourism.payment_window_minutes', 60)),
            ]);

            $this->notifyOwner($booking, "Bukti bayar {$booking->booking_code} ditolak: {$reason}. Unggah ulang sebelum batas waktu.");

            return $payment;
        });
    }

    public function cancel(Booking $booking, bool $byStaff = false): Booking
    {
        return DB::transaction(function () use ($booking, $byStaff) {
            $booking = Booking::whereKey($booking->booking_id)->lockForUpdate()->first();
            $cancellable = $byStaff
                ? ['pending_payment', 'awaiting_verification', 'paid']
                : ['pending_payment', 'awaiting_verification'];

            if (! in_array($booking->status, $cancellable, true)) {
                throw new ConflictHttpException($byStaff
                    ? 'Pemesanan ini tidak bisa dibatalkan.'
                    : 'Pemesanan yang sudah dibayar hanya bisa dibatalkan petugas (proses pengembalian dana).');
            }

            $wasPaid = $booking->status === 'paid';
            $booking->update(['status' => 'cancelled']);
            $booking->forceFill(['cancelled_at' => now(), 'ticket_token_hash' => null])->save();

            $booking->payments()->whereIn('status', ['pending', 'awaiting_verification', 'rejected'])->update(['status' => 'expired']);
            if ($wasPaid) {
                $booking->payments()->where('status', 'paid')->update(['status' => 'refunded']);
                $this->notifyOwner($booking, "Pemesanan {$booking->booking_code} dibatalkan petugas. Dana dikembalikan sesuai kebijakan pengelola.");
            }

            return $booking;
        });
    }

    /** Token QR tiket. Hanya dikirim ke pemilik pemesanan yang sudah lunas. */
    public function ticketToken(Booking $booking): string
    {
        return substr(hash_hmac('sha256', "ticket|{$booking->booking_id}|{$booking->booking_code}", (string) config('app.key')), 0, 32);
    }

    public function ticketPayload(Booking $booking): string
    {
        return $booking->booking_code.'.'.$this->ticketToken($booking);
    }

    /** Check-in di pintu masuk dari isi QR "KODE.TOKEN". */
    public function checkIn(string $payload, User $officer): Booking
    {
        [$code, $token] = array_pad(explode('.', trim($payload), 2), 2, '');

        return DB::transaction(function () use ($code, $token, $officer) {
            $booking = Booking::where('booking_code', $code)->lockForUpdate()->first();

            if (! $booking || ! hash_equals($this->ticketToken($booking), $token)) {
                throw ValidationException::withMessages(['ticket' => 'Tiket tidak valid.']);
            }
            if ($booking->status === 'checked_in') {
                throw new ConflictHttpException('Tiket sudah dipakai pada '.$booking->checked_in_at?->format('d-m-Y H:i').'.');
            }
            if ($booking->status !== 'paid') {
                throw new ConflictHttpException('Tiket belum lunas atau sudah dibatalkan.');
            }
            if (! $booking->visit_date->isToday()) {
                throw new ConflictHttpException('Tiket berlaku untuk tanggal '.$booking->visit_date->format('d-m-Y').'.');
            }

            $booking->status = 'checked_in';
            $booking->checked_in_at = now();
            $booking->checked_in_by = $officer->user_id;
            $booking->save();

            return $booking;
        });
    }

    private function notifyOwner(Booking $booking, string $message): void
    {
        Notification::create([
            'user_id' => $booking->user_id,
            'notification_type' => 'system',
            'message' => $message,
            'is_read' => false,
        ]);
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function uniqueCode(string $model, string $column, string $prefix, int $length = 8): string
    {
        do {
            // Tanpa huruf/angka yang mirip (0/O, 1/I) agar mudah dibacakan di loket.
            $code = $prefix.substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 4)), 0, $length);
        } while ($model::where($column, $code)->exists());

        return $code;
    }
}
