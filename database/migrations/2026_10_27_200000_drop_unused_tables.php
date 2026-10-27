<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus tabel sisa rancangan awal yang tidak pernah dipakai aplikasi. Tabel hanya dihapus jika kosong;
 * tabel yang ternyata berisi data dibiarkan dan dicatat di log, supaya tidak ada data yang hilang.
 */
return new class extends Migration
{
    /** Urutan penting: tabel anak sebelum induknya */
    private const TABLES = [
        'inbound_receipt_items',
        'inbound_receipts',
        'picking_lists',
        'stock_requests',
        'transactions',
        'qc_logs',
        'approvals',
        'returns',
        'audit_logs',
        'user_activity_logs',
        'barcode_scans',
        'rack_product_mappings',
        'warehouse_locations',
        'notifications',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->dropIfEmpty($table);
        }

        // vendors digantikan suppliers; kolom purchase_orders.vendor_id tidak pernah diisi aplikasi
        if (Schema::hasColumn('purchase_orders', 'vendor_id') && DB::table('purchase_orders')->whereNotNull('vendor_id')->doesntExist()) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['vendor_id']);
                $table->dropColumn('vendor_id');
            });
        }
        if (!Schema::hasColumn('purchase_orders', 'vendor_id')) {
            $this->dropIfEmpty('vendors');
        }
    }

    /** Tidak dibuat ulang: tabel-tabel ini tidak dipakai kode mana pun */
    public function down(): void
    {
    }

    private function dropIfEmpty(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (DB::table($table)->exists()) {
            Log::warning("Tabel {$table} tidak dihapus karena masih berisi data.");

            return;
        }

        Schema::drop($table);
    }
};
