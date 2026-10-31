<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barang yang sudah diambil untuk order tapi tidak dipakai lagi karena item order-nya diganti
 * (misalnya pembeli ganti produk). Barangnya masih di keranjang atau paket, jadi masuk daftar
 * "Kembalikan ke Rak" dan stok baru bertambah saat staf menaruhnya kembali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('bin_code', 50)->nullable();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('qty_initial');
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sku', 'qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_items');
    }
};
