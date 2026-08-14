<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_mass_upload_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 100);
            $table->string('expected_shop_name', 150);
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('worker_last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['account_key', 'status', 'requested_at']);
        });

        Schema::create('shopee_mass_upload_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('shopee_mass_upload_jobs')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('file_type', 32);
            $table->string('filename', 255);
            $table->string('storage_path', 500);
            $table->unsignedInteger('row_count');
            $table->string('sha256', 64);
            $table->string('status', 32);
            $table->string('shopee_status', 64)->nullable();
            $table->unsignedInteger('shopee_processed_count')->nullable();
            $table->timestamp('created_at_worker')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'file_type']);
            $table->unique(['job_id', 'sequence']);
            $table->index(['job_id', 'sequence']);
        });

        Schema::create('shopee_mass_upload_runtimes', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 100)->unique();
            $table->unsignedBigInteger('active_job_id')->nullable()->index();
            $table->timestamp('worker_last_seen_at')->nullable();
            $table->string('worker_name', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_mass_upload_runtimes');
        Schema::dropIfExists('shopee_mass_upload_files');
        Schema::dropIfExists('shopee_mass_upload_jobs');
    }
};
