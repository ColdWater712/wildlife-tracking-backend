<?php

namespace App\Services\Ai;

use App\Models\AiPrediction;
use App\Models\Animal;
use App\Models\Notification;
use App\Models\Species;
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
        'set_date',
        'select_option',
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
     *   page_elements?: array<int, array<string, mixed>>,
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
        $pageElements = array_slice(is_array($input['page_elements'] ?? null) ? $input['page_elements'] : [], 0, 35);
        $liveContext = $this->buildLiveContext($user, $roleName);

        $systemPrompt = $this->buildSystemPrompt(
            user: $user,
            roleName: $roleName,
            locale: $locale,
            currentPath: (string) ($input['current_path'] ?? '/'),
            pageContext: (string) ($input['page_context'] ?? ''),
            pageElements: $pageElements,
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
     * Ringkasan data real-time + katalog entitas (satwa, spesies, perangkat, tur) sesuai hak akses role.
     *
     * @return array<string, mixed>
     */
    private function buildLiveContext(?User $user, ?string $roleName): array
    {
        $context = [
            'today' => now()->toDateString(),
        ];

        try {
            $routes = TourRoute::query()
                ->where('is_active', true)
                ->limit(10)
                ->get(['route_id', 'slug', 'name', 'duration_hours', 'difficulty']);

            if ($routes->isNotEmpty()) {
                $context['active_tour_routes'] = $routes->map(fn ($r) => [
                    'name' => $r->name,
                    'duration_hours' => $r->duration_hours,
                    'difficulty' => $r->difficulty,
                    'detail_href' => "/tours/{$r->slug}/detail",
                    'book_href' => "/tours/{$r->slug}/create",
                ])->all();
            }
        } catch (Throwable) {
            // Ignore
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
                $speciesList = Species::query()
                    ->orderBy('common_name')
                    ->limit(25)
                    ->get(['species_id', 'common_name', 'scientific_name'])
                    ->map(fn ($s) => [
                        'id' => $s->species_id,
                        'name' => $s->common_name,
                        'scientific' => $s->scientific_name,
                        'detail_href' => "/species/{$s->species_id}/detail",
                        'model_href' => "/species/{$s->species_id}/model",
                    ])
                    ->all();

                $animalList = Animal::query()
                    ->with('species:species_id,common_name')
                    ->orderBy('animal_id')
                    ->limit(30)
                    ->get(['animal_id', 'species_id', 'name_animal', 'animal_code', 'health_status', 'is_active'])
                    ->map(fn ($a) => [
                        'id' => $a->animal_id,
                        'name' => $a->name_animal ?: $a->animal_code,
                        'code' => $a->animal_code,
                        'species' => $a->species?->common_name,
                        'health' => $a->health_status,
                        'detail_href' => "/animals/{$a->animal_id}/detail",
                    ])
                    ->all();

                $deviceList = TrackingDevice::query()
                    ->orderBy('device_id')
                    ->limit(15)
                    ->get(['device_id', 'device_code', 'device_type', 'battery_level', 'status'])
                    ->map(fn ($d) => [
                        'id' => $d->device_id,
                        'code' => $d->device_code,
                        'type' => $d->device_type,
                        'battery' => $d->battery_level,
                        'status' => $d->status,
                        'detail_href' => "/devices/{$d->device_id}/detail",
                    ])
                    ->all();

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

                $context['animals'] = $animalList;
                $context['species'] = $speciesList;
                $context['devices'] = $deviceList;
            } catch (Throwable) {
                // Ignore
            }
        }

        return $context;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pageElements
     * @param  array<int, array{href: string, label: string}>  $allowedRoutes
     * @param  array<string, mixed>  $liveContext
     */
    private function buildSystemPrompt(
        ?User $user,
        ?string $roleName,
        string $locale,
        string $currentPath,
        string $pageContext,
        array $pageElements,
        array $allowedRoutes,
        array $liveContext,
    ): string {
        $langInstruction = $locale === 'en'
            ? 'Reply in natural, concise English (1-2 short sentences, easy for text-to-speech).'
            : 'Balas dalam Bahasa Indonesia yang alami dan singkat (1-2 kalimat padat, enak didengar lewat suara).';

        $userSummary = $user
            ? "Nama: {$user->name}, Role: " . ($roleName ?? 'pengunjung')
            : 'Status: Belum login (tamu)';

        $routesJson = json_encode($allowedRoutes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = json_encode($liveContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $elementsJson = json_encode($pageElements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pageSnippet = mb_substr(trim($pageContext), 0, 800);

        return <<<PROMPT
Kamu adalah Asisten Suara AI cerdas untuk Sistem Pemantauan Satwa Liar & Ekowisata Balai Taman Nasional Gunung Leuser (BTNGL).
Tugasmu memahami MAKSUD & TUJUAN di balik ucapan bebas user dan langsung mengeksekusi aksi yang paling tepat di antarmuka web.

ATURAN WAJIB:
1. {$langInstruction}
2. JANGAN gunakan markdown (**, *, #, bullet, backtick, emoji) di field "reply" karena langsung dibacakan TTS.
3. Jawab HANYA dalam JSON valid: {"reply": "...", "action": {"type": "..."}}
4. NAVIGASI PINTAR (SPESIFIK vs UMUM):
   - Jika user menyebut modul umum (mis. "cek data satwa", "buka daftar satwa") -> navigate ke "/animals".
   - Jika user menyebut SATWA / SPESIES / PERANGKAT / RUTE TUR SPESIFIK (mis. "cek kondisi orangutan", "lihat detail harimau", "cek satwa HRM-01", "buka model 3D gajah", "lihat tur bukit lawang"):
     CARILAH di LIVE_DATA.animals, LIVE_DATA.species, LIVE_DATA.devices, atau LIVE_DATA.active_tour_routes lalu navigate LANGSUNG ke detail_href / model_href yang cocok!
     Contoh: "cek kondisi orangutan" -> pilih satwa Orangutan di LIVE_DATA.animals dan navigate ke detail_href-nya (mis. "/animals/1/detail"), atau jika tidak ada individu, ke "/species/{id}/detail".
5. TANGGAL & FORM DI HALAMAN AKTIF:
   - Jika user ingin memilih/mengatur tanggal (mis. "pilih tanggal 26 november 2026", "atur tanggal besok", "set tanggal 1 desember 2026") -> gunakan action {"type": "set_date", "date": "YYYY-MM-DD", "field": "nama kolom opsional"}. Konversi semua tanggal ke format ISO YYYY-MM-DD berdasarkan LIVE_DATA.today!
   - Jika user ingin memilih opsi dropdown/filter di halaman (lihat PAGE_ELEMENTS) -> gunakan {"type": "select_option", "field": "nama dropdown opsional", "option": "teks opsi"}.
   - Jika user ingin mengisi kolom input teks -> gunakan {"type": "fill_input", "field": "nama kolom", "value": "isi"}.
   - Jika user ingin menekan tombol/tab/link di halaman -> gunakan {"type": "click_element", "query": "teks tombol"}.
6. DARURAT / ANCAMAN:
   - Jika user minta tolong, darurat, terluka, tersesat, diserang satwa, atau lihat pemburu -> gunakan {"type": "trigger_sos", "emergency_type": "wildlife_attack|injury|lost|poaching|general"} dengan reply SANGAT SINGKAT: "Permintaan pertolongan terdeteksi. Ingin saya telepon nomor darurat?".
7. Jangan pernah bilang "perintah tidak dikenali" bila maksud user bisa dipahami atau dijawab sebagai chatbot.

DAFTAR ACTION:
- {"type": "none"}
- {"type": "navigate", "href": "/..."}
- {"type": "set_date", "date": "YYYY-MM-DD", "field": ""}
- {"type": "select_option", "option": "teks opsi", "field": ""}
- {"type": "fill_input", "field": "nama kolom", "value": "isi"}
- {"type": "click_element", "query": "teks tombol"}
- {"type": "set_theme", "scheme": "dark"|"light"}
- {"type": "set_locale", "locale": "id"|"en"}
- {"type": "set_font_scale", "scale": "md"|"lg"|"xl"}
- {"type": "set_contrast", "high": true|false}
- {"type": "reset_a11y"}
- {"type": "scroll", "direction": "up"|"down"|"top"|"bottom"}
- {"type": "history", "direction": "back"|"forward"}
- {"type": "read_page"}
- {"type": "trigger_sos", "emergency_type": "general"}
- {"type": "logout"}

INFO USER: {$userSummary}
HALAMAN SAAT INI: {$currentPath}
ALLOWED_ROUTES: {$routesJson}
PAGE_ELEMENTS: {$elementsJson}
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
            'set_date' => $this->sanitizeDateAction($action),
            'select_option' => trim((string) ($action['option'] ?? '')) !== ''
                ? [
                    'type' => 'select_option',
                    'option' => mb_substr(trim((string) $action['option']), 0, 120),
                    'field' => mb_substr(trim((string) ($action['field'] ?? '')), 0, 80),
                ]
                : ['type' => 'none'],
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
     * Izinkan navigasi ke exact allowed_routes ATAU sub-route detail/model/create
     * yang prefix-nya termasuk dalam allowed_routes (mis. /animals/1/detail, /species/2/detail).
     *
     * @param  array<string, mixed>  $action
     * @param  array<int, array{href: string, label: string}>  $allowedRoutes
     * @return array<string, mixed>
     */
    private function sanitizeNavigateAction(array $action, array $allowedRoutes): array
    {
        $href = trim((string) ($action['href'] ?? ''));
        if ($href === '' || ! str_starts_with($href, '/') || str_contains($href, '..')) {
            return ['type' => 'none'];
        }

        // 1. Exact match
        foreach ($allowedRoutes as $route) {
            if ($route['href'] === $href) {
                return [
                    'type' => 'navigate',
                    'href' => $route['href'],
                    'label' => $route['label'],
                ];
            }
        }

        // 2. Safe sub-route match (e.g. /animals/1/detail, /species/2/model, /tours/slug/detail, /tours/slug/create)
        if (preg_match('#^/[a-z0-9-]+(?:/[a-z0-9-]+){1,3}$#i', $href)) {
            foreach ($allowedRoutes as $route) {
                if ($route['href'] !== '/' && str_starts_with($href, $route['href'] . '/')) {
                    return [
                        'type' => 'navigate',
                        'href' => $href,
                        'label' => $route['label'],
                    ];
                }
            }
        }

        return ['type' => 'none'];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function sanitizeDateAction(array $action): array
    {
        $date = trim((string) ($action['date'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2})?$/', $date)) {
            return ['type' => 'none'];
        }

        return [
            'type' => 'set_date',
            'date' => $date,
            'field' => mb_substr(trim((string) ($action['field'] ?? '')), 0, 80),
        ];
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
