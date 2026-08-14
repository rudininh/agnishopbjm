<?php

$heartbeatSeconds = (int) env('GITASHOP_MASS_UPLOAD_WORKER_HEARTBEAT_SECONDS', 30);
$stbWaitSeconds = (int) env('GITASHOP_MASS_UPLOAD_STB_WAIT_SECONDS', 300);

return [
    'account_key' => 'shopee-gitacollectionbjm',
    'expected_shop_name' => 'Gitashopcollection',
    'worker_token' => trim((string) env('GITASHOP_MASS_UPLOAD_WORKER_TOKEN', '')),
    'worker_heartbeat_seconds' => max(10, min(300, $heartbeatSeconds)),
    'worker_claim_seconds' => max(60, min(900, $heartbeatSeconds * 4)),
    'local_worker_enabled' => filter_var(env('GITASHOP_MASS_UPLOAD_LOCAL_WORKER_ENABLED', true), FILTER_VALIDATE_BOOL),
    'local_worker_node_binary' => trim((string) env('GITASHOP_MASS_UPLOAD_LOCAL_WORKER_NODE_BINARY', 'node')) ?: 'node',
    'local_worker_alive_seconds' => 45,
    'local_worker_poll_seconds' => 5,
    'local_worker_timeout_seconds' => 300,
    'stb_control_url' => rtrim(trim((string) env('GITASHOP_MASS_UPLOAD_STB_CONTROL_URL', '')), '/'),
    'stb_control_token' => trim((string) env('GITASHOP_MASS_UPLOAD_STB_CONTROL_TOKEN', '')),
    'stb_wait_seconds' => max(30, min(3600, $stbWaitSeconds)),
];
