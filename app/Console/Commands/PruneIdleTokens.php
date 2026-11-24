<?php

namespace App\Console\Commands;

use App\Services\ApiSessionService;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/** Hapus token API yang sudah lewat batas idle (tidak pernah dipakai lagi), agar tabel tidak menumpuk. */
class PruneIdleTokens extends Command
{
    protected $signature = 'tokens:prune-idle';

    protected $description = 'Hapus token Sanctum yang sudah melewati batas idle sesi';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(ApiSessionService::idleSeconds());
        $deleted = 0;

        // Kandidat: last_used_at (atau created_at bila belum pernah dipakai) lebih lama dari batas idle.
        // Aktivitas terbaru bisa ada di cache, jadi tiap kandidat dicek ulang dengan isExpired().
        PersonalAccessToken::query()
            ->where(fn ($q) => $q->where('last_used_at', '<', $cutoff)
                ->orWhere(fn ($w) => $w->whereNull('last_used_at')->where('created_at', '<', $cutoff)))
            ->chunkById(500, function ($tokens) use (&$deleted) {
                foreach ($tokens as $token) {
                    if (ApiSessionService::isExpired($token)) {
                        ApiSessionService::forget($token->id);
                        $token->delete();
                        $deleted++;
                    }
                }
            });

        $this->info("{$deleted} token idle dihapus.");

        return self::SUCCESS;
    }
}
