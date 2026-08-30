<?php

namespace Tests\Unit\Services;

use App\Services\StbMappingSyncService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class StbMappingSyncServiceTest extends TestCase
{
    public function test_import_boolean_columns_are_sent_as_postgresql_boolean_literals(): void
    {
        $service = new StbMappingSyncService();
        $method = new ReflectionMethod($service, 'normalizeBooleanColumns');

        $stockMaster = $method->invoke($service, 'stock_master', [
            'is_hidden_from_mapping' => 0,
        ]);
        $tiktokProduct = $method->invoke($service, 'tiktok_products', [
            'is_active' => 1,
        ]);

        $grammar = DB::connection()->getQueryGrammar();

        $this->assertSame('false', $stockMaster['is_hidden_from_mapping']->getValue($grammar));
        $this->assertSame('true', $tiktokProduct['is_active']->getValue($grammar));
    }
}
