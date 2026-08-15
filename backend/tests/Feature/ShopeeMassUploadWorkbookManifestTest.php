<?php

namespace Tests\Feature;

use App\Http\Controllers\MarketplaceImportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class ShopeeMassUploadWorkbookManifestTest extends TestCase
{
    use RefreshDatabase;

    private string $relativeDirectory = 'import-marketplace/generated/workbook-manifest-test';
    private string $templateDirectory = 'import-marketplace/shopee-gita';
    private array $templateBackups = [];
    private bool $templateDirectoryExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = storage_path('app/'.$this->templateDirectory);
        $this->templateDirectoryExisted = File::isDirectory($directory);
        File::ensureDirectoryExists($directory);

        foreach ($this->templateFilenames() as $filename) {
            $path = $directory.'/'.$filename;
            $this->templateBackups[$path] = File::exists($path) ? File::get($path) : null;
            $this->writeWorkbookTemplate($path);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/'.$this->relativeDirectory));

        foreach ($this->templateBackups as $path => $contents) {
            if ($contents === null) {
                File::delete($path);
            } else {
                File::put($path, $contents);
            }
        }
        if (! $this->templateDirectoryExisted) {
            File::deleteDirectory(storage_path('app/'.$this->templateDirectory));
        }

        parent::tearDown();
    }

    public function test_sales_workbook_uses_the_locked_manifest_price_and_stock(): void
    {
        $now = now();
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => config('shopee_mass_upload.account_key'),
            'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
            'status' => 'preflight',
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('shopee_mass_upload_manifests')->insert([
            'job_id' => $jobId,
            'source_item_id' => '53155980298',
            'source_model_id' => 'source-model-1',
            'target_item_id' => '58112069183',
            'target_model_id' => '356049039888',
            'seller_sku' => 'INT-53155980298-SOFT-BLUE',
            'product_name' => 'Nama sumber terkunci',
            'variant_name' => 'Soft blue',
            'description' => 'Deskripsi sumber terkunci',
            'price' => 51000,
            'stock_qty' => 17,
            'product_image_urls' => json_encode(['https://cf.shopee.co.id/file/product-image']),
            'variant_image_url' => 'https://cf.shopee.co.id/file/variant-image',
            'product_image_identities' => json_encode(['/file/product-image']),
            'variant_image_identity' => '/file/variant-image',
            'fingerprint' => hash('sha256', 'manifest-test'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(MarketplaceImportController::class)->generateShopeeGitaMassUpdateFiles($this->relativeDirectory, $jobId);

        $path = storage_path('app/'.$this->relativeDirectory.'/mass_update_sales_info.xlsx');
        $this->assertSame('51000', $this->cellValue($path, 'G7'));
        $this->assertSame('17', $this->cellValue($path, 'I7'));
        $mediaPath = storage_path('app/'.$this->relativeDirectory.'/mass_update_media_info.xlsx');
        $this->assertSame('https://cf.shopee.co.id/file/product-image', $this->cellValue($mediaPath, 'E7'));
        $this->assertContains('https://cf.shopee.co.id/file/variant-image', $this->cellValues($mediaPath));
    }

    private function cellValue(string $path, string $cellReference): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xpath->query('//x:c') as $node) {
            if ($node->getAttribute('r') === $cellReference) {
                return $node->textContent;
            }
        }

        return '';
    }

    private function cellValues(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return collect($xpath->query('//x:c'))->map(fn ($node) => $node->textContent)->all();
    }

    private function templateFilenames(): array
    {
        return [
            'mass_update_basic_info.xlsx',
            'mass_update_sales_info.xlsx',
            'mass_update_media_info.xlsx',
            'mass_update_shipping_info.xlsx',
            'mass_update_dts_info.xlsx',
            'mass_republish_items.xlsx',
        ];
    }

    private function writeWorkbookTemplate(string $path): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<Types xmlns='http://schemas.openxmlformats.org/package/2006/content-types'><Default Extension='rels' ContentType='application/vnd.openxmlformats-package.relationships+xml'/><Default Extension='xml' ContentType='application/xml'/><Override PartName='/xl/workbook.xml' ContentType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'/><Override PartName='/xl/worksheets/sheet1.xml' ContentType='application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'/></Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<Relationships xmlns='http://schemas.openxmlformats.org/package/2006/relationships'><Relationship Id='rId1' Type='http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument' Target='xl/workbook.xml'/></Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<workbook xmlns='http://schemas.openxmlformats.org/spreadsheetml/2006/main' xmlns:r='http://schemas.openxmlformats.org/officeDocument/2006/relationships'><sheets><sheet name='Sheet1' sheetId='1' r:id='rId1'/></sheets></workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<Relationships xmlns='http://schemas.openxmlformats.org/package/2006/relationships'><Relationship Id='rId1' Type='http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet' Target='worksheets/sheet1.xml'/></Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<worksheet xmlns='http://schemas.openxmlformats.org/spreadsheetml/2006/main'><sheetData><row r='7'><c r='A7' t='inlineStr'><is><t>58112069183</t></is></c><c r='C7' t='inlineStr'><is><t>356049039888</t></is></c><c r='Q7' t='inlineStr'><is><t>Soft blue</t></is></c></row></sheetData></worksheet>
XML);
        $zip->close();
    }
}
