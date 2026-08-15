<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_mass_upload_manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('shopee_mass_upload_jobs')->cascadeOnDelete();
            $table->string('source_item_id', 100);
            $table->string('source_model_id', 100);
            $table->string('target_item_id', 100);
            $table->string('target_model_id', 100);
            $table->string('seller_sku', 255);
            $table->string('product_name', 500)->nullable();
            $table->string('variant_name', 500)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('stock_qty');
            $table->json('product_image_urls')->nullable();
            $table->string('variant_image_url', 1000)->nullable();
            $table->json('product_image_identities')->nullable();
            $table->string('variant_image_identity', 500)->nullable();
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(['job_id', 'source_item_id', 'source_model_id']);
            $table->unique(['job_id', 'target_item_id', 'target_model_id']);
            $table->unique(['job_id', 'seller_sku']);
            $table->index(['job_id', 'target_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_mass_upload_manifests');
    }
};
