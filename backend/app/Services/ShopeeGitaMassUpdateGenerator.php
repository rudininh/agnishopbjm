<?php

namespace App\Services;

use App\Http\Controllers\MarketplaceImportController;

class ShopeeGitaMassUpdateGenerator
{
    public function __construct(private readonly MarketplaceImportController $marketplaceImportController)
    {
    }

    public function definitions(): array
    {
        return [
            ['file_type' => 'basic-info', 'filename' => 'mass_update_basic_info.xlsx'],
            ['file_type' => 'sales-info', 'filename' => 'mass_update_sales_info.xlsx'],
            ['file_type' => 'media-info', 'filename' => 'mass_update_media_info.xlsx'],
            ['file_type' => 'shipping-info', 'filename' => 'mass_update_shipping_info.xlsx'],
            ['file_type' => 'dts-info', 'filename' => 'mass_update_dts_info.xlsx'],
            ['file_type' => 'republish-items', 'filename' => 'mass_republish_items.xlsx'],
        ];
    }

    public function generate(string $relativeDirectory, ?int $jobId = null): array
    {
        return $this->marketplaceImportController->generateShopeeGitaMassUpdateFiles($relativeDirectory, $jobId);
    }
}
