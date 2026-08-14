<?php

namespace Tests\Feature;

use App\Services\GitashopMassUploadWorkerLauncher;
use App\Services\ShopeeGitaMassUpdateGenerator;
use App\Services\ShopeeMassUploadService;
use App\Services\ShopeeMassUploadStbGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class ShopeeMassUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mass_upload_configuration_is_locked_to_gitashopcollection(): void
    {
        $this->assertSame('shopee-gitacollectionbjm', config('shopee_mass_upload.account_key'));
        $this->assertSame('Gitashopcollection', config('shopee_mass_upload.expected_shop_name'));
    }

    public function test_mass_upload_audit_tables_store_file_metadata_without_browser_profile_data(): void
    {
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => config('shopee_mass_upload.account_key'),
            'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
            'status' => 'menunggu_stb',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shopee_mass_upload_files')->insert([
            'job_id' => $jobId,
            'sequence' => 1,
            'file_type' => 'basic-info',
            'filename' => 'mass_update_basic_info.xlsx',
            'storage_path' => 'import-marketplace/generated/jobs/1/mass_update_basic_info.xlsx',
            'row_count' => 60,
            'sha256' => str_repeat('a', 64),
            'status' => 'dibuat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shopee_mass_upload_runtimes')->insert([
            'account_key' => config('shopee_mass_upload.account_key'),
            'active_job_id' => $jobId,
            'worker_name' => 'gitashop-worker',
            'worker_last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('shopee_mass_upload_files', [
            'job_id' => $jobId,
            'file_type' => 'basic-info',
            'row_count' => 60,
            'sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_user_can_create_only_one_active_job_for_the_locked_account(): void
    {
        $launcher = Mockery::mock(GitashopMassUploadWorkerLauncher::class);
        $launcher->shouldReceive('wake')->once()->andReturn(['status' => 'started']);
        $this->app->instance(GitashopMassUploadWorkerLauncher::class, $launcher);

        $this->postJson('/api/marketplace/import/shopee-gita/mass-upload/jobs')
            ->assertCreated()
            ->assertJsonPath('data.account_key', 'shopee-gitacollectionbjm')
            ->assertJsonPath('data.expected_shop_name', 'Gitashopcollection')
            ->assertJsonPath('data.status', 'menunggu_stb')
            ->assertJsonPath('worker.status', 'started');

        $this->postJson('/api/marketplace/import/shopee-gita/mass-upload/jobs')
            ->assertConflict();
    }

    public function test_user_can_wake_the_local_worker_for_an_active_job_without_creating_a_second_job(): void
    {
        $job = app(ShopeeMassUploadService::class)->create();
        $launcher = Mockery::mock(GitashopMassUploadWorkerLauncher::class);
        $launcher->shouldReceive('wake')->once()->andReturn(['status' => 'already_running']);
        $this->app->instance(GitashopMassUploadWorkerLauncher::class, $launcher);

        $this->postJson('/api/marketplace/import/shopee-gita/mass-upload/worker/wake')
            ->assertOk()
            ->assertJsonPath('data.status', 'already_running');

        $this->assertDatabaseCount('shopee_mass_upload_jobs', 1);
        $this->assertDatabaseHas('shopee_mass_upload_jobs', ['id' => $job->id, 'status' => 'menunggu_stb']);
    }

    public function test_worker_endpoints_require_the_dedicated_token_and_record_heartbeat(): void
    {
        Config::set('shopee_mass_upload.worker_token', 'dedicated-worker-token');

        $this->postJson('/api/internal/shopee-gita-mass-upload/heartbeat')
            ->assertUnauthorized();

        $this->withToken('dedicated-worker-token')
            ->postJson('/api/internal/shopee-gita-mass-upload/heartbeat', ['worker_name' => 'worker-test'])
            ->assertOk();

        $this->assertDatabaseHas('shopee_mass_upload_runtimes', [
            'account_key' => 'shopee-gitacollectionbjm',
            'worker_name' => 'worker-test',
        ]);
    }

    public function test_claim_generates_six_files_and_requires_serial_seller_centre_completion(): void
    {
        $generatedFiles = app(ShopeeGitaMassUpdateGenerator::class)->definitions();
        $generatedFiles = array_map(fn (array $file, int $index): array => [
            ...$file,
            'storage_path' => 'import-marketplace/generated/test/'.$file['filename'],
            'row_count' => $file['file_type'] === 'republish-items' ? 0 : 60,
            'sha256' => str_repeat((string) (($index % 9) + 1), 64),
        ], $generatedFiles, array_keys($generatedFiles));

        $generator = Mockery::mock(ShopeeGitaMassUpdateGenerator::class);
        $generator->shouldReceive('generate')->once()->andReturn($generatedFiles);
        $guard = Mockery::mock(ShopeeMassUploadStbGuard::class);
        $guard->shouldReceive('acquireForJob')->once()->andReturnTrue();
        $guard->shouldReceive('renewForJob')->andReturnTrue();
        $guard->shouldReceive('releaseForJob')->once();
        $this->app->instance(ShopeeGitaMassUpdateGenerator::class, $generator);
        $this->app->instance(ShopeeMassUploadStbGuard::class, $guard);

        $service = app(ShopeeMassUploadService::class);
        $job = $service->create();
        $claim = $service->claim('worker-test');

        $this->assertSame('basic-info', $claim['file']['file_type']);
        $this->assertNotEmpty($claim['claim_token']);
        $this->assertNull($service->claim('second-worker'));
        $this->assertDatabaseCount('shopee_mass_upload_files', 6);

        foreach (range(1, 6) as $sequence) {
            $file = DB::table('shopee_mass_upload_files')->where('job_id', $job->id)->where('sequence', $sequence)->first();
            $this->assertSame($sequence === 1 ? 'dibuat' : 'menunggu', $file->status);
        }

        foreach (range(1, 6) as $sequence) {
            $file = DB::table('shopee_mass_upload_files')->where('job_id', $job->id)->where('sequence', $sequence)->first();
            $service->recordFileEvent($job->id, $file->id, $claim['claim_token'], ['status' => 'diunggah']);
            $service->recordFileEvent($job->id, $file->id, $claim['claim_token'], ['status' => 'memproses']);
            $service->recordFileEvent($job->id, $file->id, $claim['claim_token'], [
                'status' => 'selesai',
                'shopee_status' => 'Selesai',
                'shopee_processed_count' => $sequence === 6 ? 0 : 60,
            ]);
        }

        $completed = $service->job($job->id);
        $this->assertSame('selesai', $completed['status']);
        $this->assertNull($service->current());
    }

    public function test_worker_can_reconcile_a_verified_terminal_file_and_resume_the_next_file(): void
    {
        $now = now();
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => 'shopee-gitacollectionbjm',
            'expected_shop_name' => 'Gitashopcollection',
            'status' => 'menunggu_verifikasi',
            'message' => 'Perlu verifikasi audit.',
            'requested_at' => $now,
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fileId = DB::table('shopee_mass_upload_files')->insertGetId([
            'job_id' => $jobId,
            'sequence' => 1,
            'file_type' => 'basic-info',
            'filename' => 'mass_update_basic_info.xlsx',
            'storage_path' => 'import-marketplace/generated/test/mass_update_basic_info.xlsx',
            'row_count' => 60,
            'sha256' => str_repeat('a', 64),
            'status' => 'menunggu_verifikasi',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $nextId = DB::table('shopee_mass_upload_files')->insertGetId([
            'job_id' => $jobId,
            'sequence' => 2,
            'file_type' => 'sales-info',
            'filename' => 'mass_update_sales_info.xlsx',
            'storage_path' => 'import-marketplace/generated/test/mass_update_sales_info.xlsx',
            'row_count' => 60,
            'sha256' => str_repeat('b', 64),
            'status' => 'menunggu',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = app(ShopeeMassUploadService::class)->reconcileVerifiedFile($jobId, $fileId, [
            'shopee_status' => 'Selesai',
            'shopee_processed_count' => 60,
            'message' => 'Hasil Seller Centre diverifikasi ulang.',
        ]);

        $this->assertSame('berjalan', $result['status']);
        $this->assertSame('selesai', DB::table('shopee_mass_upload_files')->where('id', $fileId)->value('status'));
        $this->assertSame('dibuat', DB::table('shopee_mass_upload_files')->where('id', $nextId)->value('status'));
        $this->assertSame($jobId, DB::table('shopee_mass_upload_runtimes')->where('account_key', 'shopee-gitacollectionbjm')->value('active_job_id'));
    }

    public function test_sales_info_accepts_the_verified_shopee_product_count_not_the_variant_row_count(): void
    {
        $now = now();
        $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
            'account_key' => config('shopee_mass_upload.account_key'),
            'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
            'status' => 'berjalan',
            'requested_at' => $now,
            'started_at' => $now,
            'worker_claim_token' => 'claim-token',
            'worker_claim_name' => 'worker-test',
            'worker_claimed_until_at' => $now->copy()->addMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fileId = DB::table('shopee_mass_upload_files')->insertGetId([
            'job_id' => $jobId,
            'sequence' => 1,
            'file_type' => 'sales-info',
            'filename' => 'mass_update_sales_info.xlsx',
            'storage_path' => 'import-marketplace/generated/test/mass_update_sales_info.xlsx',
            'row_count' => 1730,
            'shopee_expected_processed_count' => 60,
            'sha256' => str_repeat('c', 64),
            'status' => 'memproses',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('shopee_mass_upload_runtimes')->insert([
            'account_key' => config('shopee_mass_upload.account_key'),
            'active_job_id' => $jobId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('shopee_mass_upload_files')->insert([
            'job_id' => $jobId,
            'sequence' => 2,
            'file_type' => 'media-info',
            'filename' => 'mass_update_media_info.xlsx',
            'storage_path' => 'import-marketplace/generated/test/mass_update_media_info.xlsx',
            'row_count' => 60,
            'shopee_expected_processed_count' => 60,
            'sha256' => str_repeat('d', 64),
            'status' => 'menunggu',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $guard = Mockery::mock(ShopeeMassUploadStbGuard::class);
        $guard->shouldReceive('renewForJob')->once()->with($jobId)->andReturnTrue();
        $this->app->instance(ShopeeMassUploadStbGuard::class, $guard);

        app(ShopeeMassUploadService::class)->recordFileEvent($jobId, $fileId, 'claim-token', [
            'status' => 'selesai',
            'shopee_status' => 'Selesai',
            'shopee_processed_count' => 60,
        ]);

        $this->assertSame('selesai', DB::table('shopee_mass_upload_files')->where('id', $fileId)->value('status'));
        $this->assertSame(60, DB::table('shopee_mass_upload_files')->where('id', $fileId)->value('shopee_processed_count'));
    }
}
