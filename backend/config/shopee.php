<?php

return [
    'partner_id' => (int) env('SHOPEE_PARTNER_ID', 2013107),
    'partner_key' => env('SHOPEE_PARTNER_KEY', ''),
    'host' => env('SHOPEE_HOST', 'https://partner.shopeemobile.com'),
    'redirect_url' => env('SHOPEE_REDIRECT_URL', env('APP_URL', 'http://localhost:8000').'/api/shopee/callback'),
];
