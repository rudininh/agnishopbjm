<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MarketplaceApiService
{
    private array $shopeeModelStockCache = [];

    public function fetchShopeeOrderDetail(string $orderSn): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $response = $this->shopeeSignedGet('/api/v2/order/get_order_detail', (int) $token->shop_id, (string) $token->access_token, [
            'order_sn_list' => $orderSn,
            'response_optional_fields' => 'order_status,item_list,update_time',
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

        $config = config('tiktok');
        $path = '/order/202309/orders/'.$orderId;
        $query = [
            'app_key' => $config['app_key'],
            'access_token' => (string) $token->access_token,
            'shop_cipher' => $shopCipher,
            'timestamp' => time(),
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $config['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => (string) $token->access_token,
                'Accept' => 'application/json',
            ])
            ->get($config['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? ($payload['message'] ?? 'Detail order TikTok gagal diambil.') : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
                'response' => $payload,
            ];
        }

        return ['status' => 'success', 'order' => data_get($payload, 'data.order', data_get($payload, 'data')), 'response' => $payload];
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
