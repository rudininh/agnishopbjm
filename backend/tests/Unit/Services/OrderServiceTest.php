<?php

namespace Tests\Unit\Services;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_rejects_empty_cart(): void
    {
        $user = User::factory()->create();

        $this->expectException(DomainException::class);

        app(OrderService::class)->checkout($user, [
            'shipping_address' => 'Jl. A. Yani, Banjarmasin',
            'payment_method' => 'bank_transfer',
        ]);
    }
}
