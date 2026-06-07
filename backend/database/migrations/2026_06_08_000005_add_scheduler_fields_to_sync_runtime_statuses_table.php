<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table) {
            $table->timestamp('runner_last_scheduler_tick_at')->nullable();
            $table->string('runner_last_scheduler_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runtime_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'runner_last_scheduler_tick_at',
                'runner_last_scheduler_status',
            ]);
        });
    }
};
