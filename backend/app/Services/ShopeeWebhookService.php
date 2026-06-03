<?php

namespace App\Services;

use Illuminate\Http\Request;

class ShopeeWebhookService
{
    public function __construct(
        private readonly MarketplaceSyncService $syncService,
        private readonly MarketplaceOrderSyncService $orderSyncService,
    ) {
    }

    public function handle(Request $request): array
    {
        $payload = $request->all();
        if (! $this->signatureIsValid($request)) {
            $this->syncService->logWebhook('shopee', $this->eventType($payload), $this->sku($payload), $this->qty($payload), $payload, 'error', 'Signature webhook Shopee tidak valid.');
            return ['status' => 'error', 'message' => 'Signature webhook Shopee tidak valid.'];
        }

        $eventType = $this->eventType($payload);
        $orderSn = $this->orderSn($payload);
        if ($orderSn !== '') {
            $result = $this->orderSyncService->processShopeeOrder($orderSn, $eventType, $payload);
            $this->syncService->logWebhook('shopee', $eventType, $orderSn, null, $payload, ($result['status'] ?? '') === 'error' ? 'error' : 'success', $result['message'] ?? null);

            return $result;
        }

        $sku = $this->sku($payload);
        $qty = $this->qty($payload);

        if ($sku === '') {
            $this->syncService->logWebhook('shopee', $eventType, null, $qty, $payload, 'error', 'SKU tidak ditemukan pada payload webhook.');
            return ['status' => 'error', 'message' => 'SKU tidak ditemukan pada payload webhook.'];
        }

        $result = $this->syncService->processMarketplaceStockChange('shopee', $eventType, $sku, $qty, $payload);
        $this->syncService->logWebhook('shopee', $eventType, $sku, $qty, $payload, $result['status'], $result['message'] ?? null);

        return $result;
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('shopee.partner_key', '');
        $signature = (string) ($request->header('x-shopee-signature') ?: $request->header('authorization'));
        if ($secret === '' || $signature === '') {
            return true;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }

    private function eventType(array $payload): string
    {
        return (string) ($payload['event_type'] ?? $payload['event'] ?? $payload['code'] ?? 'UNKNOWN');
    }

    private function sku(array $payload): string
    {
        $candidates = [
            $payload['sku'] ?? null,
            $payload['seller_sku'] ?? null,
            data_get($payload, 'data.sku'),
            data_get($payload, 'data.seller_sku'),
            data_get($payload, 'data.items.0.seller_sku'),
            data_get($payload, 'data.item_list.0.model_sku'),
            data_get($payload, 'items.0.seller_sku'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function orderSn(array $payload): string
    {
        $candidates = [
            $payload['order_sn'] ?? null,
            $payload['ordersn'] ?? null,
            data_get($payload, 'data.order_sn'),
            data_get($payload, 'data.ordersn'),
            data_get($payload, 'data.order_list.0.order_sn'),
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

        return max(1, (int) data_get($payload, 'data.items.0.quantity', 1));
    }
}
