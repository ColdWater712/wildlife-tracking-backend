<?php

namespace App\Services;

use App\Models\AiPrediction;
use App\Models\Animal;
use App\Models\AnimalGeoZone;
use App\Models\GpsReading;
use App\Models\IncidentReport;
use App\Models\Species;
use App\Models\TrackingDevice;
use App\Models\WeatherData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/** Agregasi statistik monitoring untuk dashboard, laporan agregat, dan ringkasan AI. */
class MonitoringStatsService
{
    public function collect(string $start, string $end): array
    {
        return Cache::remember("monitoring_stats:{$start}:{$end}", 30, function () use ($start, $end) {
            return $this->compute($start, $end);
        });
    }

    private function compute(string $start, string $end): array
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->endOfDay();

        $incidents = IncidentReport::query()->whereBetween('timestamp', [$from, $to])->get(['incident_type', 'status', 'source']);
        $predictions = AiPrediction::query()->whereBetween('processed_at', [$from, $to])
            ->get(['analysis_type', 'processing_status', 'verification_status', 'confidence', 'threat_level', 'physical_condition', 'behavior', 'sound_category', 'predicted_animal_id', 'staff_face_count', 'unknown_face_count']);
        $completed = $predictions->where('processing_status', 'completed');

        $species = Species::query()->withCount('animals')->get();

        // Gabungkan agregasi dalam 1 query per tabel (hemat 10+ round-trip DB)
        $animalAgg = Animal::query()
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->first();

        $lowBattery = DeviceHealthService::LOW_BATTERY;
        $deviceAgg = TrackingDevice::query()
            ->selectRaw(
                "COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN device_type = 'gps' THEN 1 ELSE 0 END) as gps,
                SUM(CASE WHEN device_type = 'camera' THEN 1 ELSE 0 END) as camera,
                SUM(CASE WHEN device_type = 'audio' THEN 1 ELSE 0 END) as audio,
                SUM(CASE WHEN battery_level < ? THEN 1 ELSE 0 END) as low_battery",
                [$lowBattery],
            )
            ->first();

        $weatherAgg = WeatherData::query()
            ->whereBetween('observed_at', [$from, $to])
            ->selectRaw('AVG(temperature_c) as avg_temp, AVG(humidity_percent) as avg_hum, SUM(rainfall_mm) as total_rain')
            ->first();

        return [
            'statistics' => [
                'total_animals' => (int) ($animalAgg->total ?? 0),
                'active_animals' => (int) ($animalAgg->active ?? 0),
                'total_species' => $species->count(),
                'total_readings' => GpsReading::whereBetween('recorded_at', [$from, $to])->count(),
                'total_incidents' => $incidents->count(),
                'critical_incidents' => $incidents->whereIn('incident_type', ['konflik', 'perambahan', 'perburuan', 'kebakaran_hutan'])->count(),
                'ai_generated_incidents' => $incidents->where('source', '!=', 'manual')->count(),
                'total_devices' => (int) ($deviceAgg->total ?? 0),
                'active_devices' => (int) ($deviceAgg->active ?? 0),
                'gps_devices' => (int) ($deviceAgg->gps ?? 0),
                'camera_devices' => (int) ($deviceAgg->camera ?? 0),
                'audio_devices' => (int) ($deviceAgg->audio ?? 0),
                'low_battery_devices' => (int) ($deviceAgg->low_battery ?? 0),
                'zone_entries' => AnimalGeoZone::whereBetween('entered_at', [$from, $to])->count(),
                'total_predictions' => $predictions->count(),
                'total_verified_predictions' => $predictions->where('verification_status', 'confirmed')->count(),
                'average_confidence' => $completed->isNotEmpty() ? round((float) $completed->avg('confidence'), 4) : null,
                'identified_individuals' => $completed->whereNotNull('predicted_animal_id')->pluck('predicted_animal_id')->unique()->count(),
                'threat_detections' => $completed->filter(fn ($p) => AiPrediction::threatRank($p->threat_level) >= 2)->count(),
                'avg_temperature' => round((float) ($weatherAgg->avg_temp ?? 0), 1),
                'avg_humidity' => round((float) ($weatherAgg->avg_hum ?? 0), 1),
                'total_rainfall' => round((float) ($weatherAgg->total_rain ?? 0), 1),
            ],
            'species_breakdown' => $species->map(fn (Species $s) => [
                'species_id' => $s->species_id,
                'name' => $s->common_name,
                'common_name' => $s->common_name,
                'scientific_name' => $s->scientific_name,
                'count' => $s->animals_count,
                'status' => $s->conservation_status,
            ])->values()->all(),
            'incidents_by_type' => $incidents->groupBy('incident_type')->map->count()->all(),
            'ai_insights' => [
                'threats_by_level' => $completed->groupBy('threat_level')->map->count()->all(),
                // termasuk trek suara dari video
                'sounds_by_category' => $completed->whereNotNull('sound_category')->groupBy('sound_category')->map->count()->all(),
                'faces' => [
                    'staff_appearances' => (int) $completed->sum('staff_face_count'),
                    'unknown_faces' => (int) $completed->sum('unknown_face_count'),
                ],
                'physical_conditions' => $completed->whereNotNull('physical_condition')->groupBy('physical_condition')->map->count()->all(),
                'behaviors' => $completed->whereNotNull('behavior')->groupBy('behavior')->map->count()->all(),
            ],
        ];
    }
}
