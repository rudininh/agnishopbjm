<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShopeeSkuTiktokVariantCleanupService
{
    private const ACTION_TYPE = 'shopee_sku_tiktok_delete';

    private const SECRET_KEYS = [
        'access_token',
        'app_secret',
        'sign',
        'shop_cipher',
        'authorization',
    ];

    public function __construct(
        private readonly ShopeeSellerSkuTemplate $sellerSkuTemplate,
        private readonly MarketplaceApiService $marketplaceApi,
    ) {
    }

    public function createPreview(Collection $mappingOnlyGroups): array
    {
        $preview = $this->buildPreview($mappingOnlyGroups);
        $runId = (string) Str::uuid();

        DB::transaction(function () use ($runId, $preview): void {
            DB::table('tiktok_reconciliation_runs')->insert([
                'id' => $runId,
                'revision' => $preview['revision'],
                'status' => 'ready_for_review',
                'summary' => $this->encodeJson($this->redact($preview['summary'])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($preview['items'] as $item) {
                $isReady = $item['status'] === 'ready';
                DB::table('tiktok_reconciliation_run_items')->insert([
                    'run_id' => $runId,
                    'stock_master_id' => $item['stock_master_ids'][0] ?? null,
                    'item_key' => $item['item_key'],
                    'action_type' => self::ACTION_TYPE,
                    'status' => $item['status'],
                    'source_fingerprint' => $item['source_fingerprint'],
                    'target_product_id' => $isReady ? $item['tiktok_product_id'] : null,
                    'target_sku_id' => $isReady ? $item['tiktok_sku_id'] : null,
                    'block_reason' => $item['block_reason'],
                    'payload' => $this->encodeJson($this->redact($item)),
                    'result' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->loadRun($runId);
    }

    public function loadRun(string $runId): array
    {
        $run = DB::table('tiktok_reconciliation_runs')->where('id', $runId)->first();
        if (! $run) {
            return ['status' => 'not_found', 'run_id' => $runId];
        }

        $items = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', self::ACTION_TYPE)
            ->orderBy('item_key')
            ->get()
            ->map(function (object $row): array {
                $item = $this->decodeJson($row->payload) ?? [];
                $item['item_key'] = (string) $row->item_key;
                $item['action_type'] = self::ACTION_TYPE;
                $item['status'] = (string) $row->status;
                $item['source_fingerprint'] = (string) $row->source_fingerprint;
                $item['block_reason'] = $row->block_reason === null ? null : (string) $row->block_reason;
                $item['stock_master_ids'] = $item['stock_master_ids']
                    ?? array_values(array_filter([(int) ($row->stock_master_id ?? 0)]));
                if ($row->result !== null) {
                    $item['result'] = $this->decodeJson($row->result);
                }

                return $item;
            })
            ->all();

        return [
            'status' => (string) $run->status,
            'run_id' => $runId,
            'revision' => (string) $run->revision,
            'summary' => $this->decodeJson($run->summary) ?? [],
            'items' => $items,
        ];
    }

    public function currentRevision(Collection $mappingOnlyGroups): string
    {
        return $this->buildPreview($mappingOnlyGroups)['revision'];
    }

    private function buildPreview(Collection $mappingOnlyGroups): array
    {
        $itemsByKey = [];
        foreach ($mappingOnlyGroups as $group) {
            $group = $this->arrayValue($group);
            $productId = $this->stringValue($group['tiktok_product_id'] ?? '');
            foreach (collect($group['mapping_only_variants'] ?? []) as $variant) {
                $variant = $this->arrayValue($variant);
                $item = $this->buildItem($variant, $productId);
                $itemsByKey[$item['item_key']] = $item;
            }
        }

        ksort($itemsByKey, SORT_STRING);
        $items = array_values($itemsByKey);
        $this->blockProductsWithoutSurvivors($items);

        $revisionItems = array_map(
            fn (array $item): array => [
                'item_key' => $item['item_key'],
                'status' => $item['status'],
                'block_reason' => $item['block_reason'],
                'source_fingerprint' => $item['source_fingerprint'],
                'target_sku' => $item['target_sku'],
            ],
            $items,
        );

        return [
            'revision' => hash('sha256', $this->canonicalJson($revisionItems)),
            'summary' => [
                'products' => count(array_unique(array_filter(array_column($items, 'tiktok_product_id')))),
                'conflicts' => count($items),
                'eligible' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'ready')),
                'unchanged' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'unchanged')),
                'blocked' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'blocked')),
            ],
            'items' => $items,
        ];
    }

    private function buildItem(array $variant, string $groupProductId): array
    {
        $itemId = $this->stringValue($variant['shopee_item_id'] ?? '');
        $modelId = $this->stringValue($variant['shopee_model_id'] ?? '');
        $productId = $this->stringValue($variant['tiktok_product_id'] ?? $groupProductId);
        $tiktokSkuId = $this->stringValue($variant['tiktok_sku_id'] ?? '');
        $itemKey = "sku-cleanup:{$itemId}:{$modelId}:{$productId}:{$tiktokSkuId}";

        $model = $itemId === '' || $modelId === ''
            ? null
            : DB::table('shopee_product_model')->where('item_id', $itemId)->where('model_id', $modelId)->first();
        $tiktokSku = $productId === '' || $tiktokSkuId === ''
            ? null
            : DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->where('sku_id', $tiktokSkuId)
                ->whereRaw('COALESCE(is_active, true) = true')
                ->first();

        $currentName = $this->stringValue($model->name ?? '');
        $oldSku = $this->stringValue($model->model_sku ?? '');
        $tiktokName = $this->stringValue($tiktokSku->sku_name ?? '');
        $tiktokSellerSku = $this->stringValue($tiktokSku->seller_sku ?? '');
        $targetSku = '';
        $blockReason = null;

        if ($itemId === '' || $modelId === '' || $productId === '' || $tiktokSkuId === '' || ! $model || ! $tiktokSku) {
            $blockReason = 'incomplete_identity';
        } else {
            try {
                $targetSku = $this->sellerSkuTemplate->build($itemId, $currentName);
            } catch (\InvalidArgumentException) {
                $blockReason = 'invalid_target_sku';
            }
        }
        if ($blockReason === null && ($oldSku === '' || $tiktokSellerSku === '')) {
            $blockReason = 'incomplete_identity';
        }

        $status = 'ready';
        if ($blockReason !== null) {
            $status = 'blocked';
        } elseif ($this->normalizedSku($oldSku) === $this->normalizedSku($targetSku)) {
            $status = 'unchanged';
        } elseif ($this->normalizedSku($oldSku) !== $this->normalizedSku($tiktokSellerSku)) {
            $status = 'blocked';
            $blockReason = 'source_sku_mismatch';
        } elseif ($this->normalizedName($currentName) === $this->normalizedName($tiktokName)) {
            $status = 'blocked';
            $blockReason = 'variant_names_match';
        } elseif ($this->hasShopeeTargetCollision($itemId, $modelId, $targetSku)) {
            $status = 'blocked';
            $blockReason = 'shopee_target_collision';
        } elseif ($this->hasTiktokTargetCollision($productId, $tiktokSkuId, $targetSku)) {
            $status = 'blocked';
            $blockReason = 'tiktok_target_collision';
        }

        $stockMasterIds = $this->stockMasterIds($itemId, $modelId, $productId, $tiktokSkuId);
        $source = [
            'identity' => compact('itemId', 'modelId', 'productId', 'tiktokSkuId'),
            'shopee_model' => $model ? [
                'item_id' => $this->stringValue($model->item_id ?? ''),
                'model_id' => $this->stringValue($model->model_id ?? ''),
                'name' => $currentName,
                'model_sku' => $oldSku,
            ] : null,
            'tiktok_sku' => $tiktokSku ? [
                'product_id' => $this->stringValue($tiktokSku->product_id ?? ''),
                'sku_id' => $this->stringValue($tiktokSku->sku_id ?? ''),
                'seller_sku' => $tiktokSellerSku,
                'sku_name' => $tiktokName,
                'is_active' => (bool) ($tiktokSku->is_active ?? false),
            ] : null,
            'same_item_models' => $this->shopeeSourceRows($itemId),
            'same_product_active_skus' => $this->tiktokSourceRows($productId),
            'stock_master_ids' => $stockMasterIds,
            'target_sku' => $targetSku,
        ];

        return [
            'item_key' => $itemKey,
            'action_type' => self::ACTION_TYPE,
            'status' => $status,
            'source_fingerprint' => hash('sha256', $this->canonicalJson($source)),
            'stock_master_ids' => $stockMasterIds,
            'shopee_item_id' => $itemId,
            'shopee_model_id' => $modelId,
            'shopee_variant_name' => $currentName,
            'old_sku' => $oldSku,
            'target_sku' => $targetSku,
            'tiktok_product_id' => $productId,
            'tiktok_sku_id' => $tiktokSkuId,
            'tiktok_variant_name' => $tiktokName,
            'block_reason' => $blockReason,
        ];
    }

    private function blockProductsWithoutSurvivors(array &$items): void
    {
        $readyIdsByProduct = [];
        foreach ($items as $item) {
            if ($item['status'] === 'ready') {
                $readyIdsByProduct[$item['tiktok_product_id']][] = $item['tiktok_sku_id'];
            }
        }

        foreach ($readyIdsByProduct as $productId => $targetSkuIds) {
            $activeSkuIds = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->whereRaw('COALESCE(is_active, true) = true')
                ->pluck('sku_id')
                ->map(fn (mixed $skuId): string => $this->stringValue($skuId))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if (count(array_diff($activeSkuIds, array_unique($targetSkuIds))) > 0) {
                continue;
            }

            foreach ($items as &$item) {
                if ($item['tiktok_product_id'] === $productId && $item['status'] === 'ready') {
                    $item['status'] = 'blocked';
                    $item['block_reason'] = 'last_tiktok_variant';
                }
            }
            unset($item);
        }
    }

    private function hasShopeeTargetCollision(string $itemId, string $modelId, string $targetSku): bool
    {
        return DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_id', '!=', $modelId)
            ->get()
            ->contains(fn (object $row): bool => $this->normalizedSku($row->model_sku ?? '') === $this->normalizedSku($targetSku));
    }

    private function hasTiktokTargetCollision(string $productId, string $tiktokSkuId, string $targetSku): bool
    {
        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where('sku_id', '!=', $tiktokSkuId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->get()
            ->contains(fn (object $row): bool => $this->normalizedSku($row->seller_sku ?? '') === $this->normalizedSku($targetSku));
    }

    private function stockMasterIds(string $itemId, string $modelId, string $productId, string $tiktokSkuId): array
    {
        if ($itemId === '' || $modelId === '' || $productId === '' || $tiktokSkuId === '') {
            return [];
        }

        $mappingIds = DB::table('sku_mappings')
            ->where('shopee_item_id', $itemId)
            ->where('shopee_model_id', $modelId)
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku_id', $tiktokSkuId)
            ->pluck('stock_master_id');
        $stockIds = DB::table('stock_master')
            ->where('shopee_product_id', $itemId)
            ->where('shopee_sku', $modelId)
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku', $tiktokSkuId)
            ->pluck('id');

        return $mappingIds
            ->merge($stockIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function shopeeSourceRows(string $itemId): array
    {
        if ($itemId === '') {
            return [];
        }

        return DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->get()
            ->map(fn (object $row): array => [
                'item_id' => $this->stringValue($row->item_id ?? ''),
                'model_id' => $this->stringValue($row->model_id ?? ''),
                'name' => $this->stringValue($row->name ?? ''),
                'model_sku' => $this->stringValue($row->model_sku ?? ''),
            ])
            ->all();
    }

    private function tiktokSourceRows(string $productId): array
    {
        if ($productId === '') {
            return [];
        }

        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => $this->stringValue($row->product_id ?? ''),
                'sku_id' => $this->stringValue($row->sku_id ?? ''),
                'seller_sku' => $this->stringValue($row->seller_sku ?? ''),
                'sku_name' => $this->stringValue($row->sku_name ?? ''),
                'is_active' => (bool) ($row->is_active ?? false),
            ])
            ->all();
    }

    private function normalizedSku(mixed $value): string
    {
        return strtoupper($this->stringValue($value));
    }

    private function normalizedName(mixed $value): string
    {
        $normalized = strtolower($this->stringValue($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : (array) $value;
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                continue;
            }
            $redacted[$key] = $this->redact($item);
        }

        return $redacted;
    }

    private function canonicalJson(mixed $value): string
    {
        return $this->encodeJson($this->canonicalize($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (array_is_list($value)) {
            usort($value, fn (mixed $left, mixed $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));

            return $value;
        }
        ksort($value, SORT_STRING);

        return $value;
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
