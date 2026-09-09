<?php

namespace App\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MobileStockAdjustmentService
{
    private const REASONS = [
        'receiving',
        'sale',
        'return',
        'damaged',
        'correction',
    ];

    public function search(string $search, int $perPage): LengthAwarePaginator
    {
        $search = trim($search);
        $perPage = max(1, min(100, $perPage));

        $query = DB::table('stock_master as sm')
            ->select([
                'sm.id',
                'sm.internal_sku',
                'sm.product_name',
                'sm.variant_name',
                'sm.stock_qty',
            ]);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('sm.internal_sku', 'like', '%'.$search.'%')
                    ->orWhere('sm.product_name', 'like', '%'.$search.'%')
                    ->orWhere('sm.variant_name', 'like', '%'.$search.'%')
                    ->orWhereExists(function ($listingQuery) use ($search): void {
                        $listingQuery
                            ->selectRaw('1')
                            ->from('marketplace_listings as ml')
                            ->whereColumn('ml.stock_master_id', 'sm.id')
                            ->where('ml.seller_sku', 'like', '%'.$search.'%');
                    });
            });
        }

        $paginator = $query
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(function (object $stockMaster): array {
                return [
                    'id' => (int) $stockMaster->id,
                    'internal_sku' => (string) $stockMaster->internal_sku,
                    'product_name' => $stockMaster->product_name,
                    'variant_name' => $stockMaster->variant_name,
                    'stock_qty' => (int) $stockMaster->stock_qty,
                    'delivery_states' => $this->deliveryStates((int) $stockMaster->id),
                ];
            }),
        );

        return $paginator;
    }

    public function adjust(
        int $stockMasterId,
        int $delta,
        string $reason,
        ?string $note,
        ?string $operator,
    ): array {
        if ($delta === 0) {
            throw new InvalidArgumentException('Jumlah penyesuaian tidak boleh nol.');
        }

        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Alasan penyesuaian tidak valid.');
        }

        return DB::transaction(function () use ($stockMasterId, $delta, $reason, $note, $operator): array {
            $stockMaster = DB::table('stock_master')
                ->where('id', $stockMasterId)
                ->lockForUpdate()
                ->first();

            if ($stockMaster === null) {
                throw new InvalidArgumentException('Varian Stock Master tidak ditemukan.');
            }

            $beforeQuantity = (int) $stockMaster->stock_qty;
            $afterQuantity = $beforeQuantity + $delta;

            if ($afterQuantity < 0) {
                throw new DomainException('Stok tidak mencukupi.');
            }

            DB::table('stock_master')
                ->where('id', $stockMasterId)
                ->update([
                    'stock_qty' => $afterQuantity,
                    'updated_at' => now(),
                ]);

            $adjustmentId = DB::table('stock_adjustments')->insertGetId([
                'stock_master_id' => $stockMasterId,
                'delta' => $delta,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reason' => $reason,
                'note' => $this->normalizeNote($note),
                'operator' => $this->normalizeOperator($operator),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => (int) $adjustmentId,
                'stock_master_id' => $stockMasterId,
                'delta' => $delta,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reason' => $reason,
                'note' => $this->normalizeNote($note),
                'operator' => $this->normalizeOperator($operator),
                'delivery_states' => $this->deliveryStates($stockMasterId),
            ];
        });
    }

    public function history(int $stockMasterId, int $limit): Collection
    {
        return DB::table('stock_adjustments')
            ->where('stock_master_id', $stockMasterId)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(static fn (object $adjustment): array => [
                'id' => (int) $adjustment->id,
                'stock_master_id' => (int) $adjustment->stock_master_id,
                'delta' => (int) $adjustment->delta,
                'before_quantity' => (int) $adjustment->before_quantity,
                'after_quantity' => (int) $adjustment->after_quantity,
                'reason' => (string) $adjustment->reason,
                'note' => $adjustment->note,
                'operator' => $adjustment->operator,
                'created_at' => $adjustment->created_at,
            ]);
    }

    private function deliveryStates(int $stockMasterId): array
    {
        $accounts = config('marketplace_accounts.accounts', []);
        $activeListings = Schema::hasTable('marketplace_listings')
            ? DB::table('marketplace_listings')
                ->where('stock_master_id', $stockMasterId)
                ->where('is_active', true)
                ->get()
                ->keyBy('account_key')
            : collect();

        $states = [];
        foreach ($accounts as $accountKey => $account) {
            $enabled = (bool) ($account['enabled'] ?? false);
            $listing = $activeListings->get($accountKey);

            $states[$accountKey] = [
                'account_key' => $accountKey,
                'name' => (string) ($account['name'] ?? $accountKey),
                'channel' => (string) ($account['channel'] ?? ''),
                'status' => ! $enabled
                    ? 'disabled'
                    : ($listing === null ? 'mapping_required' : 'mapped_pending'),
                'remote_product_id' => $listing?->remote_product_id,
                'remote_variant_id' => $listing?->remote_variant_id,
            ];
        }

        return $states;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private function normalizeOperator(?string $operator): ?string
    {
        $operator = trim((string) $operator);

        return $operator === '' ? null : $operator;
    }
}
