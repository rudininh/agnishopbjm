<?php

namespace App\Exceptions;

use RuntimeException;

class DomainException extends RuntimeException
{
    public static function emptyCart(): self
    {
        return new self('Cart kosong, tidak dapat checkout.');
    }

    public static function insufficientStock(string $productName): self
    {
        return new self("Stok tidak cukup untuk produk {$productName}.");
    }
}
