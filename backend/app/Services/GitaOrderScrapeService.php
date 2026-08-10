<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GitaOrderScrapeService
{
    private const TERMINAL_STATUSES = ['success', 'needs_login', 'failed'];

    private const TAB_STATUSES = ['to_ship', 'shipped'];

    private const MATCH_STATUSES = ['matched', 'unmatched', 'duplicate_master_sku'];

    public function record(array $payload): array
    {
        $capture = $this->validateCapture($payload);

        if ($capture['status'] !== 'success') {
            return DB::transaction(function () use ($capture): array {
                $runId = DB::table('gita_order_scrape_runs')->insertGetId([
                    'status' => $capture['status'],
                    'started_at' => $capture['started_at'],
                    'finished_at' => $capture['finished_at'],
                    'message' => $capture['message'],
                    ...$this->emptySummary(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->serializeRun((object) [
                    'id' => $runId,
                    'status' => $capture['status'],
                    'started_at' => $capture['started_at'],
                    'finished_at' => $capture['finished_at'],
                    'message' => $capture['message'],
                    ...$this->emptySummary(),
                ]);
            });
        }

        $items = $this->matchItems($capture['items']);
        $summary = $this->summaryFor($items);

        return DB::transaction(function () use ($capture, $items, $summary): array {
            $runId = DB::table('gita_order_scrape_runs')->insertGetId([
                'status' => 'success',
                'started_at' => $capture['started_at'],
                'finished_at' => $capture['finished_at'],
                'message' => null,
                ...$summary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('gita_order_scrape_items')->insert(array_map(
                fn (array $item): array => [
                    'run_id' => $runId,
                    'stock_master_id' => $item['stock_master_id'],
                    'seller_order_id' => $item['seller_order_id'],
                    'tab_status' => $item['tab_status'],
                    'seller_sku' => $item['seller_sku'],
                    'product_title' => $item['product_title'],
                    'variant_label' => $item['variant_label'],
                    'quantity' => $item['quantity'],
                    'match_status' => $item['match_status'],
                    'captured_at' => $item['captured_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $items
            ));

            return $this->serializeRun((object) [
                'id' => $runId,
                'status' => 'success',
                'started_at' => $capture['started_at'],
                'finished_at' => $capture['finished_at'],
                'message' => null,
                ...$summary,
            ]);
        });
    }

    public function latestRun(): ?array
    {
        $run = DB::table('gita_order_scrape_runs')->orderByDesc('id')->first();

        return $run === null ? null : $this->serializeRun($run);
    }

    public function items(array $filters, int $page, int $perPage): array
    {
        $latestRunId = DB::table('gita_order_scrape_runs')->max('id');
        $query = DB::table('gita_order_scrape_items as items')
            ->join('gita_order_scrape_runs as runs', 'runs.id', '=', 'items.run_id')
            ->select([
                'items.*',
                'runs.status as run_status',
                'runs.finished_at as run_finished_at',
            ])
            ->orderByDesc('items.run_id')
            ->orderByDesc('items.id');

        if ($latestRunId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('items.run_id', $latestRunId);
        }

        if ($filters['match_status'] !== null) {
            $query->where('items.match_status', $filters['match_status']);
        }

        if ($filters['tab_status'] !== null) {
            $query->where('items.tab_status', $filters['tab_status']);
        }

        $total = $query->count();
        $rows = $query->forPage($page, $perPage)->get();

        return [
            'items' => $rows->map(fn (object $item): array => $this->serializeItem($item))->all(),
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
        $status = $this->nonBlankString($payload['status'] ?? null, 'status', 32);
        if (! in_array($status, self::TERMINAL_STATUSES, true)) {
            $this->invalid('status', 'Status pengambilan tidak valid.');
        }

        $capture = [
            'status' => $status,
            'started_at' => $this->timestamp($payload['started_at'] ?? null, 'started_at'),
            'finished_at' => $this->timestamp($payload['finished_at'] ?? null, 'finished_at'),
            'message' => $this->optionalString($payload['message'] ?? null, 'message', 2000),
        ];

        if ($status !== 'success') {
            if (array_key_exists('items', $payload)) {
                $this->invalid('items', 'Run non-berhasil tidak boleh memiliki item.');
            }

            return $capture;
        }

        if (! is_array($payload['items'] ?? null) || $payload['items'] === []) {
            $this->invalid('items', 'Item pesanan wajib diisi.');
        }

        $maxItems = (int) config('gita_order_scraper.max_items', 5000);
        if (count($payload['items']) > $maxItems) {
            $this->invalid('items', 'Jumlah item pesanan melebihi batas.');
        }

        $capture['items'] = $this->validateItems($payload['items']);

        return $capture;
    }

    private function validateItems(array $items): array
    {
        $validated = [];
        $seen = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $this->invalid('items', 'Item pesanan tidak valid.');
            }

            $line = [
                'seller_order_id' => $this->nonBlankString($item['seller_order_id'] ?? null, 'items.'.$index.'.seller_order_id', 100),
                'tab_status' => $this->nonBlankString($item['tab_status'] ?? null, 'items.'.$index.'.tab_status', 32),
                'seller_sku' => $this->nonBlankString($item['seller_sku'] ?? null, 'items.'.$index.'.seller_sku', 150),
                'product_title' => $this->nonBlankString($item['product_title'] ?? null, 'items.'.$index.'.product_title', 500),
                'variant_label' => $this->optionalString($item['variant_label'] ?? '', 'items.'.$index.'.variant_label', 300) ?? '',
                'quantity' => $this->positiveInteger($item['quantity'] ?? null, 'items.'.$index.'.quantity'),
                'captured_at' => $this->timestamp($item['captured_at'] ?? null, 'items.'.$index.'.captured_at'),
            ];

            if (! in_array($line['tab_status'], self::TAB_STATUSES, true)) {
                $this->invalid('items.'.$index.'.tab_status', 'Status tab pesanan tidak valid.');
            }

            $key = implode(chr(0), [$line['seller_order_id'], $line['seller_sku'], $line['variant_label']]);
            if (isset($seen[$key])) {
                $this->invalid('items', 'Baris pesanan duplikat tidak diizinkan.');
            }

            $seen[$key] = true;
            $validated[] = $line;
        }

        return $validated;
    }

    private function matchItems(array $items): array
    {
        $skus = array_values(array_unique(array_column($items, 'seller_sku')));
        $masterRows = DB::table('stock_master')
            ->select(['id', 'internal_sku'])
            ->whereIn('internal_sku', $skus)
            ->get();

        $masterIdsBySku = [];
        foreach ($masterRows as $master) {
            $masterIdsBySku[(string) $master->internal_sku][] = (int) $master->id;
        }

        foreach ($items as &$item) {
            $masterIds = $masterIdsBySku[$item['seller_sku']] ?? [];

            if (count($masterIds) === 1) {
                $item['stock_master_id'] = $masterIds[0];
                $item['match_status'] = 'matched';
                continue;
            }

            $item['stock_master_id'] = null;
            $item['match_status'] = $masterIds === [] ? 'unmatched' : 'duplicate_master_sku';
        }
        unset($item);

        return $items;
    }

    private function summaryFor(array $items): array
    {
        $summary = $this->emptySummary();
        $summary['item_count'] = count($items);

        foreach ($items as $item) {
            $summary['quantity_count'] += $item['quantity'];

            if ($item['match_status'] === 'matched') {
                $summary['matched_count'] += 1;
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
            'quantity_count' => 0,
            'matched_count' => 0,
            'unmatched_count' => 0,
            'duplicate_master_count' => 0,
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

    private function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $this->invalid($field, 'Jumlah item tidak valid.');
        }

        $parsed = (int) $value;
        if ($parsed < 1) {
            $this->invalid($field, 'Jumlah item harus minimal satu.');
        }

        return $parsed;
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
        if ($value === null || $value === '') {
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
                'quantity_count' => (int) $run->quantity_count,
                'matched_count' => (int) $run->matched_count,
                'unmatched_count' => (int) $run->unmatched_count,
                'duplicate_master_count' => (int) $run->duplicate_master_count,
            ],
        ];
    }

    private function serializeItem(object $item): array
    {
        return [
            'id' => (int) $item->id,
            'run_id' => (int) $item->run_id,
            'stock_master_id' => $item->stock_master_id === null ? null : (int) $item->stock_master_id,
            'seller_order_id' => (string) $item->seller_order_id,
            'tab_status' => (string) $item->tab_status,
            'seller_sku' => (string) $item->seller_sku,
            'product_title' => (string) $item->product_title,
            'variant_label' => (string) $item->variant_label,
            'quantity' => (int) $item->quantity,
            'match_status' => (string) $item->match_status,
            'captured_at' => $item->captured_at,
            'run_status' => (string) $item->run_status,
            'run_finished_at' => $item->run_finished_at,
        ];
    }
}
