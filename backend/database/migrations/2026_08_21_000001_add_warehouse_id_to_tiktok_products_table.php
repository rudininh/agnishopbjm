<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tiktok_products') && ! Schema::hasColumn('tiktok_products', 'warehouse_id')) {
            Schema::table('tiktok_products', function (Blueprint $table): void {
                $table->text('warehouse_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tiktok_products') && Schema::hasColumn('tiktok_products', 'warehouse_id')) {
            Schema::table('tiktok_products', function (Blueprint $table): void {
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
