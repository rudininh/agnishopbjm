<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_shipping_label_prints', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace', 30);
            $table->string('order_ref', 80);
            $table->string('document_type', 80)->default('shipping_label');
            $table->string('source', 40)->default('manual');
            $table->timestamp('printed_at')->useCurrent();
            $table->timestamps();

            $table->index(['marketplace', 'order_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_shipping_label_prints');
    }
};
