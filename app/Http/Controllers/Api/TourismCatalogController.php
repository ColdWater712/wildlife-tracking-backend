<?php

namespace App\Http\Controllers\Api;

use App\Models\GuideShift;
use App\Models\TicketPrice;
use App\Models\TourGuide;
use App\Models\TourReview;
use App\Models\TourRoute;
use App\Services\Tourism\BookingService;
use App\Services\Tourism\MidtransService;
use App\Services\Tourism\PricingService;
use App\Services\Tourism\TourismClock;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Katalog publik untuk calon pengunjung (tanpa login): rute, jadwal pemandu, tarif, simulasi harga.
 * Tidak memuat data konservasi maupun kontak pribadi pemandu.
 */
class TourismCatalogController extends ApiController
{
    public function __construct(private BookingService $bookings, private PricingService $pricing) {}

    public function routes()
    {
        $routes = TourRoute::where('is_active', true)
            ->withAvg(['reviews' => fn ($q) => $q->where('is_visible', true)], 'route_rating')
            ->withCount(['reviews' => fn ($q) => $q->where('is_visible', true)])
            ->orderBy('name')
            ->get();

        return $this->list($routes);
    }

    public function showRoute(string $slug)
    {
        $route = TourRoute::where('slug', $slug)->where('is_active', true)
            ->withAvg(['reviews' => fn ($q) => $q->where('is_visible', true)], 'route_rating')
            ->withCount(['reviews' => fn ($q) => $q->where('is_visible', true)])
            ->firstOrFail();

        $reviews = TourReview::where('route_id', $route->route_id)->where('is_visible', true)
            ->with(['user:user_id,full_name', 'guide:guide_id,display_name'])
            ->latest()->limit(20)->get()
            ->map(fn (TourReview $r) => [
                'review_id' => $r->review_id,
                // Nama pengulas disingkat agar tidak membuka identitas lengkap.
                'reviewer' => $this->shortName($r->user?->full_name),
                'route_rating' => $r->route_rating,
                'guide_rating' => $r->guide_rating,
                'guide' => $r->guide?->display_name,
                'comment' => $r->comment,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return $this->success([
            'route' => $route,
            'upcoming_shifts' => $this->presentShifts(
                GuideShift::with('guide')->where('route_id', $route->route_id)
                    ->where('status', 'scheduled')->whereDate('shift_date', '>=', TourismClock::today())
                    ->orderBy('shift_date')->orderBy('start_time')->limit(30)->get()
            ),
            'reviews' => $reviews,
        ]);
    }

    public function guides()
    {
        $guides = TourGuide::where('is_active', true)
            ->withAvg(['reviews' => fn ($q) => $q->where('is_visible', true)], 'guide_rating')
            ->withCount(['reviews' => fn ($q) => $q->where('is_visible', true)])
            ->orderBy('display_name')->get();

        return $this->list($guides);
    }

    public function showGuide(int $id)
    {
        $guide = TourGuide::where('is_active', true)
            ->withAvg(['reviews' => fn ($q) => $q->where('is_visible', true)], 'guide_rating')
            ->withCount(['reviews' => fn ($q) => $q->where('is_visible', true)])
            ->findOrFail($id);

        return $this->success([
            'guide' => $guide,
            'upcoming_shifts' => $this->presentShifts(
                GuideShift::with('route')->where('guide_id', $id)->where('status', 'scheduled')
                    ->whereDate('shift_date', '>=', TourismClock::today())->orderBy('shift_date')->limit(30)->get()
            ),
        ]);
    }

    public function guidePhoto(int $id)
    {
        $guide = TourGuide::where('is_active', true)->findOrFail($id);
        abort_unless($guide->photo_path && Storage::disk('local')->exists($guide->photo_path), 404);

        return Storage::disk('local')->response($guide->photo_path, null, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Jadwal pemandu per shift dengan sisa kuota: GET /tourism/schedule?from=&to=&route_id= */
    public function schedule(Request $request)
    {
        $data = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
            'route_id' => 'sometimes|integer',
            'guide_id' => 'sometimes|integer',
        ]);
        $this->bookings->expireStale();

        $from = $data['from'] ?? TourismClock::today();
        $to = $data['to'] ?? TourismClock::now()->addDays(13)->toDateString();
        // Batasi rentang agar query tetap ringan.
        if (now()->parse($from)->diffInDays(now()->parse($to)) > 62) {
            return $this->error('Rentang jadwal maksimal 62 hari.', 422);
        }

        $shifts = GuideShift::with(['guide', 'route'])
            ->where('status', 'scheduled')
            ->whereDate('shift_date', '>=', max($from, TourismClock::today()))
            ->whereDate('shift_date', '<=', $to)
            ->when($data['route_id'] ?? null, fn ($q, $id) => $q->where('route_id', $id))
            ->when($data['guide_id'] ?? null, fn ($q, $id) => $q->where('guide_id', $id))
            ->whereHas('guide', fn ($q) => $q->where('is_active', true))
            ->whereHas('route', fn ($q) => $q->where('is_active', true))
            ->orderBy('shift_date')->orderBy('start_time')
            ->get();

        return $this->list($this->presentShifts($shifts));
    }

    public function prices()
    {
        return $this->list(TicketPrice::where('is_active', true)->orderBy('category')->orderBy('amount')->get());
    }

    /** Simulasi harga sebelum memesan (dihitung server). */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'route_id' => 'required|integer|exists:tour_routes,route_id',
            'visit_date' => 'required|date|after_or_equal:'.TourismClock::today(),
            'wni_count' => 'sometimes|integer|min:0|max:50',
            'wna_count' => 'sometimes|integer|min:0|max:50',
            'student_count' => 'sometimes|integer|min:0|max:50',
            'drone_count' => 'sometimes|integer|min:0|max:5',
        ]);
        $route = TourRoute::where('is_active', true)->findOrFail($data['route_id']);

        return $this->success($this->pricing->quote(
            $route,
            $data['visit_date'],
            (int) ($data['wni_count'] ?? 0),
            (int) ($data['wna_count'] ?? 0),
            (int) ($data['student_count'] ?? 0),
            (int) ($data['drone_count'] ?? 0),
        ));
    }

    /** Tujuan pembayaran manual yang ditampilkan ke pengunjung. */
    public function paymentInfo()
    {
        $config = config('services.tourism');

        $simulatorEnabled = ! app()->isProduction() && (bool) ($config['payment_simulator'] ?? false);

        return $this->success([
            'bank_transfer' => [
                'bank_name' => $config['bank_name'] ?: null,
                'account_number' => $config['bank_account_number'] ?: null,
                'account_holder' => $config['bank_account_holder'] ?: null,
            ],
            'qris_merchant_name' => $config['qris_merchant_name'] ?: null,
            'payment_window_minutes' => (int) $config['payment_window_minutes'],
            'gateway_enabled' => ($config['webhook_secret'] ?? '') !== '' || MidtransService::enabled() || $simulatorEnabled,
            'midtrans_enabled' => MidtransService::enabled(),
            'simulator_enabled' => $simulatorEnabled,
        ]);
    }

    /** @param Collection<int, GuideShift> $shifts */
    private function presentShifts($shifts): array
    {
        return $shifts->map(fn (GuideShift $s) => [
            'shift_id' => $s->shift_id,
            'shift_date' => $s->shift_date->toDateString(),
            'shift_type' => $s->shift_type,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'capacity' => $s->capacity,
            'remaining' => $this->bookings->remainingCapacity($s),
            'guide' => $s->relationLoaded('guide') && $s->guide ? [
                'guide_id' => $s->guide->guide_id,
                'display_name' => $s->guide->display_name,
                'favorite_animal' => $s->guide->favorite_animal,
                'languages' => $s->guide->languages,
                'has_photo' => $s->guide->has_photo,
            ] : null,
            'route' => $s->relationLoaded('route') && $s->route ? [
                'route_id' => $s->route->route_id,
                'slug' => $s->route->slug,
                'name' => $s->route->name,
                'entry_point' => $s->route->entry_point,
            ] : null,
        ])->values()->all();
    }

    private function shortName(?string $name): string
    {
        if (! $name) {
            return 'Pengunjung';
        }
        $parts = preg_split('/\s+/', trim($name));

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : $parts[0];
    }
}
