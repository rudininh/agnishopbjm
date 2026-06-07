<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runtime_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('runtime_key')->unique();
            $table->string('active_owner')->default('local');
            $table->boolean('online_backup_enabled')->default(false);
            $table->timestamp('local_last_seen_at')->nullable();
            $table->string('local_machine_name')->nullable();
            $table->string('local_source')->nullable();
            $table->timestamp('online_last_checked_at')->nullable();
            $table->timestamp('last_decision_at')->nullable();
            $table->text('last_decision_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runtime_statuses');
    }
};
