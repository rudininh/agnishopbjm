<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_reconciliation_run_items', function (Blueprint $table): void {
            $table->uuid('execution_owner')->nullable()->after('result');
            $table->timestamp('execution_lease_until')->nullable()->after('execution_owner');
            $table->unsignedInteger('execution_attempts')->default(0)->after('execution_lease_until');
            $table->index(
                ['execution_owner', 'execution_lease_until'],
                'tiktok_recon_items_execution_lease_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_reconciliation_run_items', function (Blueprint $table): void {
            $table->dropIndex('tiktok_recon_items_execution_lease_idx');
            $table->dropColumn(['execution_owner', 'execution_lease_until', 'execution_attempts']);
        });
    }
};
