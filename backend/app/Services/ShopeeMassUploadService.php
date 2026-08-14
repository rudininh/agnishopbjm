<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ShopeeMassUploadService
{
    private const ACTIVE_STATUSES = ['menunggu_stb', 'berjalan'];
    private const TERMINAL_STATUSES = ['selesai', 'selesai_dengan_gagal', 'menunggu_verifikasi', 'dibatalkan_aman'];

    public function __construct(
        private readonly ShopeeGitaMassUpdateGenerator $generator,
        private readonly ShopeeMassUploadStbGuard $stbGuard,
    ) {
    }

    public function create(): object
    {
        return DB::transaction(function (): object {
            $runtime = $this->lockedRuntime();
            if ($runtime->active_job_id) {
                $active = DB::table('shopee_mass_upload_jobs')->where('id', $runtime->active_job_id)->first();
                if ($active && in_array($active->status, self::ACTIVE_STATUSES, true)) {
                    abort(409, 'Masih ada job upload Gitashopcollection yang aktif.');
                }
            }

            $now = now();
            $jobId = DB::table('shopee_mass_upload_jobs')->insertGetId([
                'account_key' => config('shopee_mass_upload.account_key'),
                'expected_shop_name' => config('shopee_mass_upload.expected_shop_name'),
                'status' => 'menunggu_stb',
                'message' => 'Menunggu pengaman sinkronisasi marketplace STB.',
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('shopee_mass_upload_runtimes')->where('id', $runtime->id)->update([
                'active_job_id' => $jobId,
                'updated_at' => $now,
            ]);

            return DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->firstOrFail();
        });
    }

    public function heartbeat(string $workerName): void
    {
        DB::transaction(function () use ($workerName): void {
            $runtime = $this->lockedRuntime();
            DB::table('shopee_mass_upload_runtimes')->where('id', $runtime->id)->update([
                'worker_name' => mb_substr(trim($workerName) ?: 'gitashop-mass-upload-worker', 0, 150),
                'worker_last_seen_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function claim(string $workerName): ?array
    {
        $this->heartbeat($workerName);
        $job = DB::transaction(function () use ($workerName): ?object {
            $runtime = $this->lockedRuntime();
            if (! $runtime->active_job_id) {
                return null;
            }

            $job = DB::table('shopee_mass_upload_jobs')->where('id', $runtime->active_job_id)->lockForUpdate()->first();
            if (! $job || ! in_array($job->status, self::ACTIVE_STATUSES, true)) {
                return null;
            }

            $claimedUntil = $job->worker_claimed_until_at ? \Carbon\CarbonImmutable::parse($job->worker_claimed_until_at) : null;
            if ($claimedUntil?->isAfter(now())) {
                return null;
            }

            DB::table('shopee_mass_upload_jobs')->where('id', $job->id)->update([
                'worker_claim_token' => bin2hex(random_bytes(32)),
                'worker_claim_name' => mb_substr(trim($workerName) ?: 'gitashop-mass-upload-worker', 0, 150),
                'worker_claimed_until_at' => now()->addSeconds((int) config('shopee_mass_upload.worker_claim_seconds', 120)),
                'updated_at' => now(),
            ]);

            return DB::table('shopee_mass_upload_jobs')->where('id', $job->id)->firstOrFail();
        });
        if (! $job) {
            return null;
        }

        try {
            $leaseOk = trim((string) ($job->operation_lease_token ?? '')) !== ''
                ? $this->stbGuard->renewForJob((int) $job->id)
                : $this->stbGuard->acquireForJob((int) $job->id);
        } catch (\Throwable) {
            $leaseOk = false;
        }

        if (! $leaseOk) {
            $this->terminal((int) $job->id, 'dibatalkan_aman', 'Pengaman sinkronisasi STB tidak tersedia atau masih aktif.');
            return null;
        }

        if ($job->status === 'menunggu_stb') {
            try {
                $this->generateFiles((int) $job->id);
            } catch (\Throwable) {
                $this->terminal((int) $job->id, 'dibatalkan_aman', 'File Mass Update tidak dapat dibuat dengan aman.');
                return null;
            }
        }

        $job = DB::table('shopee_mass_upload_jobs')->where('id', $job->id)->firstOrFail();
        $file = DB::table('shopee_mass_upload_files')
            ->where('job_id', $job->id)
            ->whereIn('status', ['dibuat', 'diunggah', 'memproses'])
            ->orderBy('sequence')
            ->first();

        if (! $file) {
            return null;
        }

        return [
            'job' => $this->serializeJob($job, false),
            'file' => $this->serializeFile($file),
            'upload_url' => 'https://seller.shopee.co.id/portal/product-mass/mass-update/upload',
            'claim_token' => $job->worker_claim_token,
        ];
    }

    public function recordFileEvent(int $jobId, int $fileId, string $claimToken, array $data): array
    {
        $status = $data['status'];
        $allowed = ['diunggah', 'memproses', 'selesai', 'gagal', 'menunggu_verifikasi'];
        abort_unless(in_array($status, $allowed, true), 422, 'Status file upload tidak valid.');

        $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->firstOrFail();
        abort_unless($job->status === 'berjalan', 409, 'Job upload sudah tidak aktif.');
        $this->assertWorkerClaim($job, $claimToken);
        if (! $this->stbGuard->renewForJob($jobId)) {
            $this->terminal($jobId, 'dibatalkan_aman', 'Pengaman sinkronisasi STB tidak dapat diperbarui.');
            abort(409, 'Pengaman sinkronisasi STB tidak dapat diperbarui.');
        }

        $file = DB::table('shopee_mass_upload_files')->where('id', $fileId)->where('job_id', $jobId)->firstOrFail();
        $current = DB::table('shopee_mass_upload_files')->where('job_id', $jobId)->whereIn('status', ['dibuat', 'diunggah', 'memproses'])->orderBy('sequence')->first();
        abort_unless($current && (int) $current->id === $fileId, 409, 'File upload tidak berada pada urutan aktif.');

        $transitions = [
            'dibuat' => ['diunggah', 'gagal', 'menunggu_verifikasi'],
            'diunggah' => ['memproses', 'gagal', 'menunggu_verifikasi'],
            'memproses' => ['selesai', 'gagal', 'menunggu_verifikasi'],
        ];
        abort_unless(in_array($status, $transitions[$file->status] ?? [], true), 422, 'Transisi status file tidak valid.');

        $message = $this->message($data['message'] ?? null);
        $updates = [
            'status' => $status,
            'message' => $message,
            'updated_at' => now(),
        ];
        if (array_key_exists('shopee_status', $data)) {
            $updates['shopee_status'] = mb_substr((string) $data['shopee_status'], 0, 64);
        }
        if (array_key_exists('shopee_processed_count', $data)) {
            $updates['shopee_processed_count'] = max(0, (int) $data['shopee_processed_count']);
        }
        if ($status === 'diunggah') {
            $updates['uploaded_at'] = now();
        }
        if (in_array($status, ['selesai', 'gagal', 'menunggu_verifikasi'], true)) {
            $updates['completed_at'] = now();
        }
        if (in_array($status, ['gagal', 'menunggu_verifikasi'], true)) {
            $updates['error_code'] = mb_substr((string) ($data['error_code'] ?? 'seller_centre'), 0, 80);
        }

        if ($status === 'selesai') {
            $expectedCount = $this->expectedShopeeProcessedCount($file);
            abort_unless(
                ($updates['shopee_status'] ?? $file->shopee_status) === 'Selesai'
                && (int) ($updates['shopee_processed_count'] ?? $file->shopee_processed_count) === $expectedCount,
                422,
                'Hasil Seller Centre tidak cocok dengan audit file.'
            );
        }

        DB::table('shopee_mass_upload_files')->where('id', $fileId)->update($updates);
        $this->renewWorkerClaim($jobId, $claimToken);

        if (in_array($status, ['gagal', 'menunggu_verifikasi'], true)) {
            $this->terminal($jobId, $status === 'menunggu_verifikasi' ? 'menunggu_verifikasi' : 'selesai_dengan_gagal', $message ?: 'Seller Centre tidak dapat menyelesaikan file upload.');
        }

        if ($status === 'selesai') {
            $next = DB::table('shopee_mass_upload_files')->where('job_id', $jobId)->where('sequence', $file->sequence + 1)->first();
            if ($next) {
                DB::table('shopee_mass_upload_files')->where('id', $next->id)->update(['status' => 'dibuat', 'created_at_worker' => now(), 'updated_at' => now()]);
            } else {
                $this->terminal($jobId, 'selesai', 'Enam file Mass Update selesai diproses Seller Centre.');
            }
        }

        return $this->job((int) $jobId);
    }

    public function reconcileVerifiedFile(int $jobId, int $fileId, array $data): array
    {
        $shopeeStatus = mb_substr((string) ($data['shopee_status'] ?? ''), 0, 64);
        $processedCount = max(0, (int) ($data['shopee_processed_count'] ?? -1));
        abort_unless($shopeeStatus === 'Selesai', 422, 'Rekonsiliasi harus memiliki status selesai dari Seller Centre.');

        return DB::transaction(function () use ($jobId, $fileId, $shopeeStatus, $processedCount, $data): array {
            $runtime = $this->lockedRuntime();
            abort_unless(! $runtime->active_job_id || (int) $runtime->active_job_id === $jobId, 409, 'Masih ada job upload lain yang aktif.');

            $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->lockForUpdate()->firstOrFail();
            abort_unless($job->status === 'menunggu_verifikasi', 409, 'Hanya job yang berhenti untuk verifikasi yang dapat direkonsiliasi.');

            $file = DB::table('shopee_mass_upload_files')->where('id', $fileId)->where('job_id', $jobId)->lockForUpdate()->firstOrFail();
            abort_unless($file->status === 'menunggu_verifikasi', 409, 'File tidak menunggu rekonsiliasi verifikasi.');
            $expectedCount = $this->expectedShopeeProcessedCount($file);
            abort_unless($processedCount === $expectedCount, 422, 'Jumlah hasil Seller Centre tidak cocok dengan audit file.');

            $now = now();
            DB::table('shopee_mass_upload_files')->where('id', $fileId)->update([
                'status' => 'selesai',
                'shopee_status' => $shopeeStatus,
                'shopee_processed_count' => $processedCount,
                'message' => $this->message($data['message'] ?? 'Hasil Seller Centre diverifikasi ulang.'),
                'error_code' => null,
                'uploaded_at' => $file->uploaded_at ?: $now,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            $next = DB::table('shopee_mass_upload_files')->where('job_id', $jobId)->where('sequence', $file->sequence + 1)->lockForUpdate()->first();
            if ($next) {
                DB::table('shopee_mass_upload_files')->where('id', $next->id)->update([
                    'status' => 'dibuat',
                    'created_at_worker' => $next->created_at_worker ?: $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->update([
                'status' => $next ? 'berjalan' : 'selesai',
                'message' => $next ? 'Audit Seller Centre terverifikasi. Worker melanjutkan file berikutnya.' : 'Enam file Mass Update selesai diproses Seller Centre.',
                'finished_at' => $next ? null : $now,
                'worker_claim_token' => null,
                'worker_claim_name' => null,
                'worker_claimed_until_at' => null,
                'updated_at' => $now,
            ]);
            DB::table('shopee_mass_upload_runtimes')->where('id', $runtime->id)->update([
                'active_job_id' => $next ? $jobId : null,
                'updated_at' => $now,
            ]);

            return $this->job($jobId);
        });
    }

    public function terminal(int $jobId, string $status, ?string $message = null): void
    {
        abort_unless(in_array($status, self::TERMINAL_STATUSES, true), 422, 'Status akhir job tidak valid.');
        if ($status === 'selesai') {
            $allFilesFinished = DB::table('shopee_mass_upload_files')
                ->where('job_id', $jobId)
                ->where('status', 'selesai')
                ->count() === 6;
            abort_unless($allFilesFinished, 422, 'Job tidak dapat selesai sebelum enam file selesai diproses.');
        }
        $terminalized = DB::transaction(function () use ($jobId, $status, $message): bool {
                $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->lockForUpdate()->first();
                if (! $job || in_array($job->status, self::TERMINAL_STATUSES, true)) {
                    return false;
                }
                DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->update([
                    'status' => $status,
                    'message' => $this->message($message),
                    'finished_at' => now(),
                    'updated_at' => now(),
                    'worker_claim_token' => null,
                    'worker_claim_name' => null,
                    'worker_claimed_until_at' => null,
                ]);
                DB::table('shopee_mass_upload_runtimes')->where('account_key', config('shopee_mass_upload.account_key'))->where('active_job_id', $jobId)->update(['active_job_id' => null, 'updated_at' => now()]);
                return true;
            });

        if ($terminalized) {
            $this->stbGuard->releaseForJob($jobId);
        }
    }

    public function job(int $jobId): array
    {
        $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->firstOrFail();
        return $this->serializeJob($job, true);
    }

    public function current(): ?array
    {
        $runtime = $this->runtime();
        return $runtime?->active_job_id ? $this->job((int) $runtime->active_job_id) : null;
    }

    public function history(int $perPage = 20): array
    {
        return DB::table('shopee_mass_upload_jobs')->orderByDesc('requested_at')->limit(max(1, min(50, $perPage)))->get()->map(fn (object $job) => $this->serializeJob($job, true))->all();
    }

    public function filePath(int $jobId, int $fileId, string $claimToken): object
    {
        $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->firstOrFail();
        abort_unless(in_array($job->status, self::ACTIVE_STATUSES, true), 409, 'Job upload tidak aktif.');
        $this->assertWorkerClaim($job, $claimToken);
        if (! $this->stbGuard->renewForJob($jobId)) {
            $this->terminal($jobId, 'dibatalkan_aman', 'Pengaman sinkronisasi STB tidak dapat diperbarui sebelum file diunggah.');
            abort(409, 'Pengaman sinkronisasi STB tidak dapat diperbarui.');
        }
        $current = DB::table('shopee_mass_upload_files')
            ->where('job_id', $jobId)
            ->whereIn('status', ['dibuat', 'diunggah', 'memproses'])
            ->orderBy('sequence')
            ->first();
        abort_unless($current && (int) $current->id === $fileId, 409, 'File download tidak berada pada urutan aktif.');
        $this->renewWorkerClaim($jobId, $claimToken);
        return $current;
    }

    public function renewWorkerLease(int $jobId, string $claimToken): void
    {
        $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->firstOrFail();
        abort_unless($job->status === 'berjalan', 409, 'Job upload sudah tidak aktif.');
        $this->assertWorkerClaim($job, $claimToken);
        if (! $this->stbGuard->renewForJob($jobId)) {
            $this->terminal($jobId, 'dibatalkan_aman', 'Pengaman sinkronisasi STB tidak dapat diperbarui.');
            abort(409, 'Pengaman sinkronisasi STB tidak dapat diperbarui.');
        }

        $this->renewWorkerClaim($jobId, $claimToken);
    }

    private function generateFiles(int $jobId): void
    {
        $relativeDirectory = 'import-marketplace/generated/gitashop-mass-upload/job-'.$jobId.'-'.bin2hex(random_bytes(4));
        $files = $this->generator->generate($relativeDirectory);
        abort_unless(count($files) === 6, 422, 'Generator Mass Update harus menghasilkan enam file.');

        DB::transaction(function () use ($jobId, $files): void {
            $now = now();
            foreach ($files as $index => $file) {
                DB::table('shopee_mass_upload_files')->insert([
                    'job_id' => $jobId,
                    'sequence' => $index + 1,
                    'file_type' => $file['file_type'],
                    'filename' => $file['filename'],
                    'storage_path' => $file['storage_path'],
                    'row_count' => $file['row_count'],
                    'shopee_expected_processed_count' => $file['shopee_expected_processed_count'] ?? null,
                    'sha256' => $file['sha256'],
                    'status' => $index === 0 ? 'dibuat' : 'menunggu',
                    'created_at_worker' => $index === 0 ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->update([
                'status' => 'berjalan',
                'message' => 'Enam file Mass Update dibuat dan siap diunggah berurutan.',
                'started_at' => $now,
                'worker_last_seen_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function lockedRuntime(): object
    {
        $runtime = DB::table('shopee_mass_upload_runtimes')->where('account_key', config('shopee_mass_upload.account_key'))->lockForUpdate()->first();
        if ($runtime) {
            return $runtime;
        }
        $now = now();
        DB::table('shopee_mass_upload_runtimes')->insertOrIgnore(['account_key' => config('shopee_mass_upload.account_key'), 'created_at' => $now, 'updated_at' => $now]);
        return DB::table('shopee_mass_upload_runtimes')->where('account_key', config('shopee_mass_upload.account_key'))->lockForUpdate()->firstOrFail();
    }

    private function runtime(): ?object
    {
        return DB::table('shopee_mass_upload_runtimes')->where('account_key', config('shopee_mass_upload.account_key'))->first();
    }

    private function serializeJob(object $job, bool $withFiles): array
    {
        $data = [
            'id' => (int) $job->id,
            'account_key' => $job->account_key,
            'expected_shop_name' => $job->expected_shop_name,
            'status' => $job->status,
            'message' => $this->message($job->message),
            'requested_at' => $job->requested_at,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
        ];
        if ($withFiles) {
            $data['files'] = DB::table('shopee_mass_upload_files')->where('job_id', $job->id)->orderBy('sequence')->get()->map(fn (object $file) => $this->serializeFile($file))->all();
        }
        return $data;
    }

    private function serializeFile(object $file): array
    {
        return [
            'id' => (int) $file->id,
            'sequence' => (int) $file->sequence,
            'file_type' => $file->file_type,
            'filename' => $file->filename,
            'row_count' => (int) $file->row_count,
            'shopee_expected_processed_count' => $file->shopee_expected_processed_count === null ? null : (int) $file->shopee_expected_processed_count,
            'sha256' => $file->sha256,
            'status' => $file->status,
            'shopee_status' => $file->shopee_status,
            'shopee_processed_count' => $file->shopee_processed_count === null ? null : (int) $file->shopee_processed_count,
            'created_at_worker' => $file->created_at_worker,
            'uploaded_at' => $file->uploaded_at,
            'completed_at' => $file->completed_at,
            'message' => $this->message($file->message),
        ];
    }

    private function message(?string $value): ?string
    {
        $value = trim(strip_tags((string) $value));
        return $value === '' ? null : mb_substr($value, 0, 500);
    }

    private function assertWorkerClaim(object $job, string $claimToken): void
    {
            $claimedUntil = $job->worker_claimed_until_at ? \Carbon\CarbonImmutable::parse($job->worker_claimed_until_at) : null;
        abort_unless(
            trim($claimToken) !== ''
            && $claimedUntil?->isAfter(now())
            && hash_equals((string) $job->worker_claim_token, trim($claimToken)),
            409,
            'Klaim worker upload sudah tidak aktif.'
        );
    }

    private function renewWorkerClaim(int $jobId, string $claimToken): void
    {
        DB::table('shopee_mass_upload_jobs')
            ->where('id', $jobId)
            ->where('worker_claim_token', $claimToken)
            ->update([
                'worker_claimed_until_at' => now()->addSeconds((int) config('shopee_mass_upload.worker_claim_seconds', 120)),
                'updated_at' => now(),
            ]);
    }

    private function expectedShopeeProcessedCount(object $file): int
    {
        if ($file->file_type === 'republish-items') {
            return 0;
        }

        return $file->shopee_expected_processed_count === null
            ? (int) $file->row_count
            : (int) $file->shopee_expected_processed_count;
    }
}
