<?php

namespace App\Providers;

use App\Services\ApiSessionService;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Parameter ID selalu numerik agar tidak bentrok dengan path statis
        // seperti /notifications/unread-count atau /ai-predictions/pending-verification.
        foreach (['id', 'animalId', 'deviceId', 'readingId', 'mediaId', 'permissionId', 'tokenId', 'embeddingId'] as $param) {
            Route::pattern($param, '[0-9]+');
        }

        // IP klien asli di balik proxy tepercaya (FE server action / cloudflared). Header dari IP lain diabaikan,
        // jadi klien tidak bisa memalsukan X-Forwarded-For untuk lolos dari rate limit.
        $trusted = config('app.trusted_proxies', []);
        if ($trusted !== []) {
            TrustProxies::at($trusted);
            TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        }

        // Sesi API habis setelah idle melewati sanctum.idle_timeout (default 2 jam).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => ApiSessionService::validate($token, $isValid)
        );
    }
}
