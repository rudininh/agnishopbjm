<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_master_id');
            $table->integer('delta');
            $table->unsignedInteger('before_quantity');
            $table->unsignedInteger('after_quantity');
            $table->string('reason', 30);
            $table->text('note')->nullable();
            $table->string('operator')->nullable();
            $table->timestamps();

            $table->index(['stock_master_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
