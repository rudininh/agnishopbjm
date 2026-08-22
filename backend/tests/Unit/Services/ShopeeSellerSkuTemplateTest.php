<?php

namespace Tests\Unit\Services;

use App\Services\ShopeeSellerSkuTemplate;
use PHPUnit\Framework\TestCase;

class ShopeeSellerSkuTemplateTest extends TestCase
{
    public function test_it_builds_the_sku_from_item_id_and_current_variant_name(): void
    {
        $builder = new ShopeeSellerSkuTemplate();

        $this->assertSame(
            'INT-54256579274-SOFT-DUSTY',
            $builder->build('54256579274', 'Soft Dusty')
        );
        $this->assertSame('INT-100-MERAH-L', $builder->build('100', 'Merah / L'));
    }

    public function test_it_normalizes_symbols_preserves_supported_dashes_and_limits_length(): void
    {
        $builder = new ShopeeSellerSkuTemplate();
        $sku = $builder->build('42', '  Rose & Dusty -- Premium  ');

        $this->assertSame('INT-42-ROSE-DUSTY----PREMIUM', $sku);
        $this->assertLessThanOrEqual(100, mb_strlen($sku));
    }
}
