<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceOrderSyncService;
use App\Services\StbRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncRuntimeController extends Controller
{
    private const RUNTIME_KEY = 'marketplace_auto_sync';
    private const LOCAL_TIMEOUT_MINUTES = 10;

    public function __construct(
        private readonly MarketplaceOrderSyncService $orderSyncService,
        private readonly StbRuntimeService $stbRuntimeService,
    ) {
    }

    public function status(): JsonResponse
    {
        $runtime = $this->runtimeRow();

        return response()->json([
            'status' => 'ok',
            'data' => $this->serializeRuntime($runtime),
        ]);
    }

    public function stbStatus(): JsonResponse
    {
        $statusUrl = trim((string) config('stb.status_url', ''));

        if ($statusUrl !== '') {
            try {
                $response = Http::timeout(8)->acceptJson()->get($statusUrl);
                $payload = $response->json();

                if ($response->successful() && is_array($payload)) {
                    return response()->json([
                        ...$payload,
                        'source' => 'remote_stb',
                        'status_url' => $statusUrl,
                        'proxy_checked_at' => now()->toISOString(),
                    ]);
                }

                return response()->json([
                    ...$this->stbRuntimeService->status(),
                    'source' => 'local_fallback',
                    'status_url' => $statusUrl,
                    'remote_status' => [
                        'status' => 'warning',
                        'http_status' => $response->status(),
                        'message' => 'Remote STB status tidak mengembalikan response sukses.',
                    ],
                    'proxy_checked_at' => now()->toISOString(),
                ]);
            } catch (\Throwable $exception) {
                return response()->json([
                    ...$this->stbRuntimeService->status(),
                    'source' => 'local_fallback',
                    'status_url' => $statusUrl,
                    'remote_status' => [
                        'status' => 'error',
                        'message' => $exception->getMessage(),
                    ],
                    'proxy_checked_at' => now()->toISOString(),
                ]);
            }
        }

        return response()->json([
            ...$this->stbRuntimeService->status(),
            'source' => 'local',
        ]);
    }

    public function bridgeStatus(): JsonResponse
    {
        $url = trim((string) env('AUTO_SYNC_SCHEDULER_BRIDGE_URL', 'https://agnishopbjm-laravel.vercel.app/api/auto-sync-scheduler'));

        try {
            $response = Http::timeout(15)->get($url);
            $body = $response->json();

            if (! is_array($body)) {
                $body = ['raw' => $response->body()];
            }

            $bridgeIsHealthy = $response->successful() || $response->status() === 401;

            return response()->json([
                'status' => $bridgeIsHealthy ? 'ok' : 'warning',
                'url' => $url,
                'http_status' => $response->status(),
                'secured' => $response->status() === 401,
                'bridge' => $body,
                'checked_at' => now()->toISOString(),
            ]);
        } catch (\Throwable $error) {
            return response()->json([
                'status' => 'error',
                'url' => $url,
                'message' => $error->getMessage(),
                'checked_at' => now()->toISOString(),
            ], 502);
        }
    }

    public function readiness(): JsonResponse
    {
        $runtime = $this->runtimeRow();
        $localActive = $this->localIsActive($runtime);
        $checks = [
            [
                'key' => 'local_heartbeat',
                'label' => 'Local heartbeat',
                'status' => $localActive ? 'ok' : 'warning',
                'message' => $localActive
                    ? 'Local masih aktif, online backup harus tetap standby.'
                    : 'Local heartbeat timeout, online backup boleh mengambil alih jika setting lengkap.',
            ],
            [
                'key' => 'online_backup_standby',
                'label' => 'Online backup standby',
                'status' => $runtime->online_backup_enabled ? 'ok' : 'warning',
                'message' => $runtime->online_backup_enabled
                    ? 'Online backup standby aktif.'
                    : 'Online backup standby masih OFF.',
            ],
            [
                'key' => 'real_mode',
                'label' => 'Real mode',
                'status' => ($runtime->online_backup_real_enabled ?? false) ? 'danger' : 'ok',
                'message' => ($runtime->online_backup_real_enabled ?? false)
                    ? 'Real mode ON. Pastikan token, lock, dan owner sudah benar sebelum scheduler berjalan.'
                    : 'Real mode OFF. Scheduler tidak akan menjalankan order sync.',
            ],
            [
                'key' => 'backend_runner_token',
                'label' => 'Backend runner token',
                'status' => trim((string) env('AUTO_SYNC_BACKUP_RUNNER_TOKEN', '')) !== '' ? 'ok' : 'warning',
                'message' => trim((string) env('AUTO_SYNC_BACKUP_RUNNER_TOKEN', '')) !== ''
                    ? 'Backend token sudah diset.'
                    : 'Backend token belum diset. Real mode tidak bisa diaktifkan sebelum token ini diisi.',
            ],
            [
                'key' => 'bridge_url',
                'label' => 'Bridge URL',
                'status' => trim((string) env('AUTO_SYNC_SCHEDULER_BRIDGE_URL', '')) !== '' ? 'ok' : 'warning',
                'message' => trim((string) env('AUTO_SYNC_SCHEDULER_BRIDGE_URL', '')) !== ''
                    ? 'Bridge URL memakai env backend.'
                    : 'Bridge URL memakai default agnishopbjm-laravel.vercel.app.',
            ],
        ];

        $readyForReal = $runtime->online_backup_enabled
            && ($runtime->online_backup_real_enabled ?? false)
            && ! $localActive
            && $runtime->active_owner !== 'paused';

        return response()->json([
            'status' => 'ok',
            'ready_for_real_run' => $readyForReal,
            'summary' => [
                'ok' => collect($checks)->where('status', 'ok')->count(),
                'warning' => collect($checks)->where('status', 'warning')->count(),
                'danger' => collect($checks)->where('status', 'danger')->count(),
            ],
            'checks' => $checks,
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));

        $query = DB::table('sync_runtime_events')
            ->where('runtime_key', self::RUNTIME_KEY)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $items = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'event_type' => (string) $row->event_type,
                'active_owner' => $row->active_owner,
                'local_is_active' => (bool) $row->local_is_active,
                'online_backup_enabled' => (bool) $row->online_backup_enabled,
                'message' => $row->message,
                'context' => $row->context ? json_decode((string) $row->context, true) : null,
                'created_at' => $row->created_at,
            ])
            ->values();

        return response()->json([
            'status' => 'ok',
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'machine_name' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $now = now();
        $machineName = trim((string) ($data['machine_name'] ?? ''));

        if ($machineName === '') {
            $machineName = gethostname() ?: 'Local dashboard';
        }

        $runtime = $this->runtimeRow();
        $message = 'Local dashboard heartbeat diterima. Sync order existing tidak diubah.';

        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update([
                'active_owner' => $runtime->active_owner === 'paused' ? 'paused' : 'local',
                'local_last_seen_at' => $now,
                'local_machine_name' => $machineName,
                'local_source' => trim((string) ($data['source'] ?? 'browser_dashboard')) ?: 'browser_dashboard',
                'last_decision_at' => $now,
                'last_decision_reason' => $message,
                'updated_at' => $now,
            ]);

        $this->logEvent('heartbeat', $message, [
            'machine_name' => $machineName,
            'source' => trim((string) ($data['source'] ?? 'browser_dashboard')) ?: 'browser_dashboard',
        ]);

        return $this->status();
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'online_backup_enabled' => ['nullable', 'boolean'],
            'online_backup_real_enabled' => ['nullable', 'boolean'],
            'active_owner' => ['nullable', 'string', 'in:local,paused'],
            'confirm_text' => ['nullable', 'string', 'max:80'],
        ]);

        $runtime = $this->runtimeRow();
        $now = now();
        $updates = [
            'updated_at' => $now,
            'last_decision_at' => $now,
        ];
        $notes = [];

        if (array_key_exists('online_backup_enabled', $data)) {
            $updates['online_backup_enabled'] = $data['online_backup_enabled'] ? DB::raw('true') : DB::raw('false');
            $notes[] = $data['online_backup_enabled']
                ? 'Online backup disiapkan sebagai standby.'
                : 'Online backup dimatikan.';
        }

        if (array_key_exists('online_backup_real_enabled', $data)) {
            $realRequested = (bool) $data['online_backup_real_enabled'];
            $backupEnabled = array_key_exists('online_backup_enabled', $data)
                ? (bool) $data['online_backup_enabled']
                : (bool) $runtime->online_backup_enabled;

            if ($realRequested && ! $backupEnabled) {
                throw ValidationException::withMessages([
                    'online_backup_real_enabled' => 'Aktifkan online backup standby lebih dulu sebelum real mode.',
                ]);
            }

            if ($realRequested && trim((string) env('AUTO_SYNC_BACKUP_RUNNER_TOKEN', '')) === '') {
                throw ValidationException::withMessages([
                    'online_backup_real_enabled' => 'Set AUTO_SYNC_BACKUP_RUNNER_TOKEN di backend sebelum real mode bisa diaktifkan.',
                ]);
            }

            if ($realRequested && trim((string) ($data['confirm_text'] ?? '')) !== 'AKTIFKAN REAL BACKUP') {
                throw ValidationException::withMessages([
                    'confirm_text' => 'Ketik AKTIFKAN REAL BACKUP untuk menyalakan real mode.',
                ]);
            }

            $updates['online_backup_real_enabled'] = $data['online_backup_real_enabled'] ? DB::raw('true') : DB::raw('false');
            $notes[] = $data['online_backup_real_enabled']
                ? 'Mode real online backup diaktifkan manual.'
                : 'Mode real online backup dimatikan.';
        }

        if (array_key_exists('active_owner', $data)) {
            $updates['active_owner'] = $data['active_owner'];
            $notes[] = $data['active_owner'] === 'paused'
                ? 'Local runtime dipause manual.'
                : 'Local runtime diaktifkan kembali.';
        }

        if (! $notes) {
            $updates['last_decision_reason'] = 'Tidak ada pengaturan runtime yang berubah.';
        } else {
            $updates['last_decision_reason'] = implode(' ', $notes).' Sync order existing tidak diubah.';
        }

        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update($updates);

        $this->logEvent('settings', (string) $updates['last_decision_reason'], [
            'payload' => $data,
        ]);

        return $this->status();
    }

    public function onlineBackupTick(): JsonResponse
    {
        $now = now();
        $runtime = $this->runtimeRow();
        $localActive = $this->localIsActive($runtime);

        $nextOwner = $runtime->active_owner;
        $reason = 'Online backup tick hanya mengecek status. Tidak menjalankan sync order.';

        if ($runtime->active_owner !== 'paused') {
            $nextOwner = $localActive || ! $runtime->online_backup_enabled ? 'local' : 'online_backup';
            $reason = $nextOwner === 'online_backup'
                ? 'Local heartbeat melewati timeout, online backup boleh mengambil alih di service terpisah.'
                : 'Local heartbeat masih aktif, online backup tetap standby.';
        }

        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update([
                'active_owner' => $nextOwner,
                'online_last_checked_at' => $now,
                'last_decision_at' => $now,
                'last_decision_reason' => $reason,
                'updated_at' => $now,
            ]);

        $this->logEvent('owner_check', $reason, [
            'local_active' => $localActive,
            'next_owner' => $nextOwner,
        ]);

        return $this->status();
    }

    public function backupRunnerDryRun(): JsonResponse
    {
        $now = now();
        $lockToken = (string) Str::uuid();
        $runtime = $this->runtimeRow();

        if ($this->runnerIsLocked($runtime)) {
            $message = 'Backup runner dry-run dilewati karena lock runtime masih aktif.';
            $this->logEvent('backup_dry_run_skipped', $message, [
                'locked_until_at' => $runtime->runner_locked_until_at,
            ]);

            return response()->json([
                'status' => 'locked',
                'message' => $message,
                'data' => $this->serializeRuntime($this->runtimeRow()),
            ], 409);
        }

        $locked = DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->where(function ($query) use ($now) {
                $query->whereNull('runner_locked_until_at')
                    ->orWhere('runner_locked_until_at', '<=', $now);
            })
            ->update([
                'runner_lock_token' => $lockToken,
                'runner_locked_until_at' => $now->copy()->addMinutes(2),
                'updated_at' => $now,
            ]);

        if (! $locked) {
            $message = 'Backup runner dry-run dilewati karena lock runtime baru saja diambil proses lain.';
            $this->logEvent('backup_dry_run_skipped', $message);

            return response()->json([
                'status' => 'locked',
                'message' => $message,
                'data' => $this->serializeRuntime($this->runtimeRow()),
            ], 409);
        }

        $responsePayload = null;

        try {
            $runtime = $this->runtimeRow();
            $localActive = $this->localIsActive($runtime);
            $allowed = $runtime->online_backup_enabled && ! $localActive && $runtime->active_owner !== 'paused';
            $nextOwner = $allowed ? 'online_backup' : ($runtime->active_owner === 'paused' ? 'paused' : 'local');
            $message = $allowed
                ? 'Dry-run: online backup boleh jalan jika mode real diaktifkan. Sync order belum dijalankan.'
                : 'Dry-run: online backup belum boleh jalan. Sync order belum dijalankan.';

            DB::table('sync_runtime_statuses')
                ->where('id', $runtime->id)
                ->update([
                    'active_owner' => $nextOwner,
                    'runner_last_dry_run_at' => $now,
                    'last_decision_at' => $now,
                    'last_decision_reason' => $message,
                    'updated_at' => $now,
                ]);

            $this->logEvent('backup_dry_run', $message, [
                'allowed' => $allowed,
                'local_active' => $localActive,
                'online_backup_enabled' => (bool) $runtime->online_backup_enabled,
                'next_owner' => $nextOwner,
            ]);

            $responsePayload = [
                'status' => 'ok',
                'allowed' => $allowed,
                'message' => $message,
            ];
        } finally {
            DB::table('sync_runtime_statuses')
                ->where('runtime_key', self::RUNTIME_KEY)
                ->where('runner_lock_token', $lockToken)
                ->update([
                    'runner_lock_token' => null,
                    'runner_locked_until_at' => null,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            ...$responsePayload,
            'data' => $this->serializeRuntime($this->runtimeRow()),
        ]);
    }

    public function backupRunnerRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $runtime = $this->runtimeRow();
        if (! (bool) ($runtime->online_backup_real_enabled ?? false)) {
            $message = 'Real backup runner masih OFF. Tidak ada sync order yang dijalankan.';
            $this->logEvent('backup_real_blocked', $message, [
                'reason' => 'real_mode_off',
            ]);

            return response()->json([
                'status' => 'blocked',
                'message' => $message,
                'data' => $this->serializeRuntime($this->runtimeRow()),
            ], 423);
        }

        $now = now();
        $lockToken = (string) Str::uuid();
        if ($this->runnerIsLocked($runtime)) {
            $message = 'Real backup runner dilewati karena lock runtime masih aktif.';
            $this->logEvent('backup_real_blocked', $message, [
                'reason' => 'runner_locked',
                'locked_until_at' => $runtime->runner_locked_until_at,
            ]);

            return response()->json([
                'status' => 'locked',
                'message' => $message,
                'data' => $this->serializeRuntime($this->runtimeRow()),
            ], 409);
        }

        $locked = DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->where(function ($query) use ($now) {
                $query->whereNull('runner_locked_until_at')
                    ->orWhere('runner_locked_until_at', '<=', $now);
            })
            ->update([
                'runner_lock_token' => $lockToken,
                'runner_locked_until_at' => $now->copy()->addMinutes(5),
                'updated_at' => $now,
            ]);

        if (! $locked) {
            $message = 'Real backup runner dilewati karena lock runtime baru saja diambil proses lain.';
            $this->logEvent('backup_real_blocked', $message, [
                'reason' => 'runner_lock_race',
            ]);

            return response()->json([
                'status' => 'locked',
                'message' => $message,
                'data' => $this->serializeRuntime($this->runtimeRow()),
            ], 409);
        }

        $responsePayload = null;
        try {
            $runtime = $this->runtimeRow();
            $localActive = $this->localIsActive($runtime);
            $allowed = $runtime->online_backup_enabled && ! $localActive && $runtime->active_owner !== 'paused';

            if (! $allowed) {
                $message = 'Real backup runner diblokir: local masih aktif, backup standby OFF, atau runtime sedang pause.';
                $this->logEvent('backup_real_blocked', $message, [
                    'reason' => 'owner_not_allowed',
                    'local_active' => $localActive,
                    'online_backup_enabled' => (bool) $runtime->online_backup_enabled,
                    'active_owner' => $runtime->active_owner,
                ]);

                return response()->json([
                    'status' => 'blocked',
                    'message' => $message,
                    'data' => $this->serializeRuntime($this->runtimeRow()),
                ], 423);
            }

            $hours = (int) ($data['hours'] ?? 1);
            $shopee = $this->orderSyncService->pollShopeeReadyOrders($hours);
            $tiktok = $this->orderSyncService->pollTiktokUpdatedOrders($hours);
            $failed = (int) ($shopee['failed'] ?? 0) + (int) ($tiktok['failed'] ?? 0);
            $message = sprintf(
                'Real backup runner selesai. Shopee baru=%s, TikTok baru=%s, gagal=%s.',
                (int) ($shopee['processed'] ?? 0),
                (int) ($tiktok['processed'] ?? 0),
                $failed
            );

            DB::table('sync_runtime_statuses')
                ->where('id', $runtime->id)
                ->update([
                    'active_owner' => 'online_backup',
                    'runner_last_real_run_at' => now(),
                    'last_decision_at' => now(),
                    'last_decision_reason' => $message,
                    'updated_at' => now(),
                ]);

            $this->logEvent('backup_real_run', $message, [
                'hours' => $hours,
                'shopee' => $shopee,
                'tiktok' => $tiktok,
            ]);

            $responsePayload = [
                'status' => $failed > 0 ? 'warning' : 'ok',
                'message' => $message,
                'shopee' => $shopee,
                'tiktok' => $tiktok,
            ];
        } finally {
            DB::table('sync_runtime_statuses')
                ->where('runtime_key', self::RUNTIME_KEY)
                ->where('runner_lock_token', $lockToken)
                ->update([
                    'runner_lock_token' => null,
                    'runner_locked_until_at' => null,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            ...$responsePayload,
            'data' => $this->serializeRuntime($this->runtimeRow()),
        ]);
    }

    public function schedulerTick(Request $request): JsonResponse
    {
        $tokenResponse = $this->guardSchedulerToken($request);
        if ($tokenResponse) {
            return $tokenResponse;
        }

        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $runtime = $this->runtimeRow();
        $mode = (bool) ($runtime->online_backup_real_enabled ?? false) ? 'real' : 'dry_run';
        $message = $mode === 'real'
            ? 'Scheduler tick diterima. Real mode ON, mencoba real backup runner.'
            : 'Scheduler tick diterima. Real mode OFF, menjalankan dry-run saja.';

        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update([
                'runner_last_scheduler_tick_at' => now(),
                'runner_last_scheduler_status' => $mode,
                'last_decision_at' => now(),
                'last_decision_reason' => $message,
                'updated_at' => now(),
            ]);

        $this->logEvent('scheduler_tick', $message, [
            'mode' => $mode,
            'hours' => (int) ($data['hours'] ?? 1),
        ]);

        return $mode === 'real'
            ? $this->backupRunnerRun($request)
            : $this->backupRunnerDryRun();
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
            'active_owner' => 'local',
            'online_backup_enabled' => DB::raw('false'),
            'last_decision_at' => $now,
            'last_decision_reason' => 'Runtime status dibuat. Default tetap local.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('sync_runtime_statuses')
            ->where('runtime_key', self::RUNTIME_KEY)
            ->first();
    }

    private function serializeRuntime(object $runtime): array
    {
        return [
            'active_owner' => (string) $runtime->active_owner,
            'online_backup_enabled' => (bool) $runtime->online_backup_enabled,
            'online_backup_real_enabled' => (bool) ($runtime->online_backup_real_enabled ?? false),
            'local_last_seen_at' => $runtime->local_last_seen_at,
            'local_machine_name' => $runtime->local_machine_name,
            'local_source' => $runtime->local_source,
            'local_is_active' => $this->localIsActive($runtime),
            'heartbeat_timeout_minutes' => self::LOCAL_TIMEOUT_MINUTES,
            'online_last_checked_at' => $runtime->online_last_checked_at,
            'runner_locked_until_at' => $runtime->runner_locked_until_at ?? null,
            'runner_last_dry_run_at' => $runtime->runner_last_dry_run_at ?? null,
            'runner_last_real_run_at' => $runtime->runner_last_real_run_at ?? null,
            'runner_last_scheduler_tick_at' => $runtime->runner_last_scheduler_tick_at ?? null,
            'runner_last_scheduler_status' => $runtime->runner_last_scheduler_status ?? null,
            'stb_sync_worker' => (bool) config('stb.sync_worker', false),
            'enable_auto_browser' => (bool) config('stb.features.auto_browser', true),
            'last_decision_at' => $runtime->last_decision_at,
            'last_decision_reason' => $runtime->last_decision_reason,
            'server_time' => now()->toISOString(),
        ];
    }

    private function localIsActive(object $runtime): bool
    {
        if (! $runtime->local_last_seen_at) {
            return false;
        }

        return CarbonImmutable::parse($runtime->local_last_seen_at)
            ->greaterThanOrEqualTo(now()->subMinutes(self::LOCAL_TIMEOUT_MINUTES));
    }

    private function runnerIsLocked(object $runtime): bool
    {
        if (! ($runtime->runner_lock_token ?? null) || ! ($runtime->runner_locked_until_at ?? null)) {
            return false;
        }

        return CarbonImmutable::parse($runtime->runner_locked_until_at)->isFuture();
    }

    private function guardSchedulerToken(Request $request): ?JsonResponse
    {
        $expected = trim((string) env('AUTO_SYNC_BACKUP_RUNNER_TOKEN', ''));
        if ($expected === '') {
            return null;
        }

        $given = trim((string) $request->bearerToken());
        if ($given === '') {
            $given = trim((string) ($request->header('X-Runner-Token') ?: $request->query('token', '')));
        }

        if (hash_equals($expected, $given)) {
            return null;
        }

        $message = 'Scheduler tick ditolak: token tidak valid.';
        $this->logEvent('scheduler_rejected', $message);

        return response()->json([
            'status' => 'unauthorized',
            'message' => $message,
        ], 401);
    }

    private function logEvent(string $type, string $message, array $context = []): void
    {
        $runtime = $this->runtimeRow();

        DB::table('sync_runtime_events')->insert([
            'runtime_key' => self::RUNTIME_KEY,
            'event_type' => $type,
            'active_owner' => $runtime->active_owner,
            'local_is_active' => $this->localIsActive($runtime) ? DB::raw('true') : DB::raw('false'),
            'online_backup_enabled' => $runtime->online_backup_enabled ? DB::raw('true') : DB::raw('false'),
            'message' => $message,
            'context' => json_encode($context),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
