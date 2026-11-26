<?php

namespace Tests\Feature;

use App\Services\Ai\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VoiceAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_chatbot_returns_structured_navigation_action(): void
    {
        $groq = Mockery::mock(GroqClient::class);
        $groq->shouldReceive('isConfigured')->andReturn(true);
        $groq->shouldReceive('textModel')->andReturn('llama-3.3-70b-versatile');
        $groq->shouldReceive('chatJson')->once()->andReturn([
            'json' => [
                'reply' => 'Baik, saya buka halaman Live Map untuk memantau pergerakan satwa.',
                'action' => [
                    'type' => 'navigate',
                    'href' => '/tracking/live-map',
                ],
            ],
            'raw' => '{}',
            'model' => 'llama-3.3-70b-versatile',
        ]);
        $this->app->instance(GroqClient::class, $groq);

        $response = $this->postJson('/api/voice-assistant/chat', [
            'message' => 'tunjukin peta harimau dong',
            'locale' => 'id',
            'current_path' => '/dashboard',
            'allowed_routes' => [
                ['href' => '/dashboard', 'label' => 'Dashboard'],
                ['href' => '/tracking/live-map', 'label' => 'Live Map'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.fallback', false)
            ->assertJsonPath('data.action.type', 'navigate')
            ->assertJsonPath('data.action.href', '/tracking/live-map')
            ->assertJsonPath('data.action.label', 'Live Map');
    }

    public function test_voice_chatbot_blocks_navigation_to_disallowed_route(): void
    {
        $groq = Mockery::mock(GroqClient::class);
        $groq->shouldReceive('isConfigured')->andReturn(true);
        $groq->shouldReceive('textModel')->andReturn('llama-3.3-70b-versatile');
        $groq->shouldReceive('chatJson')->once()->andReturn([
            'json' => [
                'reply' => 'Mencoba membuka manajemen pengguna.',
                'action' => [
                    'type' => 'navigate',
                    'href' => '/users',
                ],
            ],
            'raw' => '{}',
            'model' => 'llama-3.3-70b-versatile',
        ]);
        $this->app->instance(GroqClient::class, $groq);

        $response = $this->postJson('/api/voice-assistant/chat', [
            'message' => 'buka manajemen pengguna',
            'locale' => 'id',
            'allowed_routes' => [
                ['href' => '/tours', 'label' => 'Jelajah Tur'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action.type', 'none');
    }

    public function test_voice_chatbot_returns_fallback_when_groq_not_configured(): void
    {
        $groq = Mockery::mock(GroqClient::class);
        $groq->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(GroqClient::class, $groq);

        $response = $this->postJson('/api/voice-assistant/chat', [
            'message' => 'halo asisten',
            'locale' => 'id',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.fallback', true)
            ->assertJsonPath('data.action.type', 'none');
    }
}
