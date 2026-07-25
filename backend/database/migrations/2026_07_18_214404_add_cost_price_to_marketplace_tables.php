<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add cost_price to shopee_products table if exists
        if (Schema::hasTable('shopee_products')) {
            Schema::table('shopee_products', function (Blueprint $table) {
                if (!Schema::hasColumn('shopee_products', 'cost_price')) {
                    $table->decimal('cost_price', 12, 2)->nullable()->after('price');
                }
            });
        }

        // Add cost_price to tiktok_products table if exists
        if (Schema::hasTable('tiktok_products')) {
            Schema::table('tiktok_products', function (Blueprint $table) {
                if (!Schema::hasColumn('tiktok_products', 'cost_price')) {
                    $table->decimal('cost_price', 12, 2)->nullable()->after('price');
                }
            });
        }

        // Add cost_price to products table
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'cost_price')) {
                    $table->decimal('cost_price', 12, 2)->nullable()->after('price');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shopee_products') && Schema::hasColumn('shopee_products', 'cost_price')) {
            Schema::table('shopee_products', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }

        if (Schema::hasTable('tiktok_products') && Schema::hasColumn('tiktok_products', 'cost_price')) {
            Schema::table('tiktok_products', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'cost_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }
    }
};
