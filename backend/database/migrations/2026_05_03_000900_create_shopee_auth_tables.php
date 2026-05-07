<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopee_config')) {
            Schema::create('shopee_config', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('partner_id');
                $table->text('partner_key');
                $table->string('host')->default('https://partner.shopeemobile.com');
                $table->string('redirect_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        } else {
            $this->addConfigColumnsIfMissing();
        }

        if (! Schema::hasTable('shopee_callbacks')) {
            Schema::create('shopee_callbacks', function (Blueprint $table) {
                $table->id();
                $table->string('code');
                $table->bigInteger('shop_id')->nullable();
                $table->bigInteger('main_account_id')->nullable();
                $table->bigInteger('partner_id')->nullable();
                $table->json('query_payload')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shopee_tokens')) {
            Schema::create('shopee_tokens', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('partner_id')->nullable();
                $table->bigInteger('shop_id')->nullable();
                $table->bigInteger('merchant_id')->nullable();
                $table->bigInteger('supplier_id')->nullable();
                $table->bigInteger('user_id')->nullable();
                $table->json('shop_id_list')->nullable();
                $table->json('merchant_id_list')->nullable();
                $table->json('supplier_id_list')->nullable();
                $table->json('user_id_list')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->integer('expire_in')->nullable();
                $table->timestamp('expire_at')->nullable();
                $table->timestamp('access_token_expire_at')->nullable();
                $table->timestamp('refresh_token_expire_at')->nullable();
                $table->string('request_id')->nullable();
                $table->string('error')->nullable();
                $table->text('message')->nullable();
                $table->json('raw_response')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        } else {
            $this->addTokenColumnsIfMissing();
        }

        DB::table('shopee_config')->updateOrInsert(
            ['partner_id' => (int) config('shopee.partner_id')],
            [
                'partner_key' => config('shopee.partner_key'),
                'host' => config('shopee.host'),
                'redirect_url' => config('shopee.redirect_url'),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::statement('UPDATE shopee_config SET is_active = true WHERE partner_id = ?', [
            (int) config('shopee.partner_id'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_tokens');
        Schema::dropIfExists('shopee_callbacks');
        Schema::dropIfExists('shopee_config');
    }

    private function addTokenColumnsIfMissing(): void
    {
        Schema::table('shopee_tokens', function (Blueprint $table) {
            $columns = [
                'partner_id' => fn () => $table->bigInteger('partner_id')->nullable(),
                'merchant_id' => fn () => $table->bigInteger('merchant_id')->nullable(),
                'supplier_id' => fn () => $table->bigInteger('supplier_id')->nullable(),
                'user_id' => fn () => $table->bigInteger('user_id')->nullable(),
                'shop_id_list' => fn () => $table->json('shop_id_list')->nullable(),
                'merchant_id_list' => fn () => $table->json('merchant_id_list')->nullable(),
                'supplier_id_list' => fn () => $table->json('supplier_id_list')->nullable(),
                'user_id_list' => fn () => $table->json('user_id_list')->nullable(),
                'expire_at' => fn () => $table->timestamp('expire_at')->nullable(),
                'access_token_expire_at' => fn () => $table->timestamp('access_token_expire_at')->nullable(),
                'refresh_token_expire_at' => fn () => $table->timestamp('refresh_token_expire_at')->nullable(),
                'error' => fn () => $table->string('error')->nullable(),
                'message' => fn () => $table->text('message')->nullable(),
                'raw_response' => fn () => $table->json('raw_response')->nullable(),
                'is_active' => fn () => $table->boolean('is_active')->default(true),
                'created_at' => fn () => $table->timestamp('created_at')->nullable(),
                'updated_at' => fn () => $table->timestamp('updated_at')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('shopee_tokens', $column)) {
                    $definition();
                }
            }
        });
    }

    private function addConfigColumnsIfMissing(): void
    {
        Schema::table('shopee_config', function (Blueprint $table) {
            $columns = [
                'host' => fn () => $table->string('host')->default('https://partner.shopeemobile.com'),
                'redirect_url' => fn () => $table->string('redirect_url')->nullable(),
                'is_active' => fn () => $table->boolean('is_active')->default(true),
                'created_at' => fn () => $table->timestamp('created_at')->nullable(),
                'updated_at' => fn () => $table->timestamp('updated_at')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('shopee_config', $column)) {
                    $definition();
                }
            }
        });
    }
};
