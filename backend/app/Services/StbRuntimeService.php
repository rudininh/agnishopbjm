<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StbRuntimeService
{
    private const RUNTIME_KEY = 'stb_sync_worker';
    private const CACHE_HEARTBEAT_KEY = 'stb_sync_worker:last_heartbeat_at';

    public function heartbeat(string $source = 'artisan', bool $schedulerTick = false): array
    {
        $now = now();
        Cache::put(self::CACHE_HEARTBEAT_KEY, $now->toDateTimeString(), now()->addMinutes(10));

        if (! $this->runtimeTablesReady()) {
            return [
                'status' => 'warning',
                'message' => 'Heartbeat STB disimpan di cache, tabel runtime belum tersedia.',
                'heartbeat_at' => $now->toDateTimeString(),
            ];
        }

        $runtime = $this->runtimeRow();
        $updates = [
            'active_owner' => 'stb_sync_worker',
            'local_last_seen_at' => $now,
            'local_machine_name' => gethostname() ?: 'stb-sync-worker',
            'local_source' => $source,
            'last_decision_at' => $now,
            'last_decision_reason' => 'Heartbeat STB sync worker diterima.',
            'updated_at' => $now,
        ];

        if ($schedulerTick && $this->hasColumn('sync_runtime_statuses', 'runner_last_scheduler_tick_at')) {
            $updates['runner_last_scheduler_tick_at'] = $now;
            $updates['runner_last_scheduler_status'] = 'heartbeat';
        }

        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update($updates);

        $this->logEvent('stb_heartbeat', 'Heartbeat STB sync worker diterima.', [
            'source' => $source,
            'scheduler_tick' => $schedulerTick,
        ]);

        return [
            'status' => 'ok',
            'message' => 'Heartbeat STB sync worker tersimpan.',
            'heartbeat_at' => $now->toDateTimeString(),
        ];
    }

    public function markSchedulerTick(string $status, string $message, array $context = []): void
    {
        if (! $this->runtimeTablesReady()) {
            return;
        }

        $now = now();
        $runtime = $this->runtimeRow();
        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update([
                'active_owner' => 'stb_sync_worker',
                'runner_last_scheduler_tick_at' => $now,
                'runner_last_scheduler_status' => $status,
                'last_decision_at' => $now,
                'last_decision_reason' => $message,
                'updated_at' => $now,
            ]);

        $this->logEvent('stb_scheduler_tick', $message, [
            'status' => $status,
            ...$context,
        ]);
    }

    public function logEvent(string $type, string $message, array $context = []): void
    {
        if (! $this->hasTable('sync_runtime_events')) {
            return;
        }

        $runtime = $this->runtimeTablesReady() ? $this->runtimeRow() : null;
        DB::table('sync_runtime_events')->insert([
            'runtime_key' => self::RUNTIME_KEY,
            'event_type' => $type,
            'active_owner' => $runtime?->active_owner ?? 'stb_sync_worker',
            'local_is_active' => $runtime && $this->workerIsOnline($runtime->local_last_seen_at) ? DB::raw('true') : DB::raw('false'),
            'online_backup_enabled' => DB::raw('false'),
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function logSync(string $source, string $target, string $status, string $message): void
    {
        if (! $this->hasTable('marketplace_sync_logs')) {
            return;
        }

        DB::table('marketplace_sync_logs')->insert([
            'source_marketplace' => $source,
            'target_marketplace' => $target,
            ...($this->hasColumn('marketplace_sync_logs', 'runner') ? ['runner' => 'stb'] : []),
            ...($this->hasColumn('marketplace_sync_logs', 'machine_name') ? ['machine_name' => gethostname() ?: null] : []),
            'sku' => self::RUNTIME_KEY,
            'old_stock' => null,
            'new_stock' => null,
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function status(): array
    {
        $dbStatus = $this->databaseStatus();
        $runtime = $dbStatus['ok'] && $this->runtimeTablesReady() ? $this->runtimeRow() : null;
        $lastHeartbeatAt = $runtime?->local_last_seen_at ?: Cache::get(self::CACHE_HEARTBEAT_KEY);
        $queueStatus = $this->queueStatus();
        $schedulerStatus = $this->schedulerStatus($runtime);
        $disk = $this->diskStatus();
        $memory = $this->memoryStatus();

        return [
            'status' => 'ok',
            'mode' => config('stb.mode', 'stb-sync-worker'),
            'stb_sync_worker' => (bool) config('stb.sync_worker', false),
            'order_sync_enabled' => (bool) config('stb.features.order_sync', true),
            'marketplace_sync_enabled' => (bool) config('stb.features.marketplace_sync', true),
            'auto_browser_enabled' => (bool) config('stb.features.auto_browser', true),
            'frontend_enabled' => (bool) config('stb.enable_frontend', true),
            'worker_online' => $this->workerIsOnline($lastHeartbeatAt),
            'last_heartbeat_at' => $lastHeartbeatAt ? (string) $lastHeartbeatAt : null,
            'last_order_sync_at' => $this->lastOrderSyncAt(),
            'last_marketplace_sync_at' => $this->lastMarketplaceSyncAt(),
            'last_error' => $this->lastError(),
            'queue_status' => $queueStatus,
            'scheduler_status' => $schedulerStatus,
            'db_status' => $dbStatus,
            'disk_warning' => $disk,
            'memory_warning' => $memory,
            'server_time' => now()->toISOString(),
        ];
    }

    private function runtimeRow(): object
    {
        $existing = DB::table('sync_runtime_statuses')
            ->where('runtime_key', self::RUNTIME_KEY)
            ->first();

        if ($existing) {
            return $existing;
        }

        $now = now();
        DB::table('sync_runtime_statuses')->insert([
            'runtime_key' => self::RUNTIME_KEY,
            'active_owner' => 'stb_sync_worker',
            'online_backup_enabled' => DB::raw('false'),
            'last_decision_at' => $now,
            'last_decision_reason' => 'Runtime STB sync worker dibuat.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('sync_runtime_statuses')
            ->where('runtime_key', self::RUNTIME_KEY)
            ->first();
    }

    private function runtimeTablesReady(): bool
    {
        return $this->hasTable('sync_runtime_statuses');
    }

    private function workerIsOnline(mixed $lastHeartbeatAt): bool
    {
        if (! $lastHeartbeatAt) {
            return false;
        }

        try {
            return CarbonImmutable::parse($lastHeartbeatAt)
                ->greaterThanOrEqualTo(now()->subMinutes((int) config('stb.worker.heartbeat_timeout_minutes', 3)));
        } catch (\Throwable) {
            return false;
        }
    }

    private function schedulerStatus(?object $runtime): array
    {
        $lastTickAt = $runtime?->runner_last_scheduler_tick_at ?? null;
        $online = $lastTickAt
            ? CarbonImmutable::parse($lastTickAt)->greaterThanOrEqualTo(now()->subMinutes(3))
            : false;

        return [
            'status' => $online ? 'online' : 'offline',
            'last_tick_at' => $lastTickAt ? (string) $lastTickAt : null,
            'last_tick_status' => $runtime?->runner_last_scheduler_status ?? null,
        ];
    }

    private function queueStatus(): array
    {
        $program = (string) config('stb.worker.supervisor_program', 'agnishop-worker');
        $connection = config('queue.default', env('QUEUE_CONNECTION', 'sync'));

        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('shell_exec')) {
            return [
                'status' => 'unknown',
                'connection' => $connection,
                'program' => $program,
                'message' => 'Supervisor status hanya dicek langsung di Linux/STB.',
            ];
        }

        $output = trim((string) @shell_exec('supervisorctl status '.escapeshellarg($program).' 2>&1'));
        if ($output === '') {
            return [
                'status' => 'unknown',
                'connection' => $connection,
                'program' => $program,
                'message' => 'supervisorctl tidak mengembalikan output.',
            ];
        }

        return [
            'status' => str_contains($output, 'RUNNING') ? 'running' : 'warning',
            'connection' => $connection,
            'program' => $program,
            'message' => $output,
        ];
    }

    private function databaseStatus(): array
    {
        try {
            DB::select('select 1');

            return [
                'ok' => true,
                'status' => 'ok',
                'connection' => config('database.default'),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'error',
                'connection' => config('database.default'),
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function diskStatus(): array
    {
        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! $total || $free === false) {
            return [
                'status' => 'unknown',
                'message' => 'Disk usage tidak bisa dibaca.',
            ];
        }

        $usedPercent = round((($total - $free) / $total) * 100, 2);

        return [
            'status' => $usedPercent >= 80 ? 'warning' : 'ok',
            'used_percent' => $usedPercent,
            'free_mb' => round($free / 1024 / 1024, 1),
            'path' => $path,
        ];
    }

    private function memoryStatus(): array
    {
        $usageMb = round(memory_get_usage(true) / 1024 / 1024, 1);
        $limit = ini_get('memory_limit') ?: 'unknown';

        if (is_readable('/proc/meminfo')) {
            $meminfo = file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $values = [];
            foreach ($meminfo as $line) {
                if (preg_match('/^([^:]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                    $values[$matches[1]] = (int) $matches[2];
                }
            }

            $availableMb = isset($values['MemAvailable']) ? round($values['MemAvailable'] / 1024, 1) : null;

            return [
                'status' => $availableMb !== null && $availableMb < 200 ? 'warning' : 'ok',
                'available_mb' => $availableMb,
                'php_usage_mb' => $usageMb,
                'php_memory_limit' => $limit,
            ];
        }

        return [
            'status' => 'unknown',
            'php_usage_mb' => $usageMb,
            'php_memory_limit' => $limit,
            'message' => 'MemAvailable hanya tersedia di Linux.',
        ];
    }

    private function lastOrderSyncAt(): ?string
    {
        $value = $this->maxMarketplaceSyncLogAt([
            'shopee_order',
            'shopee_stock_refresh',
            'tiktok_order',
            'stb_order_sync',
        ]);

        return $value ? (string) $value : null;
    }

    private function lastMarketplaceSyncAt(): ?string
    {
        $dates = array_filter([
            $this->maxMarketplaceSyncLogAt(['stb_marketplace_lite']),
            $this->hasTable('shopee_sync_logs') ? DB::table('shopee_sync_logs')->max('synced_at') : null,
            $this->hasTable('tiktok_sync_logs') ? DB::table('tiktok_sync_logs')->max('synced_at') : null,
        ]);

        if ($dates === []) {
            return null;
        }

        rsort($dates);

        return (string) $dates[0];
    }

    private function maxMarketplaceSyncLogAt(array $sources): ?string
    {
        if (! $this->hasTable('marketplace_sync_logs')) {
            return null;
        }

        return DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', $sources)
            ->max('created_at');
    }

    private function lastError(): ?array
    {
        if (! $this->hasTable('marketplace_sync_logs')) {
            return null;
        }

        $row = DB::table('marketplace_sync_logs')
            ->where('status', 'error')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'source_marketplace' => $row->source_marketplace,
            'target_marketplace' => $row->target_marketplace,
            'message' => $row->message,
            'created_at' => $row->created_at,
        ];
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
