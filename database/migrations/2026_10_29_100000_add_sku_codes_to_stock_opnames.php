<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opname per produk: SKU yang dihitung, dipisah koma. Kosong = semua produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->text('sku_codes')->nullable()->after('rack_codes');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opnames', function (Blueprint $table) {
            $table->dropColumn('sku_codes');
        });
    }
};
