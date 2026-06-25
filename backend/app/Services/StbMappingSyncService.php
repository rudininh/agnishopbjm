<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StbMappingSyncService
{
    private const TABLES = [
        'stock_master' => ['id'],
        'sku_mappings' => ['stock_master_id'],
        'shopee_product' => ['item_id'],
        'shopee_product_model' => ['model_id', 'item_id'],
        'shopee_product_image' => ['item_id', 'model_id', 'image_url'],
        'tiktok_products' => ['product_id', 'sku_id'],
    ];

    private const STOCK_COLUMNS = [
        'stock_master' => ['stock_qty'],
        'shopee_product' => ['stock'],
        'shopee_product_model' => ['stock'],
        'tiktok_products' => ['stock_qty'],
    ];

    public function snapshot(): array
    {
        $this->ensureTables();

        $tables = [];
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            foreach (self::TABLES[$table] as $column) {
                if (in_array($column, $columns, true)) {
                    $query->orderBy($column);
                }
            }

            $rows = $query->get($columns)
                ->map(fn ($row): array => (array) $row)
                ->values()
                ->all();

            $tables[$table] = [
                'columns' => $columns,
                'count' => count($rows),
                'rows' => $rows,
            ];
        }

        return [
            'schema_version' => 1,
            'generated_at' => now()->toISOString(),
            'source_host' => gethostname() ?: null,
            'tables' => $tables,
        ];
    }

    public function importSnapshot(array $snapshot, bool $preserveStock = true, bool $dryRun = false): array
    {
        $tables = is_array($snapshot['tables'] ?? null) ? $snapshot['tables'] : [];
        if ($tables === []) {
            return [
                'status' => 'error',
                'message' => 'Snapshot mapping kosong atau format tidak valid.',
                'tables' => [],
            ];
        }

        $this->ensureTables();

        $summary = [];
        $import = function () use ($tables, $preserveStock, $dryRun, &$summary): void {
            foreach (array_keys(self::TABLES) as $table) {
                if (! isset($tables[$table]) || ! is_array($tables[$table])) {
                    continue;
                }

                $summary[$table] = $this->importTable($table, $tables[$table], $preserveStock, $dryRun);
            }
        };

        if ($dryRun) {
            $import();
        } else {
            DB::transaction($import, 3);
            foreach (array_keys($summary) as $table) {
                $this->syncSerialSequence($table);
            }
        }

        return [
            'status' => 'success',
            'message' => $dryRun
                ? 'Dry-run sync mapping STB selesai.'
                : 'Sync mapping STB selesai.',
            'preserve_stock' => $preserveStock,
            'dry_run' => $dryRun,
            'tables' => $summary,
        ];
    }

    public function endpointFromBase(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            $url = trim((string) config('stb.mapping_sync_url', ''));
        }
        if ($url === '') {
            $url = trim((string) config('stb.status_url', ''));
        }

        if ($url === '') {
            return '';
        }

        if (str_contains($url, '/api/runtime/stb-mapping-sync')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return rtrim($base, '/').'/api/runtime/stb-mapping-sync';
    }

    private function importTable(string $table, array $payload, bool $preserveStock, bool $dryRun): array
    {
        if (! Schema::hasTable($table)) {
            return ['received' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'message' => 'Tabel tidak tersedia.'];
        }

        $availableColumns = Schema::getColumnListing($table);
        $receivedRows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($receivedRows as $row) {
            if (! is_array($row)) {
                $skipped++;
                continue;
            }

            $data = array_intersect_key($row, array_flip($availableColumns));
            $condition = $this->conditionForRow($table, $data);
            if ($condition === []) {
                $skipped++;
                continue;
            }

            $exists = $this->rowExists($table, $condition);
            if ($dryRun) {
                $exists ? $updated++ : $inserted++;
                continue;
            }

            if ($exists) {
                $updates = $this->updateData($table, $data, $condition, $preserveStock);
                if ($updates !== []) {
                    DB::table($table)->where($condition)->update($updates);
                }
                $updated++;
            } else {
                DB::table($table)->insert($this->insertData($data));
                $inserted++;
            }
        }

        return [
            'received' => count($receivedRows),
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function conditionForRow(string $table, array $data): array
    {
        if ($table === 'stock_master') {
            if (isset($data['id']) && $this->rowExists($table, ['id' => $data['id']])) {
                return ['id' => $data['id']];
            }
            if (trim((string) ($data['internal_sku'] ?? '')) !== '' && $this->rowExists($table, ['internal_sku' => $data['internal_sku']])) {
                return ['internal_sku' => $data['internal_sku']];
            }
            return isset($data['id']) ? ['id' => $data['id']] : [];
        }

        if ($table === 'tiktok_products') {
            if (trim((string) ($data['product_id'] ?? '')) !== '' && trim((string) ($data['sku_id'] ?? '')) !== '') {
                return ['product_id' => $data['product_id'], 'sku_id' => $data['sku_id']];
            }
            if (isset($data['id'])) {
                return ['id' => $data['id']];
            }
        }

        if ($table === 'shopee_product_image') {
            if (trim((string) ($data['image_url'] ?? '')) === '') {
                return [];
            }
            if (isset($data['id']) && $this->rowExists($table, ['id' => $data['id']])) {
                return ['id' => $data['id']];
            }
        }

        $condition = [];
        foreach (self::TABLES[$table] ?? [] as $column) {
            if (! array_key_exists($column, $data) || ($data[$column] === null && $column !== 'model_id')) {
                return [];
            }
            $condition[$column] = $data[$column];
        }

        return $condition;
    }

    private function rowExists(string $table, array $condition): bool
    {
        return DB::table($table)->where($condition)->exists();
    }

    private function updateData(string $table, array $data, array $condition, bool $preserveStock): array
    {
        foreach (array_keys($condition) as $column) {
            unset($data[$column]);
        }
        if (! array_key_exists('id', $condition)) {
            unset($data['id']);
        }

        if ($preserveStock) {
            foreach (self::STOCK_COLUMNS[$table] ?? [] as $column) {
                unset($data[$column]);
            }
        }

        return $this->normalizeDateTimes($data);
    }

    private function insertData(array $data): array
    {
        return $this->normalizeDateTimes($data);
    }

    private function normalizeDateTimes(array $data): array
    {
        $now = now()->toDateTimeString();
        if (array_key_exists('created_at', $data) && ($data['created_at'] ?? null) === null) {
            $data['created_at'] = $now;
        }
        if (array_key_exists('updated_at', $data) && ($data['updated_at'] ?? null) === null) {
            $data['updated_at'] = $now;
        }

        return $data;
    }

    private function syncSerialSequence(string $table): void
    {
        if (! Schema::hasColumn($table, 'id')) {
            return;
        }

        try {
            $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS sequence", [$table])->sequence ?? null;
            if (! $sequence) {
                return;
            }

            $maxId = (int) DB::table($table)->max('id');
            DB::statement('SELECT setval(?, ?, true)', [$sequence, max(1, $maxId)]);
        } catch (\Throwable) {
            // Non-PostgreSQL or custom sequence. Safe to ignore for cache sync.
        }
    }

    private function ensureTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product (
                item_id BIGINT PRIMARY KEY,
                shop_id BIGINT NULL,
                name TEXT NULL,
                description TEXT NULL,
                category_id BIGINT NULL,
                price_min BIGINT DEFAULT 0,
                price_max BIGINT DEFAULT 0,
                price_before_discount BIGINT DEFAULT 0,
                currency TEXT NULL,
                stock INTEGER DEFAULT 0,
                sold INTEGER DEFAULT 0,
                liked_count INTEGER DEFAULT 0,
                rating NUMERIC(8,2) DEFAULT 0,
                historical_sold INTEGER DEFAULT 0,
                status TEXT NULL,
                create_time TIMESTAMP NULL,
                update_time TIMESTAMP NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product_model (
                model_id TEXT NOT NULL,
                item_id BIGINT NOT NULL,
                name TEXT NULL,
                model_sku TEXT NULL,
                price BIGINT DEFAULT 0,
                original_price BIGINT DEFAULT 0,
                stock INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                PRIMARY KEY (model_id, item_id)
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product_image (
                id BIGSERIAL PRIMARY KEY,
                item_id BIGINT NOT NULL,
                model_id TEXT NULL,
                image_url TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS stock_master (
                id BIGSERIAL PRIMARY KEY,
                internal_sku TEXT UNIQUE NOT NULL,
                shopee_product_id TEXT NULL,
                shopee_sku TEXT NULL,
                shopee_seller_sku TEXT NULL,
                product_name TEXT NULL,
                variant_name TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                tiktok_product_id TEXT NULL,
                tiktok_sku TEXT NULL,
                tiktok_seller_sku TEXT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS sku_mappings (
                id BIGSERIAL PRIMARY KEY,
                stock_master_id BIGINT NOT NULL UNIQUE,
                shopee_item_id TEXT NULL,
                shopee_model_id TEXT NULL,
                tiktok_product_id TEXT NULL,
                tiktok_sku_id TEXT NULL,
                tiktok_sku_name TEXT NULL,
                seller_sku TEXT NULL,
                internal_image_url TEXT NULL,
                shopee_image_url TEXT NULL,
                tiktok_image_url TEXT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_products (
                id BIGSERIAL PRIMARY KEY,
                product_id TEXT NOT NULL,
                product_name TEXT NULL,
                image_url TEXT NULL,
                sku_id TEXT NULL,
                sku_name TEXT NULL,
                seller_sku TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                price BIGINT DEFAULT 0,
                subtotal BIGINT DEFAULT 0,
                product_status TEXT NULL,
                audit_status TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                updated_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        foreach ([
            'stock_master' => [
                'shopee_product_id TEXT NULL',
                'shopee_sku TEXT NULL',
                'shopee_seller_sku TEXT NULL',
                'tiktok_product_id TEXT NULL',
                'tiktok_sku TEXT NULL',
                'tiktok_seller_sku TEXT NULL',
                'is_hidden_from_mapping BOOLEAN DEFAULT FALSE',
                'hidden_from_mapping_reason TEXT NULL',
                'hidden_from_mapping_at TIMESTAMP NULL',
                'hidden_from_mapping_by VARCHAR(255) NULL',
            ],
            'sku_mappings' => [
                'seller_sku TEXT NULL',
                'internal_image_url TEXT NULL',
                'shopee_image_url TEXT NULL',
                'tiktok_image_url TEXT NULL',
            ],
            'shopee_product' => [
                'created_at TIMESTAMP DEFAULT NOW()',
                'updated_at TIMESTAMP DEFAULT NOW()',
                'price_before_discount BIGINT DEFAULT 0',
            ],
            'shopee_product_model' => [
                'created_at TIMESTAMP DEFAULT NOW()',
                'updated_at TIMESTAMP DEFAULT NOW()',
                'original_price BIGINT DEFAULT 0',
                'model_sku TEXT NULL',
            ],
            'shopee_product_image' => [
                'created_at TIMESTAMP DEFAULT NOW()',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_products' => [
                'image_url TEXT NULL',
                'sku_id TEXT NULL',
                'seller_sku TEXT NULL',
                'product_status TEXT NULL',
                'audit_status TEXT NULL',
                'is_active BOOLEAN DEFAULT TRUE',
                'created_at TIMESTAMP DEFAULT NOW()',
            ],
        ] as $table => $definitions) {
            foreach ($definitions as $definition) {
                DB::statement('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS '.$definition);
            }
        }

        DB::statement('CREATE INDEX IF NOT EXISTS sku_mappings_stock_master_id_idx ON sku_mappings (stock_master_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS tiktok_products_product_sku_idx ON tiktok_products (product_id, sku_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS shopee_product_image_lookup_idx ON shopee_product_image (item_id, model_id)');
    }
}
