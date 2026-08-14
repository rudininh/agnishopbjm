<?php

$maxItems = (int) env('GITA_ORDER_SCRAPER_MAX_ITEMS', 5000);
$workerLeaseSeconds = (int) env('GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS', 900);

return [
    'ingest_token' => trim((string) env('GITA_ORDER_SCRAPER_INGEST_TOKEN', '')),
    'max_items' => max(1, min(5000, $maxItems)),
    'local_worker_enabled' => filter_var(env('GITA_ORDER_SCRAPER_LOCAL_WORKER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'local_worker_node_binary' => trim((string) env('GITA_ORDER_SCRAPER_LOCAL_WORKER_NODE_BINARY', 'node')) ?: 'node',
    'local_worker_alive_seconds' => max(10, min(300, (int) env('GITA_ORDER_SCRAPER_LOCAL_WORKER_ALIVE_SECONDS', 45))),
    'worker_lease_seconds' => max(60, min(3600, $workerLeaseSeconds)),
];
