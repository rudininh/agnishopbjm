<?php

return [
    'failure' => [
        'enabled' => env('MARKETPLACE_FAILURE_NOTIFY_ENABLED', false),
        'statuses' => array_filter(array_map('trim', explode(',', env('MARKETPLACE_FAILURE_NOTIFY_STATUSES', 'error,skipped')))),
        'dedup_minutes' => (int) env('MARKETPLACE_FAILURE_NOTIFY_DEDUP_MINUTES', 10),
    ],

    'telegram' => [
        'enabled' => env('MARKETPLACE_TELEGRAM_ENABLED', false),
        'bot_token' => env('MARKETPLACE_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('MARKETPLACE_TELEGRAM_CHAT_ID'),
    ],

    'whatsapp' => [
        'enabled' => env('MARKETPLACE_WHATSAPP_ENABLED', false),
        'webhook_url' => env('MARKETPLACE_WHATSAPP_WEBHOOK_URL'),
        'token' => env('MARKETPLACE_WHATSAPP_TOKEN'),
        'phone' => env('MARKETPLACE_WHATSAPP_PHONE'),
    ],
];
