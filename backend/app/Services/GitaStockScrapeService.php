<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GitaStockScrapeService
{
    private const TERMINAL_STATUSES = ['success', 'needs_login', 'failed'];

    private const MATCH_STATUSES = ['matched', 'unmatched', 'duplicate_master_sku'];

    public function record(array $payload): array
    {
        $capture = $this->validateCapture($payload);

        if ($capture['status'] !== 'success') {
            return DB::transaction(function () use ($capture): array {
                $runId = DB::table('gita_stock_scrape_runs')->insertGetId([
                    'status' => $capture['status'],
                    'started_at' => $capture['started_at'],
                    'finished_at' => $capture['finished_at'],
                    'message' => $capture['message'],
                    'item_count' => 0,
                    'matched_count' => 0,
                    'unmatched_count' => 0,
                    'duplicate_master_count' => 0,
                    'changed_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'run_id' => (int) $runId,
                    'status' => $capture['status'],
                    'summary' => $this->emptySummary(),
                ];
            });
        }

        return DB::transaction(function () use ($capture): array {
            $preparedItems = $this->prepareSuccessItems($capture['items']);
            $summary = $this->summaryFor($preparedItems);
            $now = now();

            $runId = DB::table('gita_stock_scrape_runs')->insertGetId([
                'status' => 'success',
                'started_at' => $capture['started_at'],
                'finished_at' => $capture['finished_at'],
                'message' => null,
                ...$summary,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('gita_stock_scrape_items')->insert(array_map(
                fn (array $item): array => [
                    'run_id' => $runId,
                    'stock_master_id' => $item['stock_master_id'],
                    'sku' => $item['sku'],
                    'stock' => $item['stock'],
                    'gita_product_id' => $item['gita_product_id'],
                    'gita_variant_id' => $item['gita_variant_id'],
                    'previous_stock' => $item['previous_stock'],
                    'match_status' => $item['match_status'],
                    'captured_at' => $item['captured_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $preparedItems,
            ));

            return [
                'run_id' => (int) $runId,
                'status' => 'success',
                'summary' => $summary,
            ];
        });
    }

    public function latestRun(): ?array
    {
        $run = DB::table('gita_stock_scrape_runs')->orderByDesc('id')->first();

        return $run === null ? null : $this->serializeRun($run);
    }

    public function items(array $filters, int $page, int $perPage): array
    {
        $query = DB::table('gita_stock_scrape_items as items')
            ->join('gita_stock_scrape_runs as runs', 'runs.id', '=', 'items.run_id')
            ->select([
                'items.id',
                'items.run_id',
                'items.stock_master_id',
                'items.sku',
                'items.stock',
                'items.gita_product_id',
                'items.gita_variant_id',
                'items.previous_stock',
                'items.match_status',
                'items.captured_at',
                'runs.status as run_status',
                'runs.finished_at as run_finished_at',
            ]);

        $matchStatus = $filters['match_status'] ?? null;
        if (is_string($matchStatus) && in_array($matchStatus, self::MATCH_STATUSES, true)) {
            $query->where('items.match_status', $matchStatus);
        }

        if (($filters['changed_only'] ?? false) === true) {
            $query->whereNotNull('items.previous_stock')
                ->whereColumn('items.stock', '<>', 'items.previous_stock');
        }

        $total = $query->count();
        $rows = $query
            ->orderByDesc('items.run_id')
            ->orderByDesc('items.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($item): array => $this->serializeItem($item))
            ->all();

        return [
            'items' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    private function validateCapture(array $payload): array
    {
        $status = $payload['status'] ?? null;
        if (! is_string($status) || ! in_array($status, self::TERMINAL_STATUSES, true)) {
            $this->invalid('status', 'Status pengambilan stok Gita tidak valid.');
        }

        $startedAt = $this->timestamp($payload['started_at'] ?? null, 'started_at');
        $finishedAt = $this->timestamp($payload['finished_at'] ?? null, 'finished_at');
        if ($finishedAt->lessThan($startedAt)) {
            $this->invalid('finished_at', 'Waktu selesai tidak boleh sebelum waktu mulai.');
        }

        if ($status !== 'success') {
            if (array_key_exists('items', $payload)) {
                $this->invalid('items', 'Run terminal tanpa sukses tidak boleh berisi item.');
            }

            $message = $this->nonBlankString($payload['message'] ?? null, 'message', 2000);

            return [
                'status' => $status,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'message' => $message,
            ];
        }

        if (array_key_exists('message', $payload) || ! array_key_exists('items', $payload) || ! is_array($payload['items']) || $payload['items'] === []) {
            $this->invalid('items', 'Run sukses harus berisi item lengkap tanpa pesan terminal.');
        }

        $maxItems = (int) config('gita_stock_scraper.max_items', 5000);
        if (count($payload['items']) > $maxItems) {
            $this->invalid('items', 'Jumlah item melebihi batas pengambilan.');
        }

        $items = [];
        $seenSkus = [];
        foreach ($payload['items'] as $index => $item) {
            if (! is_array($item)) {
                $this->invalid('items.'.$index, 'Item pengambilan stok tidak valid.');
            }

            $sku = $this->nonBlankString($item['sku'] ?? null, 'items.'.$index.'.sku', 150);
            if (isset($seenSkus[$sku])) {
                $this->invalid('items.'.$index.'.sku', 'SKU Gita duplikat dalam satu pengambilan.');
            }
            $seenSkus[$sku] = true;

            $stock = $item['stock'] ?? null;
            if (! is_int($stock) || $stock < 0) {
                $this->invalid('items.'.$index.'.stock', 'Stok Gita harus berupa bilangan bulat tidak negatif.');
            }

            $capturedAt = $this->timestamp($item['captured_at'] ?? null, 'items.'.$index.'.captured_at');
            if ($capturedAt->lessThan($startedAt) || $capturedAt->greaterThan($finishedAt)) {
                $this->invalid('items.'.$index.'.captured_at', 'Waktu item harus berada dalam rentang pengambilan.');
            }

            $items[] = [
                'sku' => $sku,
                'stock' => $stock,
                'gita_product_id' => $this->optionalString($item['gita_product_id'] ?? null, 'items.'.$index.'.gita_product_id', 100),
                'gita_variant_id' => $this->optionalString($item['gita_variant_id'] ?? null, 'items.'.$index.'.gita_variant_id', 100),
                'captured_at' => $capturedAt,
            ];
        }

        return [
            'status' => 'success',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'items' => $items,
        ];
    }

    private function prepareSuccessItems(array $items): array
    {
        $masterRows = DB::table('stock_master')
            ->select(['id', 'internal_sku'])
            ->whereIn('internal_sku', array_column($items, 'sku'))
            ->get();

        $mastersBySku = [];
        foreach ($masterRows as $master) {
            $sku = (string) $master->internal_sku;
            if (in_array($sku, array_column($items, 'sku'), true)) {
                $mastersBySku[$sku][] = (int) $master->id;
            }
        }

        $matchedMasterIds = [];
        foreach ($mastersBySku as $masterIds) {
            if (count($masterIds) === 1) {
                $matchedMasterIds[] = $masterIds[0];
            }
        }
        $previousStocks = $this->previousStocks($matchedMasterIds);

        foreach ($items as &$item) {
            $masterIds = $mastersBySku[$item['sku']] ?? [];
            if (count($masterIds) === 1) {
                $item['stock_master_id'] = $masterIds[0];
                $item['match_status'] = 'matched';
                $item['previous_stock'] = $previousStocks[$masterIds[0]] ?? null;
                continue;
            }

            $item['stock_master_id'] = null;
            $item['previous_stock'] = null;
            $item['match_status'] = $masterIds === [] ? 'unmatched' : 'duplicate_master_sku';
        }
        unset($item);

        return $items;
    }

    private function previousStocks(array $stockMasterIds): array
    {
        if ($stockMasterIds === []) {
            return [];
        }

        $rows = DB::table('gita_stock_scrape_items as items')
            ->join('gita_stock_scrape_runs as runs', 'runs.id', '=', 'items.run_id')
            ->select(['items.stock_master_id', 'items.stock'])
            ->where('runs.status', 'success')
            ->whereIn('items.stock_master_id', $stockMasterIds)
            ->orderByDesc('items.run_id')
            ->orderByDesc('items.id')
            ->get();

        $previousStocks = [];
        foreach ($rows as $row) {
            $stockMasterId = (int) $row->stock_master_id;
            $previousStocks[$stockMasterId] ??= (int) $row->stock;
        }

        return $previousStocks;
    }

    private function summaryFor(array $items): array
    {
        $summary = $this->emptySummary();
        $summary['item_count'] = count($items);

        foreach ($items as $item) {
            if ($item['match_status'] === 'matched') {
                $summary['matched_count'] += 1;
                if ($item['previous_stock'] !== null && $item['previous_stock'] !== $item['stock']) {
                    $summary['changed_count'] += 1;
                }
                continue;
            }

            if ($item['match_status'] === 'unmatched') {
                $summary['unmatched_count'] += 1;
                continue;
            }

            $summary['duplicate_master_count'] += 1;
        }

        return $summary;
    }

    private function emptySummary(): array
    {
        return [
            'item_count' => 0,
            'matched_count' => 0,
            'unmatched_count' => 0,
            'duplicate_master_count' => 0,
            'changed_count' => 0,
        ];
    }

    private function timestamp(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            $this->invalid($field, 'Waktu pengambilan tidak valid.');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            $this->invalid($field, 'Waktu pengambilan tidak valid.');
        }
    }

    private function nonBlankString(mixed $value, string $field, int $maxLength): string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->invalid($field, 'Nilai wajib diisi.');
        }

        $normalized = trim($value);
        if (mb_strlen($normalized) > $maxLength) {
            $this->invalid($field, 'Nilai terlalu panjang.');
        }

        return $normalized;
    }

    private function optionalString(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->nonBlankString($value, $field, $maxLength);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function serializeRun(object $run): array
    {
        return [
            'id' => (int) $run->id,
            'status' => (string) $run->status,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'message' => $run->message,
            'summary' => [
                'item_count' => (int) $run->item_count,
                'matched_count' => (int) $run->matched_count,
                'unmatched_count' => (int) $run->unmatched_count,
                'duplicate_master_count' => (int) $run->duplicate_master_count,
                'changed_count' => (int) $run->changed_count,
            ],
        ];
    }

    private function serializeItem(object $item): array
    {
        return [
            'id' => (int) $item->id,
            'run_id' => (int) $item->run_id,
            'stock_master_id' => $item->stock_master_id === null ? null : (int) $item->stock_master_id,
            'sku' => (string) $item->sku,
            'stock' => (int) $item->stock,
            'gita_product_id' => $item->gita_product_id,
            'gita_variant_id' => $item->gita_variant_id,
            'previous_stock' => $item->previous_stock === null ? null : (int) $item->previous_stock,
            'match_status' => (string) $item->match_status,
            'captured_at' => $item->captured_at,
            'run_status' => (string) $item->run_status,
            'run_finished_at' => $item->run_finished_at,
        ];
    }

}
