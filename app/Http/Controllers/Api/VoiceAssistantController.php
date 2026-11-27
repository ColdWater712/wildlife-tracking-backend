<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\VoiceAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceAssistantController extends Controller
{
    public function __construct(private VoiceAssistantService $assistant) {}

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:600'],
            'locale' => ['nullable', 'string', 'in:id,en'],
            'current_path' => ['nullable', 'string', 'max:200'],
            'page_context' => ['nullable', 'string', 'max:1200'],
            'page_elements' => ['nullable', 'array', 'max:45'],
            'allowed_routes' => ['nullable', 'array', 'max:70'],
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
}
