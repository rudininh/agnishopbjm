<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table): void {
            $table->string('marketplace_operation', 80)->nullable();
            $table->string('marketplace_operation_token', 80)->nullable();
            $table->timestamp('marketplace_operation_locked_until_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table): void {
            $table->dropColumn([
                'marketplace_operation',
                'marketplace_operation_token',
                'marketplace_operation_locked_until_at',
            ]);
        });
    }
};
