<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur barang dari pelanggan untuk order yang sudah dikirim.
 * Barang bagus masuk lagi ke rak (stock_movements type RETURN), barang rusak hanya dicatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('return_number')->unique();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('reason', 30);
            $table->text('notes')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('order_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('sku');
            $table->unsignedInteger('qty_good')->default(0);
            $table->unsignedInteger('qty_damaged')->default(0);
            $table->string('bin_code')->nullable();
            $table->timestamps();

            $table->foreign('sku')->references('sku')->on('master_skus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
