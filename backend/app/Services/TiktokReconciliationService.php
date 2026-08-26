<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TiktokReconciliationService
{
    private const SECRET_KEYS = [
        'access_token',
        'app_secret',
        'sign',
        'shop_cipher',
        'authorization',
    ];

    public function createPreview(): array
    {
        $preview = $this->currentPreview();
        $runId = (string) Str::uuid();

        DB::transaction(function () use ($runId, $preview): void {
            DB::table('tiktok_reconciliation_runs')->insert([
                'id' => $runId,
                'revision' => $preview['revision'],
                'status' => 'ready_for_review',
                'summary' => json_encode($preview['summary'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($preview['items'] as $item) {
                DB::table('tiktok_reconciliation_run_items')->insert([
                    'run_id' => $runId,
                    'stock_master_id' => $item['stock_master_ids'][0] ?? null,
                    'item_key' => $item['item_key'],
                    'action_type' => $item['action_type'],
                    'status' => $item['status'],
                    'source_fingerprint' => $item['source_fingerprint'],
                    'target_product_id' => $item['target_product_id'],
                    'target_sku_id' => null,
                    'block_reason' => $item['block_reason'],
                    'payload' => json_encode($this->redactPayload($item), JSON_THROW_ON_ERROR),
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

        $groups = [
            'new_products' => [],
            'variant_additions' => [],
            'conflicts' => [],
        ];
        foreach (DB::table('tiktok_reconciliation_run_items')->where('run_id', $runId)->orderBy('item_key')->get() as $row) {
            $item = $this->decodeJson($row->payload) ?? [];
            $item['item_key'] = (string) $row->item_key;
            $item['action_type'] = (string) $row->action_type;
            $item['status'] = (string) $row->status;
            $item['stock_master_ids'] = $item['stock_master_ids'] ?? array_values(array_filter([(int) ($row->stock_master_id ?? 0)]));
            $item['source_fingerprint'] = (string) $row->source_fingerprint;
            $item['target_product_id'] = $row->target_product_id === null ? null : (string) $row->target_product_id;
            $item['block_reason'] = $row->block_reason === null ? null : (string) $row->block_reason;

            if ($item['action_type'] === 'new_product' && $item['status'] !== 'blocked') {
                $groups['new_products'][] = $item;
            } elseif ($item['action_type'] === 'variant_addition' && $item['status'] !== 'blocked') {
                $groups['variant_additions'][] = $item;
            } else {
                $groups['conflicts'][] = $item;
            }
        }

        return [
            'status' => (string) $run->status,
            'run_id' => (string) $run->id,
            'revision' => (string) $run->revision,
            'summary' => $this->decodeJson($run->summary) ?? [],
            ...$groups,
        ];
    }

    public function claimCurrentRun(string $runId, string $revision): array
    {
        return DB::transaction(function () use ($runId, $revision): array {
            $run = DB::table('tiktok_reconciliation_runs')->where('id', $runId)->first();
            if (! $run) {
                return ['status' => 'not_found', 'run_id' => $runId];
            }
            if (! hash_equals((string) $run->revision, $revision)
                || ! hash_equals((string) $run->revision, $this->currentPreview()['revision'])) {
                return [
                    'status' => 'stale_revision',
                    'run_id' => $runId,
                    'revision' => (string) $run->revision,
                ];
            }

            $claimed = DB::table('tiktok_reconciliation_runs')
                ->where('id', $runId)
                ->where('revision', $revision)
                ->where('status', 'ready_for_review')
                ->update([
                    'status' => 'claimed',
                    'updated_at' => now(),
                ]);
            if ($claimed === 1) {
                $this->afterReconciliationRunClaimed();
                if (! hash_equals($revision, $this->currentPreview()['revision'])) {
                    $reverted = DB::table('tiktok_reconciliation_runs')
                        ->where('id', $runId)
                        ->where('revision', $revision)
                        ->where('status', 'claimed')
                        ->update([
                            'status' => 'ready_for_review',
                            'updated_at' => now(),
                        ]);
                    if ($reverted !== 1) {
                        throw new \RuntimeException('Unable to revert stale reconciliation claim.');
                    }

                    return ['status' => 'stale_revision', 'run_id' => $runId, 'revision' => $revision];
                }

                return ['status' => 'claimed', 'run_id' => $runId, 'revision' => $revision];
            }

            $currentRun = DB::table('tiktok_reconciliation_runs')->where('id', $runId)->first();
            if (! $currentRun) {
                return ['status' => 'not_found', 'run_id' => $runId];
            }
            if (! hash_equals((string) $currentRun->revision, $revision)
                || ! hash_equals((string) $currentRun->revision, $this->currentPreview()['revision'])) {
                return [
                    'status' => 'stale_revision',
                    'run_id' => $runId,
                    'revision' => (string) $currentRun->revision,
                ];
            }

            return [
                'status' => (string) $currentRun->status,
                'run_id' => $runId,
                'revision' => (string) $currentRun->revision,
            ];
        });
    }

    protected function afterReconciliationRunClaimed(): void
    {
    }

    public function recordItemResult(string $runId, string $itemKey, array $result): void
    {
        DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('item_key', $itemKey)
            ->update([
                'status' => (string) ($result['status'] ?? 'completed'),
                'result' => json_encode($this->redactPayload($result), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function buildGroups(): array
    {
        $stockRows = $this->tableRows('stock_master');
        $mappingsByStockMasterId = [];
        foreach ($this->tableRows('sku_mappings') as $mapping) {
            $stockMasterId = (int) $this->value($mapping, ['stock_master_id']);
            if ($stockMasterId > 0) {
                $mappingsByStockMasterId[$stockMasterId] = $mapping;
            }
        }
        $productsByItemId = $this->rowsBy($this->tableRows('shopee_product'), ['item_id']);
        $modelsByKey = $this->rowsBy($this->tableRows('shopee_product_model'), ['item_id', 'model_id']);
        $images = $this->tableRows('shopee_product_image');
        $activeTiktok = array_values(array_filter(
            $this->tableRows('tiktok_products'),
            fn (array $row): bool => $this->isActive($this->value($row, ['is_active'])),
        ));
        $shopeeSellerSkuCounts = [];
        foreach ($stockRows as $stockRow) {
            $sellerSku = $this->stringValue($stockRow, ['shopee_seller_sku']);
            if ($sellerSku !== '') {
                $shopeeSellerSkuCounts[$sellerSku] = ($shopeeSellerSkuCounts[$sellerSku] ?? 0) + 1;
            }
        }
        $internalSkuCounts = [];
        foreach ($stockRows as $stockRow) {
            $internalSku = $this->stringValue($stockRow, ['internal_sku']);
            if ($internalSku !== '') {
                $internalSkuCounts[$internalSku] = ($internalSkuCounts[$internalSku] ?? 0) + 1;
            }
        }

        $sourcesByItemId = [];
        foreach ($stockRows as $stockRow) {
            if (! $this->isActive($this->value($stockRow, ['is_hidden_from_mapping']), true)) {
                continue;
            }
            $itemId = $this->stringValue($stockRow, ['shopee_product_id']);
            $modelId = $this->stringValue($stockRow, ['shopee_sku']);
            if ($itemId === '' || $modelId === '') {
                continue;
            }
            $stockMasterId = (int) $this->value($stockRow, ['id']);
            $mapping = $mappingsByStockMasterId[$stockMasterId] ?? [];
            $model = $modelsByKey[$this->compositeKey([$itemId, $modelId])] ?? [];
            $product = $productsByItemId[$itemId] ?? [];
            $source = $this->sourceFor(
                $stockRow,
                $mapping,
                $product,
                $model,
                $images,
                $activeTiktok,
                $shopeeSellerSkuCounts,
                $internalSkuCounts,
            );
            $sourcesByItemId[$itemId][] = $source;
        }

        $groups = ['new_products' => [], 'variant_additions' => [], 'conflicts' => []];
        ksort($sourcesByItemId, SORT_NATURAL);
        foreach ($sourcesByItemId as $itemId => $sources) {
            usort($sources, fn (array $left, array $right): int => $left['variant']['model_id'] <=> $right['variant']['model_id']);
            foreach ($sources as $source) {
                if ($source['conflict_reason'] !== null) {
                    $groups['conflicts'][] = $this->itemFromSources(
                        'conflict:'.$itemId.':'.$source['variant']['model_id'],
                        'conflict',
                        'blocked',
                        [$source],
                        $source['target_product_id'],
                        $source['conflict_reason'],
                    );
                }
            }
            $eligibleSources = array_values(array_filter($sources, fn (array $source): bool => $source['conflict_reason'] === null));
            if ($eligibleSources === []) {
                continue;
            }
            $targetProductIds = array_values(array_unique(array_filter(array_map(
                fn (array $source): ?string => $source['target_product_id'],
                $eligibleSources,
            ))));

            if (count($targetProductIds) > 1) {
                $groups['conflicts'][] = $this->itemFromSources(
                    'conflict:'.$itemId.':ambiguous-target',
                    'conflict',
                    'blocked',
                    $eligibleSources,
                    null,
                    'ambiguous_tiktok_target',
                );
                continue;
            }

            if ($targetProductIds === []) {
                $item = $this->itemFromSources('new_product:'.$itemId, 'new_product', 'ready', $eligibleSources, null, null);
                if ($item['block_reason'] !== null) {
                    $item['status'] = 'blocked';
                    $groups['conflicts'][] = $item;
                } else {
                    $groups['new_products'][] = $item;
                }
                continue;
            }

            $targetProductId = $targetProductIds[0];
            $missingVariants = array_values(array_filter(
                $eligibleSources,
                fn (array $source): bool => ! $source['has_tiktok_sku'],
            ));
            if ($missingVariants === []) {
                continue;
            }
            $item = $this->itemFromSources(
                'variant_addition:'.$targetProductId.':'.$itemId,
                'variant_addition',
                'ready',
                $missingVariants,
                $targetProductId,
                null,
            );
            if ($item['block_reason'] !== null) {
                $item['status'] = 'blocked';
                $groups['conflicts'][] = $item;
            } else {
                $groups['variant_additions'][] = $item;
            }
        }

        foreach ($groups as &$items) {
            usort($items, fn (array $left, array $right): int => $left['item_key'] <=> $right['item_key']);
        }

        return $groups;
    }

    private function sourceFor(
        array $stockRow,
        array $mapping,
        array $product,
        array $model,
        array $images,
        array $activeTiktok,
        array $shopeeSellerSkuCounts,
        array $internalSkuCounts,
    ): array {
        $itemId = $this->stringValue($stockRow, ['shopee_product_id']);
        $modelId = $this->stringValue($stockRow, ['shopee_sku']);
        $internalSku = $this->stringValue($stockRow, ['internal_sku']);
        $shopeeSellerSku = $this->stringValue($stockRow, ['shopee_seller_sku']);
        $sellerSku = $this->firstString([$this->stringValue($model, ['seller_sku']), $shopeeSellerSku, $this->stringValue($mapping, ['seller_sku']), $internalSku]);
        $matchedByInternalSku = $this->tiktokRowsForSellerSku($activeTiktok, $internalSku);
        $matchedByShopeeSellerSku = $this->tiktokRowsForSellerSku($activeTiktok, $shopeeSellerSku);
        $directTargetIds = array_values(array_unique(array_filter([
            $this->stringValue($stockRow, ['tiktok_product_id']),
            $this->stringValue($mapping, ['tiktok_product_id']),
        ])));
        $directMatches = array_values(array_filter($activeTiktok, fn (array $row): bool => in_array($this->stringValue($row, ['product_id']), $directTargetIds, true)));
        $targetCandidates = array_values(array_unique(array_filter(array_merge(
            array_map(fn (array $row): string => $this->stringValue($row, ['product_id']), $directMatches),
            array_map(fn (array $row): string => $this->stringValue($row, ['product_id']), $matchedByInternalSku),
        ))));
        $sharedShopeeSellerSku = $shopeeSellerSku !== '' && ($shopeeSellerSkuCounts[$shopeeSellerSku] ?? 0) > 1;
        $conflictReason = null;
        if ($sharedShopeeSellerSku && $matchedByShopeeSellerSku !== []) {
            $conflictReason = 'tiktok_sku_conflict';
            $targetCandidates = array_values(array_unique(array_merge(
                $targetCandidates,
                array_map(fn (array $row): string => $this->stringValue($row, ['product_id']), $matchedByShopeeSellerSku),
            )));
        } elseif ($matchedByShopeeSellerSku !== [] && $matchedByInternalSku === []) {
            $variantName = $this->firstString([$this->stringValue($model, ['model_name']), $this->stringValue($stockRow, ['variant_name'])]);
            if (count($matchedByShopeeSellerSku) !== 1 || $this->normalizedName($variantName) !== $this->normalizedName($this->stringValue($matchedByShopeeSellerSku[0], ['sku_name']))) {
                $conflictReason = 'tiktok_sku_conflict';
                $targetCandidates = array_values(array_unique(array_merge(
                    $targetCandidates,
                    array_map(fn (array $row): string => $this->stringValue($row, ['product_id']), $matchedByShopeeSellerSku),
                )));
            } else {
                $targetCandidates[] = $this->stringValue($matchedByShopeeSellerSku[0], ['product_id']);
                $targetCandidates = array_values(array_unique($targetCandidates));
            }
        }

        $stock = $this->integerValue($model, ['stock'], $this->integerValue($stockRow, ['stock_qty']));
        $price = (float) $this->value($model, ['price'], 0);
        $imageUrl = $this->imageFor($images, $itemId, $modelId);
        $validationReasons = [];
        if ($internalSku === '' || ($internalSkuCounts[$internalSku] ?? 0) !== 1) {
            $validationReasons[] = 'non_unique_internal_sku';
        }
        if ($price <= 0) {
            $validationReasons[] = 'invalid_price';
        }
        if ($stock <= 0) {
            $validationReasons[] = 'invalid_stock';
        }
        if ($imageUrl === '') {
            $validationReasons[] = 'missing_source_image';
        }

        return [
            'stock_master_id' => (int) $this->value($stockRow, ['id']),
            'target_product_id' => count($targetCandidates) === 1 ? $targetCandidates[0] : null,
            'has_tiktok_sku' => $this->hasTiktokSku($stockRow, $mapping, $matchedByInternalSku, $matchedByShopeeSellerSku),
            'conflict_reason' => $conflictReason,
            'validation_reasons' => $validationReasons,
            'product' => [
                'item_id' => $itemId,
                'title' => $this->firstString([$this->stringValue($product, ['name', 'item_name']), $this->stringValue($stockRow, ['product_name'])]),
                'image_url' => $this->imageFor($images, $itemId, null),
            ],
            'variant' => [
                'stock_master_id' => (int) $this->value($stockRow, ['id']),
                'model_id' => $modelId,
                'internal_sku' => $internalSku,
                'seller_sku' => $sellerSku,
                'title' => $this->firstString([$this->stringValue($model, ['model_name']), $this->stringValue($stockRow, ['variant_name'])]),
                'price' => $price,
                'stock' => $stock,
                'image_url' => $imageUrl,
            ],
            'raw_source' => [
                'stock_master' => $stockRow,
                'sku_mapping' => $mapping,
                'shopee_product' => $product,
                'shopee_product_model' => $model,
                'tiktok_matches' => array_merge($directMatches, $matchedByInternalSku, $matchedByShopeeSellerSku),
            ],
        ];
    }

    private function itemFromSources(
        string $itemKey,
        string $actionType,
        string $status,
        array $sources,
        ?string $targetProductId,
        ?string $blockReason,
    ): array {
        $validationReasons = array_values(array_unique(array_merge(...array_map(
            fn (array $source): array => $source['validation_reasons'],
            $sources,
        ))));
        $resolvedBlockReason = $blockReason ?? ($validationReasons === [] ? null : implode(',', $validationReasons));
        $sourceIdentity = array_map(fn (array $source): array => $source['raw_source'], $sources);
        $product = $sources[0]['product'];
        $variants = array_map(fn (array $source): array => $source['variant'], $sources);

        return [
            'item_key' => $itemKey,
            'action_type' => $actionType,
            'status' => $resolvedBlockReason === null ? $status : 'blocked',
            'stock_master_ids' => array_values(array_map(fn (array $source): int => $source['stock_master_id'], $sources)),
            'source_fingerprint' => hash('sha256', $this->canonicalJson($sourceIdentity)),
            'product' => $product,
            'variants' => $variants,
            'target_product_id' => $targetProductId,
            'block_reason' => $resolvedBlockReason,
        ];
    }

    private function currentPreview(): array
    {
        $groups = $this->buildGroups();
        $items = array_merge($groups['new_products'], $groups['variant_additions'], $groups['conflicts']);

        return [
            'items' => $items,
            'revision' => hash('sha256', $this->canonicalJson(array_map(
                fn (array $item): array => [
                    'item_key' => $item['item_key'],
                    'source_fingerprint' => $item['source_fingerprint'],
                ],
                $items,
            ))),
            'summary' => [
                'new_products' => count($groups['new_products']),
                'new_variants' => array_sum(array_map(fn (array $item): int => count($item['variants']), $groups['variant_additions'])),
                'safe_variant_additions' => count($groups['variant_additions']),
                'conflicts' => count(array_filter($groups['conflicts'], fn (array $item): bool => $item['action_type'] === 'conflict')),
                'blocked' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'blocked')),
            ],
        ];
    }

    private function tableRows(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function rowsBy(array $rows, array $keys): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $values = array_map(fn (string $key): string => $this->stringValue($row, [$key]), $keys);
            if (! in_array('', $values, true)) {
                $indexed[$this->compositeKey($values)] = $row;
            }
        }

        return $indexed;
    }

    private function compositeKey(array $values): string
    {
        return implode(chr(31), $values);
    }

    private function tiktokRowsForSellerSku(array $rows, string $sellerSku): array
    {
        if ($sellerSku === '') {
            return [];
        }

        return array_values(array_filter($rows, fn (array $row): bool => $this->stringValue($row, ['seller_sku']) === $sellerSku));
    }

    private function hasTiktokSku(array $stockRow, array $mapping, array $matchedByInternalSku, array $matchedByShopeeSellerSku): bool
    {
        $productId = $this->firstString([$this->stringValue($stockRow, ['tiktok_product_id']), $this->stringValue($mapping, ['tiktok_product_id'])]);
        $skuId = $this->firstString([$this->stringValue($stockRow, ['tiktok_sku']), $this->stringValue($mapping, ['tiktok_sku_id'])]);

        return ($productId !== '' && $skuId !== '') || $matchedByInternalSku !== [] || $matchedByShopeeSellerSku !== [];
    }

    private function imageFor(array $images, string $itemId, ?string $modelId): string
    {
        $matching = array_values(array_filter($images, function (array $image) use ($itemId, $modelId): bool {
            if ($this->stringValue($image, ['item_id']) !== $itemId) {
                return false;
            }

            return $modelId === null
                ? $this->stringValue($image, ['model_id']) === ''
                : $this->stringValue($image, ['model_id']) === $modelId;
        }));
        if ($matching === [] && $modelId !== null) {
            return $this->imageFor($images, $itemId, null);
        }
        usort($matching, fn (array $left, array $right): int => $this->stringValue($left, ['image_url']) <=> $this->stringValue($right, ['image_url']));

        return $matching === [] ? '' : $this->stringValue($matching[0], ['image_url']);
    }

    private function value(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return $default;
    }

    private function stringValue(array $row, array $keys): string
    {
        return trim((string) $this->value($row, $keys, ''));
    }

    private function integerValue(array $row, array $keys, int $default = 0): int
    {
        return (int) $this->value($row, $keys, $default);
    }

    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function isActive(mixed $value, bool $hidden = false): bool
    {
        $truthy = ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'no'], true);

        return $hidden ? ! $truthy : $truthy;
    }

    private function normalizedName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function redactPayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                continue;
            }
            $redacted[$key] = $this->redactPayload($item);
        }

        return $redacted;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
