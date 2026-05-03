<?php

namespace App\DTOs;

final readonly class CheckoutData
{
    public function __construct(
        public string $shippingAddress,
        public string $paymentMethod,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            shippingAddress: $data['shipping_address'],
            paymentMethod: $data['payment_method'],
        );
    }
}
