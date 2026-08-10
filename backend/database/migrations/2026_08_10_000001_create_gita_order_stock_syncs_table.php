<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gita_order_stock_syncs', function (Blueprint $table): void {
            $table->id();
            $table->string('seller_order_id', 100);
            $table->string('seller_sku', 150);
            $table->unsignedBigInteger('stock_master_id')->nullable()->index();
            $table->unsignedBigInteger('collector_item_id')->nullable()->index();
            $table->unsignedInteger('quantity');
            $table->string('status', 32)->index();
            $table->text('message')->nullable();
            $table->integer('old_stock')->nullable();
            $table->integer('new_stock')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_order_id', 'seller_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gita_order_stock_syncs');
    }
};
