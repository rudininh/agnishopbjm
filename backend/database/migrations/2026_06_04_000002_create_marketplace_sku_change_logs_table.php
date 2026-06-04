<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_sku_change_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_master_id')->nullable()->index();
            $table->string('channel', 32)->index();
            $table->string('product_id', 100)->nullable()->index();
            $table->string('variant_id', 100)->nullable()->index();
            $table->string('old_seller_sku', 150)->nullable();
            $table->string('new_seller_sku', 150)->nullable();
            $table->string('action', 64)->default('manual_update')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sku_change_logs');
    }
};
