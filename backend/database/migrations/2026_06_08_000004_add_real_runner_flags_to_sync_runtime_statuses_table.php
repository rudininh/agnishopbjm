<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table) {
            $table->boolean('online_backup_real_enabled')->default(false);
            $table->timestamp('runner_last_real_run_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'online_backup_real_enabled',
                'runner_last_real_run_at',
            ]);
        });
    }
};
