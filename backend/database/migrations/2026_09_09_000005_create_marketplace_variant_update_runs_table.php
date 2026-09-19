<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_variant_update_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('stock_master_id');
            $table->unsignedBigInteger('stock_adjustment_id')->nullable();
            $table->string('operator')->nullable();
            $table->unsignedInteger('requested_stock_qty');
            $table->json('requested_accounts');
            $table->enum('status', ['pending', 'running', 'success', 'partial_success', 'failed', 'blocked'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['stock_master_id', 'created_at']);
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_variant_update_runs');
    }
};
