<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Services\Tourism\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi dari payment gateway: POST /payments/webhook
 * Header X-Signature = hex(HMAC-SHA256(body mentah, PAYMENT_WEBHOOK_SECRET)).
 * Body: {"reference": "PAY-...", "status": "paid|failed", "amount": 123000, "transaction_id": "..."}
 *
 * Ditolak bila rahasia belum diatur, tanda tangan salah, atau nominal tidak sama dengan tagihan.
 * Idempoten: notifikasi berulang untuk transaksi yang sudah lunas tidak mengubah apa pun.
 */
class PaymentWebhookController extends ApiController
{
    public function __construct(private BookingService $bookings) {}

    public function __invoke(Request $request)
    {
        $secret = (string) config('services.tourism.webhook_secret');
        if ($secret === '') {
            return $this->error('Webhook pembayaran belum diaktifkan.', 503);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, (string) $request->header('X-Signature'))) {
            Log::warning('Webhook pembayaran dengan tanda tangan tidak valid', ['ip' => $request->ip()]);

            return $this->error('Tanda tangan tidak valid.', 401);
        }

        $data = $request->validate([
            'reference' => 'required|string|max:40',
            'status' => 'required|in:paid,failed',
            'amount' => 'required|integer|min:0',
            'transaction_id' => 'required|string|max:100',
        ]);

        $payment = Payment::where('reference', $data['reference'])->firstOrFail();
        if ($payment->amount !== (int) $data['amount']) {
            Log::warning('Nominal webhook tidak sama dengan tagihan', ['reference' => $data['reference']]);

            return $this->error('Nominal tidak sesuai tagihan.', 422);
        }

        if ($data['status'] === 'failed') {
            if ($payment->status === 'pending') {
                $payment->update(['status' => 'expired']);
            }

            return $this->success(['status' => $payment->fresh()->status]);
        }

        $booking = $this->bookings->markPaid($payment, null, $data['transaction_id']);

        return $this->success(['booking_code' => $booking->booking_code, 'status' => $booking->status]);
    }
}
