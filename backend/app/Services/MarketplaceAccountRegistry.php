<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class MarketplaceAccountRegistry
{
    /**
     * @return array<int, array{key:string, name:string, channel:string, enabled:bool, connect_action:string, uses_primary_app:bool, required_env:array<int, string>}>
     */
    public function publicAccounts(): array
    {
        $accounts = [];

        foreach (config('marketplace_accounts.accounts', []) as $key => $account) {
            $accounts[] = [
                'key' => (string) $key,
                'name' => (string) ($account['name'] ?? ''),
                'channel' => (string) ($account['channel'] ?? ''),
                'enabled' => (bool) ($account['enabled'] ?? false),
                'connect_action' => (string) ($account['connect_action'] ?? ''),
                'uses_primary_app' => (bool) ($account['use_primary_app'] ?? false),
                'required_env' => array_values($account['required_env'] ?? []),
            ];
        }

        return $accounts;
    }

    /**
     * @return array<string, mixed>
     */
    public function account(string $accountKey): array
    {
        $account = config('marketplace_accounts.accounts.'.$accountKey);

        if (! is_array($account)) {
            throw new InvalidArgumentException('Akun marketplace tidak dikenal.');
        }

        return $account;
    }

    /**
     * @return array{partner_id:int, partner_key:string, host:string, redirect_url:string}
     */
    public function shopeeContext(string $accountKey): array
    {
        $account = $this->requireChannel($accountKey, 'shopee');
        $credentials = $this->shopeeCredentials($accountKey, $account);
        $context = [
            'partner_id' => (int) ($credentials['partner_id'] ?? 0),
            'partner_key' => trim((string) ($credentials['partner_key'] ?? '')),
            'host' => rtrim(trim((string) ($credentials['host'] ?? '')), '/'),
            'redirect_url' => trim((string) ($credentials['redirect_url'] ?? '')),
        ];

        if ($context['partner_id'] <= 0 || $context['partner_key'] === '' || $context['host'] === '' || $context['redirect_url'] === '') {
            throw new RuntimeException('Konfigurasi '.$this->accountName($account).' belum lengkap.');
        }

        return $context;
    }

    /**
     * @return array{app_key:string, app_secret:string, auth_host:string, api_host:string, redirect_url:string, warehouse_id:string}
     */
    public function tiktokContext(string $accountKey): array
    {
        $account = $this->requireChannel($accountKey, 'tiktok');
        $credentials = $account['credentials'] ?? [];
        $context = [
            'app_key' => trim((string) ($credentials['app_key'] ?? '')),
            'app_secret' => trim((string) ($credentials['app_secret'] ?? '')),
            'auth_host' => rtrim(trim((string) ($credentials['auth_host'] ?? '')), '/'),
            'api_host' => rtrim(trim((string) ($credentials['api_host'] ?? '')), '/'),
            'redirect_url' => trim((string) ($credentials['redirect_url'] ?? '')),
            'warehouse_id' => trim((string) ($credentials['warehouse_id'] ?? '')),
        ];

        if (in_array('', $context, true)) {
            throw new RuntimeException('Konfigurasi '.$this->accountName($account).' belum lengkap.');
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireChannel(string $accountKey, string $channel): array
    {
        $account = $this->account($accountKey);

        if (($account['channel'] ?? null) !== $channel) {
            throw new InvalidArgumentException('Kanal akun marketplace tidak sesuai.');
        }

        return $account;
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function shopeeCredentials(string $accountKey, array $account): array
    {
        if ($accountKey === 'shopee-gitacollectionbjm' && (bool) ($account['use_primary_app'] ?? false)) {
            return $this->requireChannel('shopee-agnishopbjm', 'shopee')['credentials'] ?? [];
        }

        return $account['credentials'] ?? [];
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accountName(array $account): string
    {
        return (string) ($account['name'] ?? 'marketplace');
    }
}
