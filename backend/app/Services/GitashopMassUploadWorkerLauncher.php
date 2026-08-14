<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GitashopMassUploadWorkerLauncher
{
    public function wake(): array
    {
        if (! config('shopee_mass_upload.local_worker_enabled', true)) {
            return ['status' => 'manual_required'];
        }

        $runtime = DB::table('shopee_mass_upload_runtimes')
            ->where('account_key', config('shopee_mass_upload.account_key'))
            ->first();
        $lastSeen = $runtime?->worker_last_seen_at ? \Carbon\CarbonImmutable::parse($runtime->worker_last_seen_at) : null;
        if ($lastSeen?->isAfter(now()->subSeconds((int) config('shopee_mass_upload.local_worker_alive_seconds', 45)))) {
            return ['status' => 'already_running'];
        }

        $root = dirname(base_path());
        $script = $root.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'gitashop-mass-upload-worker'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'cli.js';
        if (! is_file($script) || ! function_exists('proc_open')) {
            return ['status' => 'manual_required'];
        }

        $logDirectory = storage_path('logs');
        if (! is_dir($logDirectory) && ! @mkdir($logDirectory, 0775, true) && ! is_dir($logDirectory)) {
            return ['status' => 'manual_required'];
        }

        $command = sprintf(
            '$env:GITASHOP_MASS_UPLOAD_TIMEOUT_SECONDS=%s; $env:GITASHOP_MASS_UPLOAD_POLL_SECONDS=%s; Start-Process -FilePath %s -ArgumentList @(%s) -WorkingDirectory %s -WindowStyle Hidden -RedirectStandardOutput %s -RedirectStandardError %s',
            $this->powerShellLiteral((string) config('shopee_mass_upload.local_worker_timeout_seconds', 300)),
            $this->powerShellLiteral((string) config('shopee_mass_upload.local_worker_poll_seconds', 5)),
            $this->powerShellLiteral((string) config('shopee_mass_upload.local_worker_node_binary', 'node')),
            $this->powerShellLiteral($script),
            $this->powerShellLiteral($root),
            $this->powerShellLiteral($logDirectory.DIRECTORY_SEPARATOR.'gitashop-mass-upload-worker.log'),
            $this->powerShellLiteral($logDirectory.DIRECTORY_SEPARATOR.'gitashop-mass-upload-worker-error.log'),
        );
        $encoded = base64_encode(mb_convert_encoding($command, 'UTF-16LE', 'UTF-8'));
        $process = @proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        if (! is_resource($process)) {
            return ['status' => 'manual_required'];
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);

        return ['status' => 'started'];
    }

    private function powerShellLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
