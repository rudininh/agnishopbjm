<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncRuntimeController extends Controller
{
    private const RUNTIME_KEY = 'marketplace_auto_sync';
    private const LOCAL_TIMEOUT_MINUTES = 10;

    public function status(): JsonResponse
    {
        $runtime = $this->runtimeRow();

        return response()->json([
            'status' => 'ok',
            'data' => $this->serializeRuntime($runtime),
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
        DB::table('sync_runtime_statuses')
            ->where('id', $runtime->id)
            ->update([
                'active_owner' => $runtime->active_owner === 'paused' ? 'paused' : 'local',
                'local_last_seen_at' => $now,
                'local_machine_name' => $machineName,
                'local_source' => trim((string) ($data['source'] ?? 'browser_dashboard')) ?: 'browser_dashboard',
                'last_decision_at' => $now,
                'last_decision_reason' => 'Local dashboard heartbeat diterima. Sync order existing tidak diubah.',
                'updated_at' => $now,
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

        return $this->status();
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
            'local_last_seen_at' => $runtime->local_last_seen_at,
            'local_machine_name' => $runtime->local_machine_name,
            'local_source' => $runtime->local_source,
            'local_is_active' => $this->localIsActive($runtime),
            'heartbeat_timeout_minutes' => self::LOCAL_TIMEOUT_MINUTES,
            'online_last_checked_at' => $runtime->online_last_checked_at,
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
}
