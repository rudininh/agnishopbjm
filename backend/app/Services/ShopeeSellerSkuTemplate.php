<?php

namespace App\Services;

final class ShopeeSellerSkuTemplate
{
    public function build(string $itemId, string $variantName): string
    {
        $fragment = strtoupper(trim($variantName));
        $fragment = preg_replace('/[^A-Z0-9_-]+/', '-', $fragment);
        $fragment = trim((string) $fragment, '-');
        $fragment = substr($fragment !== '' ? $fragment : 'X', 0, 30);

        return 'INT-'.trim($itemId).'-'.$fragment;
    }
}
