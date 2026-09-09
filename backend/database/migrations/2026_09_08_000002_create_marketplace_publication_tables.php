<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_publication_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('product_uuid');
            $table->string('status', 32)->default('pending')->index();
            $table->json('requested_accounts');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('products')
                ->cascadeOnDelete();
            $table->index(['product_uuid', 'created_at']);
        });

        Schema::create('marketplace_publication_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->string('account_key', 64);
            $table->string('channel', 32);
            $table->string('status', 32)->default('pending')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->string('remote_product_id', 150)->nullable();
            $table->json('remote_variant_ids')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('run_id')
                ->references('id')
                ->on('marketplace_publication_runs')
                ->cascadeOnDelete();
            $table->unique(['run_id', 'account_key']);
            $table->index(['account_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_publication_results');
        Schema::dropIfExists('marketplace_publication_runs');
    }
};
