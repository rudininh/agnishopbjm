<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_variant_update_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedBigInteger('marketplace_listing_id');
            $table->string('account_key');
            $table->string('channel', 30);
            $table->enum('status', ['pending', 'running', 'success', 'partial_success', 'failed', 'blocked'])->default('pending');
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('requested_stock_qty');
            $table->decimal('requested_price', 15, 2)->nullable();
            $table->json('response_summary')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'account_key']);
            $table->index(['marketplace_listing_id', 'created_at']);
            $table->index(['status', 'completed_at']);
            $table->foreign('run_id')->references('id')->on('marketplace_variant_update_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_variant_update_results');
    }
};
