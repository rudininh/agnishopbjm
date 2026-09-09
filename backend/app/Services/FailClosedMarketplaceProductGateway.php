<?php

namespace App\Services;

use App\Contracts\MarketplaceProductGateway;
use RuntimeException;

class FailClosedMarketplaceProductGateway implements MarketplaceProductGateway
{
    public function publish(string $accountKey, string $channel, array $payload, string $idempotencyKey): array
    {
        throw new RuntimeException('Adapter create-product live untuk '.$accountKey.' belum diaktifkan. Payload sudah disimpan untuk retry setelah mapping API final diverifikasi.');
    }
}