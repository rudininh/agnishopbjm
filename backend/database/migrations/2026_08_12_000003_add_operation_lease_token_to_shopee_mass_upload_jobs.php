<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->string('operation_lease_token', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->dropColumn('operation_lease_token');
        });
    }
};
