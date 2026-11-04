<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah yang dipesan per baris penerimaan (Inbound) yang tidak lewat PO. Kalau barang yang datang
 * masih kurang dari yang dipesan, penerimaannya berstatus Belum Komplit sampai sisanya datang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedInteger('qty_expected')->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('qty_expected');
        });
    }
};
