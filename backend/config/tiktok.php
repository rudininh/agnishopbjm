<?php

return [
    'app_key' => env('TIKTOK_APP_KEY', ''),
    'app_secret' => env('TIKTOK_APP_SECRET', ''),
    'auth_host' => env('TIKTOK_AUTH_HOST', 'https://auth.tiktok-shops.com'),
    'api_host' => env('TIKTOK_API_HOST', 'https://open-api.tiktokglobalshop.com'),
    'redirect_url' => env('TIKTOK_REDIRECT_URL', env('APP_URL', 'http://localhost:8000').'/api/tiktok/callback'),
];
