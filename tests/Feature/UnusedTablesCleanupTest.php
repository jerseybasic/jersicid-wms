<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tabel sisa rancangan awal dihapus, kecuali yang ternyata masih berisi data.
 */
class UnusedTablesCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_tables_are_dropped(): void
    {
        foreach (['vendors', 'inbound_receipts', 'picking_lists', 'barcode_scans', 'warehouse_locations', 'returns', 'audit_logs'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} masih ada");
        }
        $this->assertFalse(Schema::hasColumn('purchase_orders', 'vendor_id'));

        // Tabel yang dipakai tetap ada
        foreach (['order_returns', 'audit_trails', 'rack_products', 'suppliers'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} hilang");
        }
    }

    public function test_table_with_data_is_kept(): void
    {
        Schema::create('qc_logs', function (Blueprint $table) {
            $table->id();
            $table->string('note');
        });
        DB::table('qc_logs')->insert(['note' => 'data lama']);

        (require database_path('migrations/2026_10_27_200000_drop_unused_tables.php'))->up();

        $this->assertTrue(Schema::hasTable('qc_logs'));
        $this->assertSame(1, DB::table('qc_logs')->count());
    }
}
