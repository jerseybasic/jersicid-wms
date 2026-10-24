<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Order Pending yang stoknya kurang: ditandai "Menunggu stok" dan dikonfirmasi otomatis saat barang masuk
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('waiting_stock_at')->nullable()->after('ship_by');
            $table->index('waiting_stock_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['waiting_stock_at']);
            $table->dropColumn('waiting_stock_at');
        });
    }
};
