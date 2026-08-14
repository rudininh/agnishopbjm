<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ShopeeMassUploadStbGuard
{
    public function __construct(private readonly MarketplaceOperationLeaseService $leases)
    {
    }

    public function acquireForJob(int $jobId): bool
    {
        return $this->withLease($jobId, 'acquire');
    }

    public function renewForJob(int $jobId): bool
    {
        return $this->withLease($jobId, 'renew');
    }

    public function releaseForJob(int $jobId): void
    {
        $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->first();
        $token = trim((string) ($job?->operation_lease_token ?? ''));
        if ($token === '') {
            return;
        }

        try {
            if ($this->usesRemoteControl()) {
                $this->remote('release', ['token' => $token]);
            } else {
                $this->leases->release($token);
            }
        } finally {
            DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->update([
                'operation_lease_token' => null,
                'updated_at' => now(),
            ]);
        }
    }

    private function withLease(int $jobId, string $action): bool
    {
        $job = DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->first();
        if (! $job) {
            return false;
        }

        $seconds = (int) config('shopee_mass_upload.stb_wait_seconds', 300);
        $token = trim((string) ($job->operation_lease_token ?? ''));

        if ($action === 'renew' && $token !== '') {
            $renewed = $this->usesRemoteControl()
                ? (bool) data_get($this->remote('renew', ['token' => $token, 'seconds' => $seconds]), 'renewed')
                : $this->leases->renew($token, $seconds);

            return $renewed;
        }

        $result = $this->usesRemoteControl()
            ? $this->remote('acquire', ['operation' => 'gitashop_mass_upload', 'seconds' => $seconds])
            : $this->leases->acquire('gitashop_mass_upload', $seconds);

        if (! (bool) ($result['acquired'] ?? false)) {
            return false;
        }

        DB::table('shopee_mass_upload_jobs')->where('id', $jobId)->update([
            'operation_lease_token' => $result['token'],
            'updated_at' => now(),
        ]);

        return true;
    }

    private function usesRemoteControl(): bool
    {
        return trim((string) config('shopee_mass_upload.stb_control_url', '')) !== '';
    }

    private function remote(string $action, array $payload): array
    {
        $baseUrl = rtrim((string) config('shopee_mass_upload.stb_control_url'), '/');
        $token = trim((string) config('shopee_mass_upload.stb_control_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Kontrol STB mass upload belum lengkap.');
        }

        $response = Http::timeout(8)->acceptJson()->withToken($token)->post($baseUrl.'/marketplace-operation/'.$action, $payload);
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new \RuntimeException('Kontrol STB tidak dapat diverifikasi.');
        }

        return $response->json('data');
    }
}
