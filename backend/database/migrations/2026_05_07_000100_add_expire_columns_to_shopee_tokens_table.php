<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return;
        }

        Schema::table('shopee_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('shopee_tokens', 'access_token_expire_at')) {
                $table->timestamp('access_token_expire_at')->nullable();
            }

            if (! Schema::hasColumn('shopee_tokens', 'refresh_token_expire_at')) {
                $table->timestamp('refresh_token_expire_at')->nullable();
            }
        });

        DB::table('shopee_tokens')
            ->whereNotNull('expire_at')
            ->whereNull('access_token_expire_at')
            ->update(['access_token_expire_at' => DB::raw('expire_at')]);

        DB::table('shopee_tokens')
            ->whereNotNull('refresh_token')
            ->whereNull('refresh_token_expire_at')
            ->whereNotNull('created_at')
            ->update(['refresh_token_expire_at' => DB::raw("created_at + INTERVAL '365 days'")]);

        DB::table('shopee_tokens')
            ->whereNotNull('refresh_token')
            ->whereNotNull('refresh_token_expire_at')
            ->whereNotNull('created_at')
            ->whereRaw("refresh_token_expire_at < created_at + INTERVAL '365 days'")
            ->update(['refresh_token_expire_at' => DB::raw("created_at + INTERVAL '365 days'")]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return;
        }

        Schema::table('shopee_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('shopee_tokens', 'access_token_expire_at')) {
                $table->dropColumn('access_token_expire_at');
            }

            if (Schema::hasColumn('shopee_tokens', 'refresh_token_expire_at')) {
                $table->dropColumn('refresh_token_expire_at');
            }
        });
    }
};
