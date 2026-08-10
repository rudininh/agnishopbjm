<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gita_stock_scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->text('message')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('duplicate_master_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });

        Schema::create('gita_stock_scrape_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('gita_stock_scrape_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_master_id')->nullable()->index();
            $table->string('sku', 150)->index();
            $table->integer('stock');
            $table->string('gita_product_id', 100)->nullable();
            $table->string('gita_variant_id', 100)->nullable();
            $table->integer('previous_stock')->nullable();
            $table->string('match_status', 32);
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gita_stock_scrape_items');
        Schema::dropIfExists('gita_stock_scrape_runs');
    }
};
