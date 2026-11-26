<?php

namespace App\Services\Ai;

use App\Models\AiPrediction;
use App\Models\Animal;
use App\Models\Notification;
use App\Models\TourRoute;
use App\Models\TrackingDevice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class VoiceAssistantService
{
    private const CONSERVATION_ROLES = [
        'administrator_sistem',
        'konservasionis_lapangan',
        'peneliti_ekologi',
        'pengambil_kebijakan',
    ];

    private const ALLOWED_ACTIONS = [
        'none',
        'navigate',
        'set_theme',
        'set_locale',
        'set_font_scale',
        'set_contrast',
        'reset_a11y',
        'scroll',
        'history',
        'read_page',
        'click_element',
        'fill_input',
        'trigger_sos',
        'logout',
    ];

    public function __construct(private GroqClient $groq) {}

    /**
     * Proses pesan suara/teks bebas user menggunakan Groq LLM dengan output JSON terstruktur.
     *
     * @param  array{
     *   message: string,
     *   locale?: string,
     *   current_path?: string|null,
     *   page_context?: string|null,
     *   allowed_routes?: array<int, array{href: string, label: string}>,
     *   history?: array<int, array{role: string, content: string}>
     * }  $input
     * @return array{ok: bool, reply: string, action: array<string, mixed>, model?: string, fallback?: bool}
     */
    public function chat(?User $user, array $input): array
    {
        $locale = ($input['locale'] ?? 'id') === 'en' ? 'en' : 'id';

        if (! $this->groq->isConfigured()) {
            return [
                'ok' => false,
                'fallback' => true,
                'reply' => $locale === 'en'
                    ? 'AI assistant service is not configured yet.'
                    : 'Layanan AI asisten belum dikonfigurasi.',
                'action' => ['type' => 'none'],
            ];
        }

        $roleName = $user?->role?->name_role;
        $allowedRoutes = $this->normalizeAllowedRoutes($input['allowed_routes'] ?? []);
        $liveContext = $this->buildLiveContext($user, $roleName);

        $systemPrompt = $this->buildSystemPrompt(
            user: $user,
            roleName: $roleName,
            locale: $locale,
            currentPath: (string) ($input['current_path'] ?? '/'),
            pageContext: (string) ($input['page_context'] ?? ''),
            allowedRoutes: $allowedRoutes,
            liveContext: $liveContext,
        );

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $history = array_slice($input['history'] ?? [], -8);
        foreach ($history as $turn) {
            $turnRole = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $turnContent = trim((string) ($turn['content'] ?? ''));
            if ($turnContent === '') {
                continue;
            }
            $messages[] = [
                'role' => $turnRole,
                'content' => mb_substr($turnContent, 0, 500),
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => mb_substr(trim($input['message']), 0, 600),
        ];

        try {
            $result = $this->groq->chatJson($messages, $this->groq->textModel(), 500);
            $json = $result['json'] ?? [];

            $reply = $this->cleanSpokenReply((string) ($json['reply'] ?? ''), $locale);
            $action = $this->sanitizeAction(
                is_array($json['action'] ?? null) ? $json['action'] : ['type' => 'none'],
                $allowedRoutes,
                $user !== null,
            );

            return [
                'ok' => true,
                'reply' => $reply,
                'action' => $action,
                'model' => $result['model'],
            ];
        } catch (Throwable $e) {
            Log::warning('VoiceAssistantService Groq call failed', [
                'error' => $e->getMessage(),
                'user_id' => $user?->user_id,
            ]);

            return [
                'ok' => false,
                'fallback' => true,
                'reply' => $locale === 'en'
                    ? 'Unable to reach AI assistant right now.'
                    : 'Asisten AI sedang tidak dapat dihubungi saat ini.',
                'action' => ['type' => 'none'],
            ];
        }
    }

    /**
     * @param  array<int, mixed>  $routes
     * @return array<int, array{href: string, label: string}>
     */
    private function normalizeAllowedRoutes(array $routes): array
    {
        $clean = [];
        foreach (array_slice($routes, 0, 50) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $href = trim((string) ($route['href'] ?? ''));
            $label = trim((string) ($route['label'] ?? ''));
            if ($href === '' || ! str_starts_with($href, '/')) {
                continue;
            }
            $clean[] = [
                'href' => mb_substr($href, 0, 120),
                'label' => mb_substr($label !== '' ? $label : $href, 0, 80),
            ];
        }

        return $clean;
    }

    /**
     * Ringkasan data real-time sesuai hak akses role user (strict no-show untuk non-konservasi).
     *
     * @return array<string, mixed>
     */
    private function buildLiveContext(?User $user, ?string $roleName): array
    {
        $context = [];

        try {
            $routes = TourRoute::query()
                ->where('is_active', true)
                ->limit(6)
                ->get(['name', 'duration_hours', 'difficulty']);

            if ($routes->isNotEmpty()) {
                $context['active_tour_routes'] = $routes->map(fn ($r) => [
                    'name' => $r->name,
                    'duration_hours' => $r->duration_hours,
                    'difficulty' => $r->difficulty,
                ])->all();
            }
        } catch (Throwable) {
            // Ignore if table unavailable
        }

        if (! $user) {
            return $context;
        }

        try {
            $unreadCount = Notification::query()
                ->where('user_id', $user->user_id)
                ->where('is_read', false)
                ->count();

            $latestUnread = Notification::query()
                ->where('user_id', $user->user_id)
                ->where('is_read', false)
                ->orderByDesc('created_at')
                ->limit(3)
                ->get(['notification_type', 'message', 'created_at'])
                ->map(fn ($n) => [
                    'type' => $n->notification_type,
                    'message' => mb_substr((string) $n->message, 0, 160),
                ])
                ->all();

            $context['unread_notifications_count'] = $unreadCount;
            if ($latestUnread !== []) {
                $context['latest_unread_notifications'] = $latestUnread;
            }
        } catch (Throwable) {
            // Ignore
        }

        if (in_array($roleName, self::CONSERVATION_ROLES, true)) {
            try {
                $context['conservation_summary'] = [
                    'total_animals' => Animal::query()->count(),
                    'active_devices' => TrackingDevice::query()->where('status', 'active')->count(),
                    'low_battery_devices' => TrackingDevice::query()
                        ->whereNotNull('battery_level')
                        ->where('battery_level', '<=', 20)
                        ->count(),
                    'pending_ai_verifications' => AiPrediction::query()
                        ->where('verification_status', 'pending')
                        ->count(),
                    'recent_high_threats' => AiPrediction::query()
                        ->whereIn('threat_level', ['high', 'critical'])
                        ->orderByDesc('created_at')
                        ->limit(3)
                        ->get(['threat_level', 'predicted_label', 'sound_label', 'summary', 'created_at'])
                        ->map(fn ($p) => [
                            'threat_level' => $p->threat_level,
                            'label' => $p->sound_label ?: $p->predicted_label,
                            'summary' => mb_substr((string) ($p->summary ?? ''), 0, 140),
                        ])
                        ->all(),
                ];
            } catch (Throwable) {
                // Ignore
            }
        }

        return $context;
    }

    /**
     * @param  array<int, array{href: string, label: string}>  $allowedRoutes
     * @param  array<string, mixed>  $liveContext
     */
    private function buildSystemPrompt(
        ?User $user,
        ?string $roleName,
        string $locale,
        string $currentPath,
        string $pageContext,
        array $allowedRoutes,
        array $liveContext,
    ): string {
        $langInstruction = $locale === 'en'
            ? 'Reply in natural, friendly, concise English (1-3 short sentences, easy to listen to via text-to-speech).'
            : 'Balas dalam Bahasa Indonesia yang alami, ramah, dan ringkas (1-3 kalimat singkat, enak didengarkan lewat suara/TTS).';

        $userSummary = $user
            ? "Nama: {$user->name}, Role: " . ($roleName ?? 'pengunjung')
            : 'Status: Belum login (tamu)';

        $routesJson = json_encode($allowedRoutes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = json_encode($liveContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pageSnippet = mb_substr(trim($pageContext), 0, 900);

        return <<<PROMPT
Kamu adalah Asisten Suara AI untuk Sistem Pemantauan Satwa Liar & Ekowisata Balai Taman Nasional Gunung Leuser (BTNGL).
Kamu bekerja seperti chatbot cerdas yang mengerti segala perintah bahasa sehari-hari, pertanyaan tentang sistem/data, maupun permintaan navigasi dan pengaturan tampilan.

ATURAN WAJIB:
1. {$langInstruction}
2. JANGAN gunakan format markdown (tanpa **, *, #, bullet list, backtick, atau emoji) di field "reply" karena teks akan langsung dibacakan oleh text-to-speech.
3. Jawab HANYA dalam format JSON valid dengan struktur persis:
   {"reply": "...", "action": {"type": "..."}}
4. Jika user meminta membuka/pindah halaman, pilih "action.type" = "navigate" dan "action.href" HARUS salah satu href dari daftar ALLOWED_ROUTES di bawah. Jika halaman yang diminta tidak ada di ALLOWED_ROUTES, gunakan "type": "none" dan jelaskan dengan sopan bahwa role user tidak memiliki akses atau halaman tidak tersedia.
5. Jika user dalam bahaya / minta tolong darurat / diserang satwa / tersesat / terluka / butuh SOS, gunakan {"type": "trigger_sos", "emergency_type": "wildlife_attack|injury|lost|poaching|general"}.
6. Untuk pertanyaan umum tentang data (ancaman, notifikasi, satwa, perangkat, rute wisata), gunakan LIVE_DATA di bawah secara akurat. Jangan mengarang angka.
7. Untuk pertanyaan tentang halaman yang sedang dibuka, gunakan CURRENT_PAGE_SUMMARY.

DAFTAR ACTION YANG DIDUKUNG:
- {"type": "none"} — untuk tanya jawab / ngobrol biasa tanpa aksi UI
- {"type": "navigate", "href": "/..."} — pindah ke halaman di ALLOWED_ROUTES
- {"type": "set_theme", "scheme": "dark"|"light"} — ubah tema gelap/terang
- {"type": "set_locale", "locale": "id"|"en"} — ubah bahasa Indonesia/Inggris
- {"type": "set_font_scale", "scale": "md"|"lg"|"xl"} — ubah ukuran teks (normal/besar/ekstra besar)
- {"type": "set_contrast", "high": true|false} — aktifkan/matikan kontras tinggi
- {"type": "reset_a11y"} — reset pengaturan tampilan aksesibilitas
- {"type": "scroll", "direction": "up"|"down"|"top"|"bottom"} — gulir halaman
- {"type": "history", "direction": "back"|"forward"} — kembali / maju halaman
- {"type": "read_page"} — bacakan ringkasan lengkap halaman saat ini
- {"type": "click_element", "query": "teks tombol"} — klik tombol/tautan di halaman
- {"type": "fill_input", "field": "nama kolom", "value": "isi"} — isi input form di halaman
- {"type": "trigger_sos", "emergency_type": "general"} — aktifkan konfirmasi panggilan darurat posko BBTNGL
- {"type": "logout"} — keluar dari akun (hanya jika user sudah login)

INFO USER: {$userSummary}
HALAMAN SAAT INI: {$currentPath}
ALLOWED_ROUTES: {$routesJson}
LIVE_DATA: {$contextJson}
CURRENT_PAGE_SUMMARY: {$pageSnippet}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<int, array{href: string, label: string}>  $allowedRoutes
     * @return array<string, mixed>
     */
    private function sanitizeAction(array $action, array $allowedRoutes, bool $isAuthenticated): array
    {
        $type = (string) ($action['type'] ?? 'none');
        if (! in_array($type, self::ALLOWED_ACTIONS, true)) {
            return ['type' => 'none'];
        }

        return match ($type) {
            'navigate' => $this->sanitizeNavigateAction($action, $allowedRoutes),
            'set_theme' => in_array($action['scheme'] ?? null, ['light', 'dark'], true)
                ? ['type' => 'set_theme', 'scheme' => $action['scheme']]
                : ['type' => 'none'],
            'set_locale' => in_array($action['locale'] ?? null, ['id', 'en'], true)
                ? ['type' => 'set_locale', 'locale' => $action['locale']]
                : ['type' => 'none'],
            'set_font_scale' => in_array($action['scale'] ?? null, ['md', 'lg', 'xl'], true)
                ? ['type' => 'set_font_scale', 'scale' => $action['scale']]
                : ['type' => 'none'],
            'set_contrast' => ['type' => 'set_contrast', 'high' => (bool) ($action['high'] ?? true)],
            'scroll' => in_array($action['direction'] ?? null, ['up', 'down', 'top', 'bottom'], true)
                ? ['type' => 'scroll', 'direction' => $action['direction']]
                : ['type' => 'none'],
            'history' => in_array($action['direction'] ?? null, ['back', 'forward'], true)
                ? ['type' => 'history', 'direction' => $action['direction']]
                : ['type' => 'none'],
            'click_element' => trim((string) ($action['query'] ?? '')) !== ''
                ? ['type' => 'click_element', 'query' => mb_substr(trim((string) $action['query']), 0, 100)]
                : ['type' => 'none'],
            'fill_input' => trim((string) ($action['field'] ?? '')) !== ''
                ? [
                    'type' => 'fill_input',
                    'field' => mb_substr(trim((string) $action['field']), 0, 80),
                    'value' => mb_substr((string) ($action['value'] ?? ''), 0, 200),
                ]
                : ['type' => 'none'],
            'trigger_sos' => [
                'type' => 'trigger_sos',
                'emergency_type' => in_array($action['emergency_type'] ?? null, ['general', 'wildlife_attack', 'injury', 'lost', 'poaching'], true)
                    ? $action['emergency_type']
                    : 'general',
            ],
            'logout' => $isAuthenticated ? ['type' => 'logout'] : ['type' => 'none'],
            default => ['type' => $type],
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<int, array{href: string, label: string}>  $allowedRoutes
     * @return array<string, mixed>
     */
    private function sanitizeNavigateAction(array $action, array $allowedRoutes): array
    {
        $href = trim((string) ($action['href'] ?? ''));
        if ($href === '') {
            return ['type' => 'none'];
        }

        foreach ($allowedRoutes as $route) {
            if ($route['href'] === $href) {
                return [
                    'type' => 'navigate',
                    'href' => $route['href'],
                    'label' => $route['label'],
                ];
            }
        }

        return ['type' => 'none'];
    }

    private function cleanSpokenReply(string $reply, string $locale): string
    {
        $clean = preg_replace('/[*_`#]+/', '', trim($reply)) ?? '';
        $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
        $clean = trim($clean);

        if ($clean === '') {
            return $locale === 'en'
                ? 'How can I help you?'
                : 'Ada yang bisa saya bantu?';
        }

        return mb_substr($clean, 0, 600);
    }
}
