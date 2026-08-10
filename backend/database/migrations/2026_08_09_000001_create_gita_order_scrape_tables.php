<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gita_order_scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->text('message')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('quantity_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('duplicate_master_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });

        Schema::create('gita_order_scrape_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('gita_order_scrape_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_master_id')->nullable()->index();
            $table->string('seller_order_id', 100)->index();
            $table->string('tab_status', 32)->index();
            $table->string('seller_sku', 150)->index();
            $table->string('product_title', 500);
            $table->string('variant_label', 300)->default('');
            $table->unsignedInteger('quantity');
            $table->string('match_status', 32);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['run_id', 'seller_order_id', 'seller_sku', 'variant_label'], 'gita_order_run_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gita_order_scrape_items');
        Schema::dropIfExists('gita_order_scrape_runs');
    }
};
