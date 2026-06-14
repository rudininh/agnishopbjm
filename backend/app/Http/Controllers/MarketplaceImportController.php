<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class MarketplaceImportController extends Controller
{
    private const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const TEMPLATE_DIR = 'import-marketplace/shopee-gita';

    public function __construct(private readonly MarketplaceSyncService $syncService)
    {
    }

    public function downloadShopeeGitaMassUpdate(Request $request): BinaryFileResponse
    {
        $templates = $this->shopeeGitaTemplates();
        $stamp = now()->format('Ymd_His');
        $workDir = storage_path('app/import-marketplace/generated/shopee-gita-'.$stamp.'-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($workDir);

        foreach ($templates as $template) {
            $fileName = $template['file'];
            $source = storage_path('app/'.self::TEMPLATE_DIR.'/'.$fileName);
            abort_if(! File::exists($source), 422, 'Template Mass Update belum lengkap: '.$fileName);

            $target = $workDir.'/'.$fileName;
            File::copy($source, $target);
            $template['writer']($target);
        }

        $archivePath = $workDir.'/shopee_gita_mass_update_'.$stamp.'.zip';
        $zip = new ZipArchive();
        abort_if($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true, 500, 'Arsip Mass Update gagal dibuat.');

        foreach ($templates as $template) {
            $fileName = $template['file'];
            $zip->addFile($workDir.'/'.$fileName, $fileName);
        }
        $zip->close();

        return response()
            ->download($archivePath, basename($archivePath), [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ])
            ->deleteFileAfterSend(true);
    }

    public function downloadShopeeGitaMassUpdateFile(string $type): BinaryFileResponse
    {
        $templates = $this->shopeeGitaTemplates();
        abort_if(! isset($templates[$type]), 404, 'Jenis Mass Update tidak dikenal.');

        $template = $templates[$type];
        $fileName = $template['file'];
        $source = storage_path('app/'.self::TEMPLATE_DIR.'/'.$fileName);
        abort_if(! File::exists($source), 422, 'Template Mass Update belum lengkap: '.$fileName);

        $stamp = now()->format('Ymd_His');
        $workDir = storage_path('app/import-marketplace/generated/shopee-gita-'.$type.'-'.$stamp.'-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($workDir);

        $target = $workDir.'/'.$fileName;
        File::copy($source, $target);
        $template['writer']($target);

        return response()
            ->download($target, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ])
            ->deleteFileAfterSend(true);
    }

    public function manualStockSync(Request $request)
    {
        set_time_limit(0);

        $data = $request->validate([
            'source_marketplace' => ['required', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.stock_master_id' => ['required', 'integer', 'exists:stock_master,id'],
            'items.*.stock' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        $source = $this->normalizeManualStockSource((string) $data['source_marketplace']);
        $results = [];
        $success = 0;
        $failed = 0;
        $updatedLocal = 0;

        foreach ($data['items'] as $item) {
            $stockMasterId = (int) $item['stock_master_id'];
            $newStock = (int) $item['stock'];
            $mapping = $this->manualStockMapping($stockMasterId);

            if (! $mapping) {
                $failed += 1;
                $results[] = [
                    'stock_master_id' => $stockMasterId,
                    'status' => 'error',
                    'message' => 'Stock master tidak ditemukan.',
                ];
                continue;
            }

            $oldStock = (int) ($mapping->stock_qty ?? 0);
            $this->syncService->updateLocalStock($mapping, 'shopee', $newStock);
            $this->syncService->updateLocalStock($mapping, 'tiktok', $newStock);
            $updatedLocal += 1;

            $shopee = $this->syncService->pushTargetStock($mapping, 'shopee', $newStock, true);
            $tiktok = $this->syncService->pushTargetStock($mapping, 'tiktok', $newStock, true);
            $shopeeOk = in_array(($shopee['status'] ?? ''), ['success', 'dry_run'], true);
            $tiktokOk = in_array(($tiktok['status'] ?? ''), ['success', 'dry_run'], true);
            $status = $shopeeOk && $tiktokOk ? 'success' : 'partial_error';
            $status === 'success' ? $success++ : $failed++;

            $sku = $this->manualStockSku($mapping);
            $message = sprintf(
                'Manual Import Marketplace %s: stok %s -> %s. Shopee: %s. TikTok: %s.',
                $source,
                $oldStock,
                $newStock,
                $shopee['message'] ?? '-',
                $tiktok['message'] ?? '-'
            );

            $this->syncService->logSync('manual_import_marketplace', 'shopee', $sku, $oldStock, $newStock, $shopeeOk ? 'success' : 'error', $message);
            $this->syncService->logSync('manual_import_marketplace', 'tiktok', $sku, $oldStock, $newStock, $tiktokOk ? 'success' : 'error', $message);
            $this->syncService->updateStatus('shopee', ['last_sync_at' => now(), 'status' => $shopeeOk ? 'connected' : 'disconnected']);
            $this->syncService->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => $tiktokOk ? 'connected' : 'disconnected']);

            $results[] = [
                'stock_master_id' => $stockMasterId,
                'sku' => $sku,
                'product_name' => (string) ($mapping->product_name ?? ''),
                'variant_name' => (string) ($mapping->variant_name ?? ''),
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'status' => $status,
                'message' => $message,
                'shopee' => [
                    'status' => $shopee['status'] ?? 'error',
                    'message' => $shopee['message'] ?? null,
                ],
                'tiktok' => [
                    'status' => $tiktok['status'] ?? 'error',
                    'message' => $tiktok['message'] ?? null,
                ],
            ];
        }

        return response()->json([
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf(
                'Sinkron stok manual %s selesai. Berhasil=%s, perlu cek=%s, lokal update=%s.',
                $source,
                $success,
                $failed,
                $updatedLocal
            ),
            'summary' => [
                'total' => count($data['items']),
                'success' => $success,
                'failed' => $failed,
                'updated_local' => $updatedLocal,
            ],
            'items' => $results,
        ], $failed > 0 ? 207 : 200);
    }

    private function shopeeGitaTemplates(): array
    {
        return [
            'basic-info' => [
                'file' => 'mass_update_basic_info.xlsx',
                'writer' => fn (string $path) => $this->fillBasicInfo($path),
            ],
            'sales-info' => [
                'file' => 'mass_update_sales_info.xlsx',
                'writer' => fn (string $path) => $this->fillSalesInfo($path),
            ],
            'media-info' => [
                'file' => 'mass_update_media_info.xlsx',
                'writer' => fn (string $path) => $this->fillMediaInfo($path),
            ],
            'shipping-info' => [
                'file' => 'mass_update_shipping_info.xlsx',
                'writer' => fn (string $path) => $this->fillShippingInfo($path),
            ],
            'dts-info' => [
                'file' => 'mass_update_dts_info.xlsx',
                'writer' => fn (string $path) => $this->fillDtsInfo($path),
            ],
            'republish-items' => [
                'file' => 'mass_republish_items.xlsx',
                'writer' => fn (string $path) => $this->fillRepublishItems($path),
            ],
        ];
    }

    private function manualStockMapping(int $stockMasterId): ?object
    {
        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on(DB::raw('spm.item_id::TEXT'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('spm.model_id::TEXT'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join) {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku');
            })
            ->where('sm.id', $stockMasterId)
            ->selectRaw("
                sm.*,
                map.seller_sku as mapped_seller_sku,
                map.tiktok_product_id as mapped_tiktok_product_id,
                map.tiktok_sku_id as mapped_tiktok_sku_id,
                map.tiktok_sku_name as mapped_tiktok_sku_name,
                spm.stock as shopee_stock,
                tp.stock_qty as tiktok_stock
            ")
            ->first();
    }

    private function manualStockSku(object $mapping): string
    {
        foreach ([
            $mapping->internal_sku ?? null,
            $mapping->mapped_seller_sku ?? null,
            $mapping->shopee_seller_sku ?? null,
            $mapping->tiktok_seller_sku ?? null,
        ] as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return 'stock-master-'.$mapping->id;
    }

    private function normalizeManualStockSource(string $source): string
    {
        $source = trim(preg_replace('/\s+/', ' ', $source) ?: '');

        return $source !== '' ? mb_substr($source, 0, 80) : 'Marketplace Lain';
    }

    private function fillSalesInfo(string $path): void
    {
        $variants = $this->variantRows();
        $bySku = $variants->filter(fn ($row) => $row->seller_sku !== '')->keyBy(fn ($row) => mb_strtolower($row->seller_sku));

        $this->updateSheetRows($path, 7, function (array $row) use ($bySku): array {
            $sku = mb_strtolower(trim((string) ($row['F'] ?? '')));
            $variant = $sku !== '' ? ($bySku[$sku] ?? null) : null;
            if (! $variant) {
                return [];
            }

            // Keep Shopee Gita product/variation IDs from the exported template.
            return [
                'F' => $variant->seller_sku,
                'I' => $variant->stock_qty,
                'J' => $row['J'] ?? '1',
            ];
        });
    }

    private function fillShippingInfo(string $path): void
    {
        $variants = $this->variantRows();
        $byModelId = $variants->keyBy('model_id');

        $this->updateSheetRows($path, 7, function (array $row) use ($byModelId): array {
            $variant = $byModelId[trim((string) ($row['D'] ?? ''))] ?? null;
            if (! $variant) {
                return [];
            }

            return [
                'A' => $variant->item_id,
                'B' => $variant->parent_sku,
                'C' => $variant->product_name,
                'D' => $variant->model_id,
                'E' => $variant->variant_name,
            ];
        });
    }

    private function fillDtsInfo(string $path): void
    {
        $variants = $this->variantRows();
        $byModelId = $variants->keyBy('model_id');

        $this->updateSheetRows($path, 7, function (array $row) use ($byModelId): array {
            $variant = $byModelId[trim((string) ($row['D'] ?? ''))] ?? null;
            if (! $variant) {
                return [];
            }

            return [
                'A' => $variant->item_id,
                'B' => $variant->parent_sku,
                'C' => $variant->product_name,
                'D' => $variant->model_id,
                'E' => $variant->variant_name,
            ];
        });
    }

    private function fillBasicInfo(string $path): void
    {
        $productsBySourceItem = $this->productRows()->keyBy('item_id');

        $this->updateSheetRows($path, 7, function (array $row) use ($productsBySourceItem): array {
            $sourceItemId = $this->sourceItemIdFromParentSku($row['B'] ?? '');
            $product = $sourceItemId !== '' ? $productsBySourceItem->get($sourceItemId) : null;
            if (! $product) {
                return [];
            }

            // Keep Gita product id and parent SKU from the Shopee template.
            $values = [
                'C' => $this->sanitizeShopeeBasicText($product->product_name),
            ];
            $description = $this->sanitizeShopeeBasicText($product->description);
            if ($description !== '') {
                $values['D'] = $description;
            }

            return $values;
        });
    }

    private function fillMediaInfo(string $path): void
    {
        $productsBySourceItem = $this->productRows()->keyBy('item_id');
        $variantsBySourceItem = $this->variantRows()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($variant) => $this->normalizeOptionName($variant->variant_name)));
        $imagesBySourceItem = $this->productImagesByItem(true);

        $this->updateSheetRows($path, 7, function (array $row) use ($productsBySourceItem, $variantsBySourceItem, $imagesBySourceItem): array {
            $sourceItemId = $this->sourceItemIdFromParentSku($row['B'] ?? '');
            if ($sourceItemId === '') {
                return [];
            }

            $values = [];
            $product = $productsBySourceItem->get($sourceItemId);
            if ($product) {
                $values['C'] = $product->product_name;
            }

            $productImages = $imagesBySourceItem[$sourceItemId] ?? [];
            foreach (array_slice($productImages, 0, 9) as $index => $imageUrl) {
                if ($index === 0) {
                    $values['E'] = $imageUrl;
                    continue;
                }
                $values[$this->columnName(5 + $index)] = $imageUrl;
            }

            $variantsByName = $variantsBySourceItem->get($sourceItemId, collect());
            for ($columnIndex = 17; $columnIndex <= 205; $columnIndex += 2) {
                $optionName = trim((string) ($row[$this->columnName($columnIndex)] ?? ''));
                if ($optionName === '') {
                    continue;
                }
                $variant = $variantsByName->get($this->normalizeOptionName($optionName));
                $imageUrl = $variant ? $this->cfShopeeImageUrl($variant->raw_image_url ?? '') : '';
                if ($imageUrl !== '') {
                    $values[$this->columnName($columnIndex + 1)] = $imageUrl;
                }
            }

            return $values;
        });

        $this->sanitizeMediaImageUrls($path);
    }

    private function fillRepublishItems(string $path): void
    {
        $this->replaceSheetDataRows($path, 4, []);
    }

    private function variantRows()
    {
        return DB::table('stock_master as sm')
            ->join('shopee_product_model as spm', function ($join) {
                $join->on(DB::raw('spm.item_id::TEXT'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('spm.model_id::TEXT'), '=', 'sm.shopee_sku');
            })
            ->join('shopee_product as sp', 'sp.item_id', '=', 'spm.item_id')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin(DB::raw("(
                SELECT DISTINCT ON (item_id::TEXT, model_id::TEXT)
                    item_id::TEXT as item_id_text,
                    model_id::TEXT as model_id_text,
                    image_url
                FROM shopee_product_image
                WHERE model_id IS NOT NULL
                ORDER BY item_id::TEXT, model_id::TEXT, CASE WHEN image_url LIKE 'http%' THEN 0 ELSE 1 END, id
            ) as img"), function ($join) {
                $join->on('img.item_id_text', '=', DB::raw('spm.item_id::TEXT'))
                    ->on('img.model_id_text', '=', 'spm.model_id');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->whereRaw('COALESCE(sp.is_active, true) = true')
            ->selectRaw("
                sm.id as stock_master_id,
                sp.item_id::TEXT as item_id,
                spm.model_id::TEXT as model_id,
                COALESCE(NULLIF(sm.product_name, ''), sp.name) as product_name,
                COALESCE(NULLIF(sm.variant_name, ''), spm.name) as variant_name,
                COALESCE(NULLIF(sm.shopee_seller_sku, ''), NULLIF(spm.model_sku, ''), NULLIF(sm.internal_sku, '')) as seller_sku,
                ('P' || sp.item_id::TEXT) as parent_sku,
                COALESCE(spm.price, sp.price_min, 0) as price,
                COALESCE(sm.stock_qty, spm.stock, 0) as stock_qty,
                COALESCE(NULLIF(map.shopee_image_url, ''), NULLIF(img.image_url, '')) as raw_image_url
            ")
            ->orderBy('sp.name')
            ->orderBy('spm.name')
            ->get()
            ->map(function ($row) {
                $row->item_id = (string) $row->item_id;
                $row->model_id = (string) $row->model_id;
                $row->seller_sku = trim((string) $row->seller_sku);
                $row->image_url = $this->marketplaceImageUrl($row->raw_image_url ?? null);
                $row->stock_qty = max(0, (int) $row->stock_qty);
                $row->price = max(99, (int) $row->price);

                return $row;
            });
    }

    private function productRows()
    {
        $variants = $this->variantRows()->groupBy('item_id');
        $imagesByItem = $this->productImagesByItem();
        $productsByItem = DB::table('shopee_product')
            ->whereIn('item_id', $variants->keys()->all())
            ->get()
            ->keyBy(fn ($product) => (string) $product->item_id);

        return $variants
            ->map(function ($rows, $itemId) use ($productsByItem, $imagesByItem) {
                $itemId = (string) $itemId;
                $firstVariant = $rows->first();
                $sourceProduct = $productsByItem->get($itemId);
                $images = $imagesByItem[$itemId] ?? [];
                $product = new \stdClass();
                $product->item_id = $itemId;
                $product->product_name = (string) ($sourceProduct->name ?? $firstVariant->product_name ?? '');
                $product->parent_sku = 'P'.$itemId;
                $product->description = (string) ($sourceProduct->description ?? $product->product_name);
                $product->category_label = (string) ($sourceProduct->category_id ?? '');
                $product->stock_qty = (int) $rows->sum('stock_qty');
                $product->sold = (int) ($sourceProduct->sold ?? 0);
                $product->cover_image_url = $images[0] ?? '';

                return $product;
            })
            ->sortBy('product_name')
            ->values();
    }

    private function productImagesByItem(bool $cfOnly = false): array
    {
        return DB::table('shopee_product_image')
            ->whereNull('model_id')
            ->whereNotNull('image_url')
            ->when($cfOnly, fn ($query) => $query->where('image_url', 'like', 'https://cf.shopee.co.id/%'))
            ->orderByRaw("CASE WHEN image_url LIKE 'https://cf.shopee.co.id/%' THEN 0 WHEN image_url LIKE 'http%' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get(['item_id', 'image_url'])
            ->groupBy(fn ($row) => (string) $row->item_id)
            ->map(fn ($rows) => $rows
                ->pluck('image_url')
                ->map(fn ($url) => $cfOnly ? $this->cfShopeeImageUrl($url) : $this->marketplaceImageUrl($url))
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    private function matchVariant(array $row, $byModelId, $bySku, string $modelColumn, string $skuColumn): ?object
    {
        $modelId = trim((string) ($row[$modelColumn] ?? ''));
        if ($modelId !== '' && isset($byModelId[$modelId])) {
            return $byModelId[$modelId];
        }

        $sku = mb_strtolower(trim((string) ($row[$skuColumn] ?? '')));
        if ($sku !== '' && isset($bySku[$sku])) {
            return $bySku[$sku];
        }

        return null;
    }

    private function updateSheetRows(string $path, int $startRow, callable $resolver): void
    {
        [$zip, $sheetPath, $sharedStrings] = $this->openWorkbookSheet($path);
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($zip->getFromName($sheetPath));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', self::XLSX_NS);

        foreach ($xpath->query('//x:sheetData/x:row') as $rowNode) {
            $rowIndex = (int) $rowNode->getAttribute('r');
            if ($rowIndex < $startRow) {
                continue;
            }

            $rowValues = $this->readRowValues($rowNode, $sharedStrings);
            $updates = $resolver($rowValues, $rowIndex);
            foreach ($updates as $column => $value) {
                $this->setCellValue($dom, $rowNode, $column, $rowIndex, $value);
            }
        }

        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();
    }

    private function sanitizeMediaImageUrls(string $path): void
    {
        $imageColumns = array_merge(
            range(5, 13),
            [15],
            range(18, 206, 2)
        );

        $this->updateSheetRows($path, 7, function (array $row) use ($imageColumns): array {
            $updates = [];
            foreach ($imageColumns as $columnIndex) {
                $column = $this->columnName($columnIndex);
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '' && ! str_starts_with($value, 'https://cf.shopee.co.id/')) {
                    $updates[$column] = '';
                }
            }

            return $updates;
        });
    }

    private function replaceSheetDataRows(string $path, int $startRow, array $rows): void
    {
        [$zip, $sheetPath] = $this->openWorkbookSheet($path);
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($zip->getFromName($sheetPath));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', self::XLSX_NS);
        $sheetData = $xpath->query('//x:sheetData')->item(0);
        $prototypeRow = null;
        foreach ($xpath->query('//x:sheetData/x:row') as $rowNode) {
            if ((int) $rowNode->getAttribute('r') === $startRow) {
                $prototypeRow = $rowNode->cloneNode(true);
                break;
            }
        }

        foreach (iterator_to_array($xpath->query('//x:sheetData/x:row')) as $rowNode) {
            if ((int) $rowNode->getAttribute('r') >= $startRow) {
                $sheetData->removeChild($rowNode);
            }
        }

        foreach ($rows as $index => $values) {
            $rowIndex = $startRow + $index;
            $rowNode = $prototypeRow
                ? $prototypeRow->cloneNode(true)
                : $dom->createElementNS(self::XLSX_NS, 'row');
            $rowNode->setAttribute('r', (string) $rowIndex);
            foreach ($rowNode->childNodes as $cell) {
                if (! $cell instanceof \DOMElement || $cell->localName !== 'c') {
                    continue;
                }

                $column = preg_replace('/\d+/', '', $cell->getAttribute('r'));
                $cell->setAttribute('r', $column.$rowIndex);
                $cell->removeAttribute('t');
                while ($cell->firstChild) {
                    $cell->removeChild($cell->firstChild);
                }
            }
            $sheetData->appendChild($rowNode);
            foreach ($values as $column => $value) {
                $this->setCellValue($dom, $rowNode, $column, $rowIndex, $value);
            }
        }

        $zip->addFromString($sheetPath, $dom->saveXML());
        $zip->close();
    }

    private function openWorkbookSheet(string $path): array
    {
        $zip = new ZipArchive();
        abort_if($zip->open($path) !== true, 500, 'File Excel tidak bisa dibuka: '.basename($path));

        return [$zip, 'xl/worksheets/sheet1.xml', $this->sharedStrings($zip)];
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', self::XLSX_NS);
        $strings = [];
        foreach ($xpath->query('//x:si') as $si) {
            $text = '';
            foreach ($xpath->query('.//x:t', $si) as $node) {
                $text .= $node->textContent;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function readRowValues(\DOMElement $rowNode, array $sharedStrings): array
    {
        $values = [];
        foreach ($rowNode->childNodes as $cell) {
            if (! $cell instanceof \DOMElement || $cell->localName !== 'c') {
                continue;
            }

            $ref = $cell->getAttribute('r');
            $column = preg_replace('/\d+/', '', $ref);
            if ($cell->getAttribute('t') === 'inlineStr') {
                $text = '';
                foreach ($cell->getElementsByTagNameNS(self::XLSX_NS, 't') as $node) {
                    $text .= $node->textContent;
                }
                $values[$column] = $text;
                continue;
            }

            $valueNode = null;
            foreach ($cell->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'v') {
                    $valueNode = $child;
                    break;
                }
            }

            $value = $valueNode?->textContent ?? '';
            if ($cell->getAttribute('t') === 's' && $value !== '') {
                $value = $sharedStrings[(int) $value] ?? $value;
            }
            $values[$column] = $value;
        }

        return $values;
    }

    private function setCellValue(\DOMDocument $dom, \DOMElement $rowNode, string $column, int $rowIndex, mixed $value): void
    {
        $cell = $this->findOrCreateCell($dom, $rowNode, strtoupper($column).$rowIndex);
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }

        $value = $value === null ? '' : (string) $value;
        $cell->setAttribute('t', 'inlineStr');
        $inline = $dom->createElementNS(self::XLSX_NS, 'is');
        $text = $dom->createElementNS(self::XLSX_NS, 't');
        if (str_contains($value, "\n") || trim($value) !== $value) {
            $text->setAttribute('xml:space', 'preserve');
        }
        $text->appendChild($dom->createTextNode($value));
        $inline->appendChild($text);
        $cell->appendChild($inline);
    }

    private function findOrCreateCell(\DOMDocument $dom, \DOMElement $rowNode, string $cellRef): \DOMElement
    {
        foreach ($rowNode->childNodes as $cell) {
            if ($cell instanceof \DOMElement && $cell->localName === 'c' && $cell->getAttribute('r') === $cellRef) {
                return $cell;
            }
        }

        $cell = $dom->createElementNS(self::XLSX_NS, 'c');
        $cell->setAttribute('r', $cellRef);
        $targetIndex = $this->columnNumber(preg_replace('/\d+/', '', $cellRef));
        foreach ($rowNode->childNodes as $existing) {
            if (! $existing instanceof \DOMElement || $existing->localName !== 'c') {
                continue;
            }
            $existingIndex = $this->columnNumber(preg_replace('/\d+/', '', $existing->getAttribute('r')));
            if ($existingIndex > $targetIndex) {
                $rowNode->insertBefore($cell, $existing);
                return $cell;
            }
        }
        $rowNode->appendChild($cell);

        return $cell;
    }

    private function marketplaceImageUrl(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($value, '/');
    }

    private function cfShopeeImageUrl(?string $value): string
    {
        $value = trim((string) $value);

        return str_starts_with($value, 'https://cf.shopee.co.id/') ? $value : '';
    }

    private function sourceItemIdFromParentSku(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^P(\d+)$/i', $value, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function normalizeOptionName(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
    }

    private function sanitizeShopeeBasicText(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F000}-\x{1FAFF}\x{FE0F}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\p{S}\x{FE0F}]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;

        return trim($text);
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function columnNumber(string $name): int
    {
        $number = 0;
        foreach (str_split(strtoupper($name)) as $char) {
            $number = ($number * 26) + (ord($char) - 64);
        }

        return $number;
    }
}
