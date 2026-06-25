<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_sync_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_sync_logs', 'runner')) {
                $table->string('runner', 32)->nullable()->index()->after('target_marketplace');
            }

            if (! Schema::hasColumn('marketplace_sync_logs', 'machine_name')) {
                $table->string('machine_name', 120)->nullable()->after('runner');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_sync_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('marketplace_sync_logs', 'machine_name')) {
                $table->dropColumn('machine_name');
            }

            if (Schema::hasColumn('marketplace_sync_logs', 'runner')) {
                $table->dropColumn('runner');
            }
        });
    }
};
