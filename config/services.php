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
    | Local / remote AI chat (HTTP /api/chat compatible)
    |--------------------------------------------------------------------------
    | Used for admin playground and campaign inbound SMS when ai_inbound_enabled
    | is true. Prefer per-campaign `ai_inbound_system_prompt`; AI_CAMPAIGN_INBOUND_SYSTEM_PROMPT
    | is the fallback when the campaign field is empty.
    */
    'ai' => [
        'url' => env('AI_URL', 'http://127.0.0.1:11434'),
        'model' => env('AI_MODEL', 'qwen2.5:7b'),
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 120),
        'campaign_inbound_system_prompt' => env('AI_CAMPAIGN_INBOUND_SYSTEM_PROMPT', ''),
        /** Max prior SMS turns (inbound + outbound pairs) sent as chat history; bounded for token limits. */
        'campaign_inbound_max_context_messages' => max(4, min(200, (int) env('AI_CAMPAIGN_INBOUND_MAX_CONTEXT_MESSAGES', 48))),
        /**
         * Wait this many seconds after the last inbound SMS before calling the AI, so rapid multi-part
         * messages produce one combined reply. Each new inbound resets the timer (2–90 seconds).
         */
        'campaign_inbound_debounce_seconds' => max(2, min(90, (int) env('AI_CAMPAIGN_INBOUND_DEBOUNCE_SECONDS', 10))),
        /**
         * Appended to every campaign AI system message so the model uses thread context and stays consistent.
         * Set AI_CAMPAIGN_INBOUND_GUARDRAILS to an empty string in .env to disable.
         */
        'campaign_inbound_guardrails' => env('AI_CAMPAIGN_INBOUND_GUARDRAILS', implode("\n\n", [
            'You are in an ongoing SMS conversation. The messages after this system text are the full thread so far—read them before you reply.',
            'Do not repeat questions the contact already answered. Do not contradict your earlier replies in this thread unless you are correcting a clear mistake.',
            'Stay consistent in tone and facts. Keep each reply short and natural for SMS.',
        ])),
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
