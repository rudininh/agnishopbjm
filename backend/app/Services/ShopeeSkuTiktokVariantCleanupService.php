<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class ShopeeSkuTiktokVariantCleanupService
{
    private const ACTION_TYPE = 'shopee_sku_tiktok_delete';

    private const SECRET_KEYS = [
        'access_token',
        'api_key',
        'app_key',
        'app_secret',
        'client_secret',
        'partner_key',
        'refresh_token',
        'secret',
        'sign',
        'shop_cipher',
        'token',
        'authorization',
        'cookie',
        'cookies',
        'password',
        'x-tts-access-token',
    ];

    public function __construct(
        private readonly ShopeeSellerSkuTemplate $sellerSkuTemplate,
        private readonly MarketplaceApiService $marketplaceApi,
        private readonly TiktokPartialEditSkuPayloadBuilder $tiktokPayloadBuilder,
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

    public function submit(string $runId, string $revision, Collection $currentGroups): array
    {
        try {
            return Cache::lock('shopee-sku-tiktok-cleanup', 900)
                ->block(5, fn (): array => $this->submitLocked($runId, $revision, $currentGroups));
        } catch (LockTimeoutException) {
            return ['status' => 'busy', 'run_id' => $runId];
        }
    }

    private function submitLocked(string $runId, string $revision, Collection $currentGroups): array
    {
        $claim = DB::transaction(function () use ($runId, $revision, $currentGroups): array {
            $run = DB::table('tiktok_reconciliation_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run) {
                return ['status' => 'not_found', 'run_id' => $runId];
            }

            if ((string) $run->status === 'completed') {
                return $this->loadRun($runId);
            }

            if ((string) $run->revision !== $revision) {
                return ['status' => 'stale_revision', 'run_id' => $runId];
            }

            if ((string) $run->status === 'ready_for_review') {
                if ($this->currentRevision($currentGroups) !== $revision) {
                    return ['status' => 'stale_revision', 'run_id' => $runId];
                }

                DB::table('tiktok_reconciliation_runs')->where('id', $runId)->update([
                    'status' => 'claimed',
                    'submitted_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->loadRun($runId);
        });

        if (! in_array($claim['status'] ?? '', ['claimed', 'partial', 'failed'], true)) {
            return $claim;
        }

        return $this->executeClaimedRun($runId);
    }

    private function executeClaimedRun(string $runId): array
    {
        $rows = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', self::ACTION_TYPE)
            ->whereIn('status', ['ready', 'partial', 'failed', 'submitted_unverified'])
            ->orderBy('item_key')
            ->get();

        $items = $rows->map(function (object $row): array {
            $payload = $this->decodeJson($row->payload) ?? [];
            $payload['_row_id'] = (int) $row->id;
            $payload['_persisted_status'] = (string) $row->status;
            $payload['_persisted_result'] = $this->decodeJson($row->result) ?? [];

            return $payload;
        });

        foreach ($items->groupBy('tiktok_product_id') as $productItems) {
            $this->executeProductGroup($productItems->values());
        }

        $this->updateRunStatus($runId);

        return $this->loadRun($runId);
    }

    private function executeProductGroup(Collection $items): void
    {
        $productId = $this->stringValue($items->first()['tiktok_product_id'] ?? '');
        $targetIds = $items
            ->pluck('tiktok_sku_id')
            ->map(fn (mixed $id): string => $this->stringValue($id))
            ->filter()
            ->uniqueStrict()
            ->values()
            ->all();

        $tiktokBeforeResult = $this->marketplaceApi->fetchTiktokProduct($productId);
        $productBefore = data_get($tiktokBeforeResult, 'data.product');
        if (! ($tiktokBeforeResult['ok'] ?? false) || ! is_array($productBefore)) {
            $this->persistProductFailure($items, 'failed', 'fresh_tiktok_unavailable', [
                'tiktok_before' => $tiktokBeforeResult,
            ]);

            return;
        }

        $modelsByItem = [];
        $modelFetchResults = [];
        foreach ($items->pluck('shopee_item_id')->map(fn (mixed $id): string => $this->stringValue($id))->uniqueStrict() as $itemId) {
            $result = $this->marketplaceApi->fetchShopeeModels($itemId);
            $models = data_get($result, 'data.models');
            $modelFetchResults[$itemId] = $result;
            if (! ($result['ok'] ?? false) || ! is_array($models)) {
                $this->persistProductFailure($items, 'failed', 'fresh_shopee_unavailable', [
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ]);

                return;
            }
            $modelsByItem[$itemId] = $models;
        }

        $freshSkus = $this->productSkus($productBefore);
        $preconditionFailure = $this->productPreconditionFailure($items, $freshSkus, $modelsByItem);
        if ($preconditionFailure !== null) {
            $failureStatus = $items->contains(
                fn (array $item): bool => data_get($item, '_persisted_result.tiktok_verified') === true,
            ) ? 'partial' : 'failed';
            $this->persistProductFailure($items, $failureStatus, $preconditionFailure, [
                'tiktok_before' => $tiktokBeforeResult,
                'shopee_before' => $modelFetchResults,
            ]);

            return;
        }

        $presentIds = array_values(array_intersect($targetIds, $this->skuIds($freshSkus)));
        $expectedNonTargetIds = array_values(array_diff($this->skuIds($freshSkus), $targetIds));
        $persistedExpectedIds = $this->persistedExpectedNonTargetIds($items);
        $deleteWasAttempted = $this->persistedDeleteWasAttempted($items);
        $deleteResult = $this->persistedTiktokDeleteResult($items);
        $verificationResult = $tiktokBeforeResult;

        if (! $deleteWasAttempted && count($presentIds) !== count($targetIds)) {
            $this->persistProductFailure($items, 'failed', 'tiktok_targets_missing_before_submit', [
                'tiktok_before' => $tiktokBeforeResult,
                'shopee_before' => $modelFetchResults,
            ]);

            return;
        } elseif ($presentIds === []) {
            if (! $deleteWasAttempted || $persistedExpectedIds === []) {
                $this->persistProductFailure($items, 'failed', 'tiktok_targets_missing_before_submit', [
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ]);

                return;
            }
            $expectedNonTargetIds = $persistedExpectedIds;
        } elseif ($deleteWasAttempted) {
            if ($persistedExpectedIds === []) {
                $this->persistProductFailure($items, 'submitted_unverified', 'tiktok_delete_unverified', [
                    'tiktok_delete_attempted' => true,
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ]);

                return;
            }
            $expectedNonTargetIds = $persistedExpectedIds;
        } else {
            try {
                $payload = $this->tiktokPayloadBuilder->deleteSkuIds($productBefore, $presentIds);
            } catch (RuntimeException $exception) {
                $this->persistProductFailure($items, 'failed', 'invalid_tiktok_delete_contract', [
                    'message' => $exception->getMessage(),
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ]);

                return;
            }

            $this->persistDeleteIntent($items, $expectedNonTargetIds, $tiktokBeforeResult);
            $deleteResult = $this->marketplaceApi->partialEditTiktokProduct($productId, $payload);
            if (! ($deleteResult['ok'] ?? false)) {
                $this->persistProductFailure($items, 'failed', 'tiktok_delete_failed', [
                    'tiktok_delete_attempted' => true,
                    'expected_non_target_sku_ids' => $expectedNonTargetIds,
                    'tiktok_before' => $tiktokBeforeResult,
                    'tiktok_delete' => $deleteResult,
                    'shopee_before' => $modelFetchResults,
                ]);

                return;
            }

            $verificationResult = $this->marketplaceApi->fetchTiktokProduct($productId);
        }

        $verifiedProduct = data_get($verificationResult, 'data.product');
        $verifiedSkuIds = is_array($verifiedProduct) ? $this->skuIds($this->productSkus($verifiedProduct)) : [];
        $tiktokVerified = ($verificationResult['ok'] ?? false)
            && is_array($verifiedProduct)
            && array_intersect($targetIds, $verifiedSkuIds) === []
            && array_diff($expectedNonTargetIds, $verifiedSkuIds) === [];

        if (! $tiktokVerified) {
            $this->persistProductFailure($items, 'submitted_unverified', 'tiktok_delete_unverified', [
                'tiktok_delete_attempted' => true,
                'expected_non_target_sku_ids' => $expectedNonTargetIds,
                'tiktok_before' => $tiktokBeforeResult,
                'tiktok_delete' => $deleteResult,
                'tiktok_verification' => $verificationResult,
                'shopee_before' => $modelFetchResults,
            ]);

            return;
        }

        foreach ($items as $item) {
            $baseResult = [
                'tiktok_verified' => true,
                'tiktok_delete_attempted' => true,
                'expected_non_target_sku_ids' => $expectedNonTargetIds,
                'tiktok_delete' => $deleteResult,
                'tiktok_verification' => $verificationResult,
            ];
            $this->persistItemOutcome($item, 'partial', 'shopee_update_pending', $baseResult);
            if (! $this->reflectVerifiedTiktokDeletion($item)) {
                $this->persistItemOutcome($item, 'partial', 'local_identity_changed', $baseResult);

                continue;
            }
            $this->executeShopeeUpdate($item, $modelsByItem[$item['shopee_item_id']] ?? [], $baseResult);
        }
    }

    private function executeShopeeUpdate(array $item, array $modelsBefore, array $baseResult): void
    {
        $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
        $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
        $oldSku = $this->stringValue($item['old_sku'] ?? '');
        $targetSku = $this->stringValue($item['target_sku'] ?? '');
        $model = $this->findShopeeModel($modelsBefore, $modelId);
        $freshSku = $this->stringValue($model['model_sku'] ?? '');
        $updateResult = null;

        if ($freshSku !== $targetSku) {
            if ($freshSku !== $oldSku) {
                $this->persistItemOutcome($item, 'partial', 'shopee_source_changed', $baseResult);

                return;
            }

            $updateResult = $this->marketplaceApi->updateShopeeModelSku($itemId, $modelId, $targetSku);
            if (! ($updateResult['ok'] ?? false)) {
                $this->persistItemOutcome($item, 'partial', 'shopee_update_failed', [
                    ...$baseResult,
                    'shopee_update' => $updateResult,
                ]);

                return;
            }

            $verificationResult = $this->marketplaceApi->fetchShopeeModels($itemId);
            $verifiedModels = data_get($verificationResult, 'data.models');
            $verifiedModel = is_array($verifiedModels) ? $this->findShopeeModel($verifiedModels, $modelId) : null;
            if (! ($verificationResult['ok'] ?? false)
                || ! is_array($verifiedModel)
                || $this->stringValue($verifiedModel['model_sku'] ?? '') !== $targetSku) {
                $this->persistItemOutcome($item, 'submitted_unverified', 'shopee_update_unverified', [
                    ...$baseResult,
                    'shopee_update' => $updateResult,
                    'shopee_verification' => $verificationResult,
                ]);

                return;
            }
        } else {
            $verificationResult = [
                'ok' => true,
                'message' => 'Shopee target SKU already verified by the fresh precondition read.',
                'data' => ['item_id' => $itemId, 'models' => $modelsBefore],
            ];
        }

        if (! $this->reconcileVerifiedShopeeUpdate($item)) {
            $this->persistItemOutcome($item, 'partial', 'local_identity_changed', [
                ...$baseResult,
                'shopee_update' => $updateResult,
                'shopee_verification' => $verificationResult,
            ]);

            return;
        }
        $this->persistItemOutcome($item, 'updated', null, [
            ...$baseResult,
            'shopee_update' => $updateResult,
            'shopee_verification' => $verificationResult,
        ]);
    }

    private function productPreconditionFailure(Collection $items, array $freshSkus, array $modelsByItem): ?string
    {
        foreach ($items as $item) {
            if (! $this->localIdentityIsCurrent($item)) {
                return 'local_identity_changed';
            }

            $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
            $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
            $oldSku = $this->stringValue($item['old_sku'] ?? '');
            $targetSku = $this->stringValue($item['target_sku'] ?? '');
            $targetTiktokSkuId = $this->stringValue($item['tiktok_sku_id'] ?? '');
            $models = $modelsByItem[$itemId] ?? [];
            $model = $this->findShopeeModel($models, $modelId);

            if (! is_array($model)) {
                return 'shopee_model_missing';
            }

            $freshName = $this->shopeeModelName($model);
            if ($freshName !== $this->stringValue($item['shopee_variant_name'] ?? '')) {
                return 'shopee_model_name_changed';
            }

            try {
                $freshTargetSku = $this->sellerSkuTemplate->build($itemId, $freshName);
            } catch (\InvalidArgumentException) {
                return 'invalid_target_sku';
            }
            if ($freshTargetSku !== $targetSku) {
                return 'shopee_target_changed';
            }

            $freshSourceSku = $this->stringValue($model['model_sku'] ?? '');
            $canAlreadyBeTarget = in_array($item['_persisted_status'], ['partial', 'submitted_unverified'], true);
            if ($freshSourceSku !== $oldSku && (! $canAlreadyBeTarget || $freshSourceSku !== $targetSku)) {
                return 'shopee_source_changed';
            }

            foreach ($models as $candidate) {
                $candidate = $this->arrayValue($candidate);
                if ($this->stringValue($candidate['model_id'] ?? '') !== $modelId
                    && $this->normalizedSku($candidate['model_sku'] ?? '') === $this->normalizedSku($targetSku)) {
                    return 'shopee_target_collision';
                }
            }

            $targetTiktokSku = $this->findTiktokSku($freshSkus, $targetTiktokSkuId);
            if (is_array($targetTiktokSku)
                && $this->normalizedSku($this->tiktokSellerSku($targetTiktokSku)) !== $this->normalizedSku($oldSku)) {
                return 'source_sku_mismatch';
            }

            foreach ($freshSkus as $candidate) {
                $candidate = $this->arrayValue($candidate);
                if ($this->stringValue($candidate['id'] ?? $candidate['sku_id'] ?? '') !== $targetTiktokSkuId
                    && $this->normalizedSku($this->tiktokSellerSku($candidate)) === $this->normalizedSku($targetSku)) {
                    return 'tiktok_target_collision';
                }
            }
        }

        return null;
    }

    private function localIdentityIsCurrent(
        array $item,
        bool $lockForUpdate = false,
        ?bool $allowClearedTiktokIdentity = null,
    ): bool
    {
        $stockMasterIds = array_values(array_filter(array_map('intval', $item['stock_master_ids'] ?? [])));
        if ($stockMasterIds === []) {
            return true;
        }

        $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
        $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
        $productId = $this->stringValue($item['tiktok_product_id'] ?? '');
        $tiktokSkuId = $this->stringValue($item['tiktok_sku_id'] ?? '');
        $oldSku = $this->stringValue($item['old_sku'] ?? '');
        $targetSku = $this->stringValue($item['target_sku'] ?? '');
        $allowClearedTiktokIdentity ??= data_get($item, '_persisted_result.tiktok_verified') === true;

        foreach ($stockMasterIds as $stockMasterId) {
            $stockQuery = DB::table('stock_master')->where('id', $stockMasterId);
            $mappingQuery = DB::table('sku_mappings')->where('stock_master_id', $stockMasterId);
            if ($lockForUpdate) {
                $stockQuery->lockForUpdate();
                $mappingQuery->lockForUpdate();
            }
            $stock = $stockQuery->first();
            $mapping = $mappingQuery->first();
            if (! $stock && ! $mapping) {
                return false;
            }

            if ($stock) {
                $stockTiktokSku = $this->stringValue($stock->tiktok_sku ?? '');
                $stockTiktokSellerSku = $this->stringValue($stock->tiktok_seller_sku ?? '');
                $allowedShopeeSkus = $allowClearedTiktokIdentity ? [$oldSku, $targetSku] : [$oldSku];
                if ($this->stringValue($stock->shopee_product_id ?? '') !== $itemId
                    || $this->stringValue($stock->shopee_sku ?? '') !== $modelId
                    || ! in_array($this->stringValue($stock->shopee_seller_sku ?? ''), $allowedShopeeSkus, true)
                    || $this->stringValue($stock->tiktok_product_id ?? '') !== $productId
                    || (! $allowClearedTiktokIdentity && $stockTiktokSku !== $tiktokSkuId)
                    || ($allowClearedTiktokIdentity && ! in_array($stockTiktokSku, ['', $tiktokSkuId], true))
                    || (! $allowClearedTiktokIdentity && $stockTiktokSellerSku !== $oldSku)
                    || ($allowClearedTiktokIdentity && ! in_array($stockTiktokSellerSku, ['', $oldSku], true))) {
                    return false;
                }
            }

            if ($mapping) {
                $mappingTiktokSku = $this->stringValue($mapping->tiktok_sku_id ?? '');
                $allowedMappingSellerSkus = $allowClearedTiktokIdentity ? [$oldSku, $targetSku] : [$oldSku];
                if ($this->stringValue($mapping->shopee_item_id ?? '') !== $itemId
                    || $this->stringValue($mapping->shopee_model_id ?? '') !== $modelId
                    || ! in_array($this->stringValue($mapping->seller_sku ?? ''), $allowedMappingSellerSkus, true)
                    || $this->stringValue($mapping->tiktok_product_id ?? '') !== $productId
                    || (! $allowClearedTiktokIdentity && $mappingTiktokSku !== $tiktokSkuId)
                    || ($allowClearedTiktokIdentity && ! in_array($mappingTiktokSku, ['', $tiktokSkuId], true))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function reflectVerifiedTiktokDeletion(array $item): bool
    {
        return DB::transaction(function () use ($item): bool {
            $tiktokUpdate = ['is_active' => DB::raw('false'), 'updated_at' => now()];
            if (Schema::hasColumn('tiktok_products', 'stock_qty')) {
                $tiktokUpdate['stock_qty'] = 0;
            }
            DB::table('tiktok_products')
                ->where('product_id', $this->stringValue($item['tiktok_product_id'] ?? ''))
                ->where('sku_id', $this->stringValue($item['tiktok_sku_id'] ?? ''))
                ->update($tiktokUpdate);

            if (! $this->localIdentityIsCurrent($item, true)) {
                return false;
            }

            $stockMasterIds = array_values(array_filter(array_map('intval', $item['stock_master_ids'] ?? [])));
            if ($stockMasterIds !== []) {
                DB::table('stock_master')->whereIn('id', $stockMasterIds)->update($this->stockMasterTiktokClearValues());
                DB::table('sku_mappings')->whereIn('stock_master_id', $stockMasterIds)->update($this->mappingTiktokClearValues());
            }

            return true;
        });
    }

    private function reconcileVerifiedShopeeUpdate(array $item): bool
    {
        return DB::transaction(function () use ($item): bool {
            if (! $this->localIdentityIsCurrent($item, true, true)) {
                return false;
            }

            $targetSku = $this->stringValue($item['target_sku'] ?? '');
            DB::table('shopee_product_model')
                ->where('item_id', $this->stringValue($item['shopee_item_id'] ?? ''))
                ->where('model_id', $this->stringValue($item['shopee_model_id'] ?? ''))
                ->update(['model_sku' => $targetSku, 'updated_at' => now()]);

            $stockMasterIds = array_values(array_filter(array_map('intval', $item['stock_master_ids'] ?? [])));
            if ($stockMasterIds === []) {
                return true;
            }

            DB::table('stock_master')->whereIn('id', $stockMasterIds)->update([
                ...$this->stockMasterTiktokClearValues(),
                'shopee_seller_sku' => $targetSku,
            ]);
            DB::table('sku_mappings')->whereIn('stock_master_id', $stockMasterIds)->update([
                ...$this->mappingTiktokClearValues(),
                'seller_sku' => $targetSku,
            ]);

            return true;
        });
    }

    private function stockMasterTiktokClearValues(): array
    {
        $values = ['tiktok_sku' => null, 'updated_at' => now()];
        foreach (['tiktok_seller_sku', 'tiktok_image_url'] as $column) {
            if (Schema::hasColumn('stock_master', $column)) {
                $values[$column] = null;
            }
        }

        return $values;
    }

    private function mappingTiktokClearValues(): array
    {
        $values = ['tiktok_sku_id' => null, 'updated_at' => now()];
        foreach (['tiktok_sku_name', 'tiktok_image_url'] as $column) {
            if (Schema::hasColumn('sku_mappings', $column)) {
                $values[$column] = null;
            }
        }

        return $values;
    }

    private function persistProductFailure(Collection $items, string $status, string $reason, array $result): void
    {
        foreach ($items as $item) {
            $this->persistItemOutcome($item, $status, $reason, $result);
        }
    }

    private function persistDeleteIntent(Collection $items, array $expectedNonTargetIds, array $tiktokBeforeResult): void
    {
        $result = $this->encodeJson($this->redact([
            'tiktok_delete_attempted' => true,
            'expected_non_target_sku_ids' => $expectedNonTargetIds,
            'tiktok_before' => $tiktokBeforeResult,
        ]));

        DB::transaction(function () use ($items, $result): void {
            DB::table('tiktok_reconciliation_run_items')
                ->whereIn('id', $items->pluck('_row_id')->map(fn (mixed $id): int => (int) $id)->all())
                ->update(['result' => $result, 'updated_at' => now()]);
        });
    }

    private function persistItemOutcome(array $item, string $status, ?string $reason, array $result): void
    {
        DB::table('tiktok_reconciliation_run_items')->where('id', (int) $item['_row_id'])->update([
            'status' => $status,
            'block_reason' => $reason,
            'result' => $this->encodeJson($this->redact($result)),
            'updated_at' => now(),
        ]);
    }

    private function updateRunStatus(string $runId): void
    {
        $statuses = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', self::ACTION_TYPE)
            ->pluck('status');
        $updated = $statuses->filter(fn (mixed $status): bool => $status === 'updated')->count();
        $failed = $statuses->filter(fn (mixed $status): bool => $status === 'failed')->count();
        $partial = $statuses->contains(fn (mixed $status): bool => in_array($status, ['partial', 'submitted_unverified'], true));

        if ($partial || ($failed > 0 && $updated > 0)) {
            $status = 'partial';
        } elseif ($failed > 0) {
            $status = 'failed';
        } else {
            $status = 'completed';
        }

        DB::table('tiktok_reconciliation_runs')->where('id', $runId)->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
            'updated_at' => now(),
        ]);
    }

    private function persistedExpectedNonTargetIds(Collection $items): array
    {
        return $items
            ->flatMap(fn (array $item): array => is_array($item['_persisted_result']['expected_non_target_sku_ids'] ?? null)
                ? $item['_persisted_result']['expected_non_target_sku_ids']
                : [])
            ->map(fn (mixed $id): string => is_string($id) ? trim($id) : '')
            ->filter()
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    private function persistedDeleteWasAttempted(Collection $items): bool
    {
        return $items->isNotEmpty() && $items->every(function (array $item): bool {
            $result = $item['_persisted_result'] ?? [];

            return ($result['tiktok_delete_attempted'] ?? false) === true
                || ($result['tiktok_verified'] ?? false) === true
                || data_get($result, 'tiktok_delete.ok') === true;
        });
    }

    private function persistedTiktokDeleteResult(Collection $items): ?array
    {
        foreach ($items as $item) {
            $result = data_get($item, '_persisted_result.tiktok_delete');
            if (is_array($result)) {
                return $result;
            }
        }

        return null;
    }

    private function productSkus(array $product): array
    {
        foreach (['skus', 'sku_list', 'sku_info_list', 'sku_infos', 'skus_info', 'variants', 'model_list', 'product_skus'] as $key) {
            $candidate = data_get($product, $key);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function skuIds(array $skus): array
    {
        return collect($skus)
            ->map(function (mixed $sku): string {
                $sku = $this->arrayValue($sku);
                $id = $sku['id'] ?? $sku['sku_id'] ?? null;

                return is_string($id) ? trim($id) : '';
            })
            ->filter()
            ->uniqueStrict()
            ->values()
            ->all();
    }

    private function findTiktokSku(array $skus, string $skuId): ?array
    {
        foreach ($skus as $sku) {
            $sku = $this->arrayValue($sku);
            $candidateId = $sku['id'] ?? $sku['sku_id'] ?? null;
            if (is_string($candidateId) && trim($candidateId) === $skuId) {
                return $sku;
            }
        }

        return null;
    }

    private function findShopeeModel(array $models, string $modelId): ?array
    {
        foreach ($models as $model) {
            $model = $this->arrayValue($model);
            if ($this->stringValue($model['model_id'] ?? '') === $modelId) {
                return $model;
            }
        }

        return null;
    }

    private function shopeeModelName(array $model): string
    {
        return $this->stringValue($model['model_name'] ?? $model['name'] ?? '');
    }

    private function tiktokSellerSku(array $sku): string
    {
        foreach (['seller_sku', 'sku_code', 'sku_no', 'sellerSku'] as $key) {
            $value = $sku[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
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
