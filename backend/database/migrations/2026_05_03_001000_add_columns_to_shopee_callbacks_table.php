<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_callbacks', function (Blueprint $table) {
            $columns = [
                'main_account_id' => fn () => $table->bigInteger('main_account_id')->nullable(),
                'partner_id' => fn () => $table->bigInteger('partner_id')->nullable(),
                'query_payload' => fn () => $table->json('query_payload')->nullable(),
                'used_at' => fn () => $table->timestamp('used_at')->nullable(),
                'updated_at' => fn () => $table->timestamp('updated_at')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('shopee_callbacks', $column)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopee_callbacks', function (Blueprint $table) {
            foreach (['main_account_id', 'partner_id', 'query_payload', 'used_at', 'updated_at'] as $column) {
                if (Schema::hasColumn('shopee_callbacks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
