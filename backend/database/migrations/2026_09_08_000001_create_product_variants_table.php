<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('product_uuid');
            $table->string('variant_name');
            $table->string('sku', 100)->unique();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('products')
                ->cascadeOnDelete();
            $table->index(['product_uuid', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
