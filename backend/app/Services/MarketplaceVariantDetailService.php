<?php

namespace App\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketplaceVariantDetailService
{
    public function __construct(
        private readonly MarketplaceAccountReadinessService $readiness,
        private readonly MobileStockAdjustmentService $adjustments,
    ) {
    }

    public function detail(int $stockMasterId): array
    {
        $variant = DB::table('stock_master')->where('id', $stockMasterId)->first();

        if ($variant === null) {
            throw (new ModelNotFoundException())->setModel('stock_master', [$stockMasterId]);
        }

        $listings = DB::table('marketplace_listings')
            ->where('stock_master_id', $stockMasterId)
            ->where('is_active', true)
            ->get()
            ->keyBy('account_key');
        $latestDeliveries = $this->latestDeliveries($stockMasterId);
        $accounts = [];

        foreach ($this->readiness->all() as $account) {
            $accountKey = (string) $account['key'];
            $listing = $listings->get($accountKey);
            $status = (string) $account['state'];
            $message = (string) $account['message'];

            if ($listing === null && $status === 'ready') {
                $status = 'mapping_required';
                $message = 'Tambahkan mapping SKU aktif untuk varian ini.';
            }

            $accounts[$accountKey] = [
                'account_key' => $accountKey,
                'name' => (string) $account['name'],
                'channel' => (string) $account['channel'],
                'status' => $status,
                'message' => $message,
                'selectable' => $status === 'ready' && $listing !== null,
                'marketplace_listing_id' => $listing === null ? null : (int) $listing->id,
                'remote_product_id' => $listing?->remote_product_id,
                'remote_variant_id' => $listing?->remote_variant_id,
                'seller_sku' => $listing?->seller_sku,
                'last_delivery' => $latestDeliveries[$accountKey] ?? null,
            ];
        }

        return [
            'variant' => [
                'id' => (int) $variant->id,
                'internal_sku' => (string) $variant->internal_sku,
                'product_name' => $variant->product_name,
                'variant_name' => $variant->variant_name,
                'stock_qty' => (int) $variant->stock_qty,
            ],
            'accounts' => $accounts,
            'adjustments' => $this->adjustments->history($stockMasterId, 5)->all(),
            'latest_runs' => DB::table('marketplace_variant_update_runs')
                ->where('stock_master_id', $stockMasterId)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'status', 'requested_stock_qty', 'completed_at', 'created_at'])
                ->map(static fn (object $run): array => [
                    'id' => (string) $run->id,
                    'status' => (string) $run->status,
                    'requested_stock_qty' => (int) $run->requested_stock_qty,
                    'completed_at' => $run->completed_at,
                    'created_at' => $run->created_at,
                ])
                ->all(),
        ];
    }

    public function selectableTargets(int $stockMasterId, array $accountKeys): array
    {
        $accountKeys = array_map(static fn (mixed $accountKey): string => trim((string) $accountKey), $accountKeys);

        if ($accountKeys === [] || in_array('', $accountKeys, true)) {
            throw new InvalidArgumentException('Pilih minimal satu akun marketplace yang siap.');
        }

        if (count($accountKeys) !== count(array_unique($accountKeys))) {
            throw new InvalidArgumentException('Akun marketplace tidak boleh dipilih lebih dari sekali.');
        }

        $detail = $this->detail($stockMasterId);
        $targets = [];

        foreach ($accountKeys as $accountKey) {
            $account = $detail['accounts'][$accountKey] ?? null;

            if ($account === null) {
                throw new InvalidArgumentException('Akun marketplace tidak dikenal.');
            }

            if (! $account['selectable']) {
                throw new InvalidArgumentException('Akun '.$account['name'].' belum siap untuk varian ini.');
            }

            $targets[] = [
                'account_key' => $account['account_key'],
                'channel' => $account['channel'],
                'marketplace_listing_id' => $account['marketplace_listing_id'],
                'remote_product_id' => $account['remote_product_id'],
                'remote_variant_id' => $account['remote_variant_id'],
            ];
        }

        return $targets;
    }

    private function latestDeliveries(int $stockMasterId): array
    {
        $deliveries = [];

        foreach (DB::table('marketplace_variant_update_results as result')
            ->join('marketplace_variant_update_runs as run', 'run.id', '=', 'result.run_id')
            ->where('run.stock_master_id', $stockMasterId)
            ->where('result.status', 'success')
            ->orderByDesc('result.completed_at')
            ->orderByDesc('result.id')
            ->get([
                'result.account_key',
                'result.run_id',
                'result.requested_stock_qty',
                'result.requested_price',
                'result.completed_at',
            ]) as $result) {
            if (isset($deliveries[$result->account_key])) {
                continue;
            }

            $deliveries[$result->account_key] = [
                'run_id' => (string) $result->run_id,
                'stock_qty' => (int) $result->requested_stock_qty,
                'price' => $result->requested_price,
                'completed_at' => $result->completed_at,
                'status' => 'success',
            ];
        }

        return $deliveries;
    }
}
