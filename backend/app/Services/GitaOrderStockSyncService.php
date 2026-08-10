<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GitaOrderStockSyncService
{
    public function __construct(private readonly MarketplaceSyncService $marketplaceSync)
    {
    }

    public function syncItem(int $itemId): array
    {
        return DB::transaction(function () use ($itemId): array {
            $latestRun = DB::table('gita_order_scrape_runs')->orderByDesc('id')->first();
            $item = $latestRun && $latestRun->status === 'success'
                ? DB::table('gita_order_scrape_items')->where('id', $itemId)->where('run_id', $latestRun->id)->lockForUpdate()->first()
                : null;

            if (! $item) {
                return $this->blocked('Baris pesanan tidak berada pada pengambilan berhasil terbaru.');
            }
            if ($item->match_status !== 'matched' || $item->stock_master_id === null) {
                return $this->blocked('SKU belum dapat disinkronkan ke Stock Master.');
            }

            $ledger = DB::table('gita_order_stock_syncs')
                ->where('seller_order_id', $item->seller_order_id)
                ->where('seller_sku', $item->seller_sku)
                ->lockForUpdate()
                ->first();

            if ($ledger && $ledger->status === 'synced') {
                if ((int) $ledger->quantity !== (int) $item->quantity) {
                    return $this->blocked('Qty pesanan berubah setelah sinkronisasi sebelumnya.');
                }

                return $this->result($ledger, true);
            }

            $mapping = $this->marketplaceSync->findSkuMappingByStockMasterId((int) $item->stock_master_id);
            $master = DB::table('stock_master')->where('id', $item->stock_master_id)->lockForUpdate()->first();
            if (! $mapping || ! $master) {
                return $this->saveBlocked($ledger, $item, 'Stock Master tidak ditemukan atau mapping tidak lengkap.');
            }

            $oldStock = (int) $master->stock_qty;
            $newStock = $oldStock - (int) $item->quantity;
            if ($newStock < 0) {
                return $this->saveBlocked($ledger, $item, 'Stok tidak cukup untuk pesanan Gita ini.');
            }

            $ledgerId = $this->saveProcessing($ledger, $item, $oldStock, $newStock);
            $shopee = $this->marketplaceSync->pushTargetStock($mapping, 'shopee', $newStock, true);
            $tiktok = $this->marketplaceSync->pushTargetStock($mapping, 'tiktok', $newStock, true);
            $success = $this->isSuccess($shopee) && $this->isSuccess($tiktok);
            $status = $success ? 'synced' : 'failed';
            $message = $success ? 'Sudah Disinkronkan' : 'Push marketplace gagal. Coba lagi.';
            $sku = $this->marketplaceSync->canonicalSku($mapping, (string) $item->seller_sku);

            if ($success) {
                $this->marketplaceSync->updateLocalStock($mapping, 'shopee', $newStock);
                $this->marketplaceSync->updateLocalStock($mapping, 'tiktok', $newStock);
            }

            DB::table('gita_order_stock_syncs')->where('id', $ledgerId)->update([
                'status' => $status,
                'message' => $message,
                'synced_at' => $success ? now() : null,
                'updated_at' => now(),
            ]);

            foreach (['shopee' => $shopee, 'tiktok' => $tiktok] as $target => $push) {
                $this->marketplaceSync->logSync('gita_order', $target, $sku, $oldStock, $newStock, $this->isSuccess($push) ? 'success' : 'error', 'Pesanan Gita '.$item->seller_order_id.': '.($push['message'] ?? '-'));
                $this->marketplaceSync->updateStatus($target, ['last_sync_at' => now(), 'status' => $this->isSuccess($push) ? 'connected' : 'disconnected']);
            }

            return [
                'status' => $status,
                'message' => $message,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'idempotent' => false,
            ];
        });
    }

    public function syncLatest(): array
    {
        $latestRun = DB::table('gita_order_scrape_runs')->orderByDesc('id')->first();
        if (! $latestRun || $latestRun->status !== 'success') {
            return ['summary' => ['total' => 0, 'synced' => 0, 'failed' => 0, 'blocked' => 0], 'items' => []];
        }

        $items = DB::table('gita_order_scrape_items')
            ->where('run_id', $latestRun->id)
            ->where('match_status', 'matched')
            ->orderBy('id')
            ->pluck('id');
        $results = [];
        $summary = ['total' => 0, 'synced' => 0, 'failed' => 0, 'blocked' => 0];

        foreach ($items as $itemId) {
            $result = $this->syncItem((int) $itemId);
            $results[] = ['item_id' => (int) $itemId, ...$result];
            $summary['total'] += 1;
            if (array_key_exists($result['status'], $summary)) {
                $summary[$result['status']] += 1;
            }
        }

        return ['summary' => $summary, 'items' => $results];
    }

    private function saveProcessing(?object $ledger, object $item, int $oldStock, int $newStock): int
    {
        $values = [
            'stock_master_id' => $item->stock_master_id,
            'collector_item_id' => $item->id,
            'quantity' => $item->quantity,
            'status' => 'processing',
            'message' => 'Sedang Diproses',
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'synced_at' => null,
            'updated_at' => now(),
        ];
        if ($ledger) {
            DB::table('gita_order_stock_syncs')->where('id', $ledger->id)->update($values);
            return (int) $ledger->id;
        }

        return (int) DB::table('gita_order_stock_syncs')->insertGetId([
            ...$values,
            'seller_order_id' => $item->seller_order_id,
            'seller_sku' => $item->seller_sku,
            'created_at' => now(),
        ]);
    }

    private function saveBlocked(?object $ledger, object $item, string $message): array
    {
        $values = ['status' => 'blocked', 'message' => $message, 'updated_at' => now()];
        if ($ledger) {
            DB::table('gita_order_stock_syncs')->where('id', $ledger->id)->update($values);
        } else {
            DB::table('gita_order_stock_syncs')->insert([
                ...$values,
                'seller_order_id' => $item->seller_order_id,
                'seller_sku' => $item->seller_sku,
                'stock_master_id' => $item->stock_master_id,
                'collector_item_id' => $item->id,
                'quantity' => $item->quantity,
                'created_at' => now(),
            ]);
        }

        return $this->blocked($message);
    }

    private function result(object $ledger, bool $idempotent): array
    {
        return ['status' => (string) $ledger->status, 'message' => (string) $ledger->message, 'old_stock' => $ledger->old_stock, 'new_stock' => $ledger->new_stock, 'idempotent' => $idempotent];
    }

    private function blocked(string $message): array
    {
        return ['status' => 'blocked', 'message' => $message, 'old_stock' => null, 'new_stock' => null, 'idempotent' => false];
    }

    private function isSuccess(array $result): bool
    {
        return in_array($result['status'] ?? '', ['success', 'dry_run'], true);
    }
}
