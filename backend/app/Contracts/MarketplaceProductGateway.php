<?php

namespace App\Contracts;

interface MarketplaceProductGateway
{
    public function publish(string $accountKey, string $channel, array $payload, string $idempotencyKey): array;
}