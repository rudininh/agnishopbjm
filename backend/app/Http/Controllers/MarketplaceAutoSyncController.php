<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceSyncService;
use App\Services\MarketplaceOrderSyncService;
use App\Services\MarketplaceApiService;
use App\Services\PdfWatermarkService;
use App\Services\StockConsistencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketplaceAutoSyncController extends Controller
{
    public function __construct(
        private readonly MarketplaceSyncService $syncService,
        private readonly MarketplaceOrderSyncService $orderSyncService,
        private readonly MarketplaceApiService $marketplaceApiService,
        private readonly StockConsistencyService $stockConsistencyService,
        private readonly PdfWatermarkService $pdfWatermarkService,
    ) {
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'data' => [
                ...$this->syncService->dashboard(),
                'safety' => $this->syncService->safetySummary(),
            ],
        ]);
    }

    public function webhookLogs(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->webhookLogs(
                $request->only(['marketplace', 'status', 'date']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function syncLogs(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->syncLogs(
                $request->only(['marketplace', 'status', 'date']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function safety(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'summary' => $this->syncService->safetySummary(),
            ...$this->syncService->safetyHistory(
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function orderSync(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->orderSyncHistory(
                $request->only(['type', 'status', 'date', 'search', 'order_class']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function shippingLabelOrders(Request $request): JsonResponse
    {
        $marketplace = strtolower(trim((string) $request->query('marketplace', 'all')));
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $mode = strtolower(trim((string) $request->query('mode', 'regular')));
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));

        abort_if(! in_array($marketplace, ['all', 'shopee', 'tiktok'], true), 422, 'Filter marketplace tidak valid.');
        abort_if(! in_array($status, ['all', 'success', 'skipped', 'error'], true), 422, 'Filter status tidak valid.');
        abort_if(! in_array($mode, ['regular', 'shopee_instant'], true), 422, 'Mode cetak resi tidak valid.');

        $query = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'tiktok_order'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1000);

        if ($marketplace !== 'all') {
            $query->where('source_marketplace', $marketplace.'_order');
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $items = collect($query->get())
            ->map(function ($row): ?array {
                $orderRef = $this->shippingLabelOrderRef($row);
                if ($orderRef === '') {
                    return null;
                }

                return [
                    'id' => (int) $row->id,
                    'marketplace' => $row->source_marketplace === 'tiktok_order' ? 'tiktok' : 'shopee',
                    'order_ref' => $orderRef,
                    'status' => (string) $row->status,
                    'message' => (string) ($row->message ?? ''),
                    'event_type' => $this->shippingLabelEventType($row),
                    'created_at' => (string) $row->created_at,
                    'target_marketplace' => (string) ($row->target_marketplace ?? ''),
                    'order_status' => '',
                    'shipping_carrier' => '',
                    'tracking_number' => '',
                ];
            })
            ->filter()
            ->unique(fn (array $row): string => $row['marketplace'].':'.$row['order_ref'])
            ->filter(fn (array $row): bool => $this->isPrintableShippingLabelEvent($row))
            ->when($search !== '', function ($rows) use ($search) {
                return $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['order_ref'].' '.$row['message'].' '.$row['order_status'].' '.$row['shipping_carrier']), $search));
            })
            ->values();

        if ($mode === 'shopee_instant') {
            $items = $this->shippingLabelShopeeInstantRows($items, $page, $perPage);
            $items = $this->withShippingLabelPrintStatus($items);
            $total = $items->count();

            return response()->json([
                'status' => 'ok',
                'summary' => [
                    'total' => $total,
                    'shopee' => $total,
                    'tiktok' => 0,
                    'success' => $items->where('status', 'success')->count(),
                ],
                'items' => $items->forPage($page, $perPage)->values(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
            ]);
        }

        $items = $this->shippingLabelUnshippedRows($items, $page, $perPage);
        $items = $this->withShippingLabelPrintStatus($items);
        $total = $items->count();

        return response()->json([
            'status' => 'ok',
            'summary' => [
                'total' => $total,
                'shopee' => $items->where('marketplace', 'shopee')->count(),
                'tiktok' => $items->where('marketplace', 'tiktok')->count(),
                'success' => $items->where('status', 'success')->count(),
            ],
            'items' => $items->forPage($page, $perPage)->values(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    private function printableShippingLabelOrder(array $row): ?array
    {
        $detail = $this->cachedMarketplaceOrderDetail($row['marketplace'], (string) $row['order_ref']);

        if (($detail['status'] ?? '') !== 'success') {
            return null;
        }

        $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
        $orderStatus = $row['marketplace'] === 'shopee'
            ? strtoupper((string) ($order['order_status'] ?? ''))
            : strtoupper((string) ($order['status'] ?? $order['order_status'] ?? data_get($order, 'line_items.0.display_status', '')));
        $address = $row['marketplace'] === 'shopee'
            ? data_get($order, 'recipient_address', [])
            : data_get($order, 'recipient_address', data_get($order, 'shipping_address', []));

        if (! $this->isUnshippedOrderStatus($row['marketplace'], $orderStatus)) {
            return null;
        }

        return [
            ...$row,
            'order_status' => $orderStatus,
            'marketplace_logistics_status' => $row['marketplace'] === 'shopee'
                ? (string) data_get($order, 'package_list.0.logistics_status', '')
                : (string) (
                    data_get($order, 'packages.0.status')
                    ?: data_get($order, 'fulfillment_status')
                    ?: data_get($order, 'line_items.0.display_status')
                    ?: ''
                ),
            'print_status' => $this->marketplaceShippingLabelStatus($row['marketplace'], $order),
            'shipping_carrier' => $row['marketplace'] === 'shopee'
                ? (string) ($order['shipping_carrier'] ?? data_get($order, 'package_list.0.shipping_carrier', ''))
                : (string) ($order['delivery_option_name'] ?? data_get($order, 'delivery_option.name', '')),
            'tracking_number' => $row['marketplace'] === 'shopee'
                ? (string) (data_get($order, 'package_list.0.tracking_number') ?: data_get($order, 'package_list.0.package_number') ?: '')
                : (string) ($order['tracking_number'] ?? data_get($order, 'packages.0.tracking_number', '')),
            'buyer_name' => $row['marketplace'] === 'shopee'
                ? (string) data_get($address, 'name', '-')
                : (string) (data_get($address, 'name') ?: data_get($address, 'recipient_name', '-')),
            'buyer_phone' => $row['marketplace'] === 'shopee'
                ? (string) data_get($address, 'phone', '')
                : (string) (data_get($address, 'phone_number') ?: data_get($address, 'phone', '')),
            'items' => $row['marketplace'] === 'shopee'
                ? $this->shopeeShippingLabelItems($order)
                : $this->tiktokShippingLabelItems($order),
        ];
    }

    private function shippingLabelShopeeInstantRows(Collection $rows, int $page, int $perPage): Collection
    {
        $shopeeRows = $rows
            ->where('marketplace', 'shopee')
            ->values();
        $scanLimit = min($shopeeRows->count(), max(100, $page * $perPage * 6));
        $scanRows = $shopeeRows->take($scanLimit)->values();
        $this->warmShopeeOrderDetailCache($scanRows);

        return $scanRows
            ->map(function (array $row): ?array {
                $cached = Cache::get($this->marketplaceOrderDetailCacheKey('shopee', (string) $row['order_ref']));
                if (is_array($cached) && ($cached['status'] ?? '') === 'success') {
                    $hydrated = $this->printableShippingLabelOrder($row);
                    if (! $hydrated || ! $this->isShopeeInstantCarrier($hydrated['shipping_carrier'] ?? '')) {
                        return null;
                    }

                    return $hydrated;
                }

                return null;
            })
            ->filter()
            ->values();
    }

    private function shippingLabelUnshippedRows(Collection $rows, int $page, int $perPage): Collection
    {
        $scanLimit = min($rows->count(), max(120, $page * $perPage * 6));
        $scanRows = $rows->take($scanLimit)->values();
        $this->warmShopeeOrderDetailCache($scanRows->where('marketplace', 'shopee')->values());

        return $scanRows
            ->map(fn (array $row): ?array => $this->printableShippingLabelOrder($row))
            ->filter()
            ->values();
    }

    private function withShippingLabelPrintStatus(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $keys = $rows
            ->map(fn (array $row): string => $row['marketplace'].':'.$row['order_ref'])
            ->values()
            ->all();

        $printRows = DB::table('marketplace_shipping_label_prints')
            ->select('marketplace', 'order_ref', DB::raw('count(*) as print_count'), DB::raw('max(printed_at) as last_printed_at'))
            ->where(function ($query) use ($rows): void {
                foreach ($rows as $row) {
                    $query->orWhere(function ($inner) use ($row): void {
                        $inner
                            ->where('marketplace', $row['marketplace'])
                            ->where('order_ref', $row['order_ref']);
                    });
                }
            })
            ->groupBy('marketplace', 'order_ref')
            ->get()
            ->keyBy(fn ($row): string => $row->marketplace.':'.$row->order_ref);

        return $rows->map(function (array $row) use ($printRows): array {
            $print = $printRows->get($row['marketplace'].':'.$row['order_ref']);
            $count = (int) ($print->print_count ?? 0);

            return [
                ...$row,
                'app_print_count' => $count,
                'app_print_status' => $count === 0 ? 'Belum print dari aplikasi' : 'Diprint dari aplikasi '.$count.'x',
                'app_last_printed_at' => $print->last_printed_at ?? null,
            ];
        });
    }

    private function marketplaceShippingLabelStatus(string $marketplace, array $order): string
    {
        if ($marketplace === 'shopee') {
            $orderStatus = strtoupper((string) ($order['order_status'] ?? ''));
            $logisticsStatus = strtoupper((string) data_get($order, 'package_list.0.logistics_status', ''));

            if (in_array($orderStatus, ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true)
                || str_contains($logisticsStatus, 'PICKUP_DONE')
                || str_contains($logisticsStatus, 'DELIVER')) {
                return 'Sudah dikirim di Shopee';
            }

            if ($logisticsStatus !== '') {
                return 'Shopee: '.$logisticsStatus;
            }

            return $orderStatus !== '' ? 'Shopee: '.$orderStatus : 'Status Shopee belum tersedia';
        }

        $orderStatus = strtoupper((string) ($order['status'] ?? $order['order_status'] ?? data_get($order, 'line_items.0.display_status', '')));
        $packageStatus = strtoupper((string) (
            data_get($order, 'packages.0.status')
            ?: data_get($order, 'fulfillment_status')
            ?: data_get($order, 'line_items.0.display_status')
            ?: ''
        ));

        if (str_contains($orderStatus, 'SHIPPED')
            || str_contains($orderStatus, 'DELIVER')
            || str_contains($packageStatus, 'SHIPPED')
            || str_contains($packageStatus, 'DELIVER')
            || str_contains($packageStatus, 'PICKUP')) {
            return 'Sudah dikirim di TikTok';
        }

        if ($packageStatus !== '') {
            return 'TikTok: '.$packageStatus;
        }

        return $orderStatus !== '' ? 'TikTok: '.$orderStatus : 'Status TikTok belum tersedia';
    }

    private function marketplaceOrderDetailCacheKey(string $marketplace, string $orderRef): string
    {
        return 'shipping-label-detail:'.$marketplace.':'.$orderRef;
    }

    private function cachedMarketplaceOrderDetail(string $marketplace, string $orderRef): array
    {
        return Cache::remember($this->marketplaceOrderDetailCacheKey($marketplace, $orderRef), now()->addMinutes(10), function () use ($marketplace, $orderRef): array {
            return $marketplace === 'shopee'
                ? $this->marketplaceApiService->fetchShopeeOrderDetail($orderRef)
                : $this->marketplaceApiService->fetchTiktokOrderDetail($orderRef);
        });
    }

    private function warmShopeeOrderDetailCache(Collection $rows): void
    {
        $missingOrderRefs = $rows
            ->pluck('order_ref')
            ->map(fn ($orderRef): string => trim((string) $orderRef))
            ->filter(fn (string $orderRef): bool => $orderRef !== '' && ! is_array(Cache::get($this->marketplaceOrderDetailCacheKey('shopee', $orderRef))))
            ->unique()
            ->values();

        if ($missingOrderRefs->isEmpty()) {
            return;
        }

        $result = $this->marketplaceApiService->fetchShopeeOrderDetails($missingOrderRefs->all());
        if (($result['status'] ?? '') !== 'success') {
            return;
        }

        $returnedOrderRefs = [];
        foreach ((array) ($result['orders'] ?? []) as $orderSn => $order) {
            if (! is_array($order)) {
                continue;
            }

            $returnedOrderRefs[(string) $orderSn] = true;
            Cache::put(
                $this->marketplaceOrderDetailCacheKey('shopee', (string) $orderSn),
                ['status' => 'success', 'order' => $order, 'response' => []],
                now()->addMinutes(10)
            );
        }

        foreach ($missingOrderRefs as $orderRef) {
            if (isset($returnedOrderRefs[$orderRef])) {
                continue;
            }

            Cache::put(
                $this->marketplaceOrderDetailCacheKey('shopee', $orderRef),
                ['status' => 'error', 'message' => 'Detail order Shopee tidak ditemukan pada batch terbaru.'],
                now()->addMinute()
            );
        }
    }

    private function shippingLabelEventType(object $row): string
    {
        $message = (string) ($row->message ?? '');
        if (preg_match('/(?:Shopee|TikTok) order [A-Z0-9_-]+ ([A-Z0-9_]+)/i', $message, $matches)
            || preg_match('/Order (?:Shopee|TikTok) [A-Z0-9_-]+ ([A-Z0-9_]+)/i', $message, $matches)) {
            return strtoupper((string) $matches[1]);
        }

        return '';
    }

    private function isPrintableShippingLabelEvent(array $row): bool
    {
        $eventType = strtoupper((string) ($row['event_type'] ?? ''));
        $message = strtoupper((string) ($row['message'] ?? ''));
        $text = trim($eventType.' '.$message);

        if ($text === '') {
            return true;
        }

        foreach (['CANCEL', 'RESTORE', 'RETURN', 'REFUND'] as $blocked) {
            if (str_contains($text, $blocked)) {
                return false;
            }
        }

        return true;
    }

    private function isShopeeInstantCarrier(string $carrier): bool
    {
        $carrier = mb_strtolower($carrier);

        return str_contains($carrier, 'instant')
            || str_contains($carrier, 'same day')
            || str_contains($carrier, 'sameday')
            || str_contains($carrier, 'grab')
            || str_contains($carrier, 'gojek')
            || str_contains($carrier, 'gosend');
    }

    private function isUnshippedOrderStatus(string $marketplace, string $status): bool
    {
        if ($status === '') {
            return false;
        }

        $shippedStatuses = $marketplace === 'shopee'
            ? ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED', 'CANCELLED', 'IN_CANCEL', 'TO_RETURN', 'RETURNED', 'RETURN_COMPLETED']
            : ['IN_TRANSIT', 'DELIVERED', 'COMPLETED', 'CANCELLED', 'CANCELED', 'RETURNED', 'REFUNDED', 'RETURN_COMPLETED'];

        return ! in_array($status, $shippedStatuses, true);
    }

    public function shippingLabelOrderDetail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketplace' => ['required', 'string', 'in:shopee,tiktok'],
            'order_ref' => ['required', 'string'],
        ]);

        $marketplace = strtolower(trim((string) $data['marketplace']));
        $orderRef = trim((string) $data['order_ref']);
        $detail = $this->cachedMarketplaceOrderDetail($marketplace, $orderRef);

        if (($detail['status'] ?? '') !== 'success') {
            return response()->json($detail, 422);
        }

        $order = $detail['order'] ?? [];

        return response()->json([
            'status' => 'ok',
            'label' => $marketplace === 'shopee'
                ? $this->normalizeShopeeShippingLabel($orderRef, is_array($order) ? $order : [])
                : $this->normalizeTiktokShippingLabel($orderRef, is_array($order) ? $order : []),
            'raw' => $order,
        ]);
    }

    public function shippingLabelOfficialDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketplace' => ['required', 'string', 'in:shopee,tiktok'],
            'order_ref' => ['required', 'string'],
            'document_type' => ['nullable', 'string'],
            'document_size' => ['nullable', 'string', 'in:A6,A5,A4'],
            'document_format' => ['nullable', 'string', 'in:PDF,ZPL'],
            'watermark_enabled' => ['nullable', 'boolean'],
            'watermark_text' => ['nullable', 'string', 'max:80'],
        ]);

        $marketplace = strtolower(trim((string) $data['marketplace']));
        $orderRef = trim((string) $data['order_ref']);
        $documentSize = strtoupper(trim((string) ($data['document_size'] ?? 'A6'))) ?: 'A6';

        $result = $marketplace === 'shopee'
            ? $this->marketplaceApiService->fetchShopeeOfficialShippingDocument(
                $orderRef,
                strtoupper(trim((string) ($data['document_type'] ?? ''))),
                $documentSize
            )
            : $this->marketplaceApiService->fetchTiktokOfficialShippingDocument(
                $orderRef,
                strtoupper(trim((string) ($data['document_type'] ?? 'SHIPPING_LABEL'))) ?: 'SHIPPING_LABEL',
                $documentSize,
                strtoupper(trim((string) ($data['document_format'] ?? 'PDF'))) ?: 'PDF'
            );

        if (($data['watermark_enabled'] ?? false) && ($result['status'] ?? '') === 'success' && is_array($result['document'] ?? null)) {
            $result['document'] = $this->pdfWatermarkService->addStampToDocument(
                $result['document'],
                trim((string) ($data['watermark_text'] ?? '')) ?: 'WAJIB VIDEO UNBOXING'
            );
        }

        return response()->json($result, in_array(($result['status'] ?? ''), ['success', 'pending'], true) ? 200 : 422);
    }

    public function markShippingLabelsPrinted(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.marketplace' => ['required', 'string', 'in:shopee,tiktok'],
            'items.*.order_ref' => ['required', 'string', 'max:80'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        $now = now();
        $rows = collect($data['items'])
            ->map(fn (array $item): array => [
                'marketplace' => strtolower(trim((string) $item['marketplace'])),
                'order_ref' => trim((string) $item['order_ref']),
                'document_type' => trim((string) ($data['document_type'] ?? 'shipping_label')) ?: 'shipping_label',
                'source' => trim((string) ($data['source'] ?? 'manual')) ?: 'manual',
                'printed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->filter(fn (array $item): bool => $item['order_ref'] !== '')
            ->values();

        if ($rows->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'Tidak ada order valid untuk dicatat print.'], 422);
        }

        DB::table('marketplace_shipping_label_prints')->insert($rows->all());

        return response()->json([
            'status' => 'success',
            'message' => sprintf('%s resi dicatat sudah diprint.', $rows->count()),
            'items' => $this->withShippingLabelPrintStatus($rows->map(fn (array $row): array => [
                'marketplace' => $row['marketplace'],
                'order_ref' => $row['order_ref'],
            ]))->values(),
        ]);
    }

    public function stockAnomalies(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->stockAnomalies(
                $request->only(['type', 'search']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 30)
            ),
        ]);
    }

    public function syncStockAnomaly(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'source_marketplace' => ['required', 'in:shopee,tiktok'],
        ]);

        $result = $this->syncService->syncStockAnomaly($data['sku'], $data['source_marketplace']);

        return response()->json($result, ($result['status'] ?? 'success') === 'error' ? 422 : 200);
    }

    public function refreshStockAnomalyProducts(Request $request): JsonResponse
    {
        set_time_limit(0);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.issue_type' => ['nullable', 'string'],
            'items.*.sku' => ['nullable', 'string'],
            'items.*.shopee_product_id' => ['nullable'],
            'items.*.tiktok_product_id' => ['nullable'],
        ]);

        $refs = collect($data['items'])
            ->reduce(function (array $carry, array $item): array {
                $issueType = (string) ($item['issue_type'] ?? '');
                $shopeeProductId = trim((string) ($item['shopee_product_id'] ?? ''));
                $tiktokProductId = trim((string) ($item['tiktok_product_id'] ?? ''));

                if (in_array($issueType, ['stock_mismatch', 'missing_shopee_stock'], true) && $shopeeProductId !== '') {
                    $carry['shopee_item_ids'][] = $shopeeProductId;
                }

                if (in_array($issueType, ['stock_mismatch', 'missing_tiktok_stock'], true) && $tiktokProductId !== '') {
                    $carry['tiktok_product_ids'][] = $tiktokProductId;
                }

                return $carry;
            }, ['shopee_item_ids' => [], 'tiktok_product_ids' => []]);

        $refs['shopee_item_ids'] = array_values(array_slice(array_unique($refs['shopee_item_ids']), 0, 30));
        $refs['tiktok_product_ids'] = array_values(array_slice(array_unique($refs['tiktok_product_ids']), 0, 30));

        if ($refs['shopee_item_ids'] === [] && $refs['tiktok_product_ids'] === []) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'Tidak ada produk marketplace spesifik yang bisa di-refresh dari anomali ini.',
                'refs' => $refs,
            ]);
        }

        $result = app(OmnichannelController::class)->syncMarketplaceProductCachesForOrder($refs);
        $this->syncService->logSync(
            'stock_anomaly_product_refresh',
            'marketplace_products',
            collect($data['items'])->pluck('sku')->filter()->unique()->take(5)->implode(','),
            null,
            null,
            ($result['status'] ?? '') === 'success' ? 'success' : 'warning',
            sprintf(
                'Auto refresh produk anomali: Shopee=%s TikTok=%s. %s',
                count($refs['shopee_item_ids']),
                count($refs['tiktok_product_ids']),
                $result['message'] ?? '-'
            )
        );

        return response()->json([
            ...$result,
            'refs' => $refs,
        ]);
    }

    private function shippingLabelOrderRef(object $row): string
    {
        if (preg_match('/(?:Shopee|TikTok) order ([A-Z0-9_-]+)/i', (string) ($row->message ?? ''), $matches)
            || preg_match('/Order (?:Shopee|TikTok) ([A-Z0-9_-]+)/i', (string) ($row->message ?? ''), $matches)) {
            return trim((string) $matches[1]);
        }

        $sku = trim((string) ($row->sku ?? ''));
        if ((string) ($row->source_marketplace ?? '') === 'shopee_order' && preg_match('/^\d[A-Z0-9]{11,19}$/i', $sku)) {
            return $sku;
        }
        if ((string) ($row->source_marketplace ?? '') === 'tiktok_order' && preg_match('/^\d{12,}$/', $sku)) {
            return $sku;
        }

        return '';
    }

    private function normalizeShopeeShippingLabel(string $orderRef, array $order): array
    {
        $address = data_get($order, 'recipient_address', []);
        $package = data_get($order, 'package_list.0', []);
        $items = $this->shopeeShippingLabelItems($order);

        return [
            'marketplace' => 'shopee',
            'order_ref' => $orderRef,
            'order_status' => (string) ($order['order_status'] ?? '-'),
            'shipping_carrier' => (string) ($order['shipping_carrier'] ?? data_get($package, 'shipping_carrier', '-')),
            'tracking_number' => (string) (data_get($package, 'tracking_number') ?: data_get($package, 'package_number') ?: ''),
            'buyer_name' => (string) data_get($address, 'name', '-'),
            'buyer_phone' => (string) data_get($address, 'phone', ''),
            'buyer_address' => (string) (data_get($address, 'full_address') ?: collect([
                data_get($address, 'town'),
                data_get($address, 'district'),
                data_get($address, 'city'),
                data_get($address, 'state'),
            ])->filter()->implode(', ')),
            'sender_name' => 'Agni Shop Banjarmasin',
            'items' => $items,
        ];
    }

    private function normalizeTiktokShippingLabel(string $orderRef, array $order): array
    {
        $address = data_get($order, 'recipient_address', data_get($order, 'shipping_address', []));
        $items = $this->tiktokShippingLabelItems($order);

        return [
            'marketplace' => 'tiktok',
            'order_ref' => $orderRef,
            'order_status' => (string) ($order['status'] ?? $order['order_status'] ?? '-'),
            'shipping_carrier' => (string) ($order['delivery_option_name'] ?? data_get($order, 'delivery_option.name', '-')),
            'tracking_number' => (string) ($order['tracking_number'] ?? data_get($order, 'packages.0.tracking_number', '')),
            'buyer_name' => (string) (data_get($address, 'name') ?: data_get($address, 'recipient_name', '-')),
            'buyer_phone' => (string) (data_get($address, 'phone_number') ?: data_get($address, 'phone', '')),
            'buyer_address' => (string) (data_get($address, 'full_address') ?: data_get($address, 'address_detail') ?: data_get($address, 'address_line1', '')),
            'sender_name' => 'Agni Shop Banjarmasin',
            'items' => $items,
        ];
    }

    private function shopeeShippingLabelItems(array $order): array
    {
        return collect(data_get($order, 'item_list', []))
            ->map(fn ($item): array => [
                'name' => (string) ($item['item_name'] ?? '-'),
                'sku' => (string) ($item['model_sku'] ?? $item['item_sku'] ?? '-'),
                'variant' => (string) ($item['model_name'] ?? '-'),
                'qty' => (int) ($item['model_quantity_purchased'] ?? $item['active_qty'] ?? 1),
                'image_url' => (string) (data_get($item, 'image_info.image_url') ?: data_get($item, 'image_url') ?: ''),
            ])
            ->values()
            ->all();
    }

    private function tiktokShippingLabelItems(array $order): array
    {
        return collect(data_get($order, 'line_items', data_get($order, 'items', [])))
            ->map(fn ($item): array => [
                'name' => (string) ($item['product_name'] ?? data_get($item, 'product.name', '-')),
                'sku' => (string) ($item['seller_sku'] ?? data_get($item, 'sku.seller_sku', '-')),
                'variant' => (string) ($item['sku_name'] ?? data_get($item, 'sku.name', '-')),
                'qty' => (int) ($item['quantity'] ?? data_get($item, 'sku.quantity', 1)),
                'image_url' => (string) (
                    data_get($item, 'sku_image')
                    ?: data_get($item, 'sku_image_url')
                    ?: data_get($item, 'product_image_url')
                    ?: data_get($item, 'product.image_url')
                    ?: data_get($item, 'image.url')
                    ?: data_get($item, 'image_url')
                    ?: ''
                ),
            ])
            ->values()
            ->all();
    }

    public function exportOrderSync(Request $request)
    {
        $rows = $this->syncService->orderSyncExportRows($request->only(['type', 'status', 'date', 'search', 'order_class']));
        $filename = 'order-sync-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['time', 'type', 'target', 'order_or_sku', 'old_stock', 'new_stock', 'status', 'message']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->created_at,
                    $row->source_marketplace,
                    $row->target_marketplace,
                    $row->sku,
                    $row->old_stock,
                    $row->new_stock,
                    $row->status,
                    $row->message,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function orderSyncDetail(int $id): JsonResponse
    {
        $detail = $this->syncService->orderSyncDetail($id);

        return response()->json($detail, ($detail['status'] ?? 'ok') === 'error' ? 404 : 200);
    }

    public function retryOrderSync(int $id): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $result = $this->orderSyncService->retryOrderSyncLog($id);

        return response()->json($result, ($result['status'] ?? 'success') === 'error' ? 422 : 200);
    }

    public function runSafetyCheck(): JsonResponse
    {
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->stockConsistencyService->run());
    }

    public function syncShopeeToTiktok(): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->syncService->syncShopeeStocksToTiktok(true));
    }

    public function instantCheck(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $marketplace = strtolower((string) $request->input('marketplace', 'all'));
        abort_if(! in_array($marketplace, ['all', 'shopee', 'tiktok'], true), 422, 'Marketplace instant check tidak valid.');

        $result = [
            'status' => 'success',
            'message' => 'Instant check order 1 jam terakhir selesai.',
            'shopee' => null,
            'tiktok' => null,
        ];

        if (in_array($marketplace, ['all', 'shopee'], true)) {
            $result['shopee'] = $this->orderSyncService->pollShopeeReadyOrders(1);
        }
        if (in_array($marketplace, ['all', 'tiktok'], true)) {
            $result['tiktok'] = $this->orderSyncService->pollTiktokUpdatedOrders(1);
        }

        $failed = (int) data_get($result, 'shopee.failed', 0) + (int) data_get($result, 'tiktok.failed', 0);
        if ($failed > 0) {
            $result['status'] = 'warning';
            $result['message'] = 'Instant check selesai dengan sebagian gagal.';
        }

        return response()->json($result);
    }

    public function retryOpenIssues(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        $limit = max(1, min(50, (int) $request->integer('limit', 10)));
        $logs = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'sku', 'source_marketplace', 'status']);

        $results = [];
        $success = 0;
        $failed = 0;
        foreach ($logs as $log) {
            try {
                $result = $this->orderSyncService->retryOrderSyncLog((int) $log->id);
            } catch (\Throwable $exception) {
                $result = ['status' => 'error', 'message' => $exception->getMessage()];
            }

            $isOk = ($result['status'] ?? '') !== 'error';
            $success += $isOk ? 1 : 0;
            $failed += $isOk ? 0 : 1;
            $results[] = [
                'id' => (int) $log->id,
                'sku' => $log->sku,
                'type' => $log->source_marketplace,
                'old_status' => $log->status,
                'status' => $result['status'] ?? 'unknown',
                'message' => $result['message'] ?? null,
            ];
        }

        return response()->json([
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => $logs->isEmpty()
                ? 'Tidak ada issue terbuka untuk di-retry.'
                : 'Retry issue terbuka selesai diproses.',
            'checked' => $logs->count(),
            'success' => $success,
            'failed' => $failed,
            'items' => $results,
        ]);
    }

    public function skuChangeHistory(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->skuChangeHistory(
                $request->only(['channel', 'status', 'date', 'search']),
                (int) $request->integer('page', 1),
                (int) $request->integer('per_page', 20)
            ),
        ]);
    }

    public function orderWatchdog(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->orderWatchdog(
                (int) $request->integer('minutes', 5),
                (int) $request->integer('hours', 24)
            ),
        ]);
    }

    public function reconciliationReport(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->reconciliationReport($request->only(['type', 'search'])),
        ]);
    }

    public function queueDashboard(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->syncService->syncQueueDashboard(
                (int) $request->integer('hours', 24),
                (int) $request->integer('limit', 50)
            ),
        ]);
    }

    public function bulkUpdateEmptySkus(Request $request): JsonResponse
    {
        set_time_limit(0);
        if (! $request->boolean('dry_run', false)) {
            app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
        }
        $limit = max(1, min(50, (int) $request->integer('limit', 20)));
        $dryRun = $request->boolean('dry_run', false);

        $skuMappingRequest = Request::create('/api/sku-mapping', 'GET', [
            'status' => 'all',
            'per_page' => 5000,
            'page' => 1,
            'compact' => 1,
        ]);
        $mappingPayload = app(OmnichannelController::class)->skuMapping($skuMappingRequest)->getData(true);
        $items = collect($mappingPayload['items'] ?? []);

        $candidates = $items->filter(function (array $item): bool {
            $templateSku = trim((string) ($item['template_sku'] ?? $item['seller_sku'] ?? $item['internal_sku'] ?? ''));
            $stockMasterId = (int) ($item['stock_master_id'] ?? 0);
            $shopee = $item['shopee'] ?? [];
            $tiktok = $item['tiktok'] ?? [];
            $hasMissingShopee = trim((string) ($shopee['item_id'] ?? '')) !== ''
                && trim((string) ($shopee['model_id'] ?? '')) !== ''
                && trim((string) ($shopee['seller_sku'] ?? '')) === '';
            $hasMissingTiktok = trim((string) ($tiktok['product_id'] ?? '')) !== ''
                && trim((string) ($tiktok['sku_id'] ?? '')) !== ''
                && trim((string) ($tiktok['seller_sku'] ?? '')) === '';

            return $stockMasterId > 0 && $templateSku !== '' && ($hasMissingShopee || $hasMissingTiktok);
        })->values();

        $results = [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        foreach ($candidates->take($limit) as $item) {
            $shopee = $item['shopee'] ?? [];
            $tiktok = $item['tiktok'] ?? [];
            $applyShopee = trim((string) ($shopee['item_id'] ?? '')) !== ''
                && trim((string) ($shopee['model_id'] ?? '')) !== ''
                && trim((string) ($shopee['seller_sku'] ?? '')) === '';
            $applyTiktok = trim((string) ($tiktok['product_id'] ?? '')) !== ''
                && trim((string) ($tiktok['sku_id'] ?? '')) !== ''
                && trim((string) ($tiktok['seller_sku'] ?? '')) === '';
            $templateSku = trim((string) ($item['template_sku'] ?? $item['seller_sku'] ?? $item['internal_sku'] ?? ''));

            $payload = [
                'stock_master_id' => (int) $item['stock_master_id'],
                'seller_sku' => $templateSku,
                'apply_shopee' => $applyShopee ? 'true' : 'false',
                'apply_tiktok' => $applyTiktok ? 'true' : 'false',
                'dry_run' => $dryRun ? 'true' : 'false',
            ];

            try {
                $response = app(OmnichannelController::class)
                    ->updateSkuMappingMarketplaceSku(Request::create('/api/sku-mapping/update-marketplace-sku', 'POST', $payload))
                    ->getData(true);
                $rowStatus = in_array(($response['status'] ?? ''), ['ok', 'success'], true) ? 'success' : ($response['status'] ?? 'warning');
            } catch (\Throwable $exception) {
                $response = ['status' => 'error', 'message' => $exception->getMessage()];
                $rowStatus = 'error';
            }

            $processed += 1;
            $isFailed = in_array($rowStatus, ['error', 'partial_error'], true);
            $success += $isFailed ? 0 : 1;
            $failed += $isFailed ? 1 : 0;
            $results[] = [
                'stock_master_id' => (int) $item['stock_master_id'],
                'product_name' => $item['product_name'] ?? null,
                'variant_name' => $item['variant_name'] ?? null,
                'seller_sku' => $templateSku,
                'apply_shopee' => $applyShopee,
                'apply_tiktok' => $applyTiktok,
                'status' => $rowStatus,
                'message' => $response['message'] ?? null,
            ];
        }

        return response()->json([
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => $dryRun ? 'Preview bulk update SKU kosong selesai.' : 'Bulk update SKU kosong selesai diproses.',
            'total_candidates' => $candidates->count(),
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'items' => $results,
        ]);
    }

    public function pollShopeeOrders(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->orderSyncService->pollShopeeReadyOrders(
            (int) $request->integer('hours', 24)
        ));
    }

    public function pollTiktokOrders(Request $request): JsonResponse
    {
        set_time_limit(0);
        app(OmnichannelController::class)->autoRefreshMarketplaceTokens();

        return response()->json($this->orderSyncService->pollTiktokUpdatedOrders(
            (int) $request->integer('hours', 24)
        ));
    }
}
