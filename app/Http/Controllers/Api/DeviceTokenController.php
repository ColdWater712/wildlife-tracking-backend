<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\TrackingDevice;
use App\Models\User;
use App\Services\ApiSessionService;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token API khusus perangkat lapangan (kamera jebak, perekam suara, kalung GPS).
 *
 * - Tidak kedaluwarsa karena idle (perangkat bisa berhari-hari tidak mengirim data), berbeda dengan token user (2 jam).
 * - Hanya boleh mengirim data perangkatnya sendiri (middleware RestrictDeviceTokens).
 * - Ditampilkan SEKALI saat dibuat; yang disimpan hanya hash-nya. Membuat token baru mencabut token lama.
 * - Dimiliki akun sistem "sistem-perangkat" (bukan akun petugas), sehingga mencabut akun petugas tidak memutus perangkat.
 */
class DeviceTokenController extends ApiController
{
    public function show(int $id)
    {
        TrackingDevice::findOrFail($id);
        $token = $this->tokens($id)->latest('id')->first();

        return $this->success([
            'exists' => $token !== null,
            'created_at' => $token?->created_at?->toIso8601String(),
            'last_used_at' => $token?->last_used_at?->toIso8601String(),
        ]);
    }

    public function store(int $id)
    {
        $device = TrackingDevice::findOrFail($id);
        $this->revoke($id);

        $token = $this->systemUser()->createToken("device:{$device->device_id}", ["device:{$device->device_id}"]);

        return $this->success([
            'token' => $token->plainTextToken,
            'device_id' => $device->device_id,
            'usage' => 'Header: Authorization: Bearer <token>. Hanya untuk telemetri, rekaman, dan GPS perangkat ini.',
        ], 'Token perangkat dibuat. Salin sekarang; token tidak bisa ditampilkan lagi.', 201);
    }

    public function destroy(int $id)
    {
        TrackingDevice::findOrFail($id);

        return $this->success(['revoked' => $this->revoke($id)], 'Token perangkat dicabut');
    }

    private function tokens(int $deviceId)
    {
        return PersonalAccessToken::query()->where('name', "device:{$deviceId}");
    }

    private function revoke(int $deviceId): int
    {
        $count = 0;
        foreach ($this->tokens($deviceId)->get() as $token) {
            ApiSessionService::forget($token->id);
            $token->delete();
            $count++;
        }

        return $count;
    }

    /** Akun sistem pemilik token perangkat (role konservasionis: punya create_data). Tidak bisa dipakai login. */
    private function systemUser(): User
    {
        return User::firstOrCreate(['username' => 'sistem-perangkat'], [
            'full_name' => 'Sistem Perangkat Lapangan',
            'email' => 'sistem-perangkat@wildlife.local',
            'password' => Str::random(64),
            'role_id' => Role::where('name_role', 'konservasionis_lapangan')->value('role_id'),
            'status' => 'aktif',
        ]);
    }
}
