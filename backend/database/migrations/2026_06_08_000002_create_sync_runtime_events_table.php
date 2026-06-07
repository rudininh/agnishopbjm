<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runtime_events', function (Blueprint $table) {
            $table->id();
            $table->string('runtime_key')->index();
            $table->string('event_type')->index();
            $table->string('active_owner')->nullable();
            $table->boolean('local_is_active')->default(false);
            $table->boolean('online_backup_enabled')->default(false);
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runtime_events');
    }
};
