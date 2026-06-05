<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MarketplaceApiService
{
    private array $shopeeModelStockCache = [];

    public function fetchShopeeOfficialShippingDocument(string $orderSn, string $documentType = '', string $documentSize = 'A6'): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $detail = $this->fetchShopeeOrderDetail($orderSn);
        if (($detail['status'] ?? '') !== 'success') {
            return $detail;
        }

        $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
        $package = data_get($order, 'package_list.0', []);
        $packageNumber = (string) (data_get($package, 'package_number') ?: '');
        $trackingNumber = (string) (data_get($package, 'tracking_number') ?: '');

        $parameter = $this->shopeeSignedPost('/api/v2/logistics/get_shipping_document_parameter', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [[
                'order_sn' => $orderSn,
                ...array_filter(['package_number' => $packageNumber], fn ($value) => $value !== ''),
            ]],
        ]);

        $parameterRow = $this->firstShopeeShippingDocumentParameter($parameter);
        $resolvedDocumentType = $documentType !== ''
            ? $documentType
            : (string) ($parameterRow['suggest_shipping_document_type'] ?? data_get($parameterRow, 'selectable_shipping_document_type.0', 'THERMAL_AIR_WAYBILL'));
        $packageNumber = $packageNumber ?: (string) ($parameterRow['package_number'] ?? '');
        $trackingNumber = $trackingNumber ?: (string) ($parameterRow['tracking_number'] ?? '');

        $orderPayload = array_filter([
            'order_sn' => $orderSn,
            'package_number' => $packageNumber,
            'tracking_number' => $trackingNumber,
            'shipping_document_type' => $resolvedDocumentType,
        ], fn ($value) => $value !== null && $value !== '');

        $create = $this->shopeeSignedPost('/api/v2/logistics/create_shipping_document', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [$orderPayload],
        ]);

        if (($create['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $this->shopeeBatchFailMessage($create) ?: ($create['message'] ?? $create['error']), 'response' => $create, 'parameter' => $parameter];
        }

        $result = [];
        $ready = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($attempt > 0) {
                usleep(800000);
            }

            $result = $this->shopeeSignedPost('/api/v2/logistics/get_shipping_document_result', (int) $token->shop_id, (string) $token->access_token, [
                'order_list' => [$orderPayload],
            ]);

            if (($result['error'] ?? '') !== '') {
                return ['status' => 'error', 'message' => $this->shopeeBatchFailMessage($result) ?: ($result['message'] ?? $result['error']), 'response' => $result, 'create_response' => $create];
            }

            $ready = $this->shopeeShippingDocumentReady($result);
            if ($ready) {
                break;
            }
        }

        if (! $ready) {
            return [
                'status' => 'pending',
                'message' => 'Dokumen resmi Shopee masih dibuat oleh marketplace. Coba klik lagi beberapa detik lagi.',
                'create_response' => $create,
                'result_response' => $result,
                'parameter' => $parameter,
            ];
        }

        $download = $this->shopeeSignedPostRaw('/api/v2/logistics/download_shipping_document', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [$orderPayload],
        ]);

        return $this->normalizeOfficialDocumentResponse($download, 'shopee-'.$orderSn.'.pdf', [
            'create_response' => $create,
            'result_response' => $result,
            'parameter' => $parameter,
        ]);
    }

    public function fetchShopeeOrderDetail(string $orderSn): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $response = $this->shopeeSignedGet('/api/v2/order/get_order_detail', (int) $token->shop_id, (string) $token->access_token, [
            'order_sn_list' => $orderSn,
            'response_optional_fields' => 'order_status,item_list,recipient_address,package_list,shipping_carrier,update_time',
        ]);

        if (($response['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
        }

        $orders = data_get($response, 'response.order_list', []);
        $order = is_array($orders) ? ($orders[0] ?? null) : null;
        if (! is_array($order)) {
            return ['status' => 'error', 'message' => 'Detail order Shopee tidak ditemukan.', 'response' => $response];
        }

        return ['status' => 'success', 'order' => $order, 'response' => $response];
    }

    public function fetchShopeeOrderSnList(int $timeFrom, int $timeTo, ?string $orderStatus = null): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $cursor = '';
        $orders = [];
        do {
            $params = [
                'time_range_field' => 'update_time',
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'page_size' => 50,
            ];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }
            if ($orderStatus) {
                $params['order_status'] = $orderStatus;
            }

            $response = $this->shopeeSignedGet('/api/v2/order/get_order_list', (int) $token->shop_id, (string) $token->access_token, $params);
            if (($response['error'] ?? '') !== '') {
                return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
            }

            $orders = array_merge($orders, data_get($response, 'response.order_list', []));
            $more = (bool) data_get($response, 'response.more', false);
            $cursor = (string) data_get($response, 'response.next_cursor', '');
        } while ($more && $cursor !== '');

        return ['status' => 'success', 'orders' => $orders];
    }

    public function fetchShopeeModelStock(string $itemId, string $modelId): array
    {
        $cacheKey = (string) $itemId;
        if (isset($this->shopeeModelStockCache[$cacheKey])) {
            return $this->stockFromCachedShopeeModels($cacheKey, $modelId);
        }

        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $response = $this->shopeeSignedGet('/api/v2/product/get_model_list', (int) $token->shop_id, (string) $token->access_token, [
            'item_id' => (int) $itemId,
        ]);

        if (($response['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
        }

        $models = data_get($response, 'response.model', data_get($response, 'response.model_list', []));
        $this->shopeeModelStockCache[$cacheKey] = is_array($models) ? $models : [];

        return $this->stockFromCachedShopeeModels($cacheKey, $modelId, $response);
    }

    private function stockFromCachedShopeeModels(string $cacheKey, string $modelId, array $response = []): array
    {
        foreach ($this->shopeeModelStockCache[$cacheKey] ?? [] as $model) {
            if ((string) ($model['model_id'] ?? '') !== (string) $modelId) {
                continue;
            }

            return [
                'status' => 'success',
                'stock' => $this->shopeeModelStock($model),
                'model' => $model,
                'response' => $response,
            ];
        }

        return ['status' => 'error', 'message' => 'Model Shopee tidak ditemukan pada response get_model_list.', 'response' => $response];
    }

    public function fetchTiktokOrderDetail(string $orderId): array
    {
        $context = $this->activeTiktokContext();
        if (($context['status'] ?? '') !== 'success') {
            return $context;
        }

        $path = '/order/202309/orders';
        $query = [
            'app_key' => $context['config']['app_key'],
            'access_token' => $context['access_token'],
            'shop_cipher' => $context['shop_cipher'],
            'timestamp' => time(),
            'ids' => $orderId,
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $context['access_token'],
                'Accept' => 'application/json',
            ])
            ->get($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? ($payload['message'] ?? 'Detail order TikTok gagal diambil.') : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
                'response' => $payload,
            ];
        }

        $orders = data_get($payload, 'data.orders', []);
        $order = is_array($orders) ? ($orders[0] ?? null) : null;
        if (! is_array($order)) {
            $order = data_get($payload, 'data.order', data_get($payload, 'data'));
        }
        if (! is_array($order)) {
            return ['status' => 'error', 'message' => 'Detail order TikTok tidak ditemukan.', 'response' => $payload];
        }

        return ['status' => 'success', 'order' => $order, 'response' => $payload];
    }

    public function fetchTiktokOfficialShippingDocument(string $orderId, string $documentType = 'SHIPPING_LABEL', string $documentSize = 'A6', string $documentFormat = 'PDF'): array
    {
        $detail = $this->fetchTiktokOrderDetail($orderId);
        if (($detail['status'] ?? '') !== 'success') {
            return $detail;
        }

        $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
        $packageId = $this->tiktokPackageId($order);
        if ($packageId === '') {
            return [
                'status' => 'error',
                'message' => 'Package ID TikTok belum tersedia pada detail order. Dokumen resmi TikTok baru bisa diambil setelah paket dibuat/dikirim via TikTok Shipping.',
                'order' => $order,
            ];
        }

        $context = $this->activeTiktokContext();
        if (($context['status'] ?? '') !== 'success') {
            return $context;
        }

        $path = '/fulfillment/202309/packages/'.$packageId.'/shipping_documents';
        $query = [
            'app_key' => $context['config']['app_key'],
            'shop_cipher' => $context['shop_cipher'],
            'timestamp' => time(),
            'document_type' => $documentType,
            'document_size' => $documentSize,
            'document_format' => $documentFormat,
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $context['access_token'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? ($payload['message'] ?? 'Dokumen resmi TikTok gagal diambil.') : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
                'response' => $payload,
            ];
        }

        $url = $this->firstDocumentUrl($payload);
        if ($url !== '') {
            return [
                'status' => 'success',
                'document' => [
                    'source' => 'url',
                    'url' => $url,
                    'mime_type' => $documentFormat === 'PDF' ? 'application/pdf' : 'application/octet-stream',
                    'filename' => 'tiktok-'.$orderId.'.'.strtolower($documentFormat),
                ],
                'response' => $payload,
            ];
        }

        $base64 = $this->firstBase64Document($payload);
        if ($base64 !== '') {
            return [
                'status' => 'success',
                'document' => [
                    'source' => 'base64',
                    'content_base64' => $base64,
                    'mime_type' => $documentFormat === 'PDF' ? 'application/pdf' : 'application/octet-stream',
                    'filename' => 'tiktok-'.$orderId.'.'.strtolower($documentFormat),
                ],
                'response' => $payload,
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Response TikTok berhasil, tetapi URL/base64 dokumen tidak ditemukan.',
            'response' => $payload,
        ];
    }

    public function fetchTiktokOrderList(int $timeFrom, int $timeTo, ?string $orderStatus = null): array
    {
        $context = $this->activeTiktokContext();
        if (($context['status'] ?? '') !== 'success') {
            return $context;
        }

        $path = '/order/202309/orders/search';
        $pageToken = '';
        $orders = [];

        do {
            $query = [
                'app_key' => $context['config']['app_key'],
                'access_token' => $context['access_token'],
                'shop_cipher' => $context['shop_cipher'],
                'timestamp' => time(),
                'page_size' => 50,
                'sort_field' => 'update_time',
                'sort_order' => 'ASC',
            ];
            if ($pageToken !== '') {
                $query['page_token'] = $pageToken;
            }

            $body = [
                'update_time_ge' => $timeFrom,
                'update_time_lt' => $timeTo,
            ];
            if ($orderStatus) {
                $body['order_status'] = $orderStatus;
            }

            $bodyString = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret'], $bodyString);

            $response = Http::timeout(45)
                ->withHeaders([
                    'x-tts-access-token' => $context['access_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($bodyString, 'application/json')
                ->post($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $payload = $response->json();

            if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
                return [
                    'status' => 'error',
                    'message' => is_array($payload) ? ($payload['message'] ?? 'Order list TikTok gagal diambil.') : 'TikTok tidak mengembalikan JSON valid.',
                    'http_status' => $response->status(),
                    'response' => $payload,
                ];
            }

            $orders = array_merge($orders, data_get($payload, 'data.orders', []));
            $pageToken = (string) data_get($payload, 'data.next_page_token', '');
        } while ($pageToken !== '');

        return ['status' => 'success', 'orders' => $orders];
    }

    private function activeShopeeToken(): ?object
    {
        return DB::table('shopee_tokens')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
    }

    private function shopeeSignedGet(string $path, int $shopId, string $accessToken, array $params = []): array
    {
        $config = config('shopee');
        $timestamp = time();
        $query = [
            'partner_id' => (int) $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            ...$params,
        ];
        $query['sign'] = $this->generateShopeeApiSign((int) $config['partner_id'], (string) $config['partner_key'], $path, $timestamp, $accessToken, $shopId);

        $response = Http::timeout(45)
            ->acceptJson()
            ->get($config['host'].$path, $query);
        $data = $response->json();

        if (! is_array($data)) {
            return [
                'error' => 'invalid_json',
                'message' => 'Shopee tidak mengembalikan JSON valid.',
                '_http_status' => $response->status(),
                '_body' => $response->body(),
            ];
        }

        return [...$data, '_http_status' => $response->status()];
    }

    private function shopeeSignedPost(string $path, int $shopId, string $accessToken, array $body = []): array
    {
        $config = config('shopee');
        $timestamp = time();
        $query = [
            'partner_id' => (int) $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign((int) $config['partner_id'], (string) $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
        ];

        $response = Http::timeout(60)
            ->acceptJson()
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $body);
        $data = $response->json();

        if (is_array($data)) {
            return [...$data, '_http_status' => $response->status()];
        }

        return [
            'error' => 'invalid_json',
            'message' => 'Shopee tidak mengembalikan JSON valid.',
            '_http_status' => $response->status(),
            '_path' => $path,
            '_body' => $response->body(),
        ];
    }

    private function shopeeSignedPostRaw(string $path, int $shopId, string $accessToken, array $body = []): array
    {
        $config = config('shopee');
        $timestamp = time();
        $query = [
            'partner_id' => (int) $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign((int) $config['partner_id'], (string) $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
        ];

        $response = Http::timeout(60)
            ->accept('*/*')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return [
            'http_status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'json' => $response->json(),
        ];
    }

    private function activeTiktokContext(): array
    {
        $token = DB::table('tiktok_tokens')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
        $shop = DB::table('tiktok_shops')->orderByDesc('updated_at')->first();
        if (! $token || trim((string) $token->access_token) === '') {
            return ['status' => 'error', 'message' => 'Token TikTok aktif belum tersedia.'];
        }

        $shopCipher = trim((string) ($shop->cipher ?? $shop->shop_cipher ?? ''));
        if ($shopCipher === '') {
            return ['status' => 'error', 'message' => 'shop_cipher TikTok belum tersedia.'];
        }

        return [
            'status' => 'success',
            'access_token' => (string) $token->access_token,
            'shop_cipher' => $shopCipher,
            'config' => config('tiktok'),
        ];
    }

    private function generateShopeeApiSign(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
    }

    private function generateTiktokSign(string $path, array $params, string $secret, ?string $body = null): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $base = $secret.$path;
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }
        $base .= $body ?? '';
        $base .= $secret;

        return hash_hmac('sha256', $base, $secret);
    }

    private function firstShopeeShippingDocumentParameter(array $response): ?array
    {
        $candidates = [
            data_get($response, 'response.result_list.0'),
            data_get($response, 'response.result.0'),
            data_get($response, 'response.0'),
            data_get($response, 'result_list.0'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function shopeeShippingDocumentReady(array $response): bool
    {
        $rows = data_get($response, 'response.result_list', data_get($response, 'response.result', []));
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['status'] ?? $row['document_status'] ?? $row['shipping_document_status'] ?? ''));
            if (in_array($status, ['READY', 'SUCCESS', 'DONE', 'COMPLETED'], true)) {
                return true;
            }
        }

        return false;
    }

    private function shopeeBatchFailMessage(array $response): string
    {
        $rows = data_get($response, 'response.result_list', []);
        if (! is_array($rows)) {
            return '';
        }

        foreach ($rows as $row) {
            $message = trim((string) ($row['fail_message'] ?? ''));
            $error = trim((string) ($row['fail_error'] ?? ''));
            if ($message !== '') {
                return $error !== '' ? $error.': '.$message : $message;
            }
        }

        return '';
    }

    private function normalizeOfficialDocumentResponse(array $download, string $filename, array $meta = []): array
    {
        $json = $download['json'] ?? null;
        if (is_array($json) && (($json['error'] ?? '') !== '')) {
            return ['status' => 'error', 'message' => $json['message'] ?? $json['error'], 'response' => $json, ...$meta];
        }

        if (is_array($json)) {
            $url = $this->firstDocumentUrl($json);
            if ($url !== '') {
                return ['status' => 'success', 'document' => ['source' => 'url', 'url' => $url, 'mime_type' => 'application/pdf', 'filename' => $filename], 'response' => $json, ...$meta];
            }

            $base64 = $this->firstBase64Document($json);
            if ($base64 !== '') {
                return ['status' => 'success', 'document' => ['source' => 'base64', 'content_base64' => $base64, 'mime_type' => 'application/pdf', 'filename' => $filename], 'response' => $json, ...$meta];
            }
        }

        $body = (string) ($download['body'] ?? '');
        if ($body !== '') {
            return [
                'status' => 'success',
                'document' => [
                    'source' => 'base64',
                    'content_base64' => base64_encode($body),
                    'mime_type' => $this->responseMimeType($download['headers'] ?? []),
                    'filename' => $filename,
                ],
                ...$meta,
            ];
        }

        return ['status' => 'error', 'message' => 'Dokumen resmi berhasil diproses, tetapi file dokumen kosong.', ...$meta];
    }

    private function responseMimeType(array $headers): string
    {
        $contentType = $headers['Content-Type'][0] ?? $headers['content-type'][0] ?? '';
        return trim(explode(';', (string) $contentType)[0]) ?: 'application/pdf';
    }

    private function firstDocumentUrl(array $payload): string
    {
        foreach (['document_url', 'shipping_document_url', 'url', 'file_url', 'download_url'] as $key) {
            $value = data_get($payload, 'data.'.$key, data_get($payload, 'response.'.$key, data_get($payload, $key)));
            if (is_string($value) && str_starts_with($value, 'http')) {
                return $value;
            }
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($payload));
        foreach ($iterator as $value) {
            if (is_string($value) && str_starts_with($value, 'http') && preg_match('/(pdf|document|label|shipping)/i', $value)) {
                return $value;
            }
        }

        return '';
    }

    private function firstBase64Document(array $payload): string
    {
        foreach (['document', 'file', 'content', 'pdf', 'shipping_document'] as $key) {
            $value = data_get($payload, 'data.'.$key, data_get($payload, 'response.'.$key, data_get($payload, $key)));
            if (is_string($value) && strlen($value) > 100) {
                return preg_replace('/^data:[^;]+;base64,/', '', $value);
            }
        }

        return '';
    }

    private function tiktokPackageId(array $order): string
    {
        foreach ([
            'packages.0.id',
            'packages.0.package_id',
            'package_list.0.id',
            'package_list.0.package_id',
            'package_id',
            'fulfillment_packages.0.id',
        ] as $path) {
            $value = trim((string) data_get($order, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function shopeeModelStock(array $model): int
    {
        $stockInfo = $model['stock_info_v2'] ?? $model['stock_info'] ?? null;
        if (is_array($stockInfo)) {
            foreach (['seller_stock', 'summary_info'] as $key) {
                $value = $stockInfo[$key] ?? null;
                if (is_array($value)) {
                    if (isset($value['total_available_stock'])) {
                        return (int) $value['total_available_stock'];
                    }
                    if (isset($value[0]['stock'])) {
                        return (int) $value[0]['stock'];
                    }
                }
            }
        }

        return (int) ($model['stock'] ?? $model['normal_stock'] ?? 0);
    }
}
