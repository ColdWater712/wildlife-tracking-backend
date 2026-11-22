<?php

namespace App\Providers;

use App\Services\ApiSessionService;
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
        foreach (['id', 'animalId', 'deviceId', 'readingId', 'mediaId', 'permissionId', 'tokenId'] as $param) {
            Route::pattern($param, '[0-9]+');
        }

        // Sesi API habis setelah idle melewati sanctum.idle_timeout (default 2 jam).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => ApiSessionService::validate($token, $isValid)
        );
    }
}
