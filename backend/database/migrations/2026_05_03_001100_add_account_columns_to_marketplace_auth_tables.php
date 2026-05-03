<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addAccountColumns('shopee_callbacks');
        $this->addAccountColumns('shopee_tokens');

        if (Schema::hasTable('tiktok_tokens')) {
            $this->addAccountColumns('tiktok_tokens');
        }

        DB::table('shopee_tokens')
            ->whereNull('account_key')
            ->update([
                'account_key' => 'shopee-agnishopbjm',
                'account_name' => 'Shopee AgniShopBJM',
            ]);

        DB::table('shopee_callbacks')
            ->whereNull('account_key')
            ->update([
                'account_key' => 'shopee-agnishopbjm',
                'account_name' => 'Shopee AgniShopBJM',
            ]);
    }

    public function down(): void
    {
        foreach (['shopee_callbacks', 'shopee_tokens', 'tiktok_tokens'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                foreach (['account_key', 'account_name'] as $column) {
                    if (Schema::hasColumn($table->getTable(), $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addAccountColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'account_key')) {
                $table->string('account_key')->nullable()->after('id');
            }

            if (! Schema::hasColumn($tableName, 'account_name')) {
                $table->string('account_name')->nullable()->after('account_key');
            }
        });
    }
};
