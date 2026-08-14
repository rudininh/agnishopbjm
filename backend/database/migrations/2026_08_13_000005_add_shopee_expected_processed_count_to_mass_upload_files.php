<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_mass_upload_files', function (Blueprint $table): void {
            $table->unsignedInteger('shopee_expected_processed_count')->nullable()->after('row_count');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_mass_upload_files', function (Blueprint $table): void {
            $table->dropColumn('shopee_expected_processed_count');
        });
    }
};
