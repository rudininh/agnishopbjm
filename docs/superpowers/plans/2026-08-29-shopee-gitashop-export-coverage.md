# Shopee Gitashop Export Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membuat export Mass Update Gitashopcollection berhenti melewatkan produk atau varian sumber secara diam-diam dengan klasifikasi coverage deterministik, workbook yang hanya memuat target valid, laporan pengecualian lengkap, dan UI preflight pada `/marketplace/import`.

**Architecture:** `ShopeeGitaExportCoverageService` menjadi unit murni yang mengklasifikasikan snapshot katalog sumber dan pemetaan workbook target, lalu menghasilkan revision SHA-256. `MarketplaceImportController` tetap menjadi adapter workbook yang sudah mapan, tetapi seluruh download manual menggunakan satu snapshot coverage, memfilter area data workbook berdasarkan target valid, dan menyertakan laporan CSV. Frontend memakai helper state murni agar ringkasan, label parsial, pencarian, dan download revision-bound dapat diuji tanpa framework test tambahan.

**Tech Stack:** PHP 8.3, Laravel 11, PHPUnit, PostgreSQL production/SQLite `:memory:` tests, `ZipArchive`/DOM XML untuk XLSX, Vue 3 Composition API, Axios, Node test runner, Vite.

## Global Constraints

- Jangan pernah memakai `item_id` atau `model_id` Shopee Agni Shop Banjarmasin sebagai identitas target Gitashopcollection.
- Mass Update hanya memuat mapping target yang nyata, unik, dan berstatus `mass_update_ready`.
- Semua varian sumber harus mempunyai tepat satu status: `mass_update_ready`, `new_product`, `new_variant`, `sku_changed`, atau `blocked`.
- Normalisasi seller SKU hanya trim dan case-insensitive; nilai asli tetap ditampilkan dan ditulis.
- Normalisasi nama varian hanya Unicode, kapitalisasi, dan spasi; tanda baca tidak dibuang.
- Lebih dari satu kecocokan SKU atau nama selalu `blocked`; jangan memilih baris pertama.
- Download harus terikat revision dan menolak snapshot basi dengan HTTP 409.
- ZIP Mass Update harus menyertakan `coverage_report.csv`.
- Plan ini tidak membuat atau mengunggah listing baru. Generator creation resmi dikerjakan sebagai plan terpisah setelah satu workbook Mass Upload/Add New Product resmi Shopee tersedia sebagai fixture tervalidasi.
- Test suite tidak boleh melakukan HTTP marketplace atau memakai database PostgreSQL lokal.
- Jalankan PHPUnit dari root repository agar `backend/phpunit.xml` memaksa SQLite `:memory:`.
- Perubahan frontend dipublikasikan dengan build `frontend/dist`, lalu `index.html` dan hashed assets disalin ke `backend/public` sesuai prosedur proyek.

---

## File Structure

- Create `backend/app/Services/ShopeeGitaExportCoverageService.php` — klasifikasi murni, summary, template metadata, revision, dan stale-revision assertion.
- Create `backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php` — kontrak seluruh status, ambiguity, summary, dan revision.
- Modify `backend/app/Http/Controllers/MarketplaceImportController.php` — perluas mapping target, bangun snapshot, endpoint coverage/exceptions, filter workbook, report CSV, dan revision-bound download.
- Modify `backend/config/shopee_mass_upload.php` — satu path template target yang dapat diarahkan ke fixture mandiri saat test.
- Modify `backend/routes/api.php` — route coverage dan exceptions.
- Create `backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php` — kontrak HTTP coverage, CSV, stale revision, dan safe filtering.
- Create `backend/tests/Support/CreatesMinimalShopeeWorkbook.php` — fixture XLSX kecil dan mandiri untuk test workbook tanpa file produksi.
- Modify `backend/app/Services/ShopeeMassUploadManifestService.php` — pesan mismatch menyertakan jumlah ready/exception dan tetap fail-closed pada automatic upload dalam phase ini.
- Modify `backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php` — regression pesan coverage mismatch.
- Create `frontend/src/pages/shopeeGitaExportCoverageState.js` — view model, filter, label, filename, dan helper blob download.
- Create `frontend/tests/shopeeGitaExportCoverageState.test.js` — test state tanpa mount Vue.
- Modify `frontend/src/services/index.js` — API coverage dan revision-bound blob downloads.
- Modify `frontend/src/pages/ImportMarketplace.vue` — preflight card, exception table, partial labels, dan download via Axios blob.
- Modify `frontend/src/pages/gitashopMassUploadState.js` — copy status automatic upload ketika coverage tidak lengkap.
- Modify `frontend/tests/gitashopMassUploadState.test.js` — regression copy blocked coverage.
- Modify `backend/public/index.html` and `backend/public/assets/*` — publish hasil build; jangan menghapus asset lama yang masih direferensikan user changes.

---

### Task 1: Deterministic Coverage Classifier

**Files:**
- Create: `backend/app/Services/ShopeeGitaExportCoverageService.php`
- Test: `backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php`

**Interfaces:**
- Consumes: iterable source rows with `item_id`, `model_id`, `seller_sku`, `product_name`, `variant_name`; iterable mapping rows with `source_item_id`, `source_seller_sku`, `target_item_id`, `target_model_id`, `target_product_name`, `target_variant_name`; template metadata with `sales_sha256` and `sales_last_modified_at`.
- Produces: `analyze(iterable $sources, iterable $mappings, array $templateMetadata): array` and `assertRevision(array $snapshot, string $revision): void`.
- Snapshot shape: `revision`, `generated_at`, `template`, `summary`, `items`, and `ready_targets`.
- Every `items` row has exactly: `status`, `reason`, `source_item_id`, `source_model_id`, `product_name`, `variant_name`, `source_seller_sku`, `target_item_id`, `target_model_id`, and `target_seller_sku`.
- `summary` has exactly: `source_products`, `source_variants`, `ready_products`, `ready_variants`, `exception_products`, `exception_variants`, `products_by_status`, and `variants_by_status`.

- [ ] **Step 1: Write the failing classification tests**

Create table-driven tests with these exact cases:

```php
public function test_classifies_every_source_variant_exactly_once(): void
{
    $sources = collect([
        $this->source('100', '1', 'INT-100-RED', 'Produk Lama', 'Red'),
        $this->source('100', '2', 'INT-100-BLUE-NEW', 'Produk Lama', 'Blue'),
        $this->source('100', '3', 'INT-100-GREEN', 'Produk Lama', 'Green'),
        $this->source('200', '4', 'INT-200-BLACK', 'Produk Baru', 'Black'),
    ]);
    $mappings = collect([
        $this->mapping('100', 'INT-100-RED', '900', '91', 'Produk Lama', 'Red'),
        $this->mapping('100', 'INT-100-BLUE-OLD', '900', '92', 'Produk Lama', 'Blue'),
    ]);

    $result = app(ShopeeGitaExportCoverageService::class)->analyze(
        $sources,
        $mappings,
        ['sales_sha256' => str_repeat('a', 64), 'sales_last_modified_at' => '2026-08-29T10:00:00+08:00'],
    );

    $this->assertSame([
        'mass_update_ready' => 1,
        'new_product' => 1,
        'new_variant' => 1,
        'sku_changed' => 1,
        'blocked' => 0,
    ], $result['summary']['variants_by_status']);
    $this->assertCount(4, $result['items']);
    $this->assertSame(4, array_sum($result['summary']['variants_by_status']));
    $this->assertSame([['target_item_id' => '900', 'target_model_id' => '91']], $result['ready_targets']);
}
```

Add focused ambiguity tests:

```php
public function test_blocks_duplicate_exact_sku_mappings(): void
{
    $result = app(ShopeeGitaExportCoverageService::class)->analyze(
        [$this->source('100', '1', 'INT-100-RED', 'Produk', 'Red')],
        [
            $this->mapping('100', 'INT-100-RED', '900', '91', 'Produk', 'Red'),
            $this->mapping('100', 'INT-100-RED', '900', '92', 'Produk', 'Red'),
        ],
        $this->metadata(),
    );

    $this->assertSame('blocked', $result['items'][0]['status']);
    $this->assertSame('duplicate_target_sku_mapping', $result['items'][0]['reason']);
}

public function test_blocks_multiple_normalized_name_matches(): void
{
    $result = app(ShopeeGitaExportCoverageService::class)->analyze(
        [$this->source('100', '1', 'INT-100-BLUE-NEW', 'Produk', 'Blue')],
        [
            $this->mapping('100', 'INT-100-BLUE-A', '900', '91', 'Produk', 'Blue'),
            $this->mapping('100', 'INT-100-BLUE-B', '900', '92', 'Produk', ' blue '),
        ],
        $this->metadata(),
    );

    $this->assertSame('blocked', $result['items'][0]['status']);
    $this->assertSame('ambiguous_target_variant_name', $result['items'][0]['reason']);
}
```

Add three separate fixed-assertion tests: `Blue.Grey` must not equal `Blue Grey`; reversing both input iterables must preserve revision while changing one source SKU, one target model, or `sales_sha256` must change it; and `assertRevision($snapshot, 'stale')` must throw a Symfony HTTP exception whose status code is 409.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php
```

Expected: FAIL because `ShopeeGitaExportCoverageService` does not exist.

- [ ] **Step 3: Implement the minimal classifier**

Use these public signatures:

```php
final class ShopeeGitaExportCoverageService
{
    public const STATUSES = [
        'mass_update_ready',
        'new_product',
        'new_variant',
        'sku_changed',
        'blocked',
    ];

    public function analyze(iterable $sources, iterable $mappings, array $templateMetadata): array;

    public function assertRevision(array $snapshot, string $revision): void
    {
        abort_unless(
            trim($revision) !== '' && hash_equals($snapshot['revision'], trim($revision)),
            409,
            'Katalog sumber atau template Gitashop berubah. Muat ulang preflight sebelum download.'
        );
    }
}
```

Implementation rules:

1. Convert rows to trimmed scalar arrays; reject no source row silently.
2. Sort canonical source and mapping arrays by stable identity before hashing.
3. Build exact index by `lower(source_item_id|source_seller_sku)`.
4. Build name index by `source_item_id|normalized_target_variant_name`.
5. Mark every source identity duplicated in the source list as `blocked` with reason `duplicate_source_identity`.
6. Apply status precedence: duplicate/ambiguity → `blocked`; unique exact mapping → `mass_update_ready`; no mapping for item → `new_product`; unique normalized-name mapping → `sku_changed`; otherwise → `new_variant`.
7. Build `ready_targets` from unique target item/model pairs only.
8. Calculate product counts by unique source item per status and assert variant status totals equal source count before returning.
9. Hash only canonical source, canonical mapping, and template metadata; exclude `generated_at`.

Use exact non-ambiguous reason codes: `matched_target_sku`, `missing_target_product`, `missing_target_variant`, `target_variant_sku_changed`, `duplicate_source_identity`, `duplicate_target_sku_mapping`, `ambiguous_target_variant_name`, and `duplicate_target_identity`.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run the command from Step 2.

Expected: PASS with no marketplace HTTP calls.

- [ ] **Step 5: Commit the classifier**

```powershell
git add backend/app/Services/ShopeeGitaExportCoverageService.php backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php
git commit -m "feat: classify Gitashop export coverage"
```

---

### Task 2: Coverage Snapshot and Read-Only API

**Files:**
- Modify: `backend/app/Http/Controllers/MarketplaceImportController.php`
- Modify: `backend/config/shopee_mass_upload.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php`

**Interfaces:**
- Consumes: `ShopeeGitaExportCoverageService::analyze()` from Task 1 and existing `shopeeGitaSourceVariants()` / `shopeeGitaSalesTargetMappings()`.
- Produces: `MarketplaceImportController::shopeeGitaCoverage(Request): JsonResponse`, protected `currentShopeeGitaCoverage(): array`, public `shopeeGitaTemplateMetadata(): array`, `shopeeGitaTemplatePath(string $filename): string`, and `GET /api/marketplace/import/shopee-gita/coverage`.

- [ ] **Step 1: Write failing mapping and API tests**

Add a regression around the Sales Info reader:

```php
public function test_sales_target_mapping_exposes_target_names_for_sku_change_detection(): void
{
    $mapping = collect(app(MarketplaceImportController::class)->shopeeGitaSalesTargetMappings())
        ->first(fn (array $row) => $row['target_item_id'] !== '' && $row['target_model_id'] !== '');

    $this->assertArrayHasKey('target_product_name', $mapping);
    $this->assertArrayHasKey('target_variant_name', $mapping);
}
```

Add an endpoint contract test using a partial controller mock bound in the container:

```php
public function test_coverage_endpoint_returns_revision_summary_and_exceptions(): void
{
    $controller = Mockery::mock(MarketplaceImportController::class, [
        app(MarketplaceSyncService::class),
        app(ShopeeGitaExportCoverageService::class),
    ])->makePartial();
    $controller->shouldReceive('shopeeGitaSourceVariants')->andReturn(collect([
        (object) $this->source('200', '4', 'INT-200-BLACK', 'Produk Baru', 'Black'),
    ]));
    $controller->shouldReceive('shopeeGitaSalesTargetMappings')->andReturn(collect());
    $controller->shouldReceive('shopeeGitaTemplateMetadata')->andReturn([
        'sales_sha256' => str_repeat('a', 64),
        'sales_last_modified_at' => '2026-08-29T10:00:00+08:00',
    ]);
    $this->app->instance(MarketplaceImportController::class, $controller);

    $this->getJson('/api/marketplace/import/shopee-gita/coverage')
        ->assertOk()
        ->assertJsonPath('data.summary.variants_by_status.new_product', 1)
        ->assertJsonPath('data.items.0.status', 'new_product')
        ->assertJsonStructure(['data' => ['revision', 'template', 'summary', 'items']]);
}
```

- [ ] **Step 2: Run the feature test and verify RED**

```powershell
backend/vendor/bin/phpunit backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php
```

Expected: FAIL because the route and target-name fields do not exist.

- [ ] **Step 3: Extend the target mapping reader**

In `shopeeGitaSalesTargetMappings()`, expose the stable columns already present in Sales Info:

```php
[
    'source_item_id' => $this->sourceItemIdFromParentSku($row['E'] ?? ''),
    'source_seller_sku' => trim((string) ($row['F'] ?? '')),
    'target_item_id' => trim((string) ($row['A'] ?? '')),
    'target_model_id' => trim((string) ($row['C'] ?? '')),
    'target_product_name' => trim((string) ($row['B'] ?? '')),
    'target_variant_name' => trim((string) ($row['D'] ?? '')),
]
```

- [ ] **Step 4: Implement the snapshot adapter and route**

Add constructor injection for the coverage service while retaining `MarketplaceSyncService`:

```php
public function __construct(
    private readonly MarketplaceSyncService $syncService,
    private readonly ShopeeGitaExportCoverageService $coverageService,
) {}
```

Add:

```php
public function shopeeGitaCoverage(Request $request): JsonResponse
{
    $snapshot = $this->currentShopeeGitaCoverage();

    return response()->json(['status' => 'ok', 'data' => $snapshot]);
}

protected function currentShopeeGitaCoverage(): array
{
    return $this->coverageService->analyze(
        $this->shopeeGitaSourceVariants(),
        $this->shopeeGitaSalesTargetMappings(),
        $this->shopeeGitaTemplateMetadata(),
    );
}

public function shopeeGitaTemplateMetadata(): array
{
    $salesPath = $this->shopeeGitaTemplatePath('mass_update_sales_info.xlsx');
    abort_if(! File::exists($salesPath), 422, 'Template Sales Info Gitashop belum tersedia.');

    return [
        'sales_sha256' => hash_file('sha256', $salesPath),
        'sales_last_modified_at' => date(DATE_ATOM, File::lastModified($salesPath)),
    ];
}

public function shopeeGitaTemplatePath(string $filename): string
{
    abort_unless(in_array($filename, array_column($this->shopeeGitaTemplates(), 'file'), true), 404, 'Template Gitashop tidak dikenal.');

    return rtrim((string) config('shopee_mass_upload.template_directory'), '/\\').DIRECTORY_SEPARATOR.$filename;
}
```

Add this config default and replace every direct `storage_path('app/'.self::TEMPLATE_DIR.'/...')` lookup for the six Gitashop workbooks with `shopeeGitaTemplatePath()`:

```php
'template_directory' => storage_path('app/import-marketplace/shopee-gita'),
```

Register:

```php
Route::get('marketplace/import/shopee-gita/coverage', [MarketplaceImportController::class, 'shopeeGitaCoverage']);
```

- [ ] **Step 5: Run focused and controller regression tests**

```powershell
backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the API slice**

```powershell
git add backend/app/Http/Controllers/MarketplaceImportController.php backend/config/shopee_mass_upload.php backend/routes/api.php backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php
git commit -m "feat: expose Gitashop export coverage"
```

---

### Task 3: Self-Contained XLSX Fixture and Safe Workbook Filtering

**Files:**
- Create: `backend/tests/Support/CreatesMinimalShopeeWorkbook.php`
- Modify: `backend/app/Http/Controllers/MarketplaceImportController.php`
- Modify: `backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php`

**Interfaces:**
- Consumes: snapshot `ready_targets` from Task 1.
- Produces: `filterShopeeGitaWorkbook(string $path, string $type, array $readyTargets): void`, preserving headers and only permitted target data rows.

- [ ] **Step 1: Create the minimal workbook fixture helper**

The trait must create a valid XLSX ZIP with `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/styles.xml`, `xl/sharedStrings.xml`, and `xl/worksheets/sheet1.xml`. Its public helper is:

```php
protected function createMinimalShopeeWorkbook(string $path, array $rows, int $headerRows = 6): void;
protected function readMinimalShopeeWorkbookRows(string $path, int $startRow = 7): array;
```

Each `$rows` entry is an associative Excel-column map such as:

```php
[
    'A' => 'target-item-1',
    'B' => 'Produk 1',
    'C' => 'target-model-1',
    'D' => 'Red',
    'E' => 'Psource-item-1',
    'F' => 'INT-1-RED',
]
```

Use inline strings in cells so tests do not depend on production shared strings. Preserve six header rows and start data at row 7.

- [ ] **Step 2: Write failing filter tests for product and variant workbooks**

Add these filter tests using `ReflectionMethod` to invoke the private adapter directly:

```php
public function test_sales_filter_keeps_only_ready_target_item_model_pairs(): void
{
    $path = storage_path('framework/testing/coverage-sales.xlsx');
    $this->createMinimalShopeeWorkbook($path, [
        ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-1-RED'],
        ['A' => 'target-2', 'C' => 'model-2', 'E' => 'Psource-2', 'F' => 'INT-2-BLACK'],
    ]);

    $method = new ReflectionMethod(MarketplaceImportController::class, 'filterShopeeGitaWorkbook');
    $method->invoke(app(MarketplaceImportController::class), $path, 'sales-info', [
        ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
    ]);

    $this->assertSame([
        ['A' => 'target-1', 'C' => 'model-1', 'E' => 'Psource-1', 'F' => 'INT-1-RED'],
    ], $this->readMinimalShopeeWorkbookRows($path));
}

public function test_basic_and_media_filters_keep_one_row_per_ready_target_product(): void
{
    foreach (['basic-info', 'media-info'] as $type) {
        $path = storage_path("framework/testing/coverage-{$type}.xlsx");
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'C' => 'Produk 1'],
            ['A' => 'target-2', 'C' => 'Produk 2'],
        ]);
        $this->invokeWorkbookFilter($path, $type, [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-2'],
        ]);
        $this->assertCount(1, $this->readMinimalShopeeWorkbookRows($path));
        $this->assertSame('target-1', $this->readMinimalShopeeWorkbookRows($path)[0]['A']);
    }
}

public function test_shipping_and_dts_filters_require_the_target_item_model_pair(): void
{
    foreach (['shipping-info', 'dts-info'] as $type) {
        $path = storage_path("framework/testing/coverage-{$type}.xlsx");
        $this->createMinimalShopeeWorkbook($path, [
            ['A' => 'target-1', 'D' => 'model-1'],
            ['A' => 'target-1', 'D' => 'model-2'],
        ]);
        $this->invokeWorkbookFilter($path, $type, [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-2'],
        ]);
        $this->assertSame('model-2', $this->readMinimalShopeeWorkbookRows($path)[0]['D']);
    }
}

public function test_filter_rejects_duplicate_ready_target_pairs(): void
{
    $path = storage_path('framework/testing/coverage-duplicate.xlsx');
    $this->createMinimalShopeeWorkbook($path, [['A' => 'target-1', 'C' => 'model-1']]);

    try {
        $this->invokeWorkbookFilter($path, 'sales-info', [
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
            ['target_item_id' => 'target-1', 'target_model_id' => 'model-1'],
        ]);
        $this->fail('Duplicate target pair was accepted.');
    } catch (HttpException $exception) {
        $this->assertSame(422, $exception->getStatusCode());
    }
}
```

Add `invokeWorkbookFilter()` to the test class as a three-line ReflectionMethod wrapper. Delete every fixture path created by these tests in `tearDown()` with explicit `File::delete([...])` paths.

- [ ] **Step 3: Run the feature test and verify RED**

```powershell
backend/vendor/bin/phpunit backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php
```

Expected: FAIL because workbook filtering is not implemented.

- [ ] **Step 4: Implement exact per-type row filtering**

Add a private method that opens sheet1, reads each data row, and removes rows not included in the allowed set:

```php
private function filterShopeeGitaWorkbook(string $path, string $type, array $readyTargets): void
{
    $rawVariantKeys = collect($readyTargets)
        ->map(fn (array $row) => trim($row['target_item_id']).'|'.trim($row['target_model_id']))
        ->all();
    abort_if(count($rawVariantKeys) !== count(array_unique($rawVariantKeys)), 422, 'Target Mass Update duplikat.');
    $variantKeys = array_fill_keys($rawVariantKeys, true);
    $productKeys = array_fill_keys(collect($readyTargets)
        ->pluck('target_item_id')->map(fn ($id) => trim((string) $id))->uniqueStrict()->all(), true);

    [$zip, $sheetPath, $sharedStrings] = $this->openWorkbookSheet($path);
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->loadXML($zip->getFromName($sheetPath));
    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('x', self::XLSX_NS);
    $kept = [];

    foreach (iterator_to_array($xpath->query('//x:sheetData/x:row')) as $rowNode) {
        if ((int) $rowNode->getAttribute('r') < 7) {
            continue;
        }
        $values = $this->readRowValues($rowNode, $sharedStrings);
        $allowed = match ($type) {
            'basic-info', 'media-info' => isset($productKeys[trim((string) ($values['A'] ?? ''))]),
            'sales-info' => isset($variantKeys[trim((string) ($values['A'] ?? '')).'|'.trim((string) ($values['C'] ?? ''))]),
            'shipping-info', 'dts-info' => isset($variantKeys[trim((string) ($values['A'] ?? '')).'|'.trim((string) ($values['D'] ?? ''))]),
            default => false,
        };
        if (! $allowed) {
            $rowNode->parentNode->removeChild($rowNode);
            continue;
        }
        $kept[] = $rowNode;
    }

    foreach ($kept as $offset => $rowNode) {
        $rowIndex = 7 + $offset;
        $rowNode->setAttribute('r', (string) $rowIndex);
        foreach ($rowNode->childNodes as $cell) {
            if ($cell instanceof \DOMElement && $cell->localName === 'c') {
                $column = preg_replace('/\d+/', '', $cell->getAttribute('r'));
                $cell->setAttribute('r', $column.$rowIndex);
            }
        }
    }

    if ($dimension = $xpath->query('//x:dimension')->item(0)) {
        preg_match('/:?([A-Z]+)\d+$/', $dimension->getAttribute('ref'), $matches);
        $lastColumn = $matches[1] ?? 'A';
        $dimension->setAttribute('ref', 'A1:'.$lastColumn.max(6, 6 + count($kept)));
    }
    $zip->addFromString($sheetPath, $dom->saveXML());
    $zip->close();
}
```

Use these exact key columns:

- `basic-info`: target item `A`;
- `media-info`: target item `A`;
- `sales-info`: target item/model `A|C`;
- `shipping-info`: target item/model `A|D`;
- `dts-info`: target item/model `A|D`;
- `republish-items`: remove every row from row 4 onward using the existing empty contract.

When renumbering a row, update both `<row r="...">` and each `<c r="COLUMN...">` reference. Recalculate the worksheet dimension if a dimension node exists.

- [ ] **Step 5: Run focused tests and inspect the generated XML**

Run the command from Step 3.

Expected: PASS; no production template path is read by the fixture-based filter tests.

- [ ] **Step 6: Commit workbook filtering**

```powershell
git add backend/tests/Support/CreatesMinimalShopeeWorkbook.php backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php backend/app/Http/Controllers/MarketplaceImportController.php
git commit -m "feat: filter Gitashop workbooks by coverage"
```

---

### Task 4: Revision-Bound ZIP and Exception CSV Downloads

**Files:**
- Modify: `backend/app/Http/Controllers/MarketplaceImportController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php`

**Interfaces:**
- Consumes: `currentShopeeGitaCoverage()`, `assertRevision()`, and workbook filter from Tasks 1–3.
- Produces: revision-bound ZIP, single workbook downloads, and `GET /api/marketplace/import/shopee-gita/exceptions`.

- [ ] **Step 1: Write failing download tests**

Add a reusable test binding that leaves download code real while replacing only the protected snapshot boundary:

```php
private function bindCoverageSnapshot(array $snapshot): void
{
    $controller = Mockery::mock(MarketplaceImportController::class, [
        app(MarketplaceSyncService::class),
        app(ShopeeGitaExportCoverageService::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();
    $controller->shouldReceive('currentShopeeGitaCoverage')->andReturn($snapshot);
    $this->app->instance(MarketplaceImportController::class, $controller);
}

public function test_mass_update_download_rejects_missing_or_stale_revision(): void
{
    $snapshot = $this->partialCoverageSnapshot();
    $this->bindCoverageSnapshot($snapshot);

    $this->get('/api/marketplace/import/shopee-gita/mass-update')->assertStatus(409);
    $this->get('/api/marketplace/import/shopee-gita/mass-update?revision=stale')->assertStatus(409);
}

public function test_exception_csv_contains_every_non_ready_source_row(): void
{
    $snapshot = $this->partialCoverageSnapshot();
    $this->bindCoverageSnapshot($snapshot);

    $response = $this->get('/api/marketplace/import/shopee-gita/exceptions?revision='.$snapshot['revision']);
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();
    $this->assertStringContainsString('Khiban series', $csv);
    $this->assertStringContainsString('NINJA NON RESLETING', $csv);
    $this->assertSame(2, substr_count($csv, 'new_product'));
}

public function test_download_headers_identify_partial_coverage(): void
{
    $snapshot = $this->partialCoverageSnapshot();
    $this->bindCoverageSnapshot($snapshot);

    $this->get('/api/marketplace/import/shopee-gita/exceptions?revision='.$snapshot['revision'])
        ->assertOk()
        ->assertHeader('X-Agni-Coverage-Status', 'partial')
        ->assertHeader('X-Agni-Ready-Variants', '1')
        ->assertHeader('X-Agni-Exception-Variants', '2');
}
```

Implement the ZIP assertion as follows after `setUp()` points `shopee_mass_upload.template_directory` to a directory containing six workbooks from `CreatesMinimalShopeeWorkbook`:

```php
public function test_mass_update_zip_contains_filtered_workbooks_and_coverage_report(): void
{
    $snapshot = $this->partialCoverageSnapshot();
    $this->bindCoverageSnapshot($snapshot);

    $response = $this->get('/api/marketplace/import/shopee-gita/mass-update?revision='.$snapshot['revision']);
    $response->assertOk()->assertHeader('X-Agni-Coverage-Status', 'partial');
    $archivePath = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive();
    $this->assertTrue($zip->open($archivePath) === true);
    $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->sort()->values()->all();
    $this->assertSame([
        'coverage_report.csv',
        'mass_republish_items.xlsx',
        'mass_update_basic_info.xlsx',
        'mass_update_dts_info.xlsx',
        'mass_update_media_info.xlsx',
        'mass_update_sales_info.xlsx',
        'mass_update_shipping_info.xlsx',
    ], $entries);
    $report = $zip->getFromName('coverage_report.csv');
    $salesBytes = $zip->getFromName('mass_update_sales_info.xlsx');
    $zip->close();

    $this->assertStringContainsString('Khiban series', $report);
    $this->assertStringContainsString('NINJA NON RESLETING', $report);
    $salesPath = storage_path('framework/testing/filtered-sales.xlsx');
    File::put($salesPath, $salesBytes);
    $this->assertSame([['A' => 'target-1', 'C' => 'model-1']], $this->readMinimalShopeeWorkbookRows($salesPath));
}
```

`partialCoverageSnapshot()` must return one ready row plus Khiban and Ninja exception rows using the item shape from Task 1, with summary `ready_variants = 1` and `exception_variants = 2`. The Sales fixture contains `target-1|model-1` and one rejected target row; the read helper may return additional columns only when the expected assertion lists those columns.

The ZIP test must open the response file and assert:

- `coverage_report.csv` exists;
- both example `new_product` names are present in the report fixture;
- Sales Info contains only `mass_update_ready` target pairs;
- `X-Agni-Coverage-Status` is `partial` when any non-ready item exists;
- `X-Agni-Ready-Variants` and `X-Agni-Exception-Variants` match summary values.

- [ ] **Step 2: Run the download tests and verify RED**

```powershell
backend/vendor/bin/phpunit backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php
```

Expected: FAIL because revision validation and the report file are absent.

- [ ] **Step 3: Implement CSV rendering**

Add:

```php
private function shopeeGitaCoverageCsv(array $snapshot): string
{
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, [
        'status', 'reason', 'source_item_id', 'source_model_id',
        'product_name', 'variant_name', 'source_seller_sku',
        'target_item_id', 'target_model_id', 'target_seller_sku',
    ]);
    foreach ($snapshot['items'] as $item) {
        if ($item['status'] === 'mass_update_ready') {
            continue;
        }
        fputcsv($stream, [
            $item['status'],
            $item['reason'],
            $item['source_item_id'],
            $item['source_model_id'],
            $item['product_name'],
            $item['variant_name'],
            $item['source_seller_sku'],
            $item['target_item_id'],
            $item['target_model_id'],
            $item['target_seller_sku'],
        ]);
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return "\xEF\xBB\xBF".$csv;
}
```

The UTF-8 BOM is required so Indonesian product names open correctly in Excel.

- [ ] **Step 4: Bind every manual download to one revision**

At the start of ZIP and individual-file methods:

```php
$snapshot = $this->currentShopeeGitaCoverage();
$this->coverageService->assertRevision($snapshot, (string) $request->query('revision', ''));
```

Change `downloadShopeeGitaMassUpdateFile` to accept `Request $request, string $type` so it can validate revision. After the existing writer runs, invoke `filterShopeeGitaWorkbook($target, $type, $snapshot['ready_targets'])`.

For ZIP generation, filter every workbook after writing, add `coverage_report.csv`, then set:

```php
'X-Agni-Coverage-Status' => $snapshot['summary']['exception_variants'] > 0 ? 'partial' : 'complete',
'X-Agni-Ready-Variants' => (string) $snapshot['summary']['ready_variants'],
'X-Agni-Exception-Variants' => (string) $snapshot['summary']['exception_variants'],
```

Use archive name `shopee_gita_mass_update_partial_<stamp>.zip` whenever exceptions exist.

- [ ] **Step 5: Add the exception download endpoint**

Register:

```php
Route::get('marketplace/import/shopee-gita/exceptions', [MarketplaceImportController::class, 'downloadShopeeGitaExceptions']);
```

The method validates revision and returns `shopee_gita_exceptions_<stamp>.csv` with `text/csv; charset=UTF-8`, `Cache-Control: no-store`, and the same coverage headers.

- [ ] **Step 6: Run all focused backend tests**

```powershell
backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php backend/tests/Feature/ShopeeMassUploadWorkbookManifestTest.php backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit safe downloads**

```powershell
git add backend/app/Http/Controllers/MarketplaceImportController.php backend/routes/api.php backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php
git commit -m "feat: make Gitashop exports coverage aware"
```

---

### Task 5: Actionable Automatic-Upload Guard

**Files:**
- Modify: `backend/app/Services/ShopeeMassUploadManifestService.php`
- Modify: `backend/app/Services/ShopeeMassUploadService.php`
- Modify: `backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php`
- Modify: `backend/tests/Feature/ShopeeMassUploadControllerTest.php`

**Interfaces:**
- Consumes: existing immutable manifest build and the source/mapping collections.
- Produces: an actionable sanitized `RuntimeException` message surfaced in job audit without uploading any file.

- [ ] **Step 1: Write the failing mismatch-message regression**

Add a test with two source rows and one mapping:

```php
public function test_manifest_reports_unmapped_counts_without_creating_a_partial_upload_job(): void
{
    $imports->shouldReceive('shopeeGitaSourceVariants')->once()->andReturn(collect([
        (object) $this->sourceVariant('source-item-1', 'source-model-1', 'INT-1-RED'),
        (object) $this->sourceVariant('source-item-2', 'source-model-2', 'INT-2-BLACK'),
    ]));
    $imports->shouldReceive('shopeeGitaSalesTargetMappings')->once()->andReturn(collect([
        $this->targetMapping('source-item-1', 'INT-1-RED', 'target-item-1', 'target-model-1'),
    ]));
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Template Gitashop belum mencakup 1 dari 2 varian sumber');

    (new ShopeeMassUploadManifestService($omnichannel, $imports))->buildForJob($jobId);
}
```

Define fixed helpers so this test reaches the coverage comparison instead of failing an earlier manifest validation:

```php
private function sourceVariant(string $itemId, string $modelId, string $sellerSku): array
{
    return [
        'item_id' => $itemId,
        'model_id' => $modelId,
        'seller_sku' => $sellerSku,
        'product_name' => 'Produk '.$itemId,
        'variant_name' => 'Varian '.$modelId,
        'description' => 'Deskripsi aman',
        'price' => 50000,
        'stock_qty' => 1,
        'raw_image_url' => 'https://cf.shopee.co.id/file/'.$modelId,
        'product_image_urls' => ['https://cf.shopee.co.id/file/'.$itemId],
    ];
}

private function targetMapping(string $sourceItemId, string $sellerSku, string $targetItemId, string $targetModelId): array
{
    return [
        'source_item_id' => $sourceItemId,
        'source_seller_sku' => $sellerSku,
        'target_item_id' => $targetItemId,
        'target_model_id' => $targetModelId,
    ];
}
```

Add a controller/service test proving a claim terminates as `dibatalkan_aman`, creates zero `shopee_mass_upload_files`, and records the sanitized actionable message rather than the generic message.

- [ ] **Step 2: Run focused tests and verify RED**

```powershell
backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php backend/tests/Feature/ShopeeMassUploadControllerTest.php
```

Expected: FAIL because the current exception and terminal message are generic.

- [ ] **Step 3: Implement exact mismatch accounting**

In `buildForJob()`, calculate:

```php
$missingSourceKeys = array_diff_key($sources, $mappings);
$staleTargetKeys = array_diff_key($mappings, $sources);
```

Throw a sanitized message containing counts only:

```php
throw new RuntimeException(sprintf(
    'Template Gitashop belum mencakup %d dari %d varian sumber; %d baris target sudah tidak cocok. Periksa preflight Download Mass Update.',
    count($missingSourceKeys),
    count($sources),
    count($staleTargetKeys),
));
```

In `ShopeeMassUploadService::claim()`, keep the caught exception message only when it begins with `Template Gitashop belum mencakup`; otherwise retain the existing generic safe message. Do not include SKU, names, stack traces, or raw workbook values in the job audit.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run the command from Step 2.

Expected: PASS and zero file rows for a mismatch.

- [ ] **Step 5: Commit the guard**

```powershell
git add backend/app/Services/ShopeeMassUploadManifestService.php backend/app/Services/ShopeeMassUploadService.php backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php backend/tests/Feature/ShopeeMassUploadControllerTest.php
git commit -m "fix: explain Gitashop upload coverage gaps"
```

---

### Task 6: Frontend Coverage State and API Client

**Files:**
- Create: `frontend/src/pages/shopeeGitaExportCoverageState.js`
- Create: `frontend/tests/shopeeGitaExportCoverageState.test.js`
- Modify: `frontend/src/services/index.js`

**Interfaces:**
- Consumes: backend snapshot from Task 2 and blob responses from Task 4.
- Produces: `toShopeeGitaCoverageViewModel(payload)`, `filterShopeeGitaExceptions(items, keyword)`, `coverageDownloadFilename(kind, headers)`, plus service methods for coverage/ZIP/file/exceptions.

- [ ] **Step 1: Write failing frontend state tests**

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  coverageDownloadFilename,
  filterShopeeGitaExceptions,
  toShopeeGitaCoverageViewModel
} from '../src/pages/shopeeGitaExportCoverageState.js'

test('maps coverage summary and marks partial exports', () => {
  const view = toShopeeGitaCoverageViewModel({
    revision: 'abc',
    summary: {
      source_products: 65,
      source_variants: 1992,
      ready_products: 60,
      ready_variants: 1693,
      exception_variants: 299,
      variants_by_status: { new_product: 82, new_variant: 217, sku_changed: 0, blocked: 0 }
    },
    items: []
  })

  assert.equal(view.isPartial, true)
  assert.equal(view.readyLabel, '60 produk / 1.693 varian')
  assert.equal(view.exceptionLabel, '299 varian perlu tindakan')
})

test('filters exceptions by product variant and seller SKU', () => {
  const items = [
    { product_name: 'Khiban series', variant_name: 'Baby pink', source_seller_sku: 'INT-478-BABY-PINK', status: 'new_product', reason: 'missing_target_product' },
    { product_name: 'Ninja non resleting', variant_name: 'Hitam', source_seller_sku: 'INT-526-HITAM', status: 'new_product', reason: 'missing_target_product' }
  ]

  assert.equal(filterShopeeGitaExceptions(items, 'khiban').length, 1)
  assert.equal(filterShopeeGitaExceptions(items, 'hitam')[0].product_name, 'Ninja non resleting')
  assert.equal(filterShopeeGitaExceptions(items, 'INT-478')[0].variant_name, 'Baby pink')
})

test('uses the partial filename returned by content disposition', () => {
  assert.equal(
    coverageDownloadFilename('mass-update', { 'content-disposition': 'attachment; filename="shopee_gita_mass_update_partial_20260829.zip"' }),
    'shopee_gita_mass_update_partial_20260829.zip'
  )
})
```

- [ ] **Step 2: Run frontend tests and verify RED**

```powershell
npm --prefix frontend test
```

Expected: FAIL because the state module does not exist.

- [ ] **Step 3: Implement pure state helpers**

Rules:

- Use `Intl.NumberFormat('id-ID')` for counts.
- Expose `isPartial`, `canDownloadMassUpdate`, `canDownloadExceptions`, `readyLabel`, `exceptionLabel`, `templateAgeLabel`, and normalized `items`.
- Search a lowercase concatenation of `product_name`, `variant_name`, `source_seller_sku`, `status`, and `reason`.
- Parse `Content-Disposition` `filename*=` first, then `filename=`, and fall back to `shopee_gita_<kind>.zip|csv|xlsx`.

- [ ] **Step 4: Add revision-bound service methods**

In `omnichannelService` add:

```js
shopeeGitaExportCoverage() {
  return api.get('/marketplace/import/shopee-gita/coverage')
},
downloadShopeeGitaMassUpdate(revision) {
  return api.get('/marketplace/import/shopee-gita/mass-update', {
    params: { revision },
    responseType: 'blob'
  })
},
downloadShopeeGitaMassUpdateFile(type, revision) {
  return api.get(`/marketplace/import/shopee-gita/mass-update/${type}`, {
    params: { revision },
    responseType: 'blob'
  })
},
downloadShopeeGitaExceptions(revision) {
  return api.get('/marketplace/import/shopee-gita/exceptions', {
    params: { revision },
    responseType: 'blob'
  })
}
```

Remove the duplicate no-argument `downloadShopeeGitaMassUpdate()` method.

- [ ] **Step 5: Run frontend tests and verify GREEN**

```powershell
npm --prefix frontend test
```

Expected: all frontend tests PASS.

- [ ] **Step 6: Commit frontend state and client**

```powershell
git add frontend/src/pages/shopeeGitaExportCoverageState.js frontend/tests/shopeeGitaExportCoverageState.test.js frontend/src/services/index.js
git commit -m "feat: add Gitashop coverage frontend state"
```

---

### Task 7: Import Page Preflight and Safe Downloads

**Files:**
- Modify: `frontend/src/pages/ImportMarketplace.vue`
- Modify: `frontend/src/pages/gitashopMassUploadState.js`
- Modify: `frontend/tests/gitashopMassUploadState.test.js`

**Interfaces:**
- Consumes: state helpers and service methods from Task 6.
- Produces: visible coverage summary, exception search/table, stale-revision recovery, and blob downloads.

- [ ] **Step 1: Write failing copy/state regression**

Extend `gitashopMassUploadState.test.js` with:

```js
test('describes coverage mismatch as an actionable blocked upload', () => {
  const view = toMassUploadViewModel({
    id: 44,
    status: 'dibatalkan_aman',
    message: 'Template Gitashop belum mencakup 29 dari 1992 varian sumber; 3 baris target sudah tidak cocok. Periksa preflight Download Mass Update.',
    files: []
  })

  assert.match(view.message, /Periksa preflight Download Mass Update/)
  assert.equal(view.statusTone, 'warning')
})
```

- [ ] **Step 2: Run frontend tests and verify RED if tone mapping is missing**

```powershell
npm --prefix frontend test
```

Expected: the new assertion fails until the blocked coverage state is mapped to warning.

- [ ] **Step 3: Add coverage state to the page script**

Import Task 6 helpers and add refs:

```js
const shopeeCoverage = ref(null)
const loadingShopeeCoverage = ref(false)
const shopeeCoverageSearch = ref('')
const downloadingShopeeCoverage = ref('')
```

Add `loadShopeeCoverage()`, a computed filtered list, and `downloadCoverageBlob(kind, type = '')`. Blob download must:

1. call the matching service with `shopeeCoverage.value.revision`;
2. create an object URL;
3. click one temporary anchor with the parsed response filename;
4. revoke the object URL;
5. on HTTP 409, reload coverage and show `Katalog berubah; preflight sudah diperbarui. Silakan download ulang.`;
6. decode JSON error blobs before falling back to a generic message.

- [ ] **Step 4: Add the preflight UI**

Above the current Mass Update file table, render:

- summary cards for source, ready, new product, new variant, SKU changed, and blocked;
- template last-modified timestamp and a red stale badge when older than 7 days;
- warning text `Export Mass Update ini parsial` when `isPartial`;
- buttons `Download Mass Update Produk Lama` and `Download Laporan Pengecualian`;
- disabled `Download Produk Baru Gitashop` with text `Memerlukan template resmi Mass Upload Produk Baru Shopee`;
- search input and exception table columns Produk, Varian, SKU, Status, Alasan.

Limit visible exception rows to 200 after filtering and state the total when truncated. Do not hide either of the two example products when the search is empty.

Replace every Shopee anchor-based `downloadUrl(...)` call with the revision-bound blob handler. Leave Lazada downloads unchanged.

- [ ] **Step 5: Load coverage on mount and refresh after stale responses**

Change the mount block to:

```js
onMounted(async () => {
  await Promise.all([loadProducts(), refreshMassUpload(), loadShopeeCoverage()])
  ensureMassUploadPolling()
})
```

Automatic upload start remains available, but the page must warn before the request when coverage is partial that the current phase remains fail-closed and will not create new listings.

- [ ] **Step 6: Run frontend tests and build**

```powershell
npm --prefix frontend test
npm --prefix frontend run build
```

Expected: tests PASS and Vite exits 0.

- [ ] **Step 7: Commit the page**

```powershell
git add frontend/src/pages/ImportMarketplace.vue frontend/src/pages/gitashopMassUploadState.js frontend/tests/gitashopMassUploadState.test.js
git commit -m "feat: show Gitashop export coverage"
```

---

### Task 8: Full Verification, Local Data Check, and Publish

**Files:**
- Modify: `backend/public/index.html`
- Create: hash-named JavaScript and CSS files under `backend/public/assets/` emitted by the verified Vite build and referenced by the published `index.html`.
- Verify only: all files from Tasks 1–7

**Interfaces:**
- Consumes: complete backend/frontend implementation.
- Produces: verified local deployment at `http://agnishopbjm-laravel.test/marketplace/import`.

- [ ] **Step 1: Run PHP syntax checks on changed PHP files**

```powershell
php -l backend/app/Services/ShopeeGitaExportCoverageService.php
php -l backend/app/Http/Controllers/MarketplaceImportController.php
php -l backend/app/Services/ShopeeMassUploadManifestService.php
php -l backend/app/Services/ShopeeMassUploadService.php
php -l backend/routes/api.php
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 2: Run focused backend and frontend suites**

```powershell
backend/vendor/bin/phpunit backend/tests/Unit/Services/ShopeeGitaExportCoverageServiceTest.php backend/tests/Feature/ShopeeGitaExportCoverageApiTest.php backend/tests/Unit/Services/ShopeeMassUploadManifestServiceTest.php backend/tests/Feature/ShopeeMassUploadControllerTest.php backend/tests/Feature/ShopeeMassUploadWorkbookManifestTest.php
npm --prefix frontend test
```

Expected: all focused tests PASS.

- [ ] **Step 3: Run the full backend suite**

```powershell
backend/vendor/bin/phpunit
```

Expected: all tests PASS using SQLite `:memory:`.

- [ ] **Step 4: Build and publish frontend assets**

```powershell
npm --prefix frontend run build
Copy-Item -LiteralPath 'frontend/dist/index.html' -Destination 'backend/public/index.html' -Force
Copy-Item -Path 'frontend/dist/assets/*' -Destination 'backend/public/assets' -Force
```

Expected: build exits 0 and `backend/public/index.html` references files present under `backend/public/assets`.

- [ ] **Step 5: Verify the deployed HTTP contracts without marketplace mutation**

```powershell
$coverageResponse = Invoke-RestMethod 'http://agnishopbjm-laravel.test/api/marketplace/import/shopee-gita/coverage'
$coverageResponse.status
$coverageResponse.data.summary | ConvertTo-Json -Depth 6
$coverageResponse.data.items |
  Where-Object { $_.product_name -like '*Khiban series*' -or $_.product_name -like '*NINJA NON RESLETING*' } |
  Select-Object product_name,status,variant_name |
  ConvertTo-Json -Depth 4
(Invoke-WebRequest -UseBasicParsing 'http://agnishopbjm-laravel.test/marketplace/import').StatusCode
```

Expected:

- API status `ok`;
- both example products appear only with status `new_product`;
- Khiban has 17 source variants and Ninja has 12 source variants in the snapshot grouping;
- page returns HTTP 200.

- [ ] **Step 6: Download and inspect a revision-bound ZIP safely**

Create an exact temporary file, download the ZIP, inspect it, then delete only that file:

```powershell
$coverageRevision = $coverageResponse.data.revision
$exportTempPath = Join-Path ([System.IO.Path]::GetTempPath()) ('agni-gita-export-' + [guid]::NewGuid().ToString('N') + '.zip')
Invoke-WebRequest -UseBasicParsing -Uri ("http://agnishopbjm-laravel.test/api/marketplace/import/shopee-gita/mass-update?revision=" + [uri]::EscapeDataString($coverageRevision)) -OutFile $exportTempPath
$exportZip = [System.IO.Compression.ZipFile]::OpenRead($exportTempPath)
$exportZip.Entries | Select-Object FullName,Length
$exportZip.Dispose()
Remove-Item -LiteralPath $exportTempPath -Force
```

Expected: six workbook entries plus `coverage_report.csv`; only the uniquely generated temporary ZIP is removed.

- [ ] **Step 7: Verify the rendered page with the browser skill**

Open `http://agnishopbjm-laravel.test/marketplace/import`, reload after publishing, and take a fresh DOM snapshot. Verify the page visibly contains `Export Mass Update ini parsial`, `Download Mass Update Produk Lama`, `Download Laporan Pengecualian`, and the disabled `Download Produk Baru Gitashop` action. Search the exception table for `Khiban series` and `NINJA NON RESLETING` one at a time and verify each is labeled `Produk baru`. Take one screenshot showing the preflight summary and include it in the final handoff. Do not click automatic upload or any marketplace mutation action.

- [ ] **Step 8: Verify published asset content and working-tree hygiene**

```powershell
$publishedIndex = Get-Content -Raw 'backend/public/index.html'
$publishedIndex
rg -n "Export Mass Update ini parsial|Download Laporan Pengecualian|Memerlukan template resmi Mass Upload" backend/public/assets
git diff --check
git status --short
```

Expected: published bundle contains the new UI copy; `git diff --check` is clean; unrelated pre-existing untracked files remain untouched.

- [ ] **Step 9: Commit published assets**

Stage only the new feature and Vite outputs actually referenced by `backend/public/index.html`:

```powershell
$builtAssetFiles = Get-ChildItem 'frontend/dist/assets' -File
$publishedAssetPaths = $builtAssetFiles | ForEach-Object { 'backend/public/assets/' + $_.Name }
git add -- 'backend/public/index.html' $publishedAssetPaths
git commit -m "build: publish Gitashop coverage UI"
```

- [ ] **Step 10: Request code review and verify before completion**

Use the `requesting-code-review` skill against the commits created by this plan. Resolve only findings within this plan's scope. Then use `verification-before-completion`, rerun the affected focused tests plus the full backend/frontend suites, and rerun `git diff --check` before making a completion claim.

---

## Plan Boundary and Follow-Up Input

This plan fully fixes silent omission and makes both example products visible as `new_product`, but intentionally does not fabricate an upload-ready Shopee creation workbook. The next implementation plan starts only after the user supplies one current official **Mass Upload/Add New Product** workbook downloaded from Gitashopcollection Seller Centre. That workbook becomes the authoritative fixture for sheet names, header rows, mandatory columns, styles, validations, and category behavior; no column contract will be guessed.
