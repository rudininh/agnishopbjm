<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->timestamp('source_refreshed_at')->nullable()->after('requested_at');
            $table->unsignedInteger('expected_product_count')->nullable()->after('source_refreshed_at');
            $table->unsignedInteger('expected_variant_count')->nullable()->after('expected_product_count');
            $table->unsignedInteger('matched_variant_count')->nullable()->after('expected_variant_count');
            $table->unsignedInteger('mismatched_variant_count')->nullable()->after('matched_variant_count');
        });

        Schema::create('shopee_mass_upload_reconciliation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('shopee_mass_upload_jobs')->cascadeOnDelete();
            $table->string('target_item_id', 100);
            $table->string('target_model_id', 100);
            $table->string('status', 32);
            $table->json('mismatch_fields')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'target_item_id', 'target_model_id']);
            $table->index(['job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_mass_upload_reconciliation_results');
        Schema::table('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->dropColumn(['source_refreshed_at', 'expected_product_count', 'expected_variant_count', 'matched_variant_count', 'mismatched_variant_count']);
        });
    }
};
