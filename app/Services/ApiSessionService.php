<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sesi API berbasis token Sanctum dengan batas waktu idle (default 120 menit).
 *
 * Waktu aktivitas terakhir tiap token disimpan di cache dengan TTL sama dengan
 * batas idle. Jika cache kosong (TTL habis, cache dibersihkan, atau server
 * restart), nilai last_used_at di database dipakai sebagai cadangan.
 *
 * Pengecekan harus berjalan sebelum Sanctum menimpa last_used_at, karena itu
 * dipanggil dari Sanctum::authenticateAccessTokensUsing() (AppServiceProvider),
 * bukan dari middleware.
 */
class ApiSessionService
{
    public const EXPIRED_ATTRIBUTE = 'api_session_expired';

    public static function idleSeconds(): int
    {
        return max(1, (int) config('sanctum.idle_timeout', 120)) * 60;
    }

    public static function cacheKey(int $tokenId): string
    {
        return "api_session:last_activity:{$tokenId}";
    }

    public static function lastActivity(PersonalAccessToken $token): int
    {
        $cached = Cache::get(self::cacheKey($token->id));

        if ($cached !== null) {
            return (int) $cached;
        }

        return ($token->last_used_at ?? $token->created_at ?? now())->getTimestamp();
    }

    public static function isExpired(PersonalAccessToken $token): bool
    {
        return now()->getTimestamp() - self::lastActivity($token) > self::idleSeconds();
    }

    public static function touch(PersonalAccessToken $token): void
    {
        Cache::put(self::cacheKey($token->id), now()->getTimestamp(), self::idleSeconds());
    }

    public static function forget(?int $tokenId): void
    {
        if ($tokenId !== null) {
            Cache::forget(self::cacheKey($tokenId));
        }
    }

    /** Dipanggil Sanctum untuk setiap token yang valid secara format/expiry. */
    public static function validate(PersonalAccessToken $token, bool $isValid): bool
    {
        if (! $isValid) {
            return false;
        }

        if (($token->tokenable->status ?? 'aktif') !== 'aktif') {
            self::forget($token->id);
            $token->delete();

            return false;
        }

        if (self::isExpired($token)) {
            self::forget($token->id);
            $token->delete();
            request()->attributes->set(self::EXPIRED_ATTRIBUTE, true);

            return false;
        }

        self::touch($token);

        return true;
    }
}
