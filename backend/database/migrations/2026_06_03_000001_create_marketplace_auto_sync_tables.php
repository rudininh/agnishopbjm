<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace', 32)->index();
            $table->string('event_type', 100)->nullable()->index();
            $table->string('sku', 150)->nullable()->index();
            $table->integer('qty')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('marketplace_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('source_marketplace', 32)->nullable()->index();
            $table->string('target_marketplace', 32)->nullable()->index();
            $table->string('sku', 150)->nullable()->index();
            $table->integer('old_stock')->nullable();
            $table->integer('new_stock')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('marketplace_sync_status', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace', 32)->unique();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('status', 32)->default('disconnected')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_status');
        Schema::dropIfExists('marketplace_sync_logs');
        Schema::dropIfExists('marketplace_webhook_logs');
    }
};
