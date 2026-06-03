<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MarketplaceOrderSyncService
{
    public function __construct(
        private readonly MarketplaceApiService $apiService,
        private readonly MarketplaceSyncService $syncService,
    ) {
    }

    public function processShopeeOrder(string $orderSn, string $eventType, array $payload = []): array
    {
        $orderSn = trim($orderSn);
        if ($orderSn === '') {
            return ['status' => 'error', 'message' => 'order_sn Shopee kosong.'];
        }

        $detail = $this->apiService->fetchShopeeOrderDetail($orderSn);
        if (($detail['status'] ?? '') !== 'success') {
            $this->syncService->logSync('shopee_order', 'tiktok', $orderSn, null, null, 'error', $detail['message'] ?? 'Detail order Shopee gagal diambil.');
            return $detail;
        }

        $order = $detail['order'];
        $orderStatus = (string) ($order['order_status'] ?? data_get($payload, 'data.status', 'UNKNOWN'));
        $items = $order['item_list'] ?? [];
        $results = [];
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach (is_array($items) ? $items : [] as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $modelId = (string) ($item['model_id'] ?? '');
            $sellerSku = trim((string) ($item['model_sku'] ?? $item['item_sku'] ?? ''));
            $mapping = $this->syncService->findSkuMappingByShopeeModel($itemId, $modelId, true)
                ?: ($sellerSku !== '' ? $this->syncService->findSkuMapping($sellerSku) : null);

            if (! $mapping) {
                $skipped++;
                $message = sprintf('Order %s item dilewati: SKU mapping tidak ditemukan untuk item_id=%s model_id=%s seller_sku=%s.', $orderSn, $itemId, $modelId, $sellerSku ?: '-');
                $this->syncService->logSync('shopee_order', 'tiktok', $sellerSku ?: $orderSn, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $result = $this->syncService->mirrorShopeeStockToTiktok($mapping, sprintf('Shopee order %s %s/%s', $orderSn, $eventType, $orderStatus), true, true);
            $results[] = $result;
            if (($result['status'] ?? '') === 'success') {
                $success++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        if ($items === []) {
            $this->syncService->logSync('shopee_order', 'tiktok', $orderSn, null, null, 'skipped', 'Detail order Shopee tidak memiliki item_list.');
            $skipped++;
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Order Shopee %s diproses. Success=%s skipped=%s failed=%s.', $orderSn, $success, $skipped, $failed),
            'order_sn' => $orderSn,
            'order_status' => $orderStatus,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    public function pollShopeeReadyOrders(int $hours = 24): array
    {
        $timeTo = time();
        $timeFrom = $timeTo - (max(1, $hours) * 3600);
        $statuses = ['PROCESSED', 'READY_TO_SHIP'];
        $seen = [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $messages = [];

        foreach ($statuses as $status) {
            $list = $this->apiService->fetchShopeeOrderSnList($timeFrom, $timeTo, $status);
            if (($list['status'] ?? '') !== 'success') {
                $failed++;
                $messages[] = $status.': '.($list['message'] ?? 'Order list Shopee gagal diambil.');
                continue;
            }

            foreach ($list['orders'] ?? [] as $order) {
                $orderSn = trim((string) ($order['order_sn'] ?? ''));
                if ($orderSn === '' || isset($seen[$orderSn])) {
                    continue;
                }
                $seen[$orderSn] = true;
                if ($this->alreadyProcessed('shopee_order', $orderSn, 'POLL_READY_ORDER', $orderSn)) {
                    $skipped++;
                    continue;
                }

                $result = $this->processShopeeOrder($orderSn, 'POLL_READY_ORDER', ['order_status' => $status]);
                $processed++;
                if (($result['status'] ?? '') === 'success') {
                    $success++;
                    $this->syncService->logSync('shopee_order', 'tiktok', $orderSn, null, null, 'success', sprintf('Shopee order %s POLL_READY_ORDER selesai.', $orderSn));
                } else {
                    $failed++;
                    $messages[] = $orderSn.': '.($result['message'] ?? 'gagal');
                }
            }
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Polling order Shopee selesai. Processed=%s success=%s skipped=%s failed=%s.', $processed, $success, $skipped, $failed),
            'processed' => $processed,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public function processTiktokOrder(string $orderId, string $eventType, array $payload = []): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['status' => 'error', 'message' => 'order_id TikTok kosong.'];
        }

        $detail = $this->apiService->fetchTiktokOrderDetail($orderId);
        if (($detail['status'] ?? '') !== 'success') {
            $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'error', $detail['message'] ?? 'Detail order TikTok gagal diambil.');
            return $detail;
        }

        $order = $detail['order'];
        $lineItems = data_get($order, 'line_items', data_get($order, 'items', []));
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach (is_array($lineItems) ? $lineItems : [] as $item) {
            $sellerSku = trim((string) ($item['seller_sku'] ?? data_get($item, 'sku.seller_sku', '')));
            $skuId = trim((string) ($item['sku_id'] ?? data_get($item, 'sku.id', '')));
            $mapping = $sellerSku !== '' ? $this->syncService->findSkuMapping($sellerSku) : null;

            if (! $mapping && $skuId !== '') {
                $mapping = $this->syncService->findSkuMapping($skuId);
            }

            if (! $mapping) {
                $skipped++;
                $message = sprintf('Order TikTok %s item dilewati: SKU mapping tidak ditemukan untuk seller_sku=%s sku_id=%s.', $orderId, $sellerSku ?: '-', $skuId ?: '-');
                $this->syncService->logSync('tiktok_order', 'shopee', $sellerSku ?: $orderId, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $canonicalSku = $this->syncService->canonicalSku($mapping, $sellerSku);
            if ($this->alreadyProcessed('tiktok_order', $orderId, $eventType, $canonicalSku)) {
                $skipped++;
                $message = sprintf('TikTok order %s %s SKU %s sudah pernah diproses, dilewati agar stok tidak berubah dua kali.', $orderId, $eventType, $canonicalSku);
                $this->syncService->logSync('tiktok_order', 'shopee', $canonicalSku, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $oldStock = $this->syncService->currentStockForMarketplace('tiktok', $mapping) ?? (int) ($mapping->stock_qty ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? data_get($item, 'sku.quantity', 1)));
            $newStock = $this->stockAfterOrderEvent($eventType, $oldStock, $qty);
            $this->syncService->updateLocalStock($mapping, 'tiktok', $newStock);
            $pushResult = $this->syncService->pushTargetStock($mapping, 'shopee', $newStock, true);
            $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
            $this->syncService->logSync('tiktok_order', 'shopee', $canonicalSku, $oldStock, $newStock, $status, sprintf('TikTok order %s %s: stok %s -> %s. %s', $orderId, $eventType, $oldStock, $newStock, $pushResult['message'] ?? '-'));
            $results[] = ['status' => $status, 'sku' => $canonicalSku];
            $status === 'success' ? $success++ : $failed++;
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Order TikTok %s diproses. Success=%s skipped=%s failed=%s.', $orderId, $success, $skipped, $failed),
            'order_id' => $orderId,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $results,
        ];
    }

    private function stockAfterOrderEvent(string $eventType, int $oldStock, int $qty): int
    {
        $event = strtoupper($eventType);
        if (str_contains($event, 'CANCEL') || str_contains($event, 'RETURN') || str_contains($event, 'REFUND')) {
            return $oldStock + max(0, $qty);
        }

        return max(0, $oldStock - max(0, $qty));
    }

    private function alreadyProcessed(string $source, string $orderId, string $eventType, string $sku): bool
    {
        return DB::table('marketplace_sync_logs')
            ->where('source_marketplace', $source)
            ->where('sku', $sku)
            ->where('status', 'success')
            ->where('message', 'like', '%'.$orderId.' '.$eventType.'%')
            ->exists();
    }
}
