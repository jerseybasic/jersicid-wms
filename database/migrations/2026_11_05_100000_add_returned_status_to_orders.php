<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status order "Diretur" (RETURNED): order Dikirim/Selesai yang paket returnya sudah diterima gudang.
 * Order lama yang sudah punya retur sejak pengiriman terakhirnya langsung ditandai Diretur.
 */
return new class extends Migration
{
    private const BASE = ['PENDING', 'CONFIRMED', 'PICKING', 'PACKING', 'READY_TO_SHIP', 'SHIPPED', 'COMPLETED'];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [...self::BASE, 'RETURNED', 'CANCELLED'])->default('PENDING')->change();
        });

        $returned = DB::table('order_returns as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->whereIn('o.status', ['SHIPPED', 'COMPLETED'])
            ->where(fn ($q) => $q->whereNull('o.shipped_at')->orWhereColumn('r.created_at', '>=', 'o.shipped_at'))
            ->distinct()
            ->pluck('o.id');

        DB::table('orders')->whereIn('id', $returned)->update(['status' => 'RETURNED']);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'RETURNED')->update(['status' => 'COMPLETED']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [...self::BASE, 'CANCELLED'])->default('PENDING')->change();
        });
    }
};
