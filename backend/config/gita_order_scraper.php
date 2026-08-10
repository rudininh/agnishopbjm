<?php

$maxItems = (int) env('GITA_ORDER_SCRAPER_MAX_ITEMS', 5000);

return [
    'ingest_token' => trim((string) env('GITA_ORDER_SCRAPER_INGEST_TOKEN', '')),
    'max_items' => max(1, min(5000, $maxItems)),
];
