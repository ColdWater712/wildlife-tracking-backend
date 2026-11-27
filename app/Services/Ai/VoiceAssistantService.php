<?php

namespace App\Services\Ai;

use App\Models\AiPrediction;
use App\Models\Animal;
use App\Models\GeoZone;
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

        // Cascade model cepat & tahan rate-limit (hindari 400 json_validate_failed & 429 ITPM pada qwen)
        $configuredModel = $this->groq->textModel();
        $candidates = array_values(array_unique(array_filter([
            'llama-3.3-70b-versatile',
            'llama-3.1-8b-instant',
            $configuredModel,
        ])));

        $lastError = null;
        foreach ($candidates as $candidateModel) {
            try {
                // Gunakan strict JSON untuk Llama (stabil), atau non-strict + decodeJsonObject untuk model lain
                $useStrict = str_starts_with($candidateModel, 'llama-');
                $result = $this->groq->chatJson($messages, $candidateModel, 350, $useStrict);
                $json = $result['json'] ?? [];
                $rawText = trim((string) ($result['raw'] ?? ''));

                // Jika model tetap membalas teks biasa tanpa JSON, gunakan teks tersebut sebagai reply (type: none)
                $rawReply = (string) ($json['reply'] ?? ($json === [] && $rawText !== '' ? $rawText : ''));
                $reply = $this->cleanSpokenReply($rawReply, $locale);
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
                $lastError = $e->getMessage();
                Log::warning('VoiceAssistantService Groq attempt failed', [
                    'model' => $candidateModel,
                    'error' => $lastError,
                    'user_id' => $user?->user_id,
                ]);
            }
        }

        return [
            'ok' => false,
            'fallback' => true,
            'reply' => $locale === 'en'
                ? 'Unable to reach AI assistant right now.'
                : 'Asisten AI sedang tidak dapat dihubungi saat ini.',
            'action' => ['type' => 'none'],
        ];
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

                $zoneList = GeoZone::query()
                    ->orderBy('zone_name')
                    ->limit(25)
                    ->get(['zone_id', 'zone_name', 'zone_type'])
                    ->map(fn ($z) => [
                        'id' => $z->zone_id,
                        'name' => $z->zone_name,
                        'type' => $z->zone_type,
                    ])
                    ->all();

                $context['animals'] = $animalList;
                $context['species'] = $speciesList;
                $context['devices'] = $deviceList;
                $context['geo_zones'] = $zoneList;
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

        $routesCompact = implode(', ', array_map(
            fn ($r) => "{$r['href']}({$r['label']})",
            array_slice($allowedRoutes, 0, 30),
        ));

        $elementsCompact = implode('; ', array_map(function ($el) {
            $kind = $el['kind'] ?? 'el';
            $lbl = $el['label'] ?? '';
            $opts = is_array($el['options'] ?? null) ? '[' . implode('|', array_slice($el['options'], 0, 12)) . ']' : '';
            $val = isset($el['value']) ? "={$el['value']}" : '';
            return "{$kind}:{$lbl}{$val}{$opts}";
        }, array_slice($pageElements, 0, 18)));

        // Ringkas LIVE_DATA agar hemat token (~600 token total, bebas 429 rate-limit)
        $compactLive = ['today' => $liveContext['today'] ?? now()->toDateString()];
        if (isset($liveContext['unread_notifications_count'])) {
            $compactLive['unread_notif'] = $liveContext['unread_notifications_count'];
        }
        if (! empty($liveContext['animals'])) {
            $compactLive['animals'] = array_map(
                fn ($a) => "{$a['detail_href']}={$a['name']}({$a['species']},{$a['health']})",
                array_slice($liveContext['animals'], 0, 15),
            );
        }
        if (! empty($liveContext['species'])) {
            $compactLive['species'] = array_map(
                fn ($s) => "{$s['detail_href']}={$s['name']}",
                array_slice($liveContext['species'], 0, 12),
            );
        }
        if (! empty($liveContext['geo_zones'])) {
            $compactLive['zones'] = array_map(fn ($z) => $z['name'], array_slice($liveContext['geo_zones'], 0, 15));
        }
        if (! empty($liveContext['active_tour_routes'])) {
            $compactLive['tours'] = array_map(
                fn ($r) => "{$r['detail_href']}={$r['name']}",
                array_slice($liveContext['active_tour_routes'], 0, 8),
            );
        }
        if (! empty($liveContext['conservation_summary'])) {
            $compactLive['stats'] = $liveContext['conservation_summary'];
        }

        $contextJson = json_encode($compactLive, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pageSnippet = mb_substr(trim($pageContext), 0, 350);

        return <<<PROMPT
Asisten Suara AI Sistem Monitoring Satwa & Wisata BTNGL. Pahami segala bahasa sehari-hari user.
ATURAN:
1. {$langInstruction} Tanpa markdown/emoji/simbol di "reply".
2. BALAS HANYA JSON VALID: {"reply":"...","action":{"type":"..."}}
3. NAVIGASI: Jika user minta buka/pindah halaman, pakai {"type":"navigate","href":"/..."} dari ROUTES atau detail_href di LIVE_DATA (mis. "cek kondisi orangutan" -> detail_href Orangutan di LIVE_DATA.animals).
4. FORM/DROPDOWN/TANGGAL:
   - Pilih tanggal -> {"type":"set_date","date":"YYYY-MM-DD","field":""}
   - Pilih opsi dropdown -> {"type":"select_option","option":"nama opsi","field":"nama dropdown"}
   - Isi teks -> {"type":"fill_input","field":"nama kolom","value":"isi"}
   - Klik tombol -> {"type":"click_element","query":"teks tombol"}
5. DILARANG NAVIGATE saat user BERTANYA tentang isi dropdown/daftar data ("ada zona/status/pilihan apa saja"). Jawab langsung dari ELEMENTS/LIVE_DATA dengan {"type":"none"}.
6. DARURAT -> {"type":"trigger_sos","emergency_type":"general"} dengan reply: "Permintaan pertolongan terdeteksi. Ingin saya telepon nomor darurat?".
7. Aksi lain: set_theme(dark|light), set_locale(id|en), set_font_scale(md|lg|xl), set_contrast(true|false), reset_a11y, scroll(up|down|top|bottom), history(back|forward), read_page, logout, none.

USER: {$userSummary} | PATH: {$currentPath}
ROUTES: {$routesCompact}
ELEMENTS: {$elementsCompact}
LIVE_DATA: {$contextJson}
PAGE: {$pageSnippet}
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
