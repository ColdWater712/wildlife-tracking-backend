<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\GroqClient;
use App\Services\Ai\VoiceAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceAssistantController extends Controller
{
    public function __construct(
        private VoiceAssistantService $assistant,
        private GroqClient $groq,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:600'],
            'locale' => ['nullable', 'string', 'in:id,en'],
            'current_path' => ['nullable', 'string', 'max:200'],
            'page_context' => ['nullable', 'string', 'max:1200'],
            'page_elements' => ['nullable', 'array', 'max:40'],
            'allowed_routes' => ['nullable', 'array', 'max:50'],
            'allowed_routes.*.href' => ['required_with:allowed_routes', 'string', 'max:120'],
            'allowed_routes.*.label' => ['required_with:allowed_routes', 'string', 'max:80'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:500'],
        ]);

        // Resolve authenticated Sanctum user if token is provided (route also works for guests on /login).
        $user = $request->user('sanctum');
        if ($user) {
            $user->loadMissing('role:role_id,name_role');
        }

        $result = $this->assistant->chat($user, $validated);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['reply'],
                'data' => [
                    'reply' => $result['reply'],
                    'action' => $result['action'],
                    'fallback' => true,
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'reply' => $result['reply'],
                'action' => $result['action'],
                'model' => $result['model'] ?? null,
                'fallback' => false,
            ],
        ]);
    }

    /**
     * Transkripsi rekaman suara mikrofon lewat Groq Whisper (fallback andal saat Web Speech API browser bermasalah).
     */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:10240'],
        ]);

        if (! $this->groq->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'GROQ_API_KEY belum dikonfigurasi.',
                'data' => null,
            ], 503);
        }

        $file = $request->file('audio');
        $ext = $file->getClientOriginalExtension() ?: 'webm';
        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voice_' . uniqid('', true) . '.' . $ext;
        $file->move(dirname($tmpPath), basename($tmpPath));

        try {
            $result = $this->groq->transcribe($tmpPath);

            return response()->json([
                'success' => true,
                'message' => 'OK',
                'data' => [
                    'text' => trim((string) ($result['text'] ?? '')),
                    'language' => $result['language'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mentranskripsi suara: ' . $e->getMessage(),
                'data' => null,
            ], 502);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
