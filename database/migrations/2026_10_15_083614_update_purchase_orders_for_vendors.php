<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah supplier_id jika belum ada (untuk jaga-jaga)
        if (!Schema::hasColumn('purchase_orders', 'supplier_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->string('supplier_id')->nullable();
            });
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            // 2. Tambah vendor_id (tanpa after, biar aman)
            if (!Schema::hasColumn('purchase_orders', 'vendor_id')) {
                $table->uuid('vendor_id')->nullable();
                $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
            }

            // 3. Tambah kolom tracking defect & pickup (TANPA after)
            if (!Schema::hasColumn('purchase_orders', 'defect_count')) {
                $table->integer('defect_count')->default(0);
            }

            if (!Schema::hasColumn('purchase_orders', 'defect_notes')) {
                $table->text('defect_notes')->nullable();
            }

            if (!Schema::hasColumn('purchase_orders', 'ready_at')) {
                $table->timestamp('ready_at')->nullable();
            }
        });

        // 4. Update status enum (Schema builder agar jalan di MySQL & SQLite)
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'RECEIVED', 'CANCELLED'])->default('DRAFT')->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'vendor_id')) {
                $table->dropForeign(['vendor_id']);
                $table->dropColumn('vendor_id');
            }

            if (Schema::hasColumn('purchase_orders', 'defect_count')) {
                $table->dropColumn('defect_count');
            }

            if (Schema::hasColumn('purchase_orders', 'defect_notes')) {
                $table->dropColumn('defect_notes');
            }

            if (Schema::hasColumn('purchase_orders', 'ready_at')) {
                $table->dropColumn('ready_at');
            }

            if (Schema::hasColumn('purchase_orders', 'supplier_id')) {
                // Hanya drop jika kita yang tambah, tapi susah track. 
                // Biasanya supplier_id sudah ada di sistem PO. 
                // Kita skip drop supplier_id di down() untuk keamanan data.
            }
        });
    }
};
