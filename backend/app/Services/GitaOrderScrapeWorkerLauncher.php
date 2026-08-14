<?php

namespace App\Services;

class GitaOrderScrapeWorkerLauncher
{
    public function __construct(private readonly GitaOrderScrapeWorkerLeaseService $workerLeaseService)
    {
    }

    public function wake(): array
    {
        if (! config('gita_order_scraper.local_worker_enabled', true)) {
            return ['status' => 'manual_required'];
        }

        $claim = $this->workerLeaseService->claim();
        if ($claim['status'] !== 'claimed') {
            return $claim;
        }

        $root = dirname(base_path());
        $script = $root.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'gita-order-scraper'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'cli.js';
        if (! is_file($script) || ! function_exists('proc_open')) {
            $this->workerLeaseService->release((string) $claim['token']);

            return ['status' => 'manual_required'];
        }

        $logDirectory = storage_path('logs');
        if (! is_dir($logDirectory) && ! @mkdir($logDirectory, 0775, true) && ! is_dir($logDirectory)) {
            $this->workerLeaseService->release((string) $claim['token']);

            return ['status' => 'manual_required'];
        }

        $command = sprintf(
            '$env:GITA_ORDER_SCRAPER_OPERATION_LEASE_TOKEN=%s; $env:GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS=%s; Start-Process -FilePath %s -ArgumentList @(%s) -WorkingDirectory %s -WindowStyle Hidden -RedirectStandardOutput %s -RedirectStandardError %s',
            $this->powerShellLiteral((string) $claim['token']),
            $this->powerShellLiteral((string) config('gita_order_scraper.worker_lease_seconds', 900)),
            $this->powerShellLiteral((string) config('gita_order_scraper.local_worker_node_binary', 'node')),
            $this->powerShellLiteral($script),
            $this->powerShellLiteral($root),
            $this->powerShellLiteral($logDirectory.DIRECTORY_SEPARATOR.'gita-order-scraper-worker.log'),
            $this->powerShellLiteral($logDirectory.DIRECTORY_SEPARATOR.'gita-order-scraper-worker-error.log'),
        );
        $encoded = base64_encode(mb_convert_encoding($command, 'UTF-16LE', 'UTF-8'));
        $process = @proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        if (! is_resource($process)) {
            $this->workerLeaseService->release((string) $claim['token']);

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
        return chr(39).str_replace(chr(39), chr(39).chr(39), $value).chr(39);
    }
}
