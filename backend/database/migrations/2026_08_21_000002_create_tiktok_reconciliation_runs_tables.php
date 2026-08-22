<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tiktok_reconciliation_runs')) {
            Schema::create('tiktok_reconciliation_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('revision', 64);
                $table->string('status', 32);
                $table->json('summary');
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tiktok_reconciliation_run_items')) {
            Schema::create('tiktok_reconciliation_run_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('run_id')->constrained('tiktok_reconciliation_runs')->cascadeOnDelete();
                $table->unsignedBigInteger('stock_master_id')->nullable();
                $table->string('item_key', 191);
                $table->string('action_type', 32);
                $table->string('status', 32);
                $table->string('source_fingerprint', 64);
                $table->string('target_product_id')->nullable();
                $table->string('target_sku_id')->nullable();
                $table->text('block_reason')->nullable();
                $table->json('payload')->nullable();
                $table->json('result')->nullable();
                $table->timestamps();

                $table->unique(['run_id', 'item_key']);
                $table->index('status');
                $table->index('action_type');
                $table->index('stock_master_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_reconciliation_run_items');
        Schema::dropIfExists('tiktok_reconciliation_runs');
    }
};
