<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Groq (vision, text, dan Whisper untuk transkripsi audio)
    'groq' => [
        'key' => env('GROQ_API_KEY', ''),
        'vision_model' => env('GROQ_VISION_MODEL', 'qwen/qwen3.8-27b'),
        'text_model' => env('GROQ_TEXT_MODEL', 'qwen/qwen3.8-27b'),
        'audio_model' => env('GROQ_AUDIO_MODEL', 'whisper-large-v3-turbo'),
        'timeout' => (int) env('GROQ_TIMEOUT', 60),
    ],

    // Opsional: microservice klasifikasi audio (YAMNet / BirdNET / PANNs, dsb.)
    // Kontrak: POST {url}/classify-audio (multipart "file") -> {label, confidence, category?, species?}
    'ai' => [
        // true = analisis AI di background (butuh `php artisan queue:work --queue=ai,default`).
        'queue' => (bool) env('AI_QUEUE', false),
    ],

    // ai-service visual (SpeciesNet + MegaDescriptor). Biasanya URL yang sama dengan ai_audio.
    'ai_vision' => [
        'url' => env('AI_VISION_SERVICE_URL'),
        'token' => env('AI_VISION_SERVICE_TOKEN', env('AI_AUDIO_SERVICE_TOKEN')),
        'timeout' => (int) env('AI_VISION_SERVICE_TIMEOUT', 180),
        'country' => env('AI_COUNTRY', 'IDN'),
        // Kemiripan kosinus minimum agar dua foto dianggap individu yang sama (diuji pada ATRW: 0,6).
        'reid_threshold' => (float) env('AI_REID_THRESHOLD', 0.6),
    ],

    'ai_audio' => [
        'url' => env('AI_AUDIO_SERVICE_URL'),
        'token' => env('AI_AUDIO_SERVICE_TOKEN'),
        'model' => env('AI_AUDIO_SERVICE_MODEL', 'audio-classifier'),
        'timeout' => (int) env('AI_AUDIO_SERVICE_TIMEOUT', 60),
    ],

    // Dipakai untuk mengambil frame dari video kamera jebak
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
    ],

];