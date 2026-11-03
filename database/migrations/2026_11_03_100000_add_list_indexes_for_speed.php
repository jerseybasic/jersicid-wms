<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk daftar yang paling sering dibuka, supaya database tidak perlu
 * membaca dan mengurutkan seluruh tabel setiap kali halaman dimuat:
 * - Outbound: urut terbaru, dengan/tanpa filter status
 * - Inbound & laporan pergerakan: filter jenis + rentang tanggal
 * - Peta rak / cek bin: cari stok per kode bin
 * - Laporan kinerja: hitung aksi per rentang tanggal
 */
return new class extends Migration
{
    private const INDEXES = [
        'orders' => [['created_at'], ['status', 'created_at']],
        'stock_movements' => [['type', 'created_at']],
        'stock_actives' => [['bin_code']],
        'audit_trails' => [['action', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (!Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
                }
            }
        }
    }
};
