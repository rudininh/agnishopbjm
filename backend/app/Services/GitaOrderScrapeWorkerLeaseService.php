<?php

namespace App\Services;

class GitaOrderScrapeWorkerLeaseService
{
    private const OPERATION = 'gita_order_scrape';

    public function __construct(private readonly MarketplaceOperationLeaseService $marketplaceLease)
    {
    }

    public function claim(): array
    {
        $active = $this->marketplaceLease->status();
        if ($active['active']) {
            return $this->busyResult($active);
        }

        $lease = $this->marketplaceLease->acquire(self::OPERATION, $this->leaseSeconds());
        if ($lease['acquired']) {
            return [
                'status' => 'claimed',
                'token' => $lease['token'],
                'locked_until_at' => $lease['locked_until_at'],
                'operation' => self::OPERATION,
            ];
        }

        return $this->busyResult([
            'active' => true,
            'operation' => $lease['operation'] ?? null,
            'locked_until_at' => $lease['locked_until_at'] ?? null,
        ]);
    }

    public function renew(string $token): bool
    {
        return $this->marketplaceLease->renew($token, $this->leaseSeconds());
    }

    public function release(string $token): bool
    {
        return $this->marketplaceLease->release($token);
    }

    private function busyResult(array $lease): array
    {
        $operation = trim((string) ($lease['operation'] ?? ''));

        return [
            'status' => $operation === self::OPERATION ? 'already_running' : 'marketplace_busy',
            'operation' => $operation !== '' ? $operation : null,
            'locked_until_at' => $lease['locked_until_at'] ?? null,
        ];
    }

    private function leaseSeconds(): int
    {
        return (int) config('gita_order_scraper.worker_lease_seconds', 900);
    }
}
