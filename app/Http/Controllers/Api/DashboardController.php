<?php

namespace App\Http\Controllers\Api;

use App\Models\AiPrediction;
use App\Models\GpsReading;
use App\Models\IncidentReport;
use App\Models\Notification;
use App\Models\TrackingDevice;
use App\Services\DeviceHealthService;
use App\Services\MonitoringStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends ApiController
{
    public function __construct(private MonitoringStatsService $stats) {}

    public function index(Request $request)
    {
        $userId = $request->user()->user_id;

        $shared = Cache::remember('dashboard:shared', 30, function () {
            $data = $this->stats->collect(now()->subDays(30)->toDateString(), now()->toDateString());

            return [
                ...$data['statistics'],
                'device_alerts' => TrackingDevice::query()
                    ->where(fn ($q) => $q->where('battery_level', '<', DeviceHealthService::LOW_BATTERY)
                        ->orWhereIn('device_condition', ['damaged', 'lost']))
                    ->get(['device_id', 'device_type', 'animal_id', 'model', 'battery_level', 'signal_strength', 'device_condition']),
                'latest_threats' => AiPrediction::query()
                    ->with(['media.zone', 'media.device'])
                    ->whereIn('threat_level', ['high', 'critical'])
                    ->latest('processed_at')->limit(5)->get(),
                'latest_incidents' => IncidentReport::query()->with(['animal', 'zone'])->latest('timestamp')->limit(5)->get(),
                'latest_readings' => GpsReading::query()->with(['device.animal'])->latest('recorded_at')->limit(5)->get(),
            ];
        });

        $latestNotifications = Notification::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->limit(5)
            ->get();

        $unreadCount = Notification::where('user_id', $userId)->where('is_read', false)->count();

        return $this->success([
            ...$shared,
            'unread_notifications' => $unreadCount,
            'latest_notifications' => $latestNotifications,
        ]);
    }

    public function stats(Request $request)
    {
        $days = max(1, min(365, $request->integer('period', 7)));
        $data = $this->stats->collect(now()->subDays($days)->toDateString(), now()->toDateString());

        return $this->success(['period_days' => $days, ...$data]);
    }

    public function charts(Request $request)
    {
        $days = max(1, min(365, $request->integer('period', 30)));
        $from = now()->subDays($days)->startOfDay();

        $perDay = fn ($query, string $column) => $query
            ->where($column, '>=', $from)
            ->selectRaw("DATE({$column}) as date, COUNT(*) as count")
            ->groupBy(DB::raw("DATE({$column})"))
            ->orderBy('date')
            ->get();

        return $this->success([
            'incidents' => $perDay(IncidentReport::query(), 'timestamp'),
            'readings' => $perDay(GpsReading::query(), 'recorded_at'),
            'ai_detections' => $perDay(AiPrediction::query(), 'processed_at'),
            'threats' => $perDay(AiPrediction::query()->whereIn('threat_level', ['medium', 'high', 'critical']), 'processed_at'),
        ]);
    }
}
