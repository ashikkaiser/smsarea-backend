<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | SMS gateway (FastAPI WebSocket service on Fly, etc.)
    |--------------------------------------------------------------------------
    | ws_url: base URL returned to Android (device/register, device/status).
    | http_url: Laravel uses this to POST /send_sms/{device_uid} and /send_mms/{device_uid}.
    */
    /*
    |--------------------------------------------------------------------------
    | Ollama (campaign inbound AI replies)
    |--------------------------------------------------------------------------
    | Used when a campaign is active, ai_inbound_enabled is true, and an SMS
    | arrives on a line attached to that campaign. Set campaign_inbound_system_prompt
    | (env OLLAMA_CAMPAIGN_INBOUND_SYSTEM_PROMPT) before enabling in production.
    */
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),
        'timeout_seconds' => (int) env('OLLAMA_TIMEOUT_SECONDS', 120),
        'campaign_inbound_system_prompt' => env('OLLAMA_CAMPAIGN_INBOUND_SYSTEM_PROMPT', ''),
    ],

    'sms_gateway' => [
        'ws_url' => env('SMS_GATEWAY_WS_URL', env('APP_URL', 'http://localhost')),
        'http_url' => env('SMS_GATEWAY_HTTP_URL'),
        /** Must match Python gateway PRESENCE_KEY_PREFIX (default sms_gateway:presence:). */
        'presence_prefix' => env('SMS_GATEWAY_PRESENCE_PREFIX', 'sms_gateway:presence:'),
        /** Read via config() so admin device presence works when `php artisan config:cache` is used. */
        'upstash_rest_url' => env('UPSTASH_REDIS_REST_URL'),
        'upstash_rest_token' => env('UPSTASH_REDIS_REST_TOKEN'),
    ],

];
