<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->string('worker_claim_token', 80)->nullable();
            $table->string('worker_claim_name', 150)->nullable();
            $table->timestamp('worker_claimed_until_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->dropColumn(['worker_claim_token', 'worker_claim_name', 'worker_claimed_until_at']);
        });
    }
};
