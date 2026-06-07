<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table) {
            $table->string('runner_lock_token')->nullable();
            $table->timestamp('runner_locked_until_at')->nullable();
            $table->timestamp('runner_last_dry_run_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'runner_lock_token',
                'runner_locked_until_at',
                'runner_last_dry_run_at',
            ]);
        });
    }
};
