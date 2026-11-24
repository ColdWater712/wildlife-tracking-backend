<?php

namespace App\Services\Tourism;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Integrasi Midtrans Snap (QRIS, virtual account bank, e-wallet, kartu) untuk pembayaran tiket online.
 * Aktif hanya bila MIDTRANS_SERVER_KEY diisi. Server key tidak pernah dikirim ke browser.
 *
 * Keamanan notifikasi:
 *  1. signature_key = SHA512(order_id + status_code + gross_amount + server_key) dicek dengan hash_equals;
 *  2. status transaksi lalu DIAMBIL ULANG dari API Midtrans (bukan dipercaya dari body notifikasi);
 *  3. nominal harus sama dengan tagihan; pemrosesan idempoten (markPaid aman dipanggil berulang).
 */
class MidtransService
{
    public function __construct(private BookingService $bookings) {}

    public static function enabled(): bool
    {
        return (string) config('services.midtrans.server_key') !== '';
    }

    private function serverKey(): string
    {
        return (string) config('services.midtrans.server_key');
    }

    private function snapUrl(): string
    {
        return config('services.midtrans.production')
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    private function statusUrl(string $orderId): string
    {
        $base = config('services.midtrans.production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';

        return $base.'/v2/'.rawurlencode($orderId).'/status';
    }

    /**
     * Buat transaksi Snap baru untuk pemesanan yang menunggu pembayaran.
     *
     * @return array{payment: Payment, redirect_url: string, token: string}
     */
    public function createTransaction(Booking $booking): array
    {
        if (! self::enabled()) {
            throw new RuntimeException('Pembayaran online belum diaktifkan.');
        }

        // Transaksi gateway lama yang belum dibayar dibatalkan agar hanya satu tagihan online yang berlaku.
        $booking->payments()->where('method', 'gateway')->where('status', 'pending')->update(['status' => 'expired']);

        $payment = $booking->payments()->create([
            'reference' => $this->bookings->uniqueCode(Payment::class, 'reference', 'MT-', 14),
            'method' => 'gateway',
            'amount' => $booking->total_amount,
            'status' => 'pending',
        ]);

        // Rincian item harus berjumlah sama persis dengan gross_amount.
        $items = collect($booking->price_lines)->map(fn (array $line) => [
            'id' => substr((string) $line['code'], 0, 50),
            'price' => (int) $line['unit_price'] * max(1, (int) $line['days']),
            'quantity' => (int) $line['quantity'],
            'name' => mb_substr((string) $line['label'], 0, 50),
        ])->values()->all();

        $minutes = $booking->expires_at ? max(5, (int) ceil(now()->diffInMinutes($booking->expires_at, false))) : 60;

        $response = Http::withBasicAuth($this->serverKey(), '')
            ->acceptJson()
            ->timeout(20)
            ->post($this->snapUrl(), [
                'transaction_details' => ['order_id' => $payment->reference, 'gross_amount' => (int) $booking->total_amount],
                'item_details' => $items,
                'customer_details' => [
                    'first_name' => mb_substr($booking->contact_name, 0, 50),
                    'phone' => $booking->contact_phone,
                    'email' => $booking->user?->email,
                ],
                'expiry' => ['unit' => 'minute', 'duration' => $minutes],
                'callbacks' => ['finish' => rtrim((string) config('services.midtrans.finish_url'), '/').'/'.$booking->booking_code],
            ]);

        if (! $response->successful() || ! $response->json('redirect_url')) {
            $payment->update(['status' => 'expired']);
            Log::warning('Midtrans Snap gagal', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            throw new RuntimeException('Gagal membuat transaksi pembayaran online. Coba lagi atau gunakan transfer manual.');
        }

        return ['payment' => $payment, 'redirect_url' => (string) $response->json('redirect_url'), 'token' => (string) $response->json('token')];
    }

    public function validSignature(array $notification): bool
    {
        $expected = hash('sha512', ($notification['order_id'] ?? '').($notification['status_code'] ?? '').($notification['gross_amount'] ?? '').$this->serverKey());

        return hash_equals($expected, (string) ($notification['signature_key'] ?? ''));
    }

    /** Proses notifikasi yang tanda tangannya sudah valid. Mengembalikan status pembayaran setelah diproses. */
    public function handleNotification(string $orderId): string
    {
        $payment = Payment::where('reference', $orderId)->where('method', 'gateway')->firstOrFail();

        $status = Http::withBasicAuth($this->serverKey(), '')->acceptJson()->timeout(20)->get($this->statusUrl($orderId));
        if (! $status->successful()) {
            throw new RuntimeException('Tidak dapat memverifikasi status ke Midtrans.');
        }

        $transaction = (string) $status->json('transaction_status');
        $fraud = (string) $status->json('fraud_status', 'accept');
        $amount = (int) round((float) $status->json('gross_amount'));

        if ($amount !== (int) $payment->amount) {
            Log::warning('Nominal Midtrans tidak sama dengan tagihan', ['order_id' => $orderId]);

            return 'amount_mismatch';
        }

        if ($transaction === 'settlement' || ($transaction === 'capture' && $fraud === 'accept')) {
            if (in_array($payment->booking->status, ['pending_payment', 'awaiting_verification'], true) || $payment->status === 'paid') {
                $this->bookings->markPaid($payment, null, (string) $status->json('transaction_id'));
            } else {
                // Dibayar setelah pemesanan kedaluwarsa/dibatalkan: catat untuk pengembalian dana manual.
                Log::warning('Pembayaran Midtrans masuk untuk pemesanan yang tidak aktif', ['order_id' => $orderId]);
                $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'gateway_transaction_id' => (string) $status->json('transaction_id')])->save();
            }
        } elseif (in_array($transaction, ['deny', 'cancel', 'expire', 'failure'], true) && $payment->status === 'pending') {
            $payment->update(['status' => 'expired']);
        }

        return $payment->fresh()->status;
    }
}
