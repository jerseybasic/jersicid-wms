<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Harga modal (HPP) per SKU, terpisah dari harga jual.
     */
    public function up(): void
    {
        Schema::table('master_skus', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_price')->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('master_skus', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
