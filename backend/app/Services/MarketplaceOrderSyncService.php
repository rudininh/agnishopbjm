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
        $alreadyProcessed = 0;
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
                    $alreadyProcessed++;
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
            'message' => sprintf('Polling order Shopee selesai. Baru diproses=%s, berhasil=%s, sudah pernah diproses=%s, dilewati=%s, gagal=%s.', $processed, $success, $alreadyProcessed, $skipped, $failed),
            'processed' => $processed,
            'success' => $success,
            'already_processed' => $alreadyProcessed,
            'skipped' => $skipped,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public function pollTiktokUpdatedOrders(int $hours = 24): array
    {
        $timeTo = time();
        $timeFrom = $timeTo - (max(1, $hours) * 3600);
        $list = $this->apiService->fetchTiktokOrderList($timeFrom, $timeTo);
        if (($list['status'] ?? '') !== 'success') {
            return [
                'status' => 'warning',
                'message' => 'Polling order TikTok gagal: '.($list['message'] ?? 'Order list TikTok gagal diambil.'),
                'processed' => 0,
                'success' => 0,
                'already_processed' => 0,
                'skipped' => 0,
                'failed' => 1,
                'messages' => [$list['message'] ?? 'Order list TikTok gagal diambil.'],
            ];
        }

        $seen = [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $alreadyProcessed = 0;
        $messages = [];

        foreach ($list['orders'] ?? [] as $order) {
            $orderId = trim((string) ($order['id'] ?? $order['order_id'] ?? ''));
            if ($orderId === '' || isset($seen[$orderId])) {
                continue;
            }
            $seen[$orderId] = true;

            $stockEvent = $this->tiktokStockEventType('POLL_UPDATED_ORDER', is_array($order) ? $order : []);
            if ($stockEvent === null) {
                $skipped++;
                continue;
            }

            if ($this->alreadyProcessed('tiktok_order', $orderId, $stockEvent, $orderId)) {
                $alreadyProcessed++;
                continue;
            }

            $result = $this->processTiktokOrder($orderId, $stockEvent, ['poll_order' => $order]);
            $processed++;
            if (($result['status'] ?? '') === 'success') {
                $success++;
                $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'success', sprintf('TikTok order %s %s selesai.', $orderId, $stockEvent));
            } elseif (($result['status'] ?? '') === 'warning') {
                $failed++;
                $messages[] = $orderId.': '.($result['message'] ?? 'warning');
            } else {
                $failed++;
                $messages[] = $orderId.': '.($result['message'] ?? 'gagal');
            }
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Polling order TikTok selesai. Baru diproses=%s, berhasil=%s, sudah pernah diproses=%s, dilewati=%s, gagal=%s.', $processed, $success, $alreadyProcessed, $skipped, $failed),
            'processed' => $processed,
            'success' => $success,
            'already_processed' => $alreadyProcessed,
            'skipped' => $skipped,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public function retryOrderSyncLog(int $logId): array
    {
        $log = DB::table('marketplace_sync_logs')->where('id', $logId)->first();
        if (! $log) {
            return ['status' => 'error', 'message' => 'Log order sync tidak ditemukan.'];
        }

        $orderRef = $this->syncService->orderReferenceFromLog($log);
        if ($orderRef !== '' && in_array($log->source_marketplace, ['shopee_order', 'shopee_stock_refresh'], true)) {
            return $this->processShopeeOrder($orderRef, 'MANUAL_RETRY', ['retry_log_id' => $logId]);
        }

        if ($orderRef !== '' && $log->source_marketplace === 'tiktok_order') {
            return $this->processTiktokOrder($orderRef, 'MANUAL_RETRY', ['retry_log_id' => $logId]);
        }

        $mapping = trim((string) $log->sku) !== '' ? $this->syncService->findSkuMapping((string) $log->sku) : null;
        if (! $mapping) {
            return ['status' => 'error', 'message' => 'Retry gagal: order reference/SKU mapping tidak ditemukan.'];
        }

        return $this->syncService->mirrorShopeeStockToTiktok($mapping, 'Manual retry order sync log '.$logId, true, true);
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
        $stockEvent = $this->tiktokStockEventType($eventType, $order);
        if ($stockEvent === null) {
            $status = strtoupper((string) ($order['status'] ?? $order['order_status'] ?? data_get($payload, 'data.order_status', 'UNKNOWN')));
            $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'skipped', sprintf('TikTok order %s status %s belum mengubah stok, dilewati.', $orderId, $status));
            return [
                'status' => 'success',
                'message' => sprintf('Order TikTok %s dilewati karena status %s belum mengubah stok.', $orderId, $status),
                'order_id' => $orderId,
                'success' => 0,
                'skipped' => 1,
                'failed' => 0,
                'items' => [],
            ];
        }

        $lineItems = data_get($order, 'line_items', data_get($order, 'items', []));
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach (is_array($lineItems) ? $lineItems : [] as $item) {
            $sellerSku = $this->normalizeOrderSku($item['seller_sku'] ?? data_get($item, 'sku.seller_sku', ''));
            $skuId = trim((string) ($item['sku_id'] ?? data_get($item, 'sku.id', '')));
            $productId = trim((string) ($item['product_id'] ?? data_get($item, 'product.id', '')));
            $mapping = $this->syncService->findSkuMappingByTiktokOrderItem($productId, $skuId, $sellerSku);

            if (! $mapping) {
                $skipped++;
                $message = sprintf('Order TikTok %s item dilewati: SKU mapping tidak ditemukan untuk seller_sku=%s sku_id=%s.', $orderId, $sellerSku ?: '-', $skuId ?: '-');
                $this->syncService->logSync('tiktok_order', 'shopee', $sellerSku ?: $orderId, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $canonicalSku = $this->syncService->canonicalSku($mapping, $sellerSku);
            if ($this->alreadyProcessed('tiktok_order', $orderId, $stockEvent, $canonicalSku)) {
                $skipped++;
                $message = sprintf('TikTok order %s %s SKU %s sudah pernah diproses, dilewati agar stok tidak berubah dua kali.', $orderId, $stockEvent, $canonicalSku);
                $this->syncService->logSync('tiktok_order', 'shopee', $canonicalSku, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $oldStock = $this->syncService->currentStockForMarketplace('tiktok', $mapping) ?? (int) ($mapping->stock_qty ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? data_get($item, 'sku.quantity', 1)));
            $newStock = $this->stockAfterOrderEvent($stockEvent, $oldStock, $qty);
            $this->syncService->updateLocalStock($mapping, 'tiktok', $newStock);
            $pushResult = $this->syncService->pushTargetStock($mapping, 'shopee', $newStock, true);
            $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
            if ($status === 'success') {
                $this->syncService->updateLocalStock($mapping, 'shopee', $newStock);
            }
            $this->syncService->logSync('tiktok_order', 'shopee', $canonicalSku, $oldStock, $newStock, $status, sprintf('TikTok order %s %s: stok %s -> %s. %s', $orderId, $stockEvent, $oldStock, $newStock, $pushResult['message'] ?? '-'));
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

    private function normalizeOrderSku(mixed $value): string
    {
        $sku = trim((string) $value);

        return $sku === '-' ? '' : $sku;
    }

    private function tiktokStockEventType(string $eventType, array $order): ?string
    {
        $event = strtoupper($eventType);
        $orderStatus = strtoupper((string) ($order['status'] ?? $order['order_status'] ?? data_get($order, 'line_items.0.display_status', '')));

        if (
            str_contains($event, 'CANCEL')
            || str_contains($event, 'RETURN')
            || str_contains($event, 'REFUND')
            || in_array($orderStatus, ['CANCELLED', 'CANCELED', 'RETURNED', 'REFUNDED', 'RETURN_COMPLETED'], true)
        ) {
            return 'TIKTOK_RESTORE';
        }

        if (
            str_contains($event, 'PAID')
            || str_contains($event, 'ORDER_CREATED')
            || str_contains($event, 'READY')
            || in_array($orderStatus, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'PARTIALLY_SHIPPING', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true)
        ) {
            return 'TIKTOK_SALE';
        }

        return null;
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
