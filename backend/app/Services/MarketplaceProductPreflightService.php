<?php

namespace App\Services;

use App\Models\Product;

class MarketplaceProductPreflightService
{
    public function __construct(private readonly MarketplaceAccountRegistry $registry)
    {
    }

    /**
     * @param array<int, string> $accountKeys
     * @return array{valid: bool, errors: array<int, array{account_key: ?string, code: string, field: ?string, message: string}>}
     */
    public function validate(Product $product, array $accountKeys): array
    {
        $errors = [];
        $accounts = [];

        foreach ($this->registry->publicAccounts() as $account) {
            $accounts[(string) $account['key']] = $account;
        }

        foreach (array_values(array_unique($accountKeys)) as $accountKey) {
            if (! isset($accounts[$accountKey])) {
                $errors[] = [
                    'account_key' => $accountKey,
                    'code' => 'unknown_account',
                    'field' => 'accounts',
                    'message' => 'Akun marketplace tidak dikenal.',
                ];
                continue;
            }

            if (! (bool) $accounts[$accountKey]['enabled']) {
                $errors[] = [
                    'account_key' => $accountKey,
                    'code' => 'account_disabled',
                    'field' => 'accounts',
                    'message' => 'Akun marketplace sedang dinonaktifkan.',
                ];
            }
        }

        if ($accountKeys === []) {
            $errors[] = [
                'account_key' => null,
                'code' => 'accounts_required',
                'field' => 'accounts',
                'message' => 'Pilih minimal satu marketplace.',
            ];
        }

        $product->loadMissing('variants');
        $variants = $product->variants;

        if ($variants->isEmpty()) {
            $errors[] = [
                'account_key' => null,
                'code' => 'variants_required',
                'field' => 'variants',
                'message' => 'Produk harus memiliki minimal satu varian.',
            ];
        }

        $skuOwners = [strtolower(trim((string) $product->sku)) => 'product'];

        foreach ($variants as $variant) {
            $variantName = trim((string) $variant->variant_name);
            $sku = strtolower(trim((string) $variant->sku));

            if ($variantName === '') {
                $errors[] = [
                    'account_key' => null,
                    'code' => 'variant_name_required',
                    'field' => 'variants',
                    'message' => 'Nama varian wajib diisi.',
                ];
            }

            if ($sku === '') {
                $errors[] = [
                    'account_key' => null,
                    'code' => 'variant_sku_required',
                    'field' => 'variants',
                    'message' => 'SKU varian wajib diisi.',
                ];
            } elseif (isset($skuOwners[$sku])) {
                $errors[] = [
                    'account_key' => null,
                    'code' => 'sku_conflict',
                    'field' => 'variants.sku',
                    'message' => 'SKU produk dan varian tidak boleh sama.',
                ];
            } else {
                $skuOwners[$sku] = 'variant';
            }

            if (trim((string) $variant->image_url) === '') {
                $errors[] = [
                    'account_key' => null,
                    'code' => 'variant_image_required',
                    'field' => 'variants.image_url',
                    'message' => 'Setiap varian harus memiliki URL gambar.',
                ];
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
