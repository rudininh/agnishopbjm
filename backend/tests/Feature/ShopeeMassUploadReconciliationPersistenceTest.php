<?php

namespace Tests\Feature;

use App\Services\ShopeeMassUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeeMassUploadReconciliationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_exposes_safe_reconciliation_summary(): void
    {
        $now = now();
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => config('shopee_mass_upload.account_key'),
            'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
            'status' => 'memverifikasi',
            'requested_at' => $now,
            'source_refreshed_at' => $now,
            'expected_product_count' => 60,
            'expected_variant_count' => 1730,
            'matched_variant_count' => 1729,
            'mismatched_variant_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('shopee_mass_upload_reconciliation_results')->insert([
            'job_id' => $jobId,
            'target_item_id' => 'target-item-1',
            'target_model_id' => 'target-model-1',
            'status' => 'mismatched',
            'mismatch_fields' => json_encode(['price']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job = app(ShopeeMassUploadService::class)->job($jobId);

        $this->assertSame(60, $job['expected_product_count']);
        $this->assertSame(1730, $job['expected_variant_count']);
        $this->assertSame(1729, $job['matched_variant_count']);
        $this->assertSame(1, $job['mismatched_variant_count']);
        $this->assertSame(1, $job['reconciliation']['mismatched_count']);
        $this->assertArrayNotHasKey('access_token', $job);
        $this->assertArrayNotHasKey('worker_claim_token', $job);
    }
}
