<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 120)->unique();
            $table->string('name');
            $table->string('channel', 40);
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamps();

            $table->index(['channel', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_accounts');
    }
};