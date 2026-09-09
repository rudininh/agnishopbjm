<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketplaceAccountReadinessService
{
    public function __construct(private readonly MarketplaceAccountRegistry $registry)
    {
    }

    /**
     * @return array<int, array{
     *     key:string,
     *     name:string,
     *     channel:string,
     *     state:string,
     *     message:string,
     *     checks:array<string, bool>,
     *     mapped_skus:int,
     *     connect_action:string,
     *     required_env:array<int, string>
     * }>
     */
    public function all(): array
    {
        return array_map(fn (array $account): array => $this->readiness($account), $this->registry->publicAccounts());
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function readiness(array $account): array
    {
        $accountKey = (string) $account['key'];
        $channel = (string) $account['channel'];
        $enabled = (bool) $account['enabled'];
        $mappedSkus = $this->mappedSkuCount($accountKey);
        $listingWarehouse = $channel === 'tiktok' ? $this->listingWarehouse($accountKey) : '';
        [$credentials, $configuredWarehouse] = $this->credentialChecks($accountKey, $channel, $listingWarehouse);
        $token = $this->activeToken($channel, $accountKey);
        $activeToken = $token !== null && trim((string) ($token->access_token ?? '')) !== '';
        $shopIdentity = $channel === 'tiktok'
            ? $this->hasTikTokShopIdentity()
            : $token !== null && (int) ($token->shop_id ?? 0) > 0;
        $tokenUsable = $activeToken && $this->tokenUsable($token);
        $warehouse = $channel !== 'tiktok' || $configuredWarehouse || $listingWarehouse !== '';
        $mappings = $mappedSkus > 0;

        $state = match (true) {
            ! $enabled => 'disabled',
            ! $credentials => 'waiting_credentials',
            ! $activeToken || ! $shopIdentity => 'authorization_required',
            ! $tokenUsable => 'token_expired',
            ! $mappings => 'mapping_required',
            default => 'ready',
        };

        return [
            'key' => $accountKey,
            'name' => (string) $account['name'],
            'channel' => $channel,
            'state' => $state,
            'message' => $this->message($state),
            'checks' => [
                'enabled' => $enabled,
                'credentials' => $credentials,
                'active_token' => $activeToken,
                'shop_identity' => $shopIdentity,
                'token_usable' => $tokenUsable,
                'warehouse' => $warehouse,
                'mappings' => $mappings,
            ],
            'mapped_skus' => $mappedSkus,
            'connect_action' => (string) $account['connect_action'],
            'required_env' => array_values($account['required_env'] ?? []),
        ];
    }

    /**
     * @return array{0:bool, 1:bool}
     */
    private function credentialChecks(string $accountKey, string $channel, string $listingWarehouse): array
    {
        try {
            $context = $channel === 'tiktok'
                ? $this->registry->tiktokContext($accountKey)
                : $this->registry->shopeeContext($accountKey);

            return [true, $channel !== 'tiktok' || trim((string) ($context['warehouse_id'] ?? '')) !== ''];
        } catch (Throwable) {
            if ($channel === 'tiktok' && $listingWarehouse !== '' && $this->hasTikTokCoreCredentials($accountKey)) {
                return [true, false];
            }

            return [false, false];
        }
    }

    private function hasTikTokCoreCredentials(string $accountKey): bool
    {
        try {
            $credentials = $this->registry->account($accountKey)['credentials'] ?? [];
        } catch (Throwable) {
            return false;
        }

        foreach (['app_key', 'app_secret', 'auth_host', 'api_host', 'redirect_url'] as $key) {
            if (trim((string) ($credentials[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function activeToken(string $channel, string $accountKey): ?object
    {
        $table = $channel === 'tiktok' ? 'tiktok_tokens' : 'shopee_tokens';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'account_key')) {
            return null;
        }

        return DB::table($table)
            ->where('account_key', $accountKey)
            ->whereRaw('is_active = true')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function tokenUsable(object $token): bool
    {
        $expiry = null;
        foreach (['access_token_expire_at', 'expire_at'] as $column) {
            $value = trim((string) ($token->{$column} ?? ''));
            if ($value !== '') {
                $expiry = $value;
                break;
            }
        }

        if ($expiry === null) {
            return true;
        }

        try {
            return Carbon::parse($expiry)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    private function hasTikTokShopIdentity(): bool
    {
        if (! Schema::hasTable('tiktok_shops')) {
            return false;
        }

        $shop = DB::table('tiktok_shops')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        if ($shop === null) {
            return false;
        }

        $shopId = trim((string) ($shop->shop_id ?? $shop->id ?? ''));
        $cipher = trim((string) ($shop->cipher ?? $shop->shop_cipher ?? ''));

        return $shopId !== '' && $cipher !== '';
    }

    private function mappedSkuCount(string $accountKey): int
    {
        if (! Schema::hasTable('marketplace_listings')) {
            return 0;
        }

        return (int) DB::table('marketplace_listings')
            ->where('account_key', $accountKey)
            ->whereRaw('is_active = true')
            ->count();
    }

    private function listingWarehouse(string $accountKey): string
    {
        if (! Schema::hasTable('marketplace_listings') || ! Schema::hasColumn('marketplace_listings', 'warehouse_id')) {
            return '';
        }

        return trim((string) (DB::table('marketplace_listings')
            ->where('account_key', $accountKey)
            ->whereRaw('is_active = true')
            ->whereNotNull('warehouse_id')
            ->where('warehouse_id', '!=', '')
            ->orderByDesc('updated_at')
            ->value('warehouse_id') ?? ''));
    }

    private function message(string $state): string
    {
        return match ($state) {
            'disabled' => 'Akun marketplace dinonaktifkan.',
            'waiting_credentials' => 'Konfigurasi kredensial akun belum lengkap.',
            'authorization_required' => 'Hubungkan akun marketplace dan pastikan identitas toko tersedia.',
            'token_expired' => 'Token akses akun sudah kedaluwarsa atau tidak valid.',
            'mapping_required' => 'Tambahkan mapping SKU aktif untuk akun ini.',
            default => 'Akun siap untuk sinkronisasi.',
        };
    }
}
