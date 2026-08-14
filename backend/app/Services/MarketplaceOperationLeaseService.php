<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MarketplaceOperationLeaseService
{
    private const RUNTIME_KEY = 'stb_sync_worker';

    public function acquire(string $operation, int $seconds): array
    {
        $operation = trim($operation);
        if ($operation === '') {
            throw new \InvalidArgumentException('Marketplace operation is required.');
        }

        $seconds = max(10, min(3600, $seconds));

        return DB::transaction(function () use ($operation, $seconds): array {
            $runtime = $this->lockedRuntimeRow();
            $now = now();
            $lockedUntil = $runtime->marketplace_operation_locked_until_at
                ? \Carbon\CarbonImmutable::parse($runtime->marketplace_operation_locked_until_at)
                : null;
            $activeOperation = trim((string) ($runtime->marketplace_operation ?? ''));

            if ($activeOperation !== '' && $lockedUntil && $lockedUntil->isAfter($now)) {
                return [
                    'acquired' => false,
                    'operation' => $activeOperation,
                    'token' => null,
                    'locked_until_at' => $lockedUntil->toDateTimeString(),
                ];
            }

            $token = bin2hex(random_bytes(32));
            $until = $now->copy()->addSeconds($seconds);
            DB::table('sync_runtime_statuses')->where('id', $runtime->id)->update([
                'marketplace_operation' => $operation,
                'marketplace_operation_token' => $token,
                'marketplace_operation_locked_until_at' => $until,
                'last_decision_at' => $now,
                'last_decision_reason' => 'Operasi marketplace terkunci: '.$operation.'.',
                'updated_at' => $now,
            ]);

            return [
                'acquired' => true,
                'operation' => $operation,
                'token' => $token,
                'locked_until_at' => $until->toDateTimeString(),
            ];
        });
    }

    public function renew(string $token, int $seconds): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $seconds = max(10, min(3600, $seconds));

        return DB::transaction(function () use ($token, $seconds): bool {
            $runtime = $this->lockedRuntimeRow();
            if (! hash_equals((string) ($runtime->marketplace_operation_token ?? ''), $token)) {
                return false;
            }

            $until = now()->addSeconds($seconds);
            DB::table('sync_runtime_statuses')->where('id', $runtime->id)->update([
                'marketplace_operation_locked_until_at' => $until,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function release(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        return DB::transaction(function () use ($token): bool {
            $runtime = $this->lockedRuntimeRow();
            $currentToken = (string) ($runtime->marketplace_operation_token ?? '');
            if ($currentToken === '') {
                return true;
            }
            if (! hash_equals($currentToken, $token)) {
                return false;
            }

            DB::table('sync_runtime_statuses')->where('id', $runtime->id)->update([
                'marketplace_operation' => null,
                'marketplace_operation_token' => null,
                'marketplace_operation_locked_until_at' => null,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function status(): array
    {
        $runtime = DB::table('sync_runtime_statuses')->where('runtime_key', self::RUNTIME_KEY)->first();
        $lockedUntil = $runtime?->marketplace_operation_locked_until_at
            ? \Carbon\CarbonImmutable::parse($runtime->marketplace_operation_locked_until_at)
            : null;
        $operation = trim((string) ($runtime?->marketplace_operation ?? ''));

        return [
            'active' => $operation !== '' && $lockedUntil?->isAfter(now()),
            'operation' => $operation !== '' ? $operation : null,
            'locked_until_at' => $lockedUntil?->toDateTimeString(),
        ];
    }

    private function lockedRuntimeRow(): object
    {
        $runtime = DB::table('sync_runtime_statuses')
            ->where('runtime_key', self::RUNTIME_KEY)
            ->lockForUpdate()
            ->first();

        if ($runtime) {
            return $runtime;
        }

        $now = now();
        DB::table('sync_runtime_statuses')->insertOrIgnore([
            'runtime_key' => self::RUNTIME_KEY,
            'active_owner' => 'stb_sync_worker',
            'online_backup_enabled' => false,
            'last_decision_at' => $now,
            'last_decision_reason' => 'Runtime lease marketplace dibuat.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('sync_runtime_statuses')
            ->where('runtime_key', self::RUNTIME_KEY)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
