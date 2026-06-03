<?php

namespace App\Services;

use Illuminate\Http\Request;

class TikTokWebhookService
{
    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function handle(Request $request): array
    {
        $payload = $request->all();
        if (! $this->signatureIsValid($request)) {
            $this->syncService->logWebhook('tiktok', $this->eventType($payload), $this->sku($payload), $this->qty($payload), $payload, 'error', 'Signature webhook TikTok tidak valid.');
            return ['status' => 'error', 'message' => 'Signature webhook TikTok tidak valid.'];
        }

        $eventType = $this->eventType($payload);
        $sku = $this->sku($payload);
        $qty = $this->qty($payload);

        if ($sku === '') {
            $this->syncService->logWebhook('tiktok', $eventType, null, $qty, $payload, 'error', 'SKU tidak ditemukan pada payload webhook.');
            return ['status' => 'error', 'message' => 'SKU tidak ditemukan pada payload webhook.'];
        }

        $result = $this->syncService->processMarketplaceStockChange('tiktok', $eventType, $sku, $qty, $payload);
        $this->syncService->logWebhook('tiktok', $eventType, $sku, $qty, $payload, $result['status'], $result['message'] ?? null);

        return $result;
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('tiktok.app_secret', '');
        $signature = (string) ($request->header('x-tts-signature') ?: $request->header('x-tiktok-signature'));
        if ($secret === '' || $signature === '') {
            return true;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }

    private function eventType(array $payload): string
    {
        return (string) ($payload['event_type'] ?? $payload['type'] ?? 'UNKNOWN');
    }

    private function sku(array $payload): string
    {
        $candidates = [
            $payload['sku'] ?? null,
            $payload['seller_sku'] ?? null,
            data_get($payload, 'data.sku'),
            data_get($payload, 'data.seller_sku'),
            data_get($payload, 'data.line_items.0.seller_sku'),
            data_get($payload, 'data.skus.0.seller_sku'),
            data_get($payload, 'line_items.0.seller_sku'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function qty(array $payload): int
    {
        foreach (['qty', 'quantity', 'amount'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return max(1, (int) data_get($payload, 'data.line_items.0.quantity', 1));
    }
}
