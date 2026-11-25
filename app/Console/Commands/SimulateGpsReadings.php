<?php

namespace App\Console\Commands;

use App\Models\GpsReading;
use App\Models\TrackingDevice;
use App\Services\GeofenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mengirim satu titik GPS baru untuk setiap kalung aktif yang terpasang pada satwa aktif.
 *
 * Dipakai selama kalung fisik belum mengirim data sendiri. Titik baru melanjutkan
 * arah gerak titik sebelumnya (jalan acak berkorelasi) dan ditarik kembali bila satwa
 * keluar lebih dari HOME_RANGE_M dari titik pertamanya, sehingga jejaknya tetap
 * berada di wilayah jelajah yang sama. Hasilnya melewati GeofenceService seperti
 * data kalung sungguhan (kunjungan zona & peringatan zona rawan tetap tercatat).
 */
class SimulateGpsReadings extends Command
{
    protected $signature = 'tracking:simulate-gps';

    protected $description = 'Tambahkan satu titik GPS simulasi untuk setiap kalung aktif (dijadwalkan tiap 10 detik)';

    private const EARTH_M_PER_DEG = 111_320;

    private const HOME_RANGE_M = 2_500;

    public function handle(GeofenceService $geofence): int
    {
        if (! config('services.gps_simulator.enabled')) {
            $this->warn('Simulator GPS nonaktif (GPS_SIMULATOR_ENABLED=false).');

            return self::SUCCESS;
        }

        $devices = TrackingDevice::query()
            ->where('device_type', TrackingDevice::TYPE_GPS)
            ->where('is_active', true)
            ->where('device_condition', '!=', 'lost')
            ->whereNotNull('animal_id')
            ->whereHas('animal', fn ($q) => $q->where('is_active', true))
            ->get();

        $now = now()->startOfSecond();
        $created = 0;

        foreach ($devices as $device) {
            $recent = GpsReading::query()
                ->where('device_id', $device->device_id)
                ->orderByDesc('recorded_at')
                ->limit(2)
                ->get();

            // Tanpa titik awal kita tidak tahu wilayah jelajahnya; lewati.
            if ($recent->isEmpty() || $recent->first()->recorded_at->gte($now)) {
                continue;
            }

            $home = GpsReading::query()
                ->where('device_id', $device->device_id)
                ->orderBy('recorded_at')
                ->first();

            [$lat, $lon] = $this->nextPoint($recent->all(), $home);

            $reading = DB::transaction(function () use ($device, $lat, $lon, $now, $geofence) {
                $payload = [
                    'device_id' => $device->device_id,
                    'latitude' => round($lat, 6),
                    'longitude' => round($lon, 6),
                    'recorded_at' => $now->toDateTimeString(),
                    'altitude_meters' => null,
                    'accuracy_meters' => mt_rand(30, 120) / 10,
                ];
                $payload['checksum'] = hash('sha256', json_encode($payload));

                $reading = GpsReading::create($payload);
                $reading->setRelation('device', $device);
                $geofence->handleReading($reading);

                return $reading;
            });

            if ($reading) {
                $created++;
            }
        }

        $this->info("{$created} titik GPS ditambahkan.");

        return self::SUCCESS;
    }

    /** @param  GpsReading[]  $recent  titik terbaru lebih dulu */
    private function nextPoint(array $recent, GpsReading $home): array
    {
        $last = $recent[0];
        $lat = (float) $last->latitude;
        $lon = (float) $last->longitude;
        $metersPerDegLon = self::EARTH_M_PER_DEG * cos(deg2rad($lat));

        // Arah dari dua titik terakhir; titik pertama memakai arah acak.
        if (isset($recent[1])) {
            $dNorth = ($lat - (float) $recent[1]->latitude) * self::EARTH_M_PER_DEG;
            $dEast = ($lon - (float) $recent[1]->longitude) * $metersPerDegLon;
            $heading = ($dNorth == 0.0 && $dEast == 0.0) ? deg2rad(mt_rand(0, 359)) : atan2($dEast, $dNorth);
        } else {
            $heading = deg2rad(mt_rand(0, 359));
        }

        $homeNorth = ((float) $home->latitude - $lat) * self::EARTH_M_PER_DEG;
        $homeEast = ((float) $home->longitude - $lon) * $metersPerDegLon;

        if (hypot($homeNorth, $homeEast) > self::HOME_RANGE_M) {
            $heading = atan2($homeEast, $homeNorth) + deg2rad(mt_rand(-30, 30));
        } else {
            $heading += deg2rad(mt_rand(-35, 35));
        }

        // Kira-kira 1 dari 5 titik satwa diam (makan/istirahat); selebihnya berjalan
        // 3–15 m per 10 detik (±1–5 km/jam, kecepatan jalan harimau/gajah/orangutan).
        $step = mt_rand(1, 5) === 1 ? mt_rand(0, 2) : mt_rand(3, 15);

        return [
            $lat + ($step * cos($heading)) / self::EARTH_M_PER_DEG,
            $lon + ($step * sin($heading)) / $metersPerDegLon,
        ];
    }
}
