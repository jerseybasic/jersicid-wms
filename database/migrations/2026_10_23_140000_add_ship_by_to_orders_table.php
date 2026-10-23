<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Batas kirim per order: dari marketplace bila ada, atau dihitung dari jam potong di Pengaturan
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ship_by')->nullable()->after('shipped_at');
            $table->index('ship_by');
        });

        // Order lama yang belum dikirim: batasnya akhir hari order dibuat
        DB::table('orders')->whereNull('ship_by')
            ->whereIn('status', ['PENDING', 'CONFIRMED', 'PICKING', 'PACKING', 'READY_TO_SHIP'])
            ->orderBy('id')
            ->each(function ($order) {
                DB::table('orders')->where('id', $order->id)->update([
                    'ship_by' => Carbon::parse($order->created_at)->endOfDay(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['ship_by']);
            $table->dropColumn('ship_by');
        });
    }
};
