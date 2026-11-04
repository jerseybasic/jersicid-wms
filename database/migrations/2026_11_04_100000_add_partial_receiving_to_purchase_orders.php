<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penerimaan PO bertahap: setiap kedatangan barang dicatat sebagai satu baris penerimaan
 * (tanggal, qty, penerima, surat jalan), dan PO punya status "Diterima Sebagian" selama sisanya belum datang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED'])
                ->default('DRAFT')->change();
        });

        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->string('sku');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('qty_rejected')->default(0);
            $table->string('receipt_number'); // No. surat jalan, sama dengan reference_no di stock_movements
            $table->date('received_date');
            $table->string('bin_code')->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'received_date']);
            $table->index('receipt_number');
        });

        // PO lama yang sudah menerima sebagian barang langsung ditandai Diterima Sebagian
        $partial = DB::table('purchase_order_items')
            ->where('qty_received', '>', 0)
            ->distinct()
            ->pluck('purchase_order_id');

        DB::table('purchase_orders')
            ->whereIn('id', $partial)
            ->whereIn('status', ['APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP'])
            ->update(['status' => 'PARTIALLY_RECEIVED']);
    }

    public function down(): void
    {
        DB::table('purchase_orders')->where('status', 'PARTIALLY_RECEIVED')->update(['status' => 'APPROVED']);

        Schema::dropIfExists('purchase_order_receipts');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'RECEIVED', 'CANCELLED'])
                ->default('DRAFT')->change();
        });
    }
};
