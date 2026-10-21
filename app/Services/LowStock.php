<?php

namespace App\Services;

use App\Models\MasterSku;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Satu aturan "stok menipis" untuk dashboard, Tugas Hari Ini, dan PO dari stok menipis.
 *
 * - SKU dengan min_stock > 0 memakai min_stock miliknya.
 * - SKU dengan min_stock 0 memakai batas umum di Pengaturan (low_stock_threshold),
 *   tetapi hanya jika SKU itu pernah punya stok di gudang. SKU yang belum pernah
 *   diproduksi tidak ikut dihitung supaya daftar tidak penuh desain lama.
 */
class LowStock
{
    public static function defaultThreshold(): int
    {
        return max(0, (int) SystemSetting::get('low_stock_threshold', 0));
    }

    /**
     * SKU aktif + total stok (current_stock) + batasnya (limit_qty) yang stoknya di bawah/sama dengan batas.
     */
    public static function query(): Builder
    {
        $default = self::defaultThreshold();

        $stockPerSku = DB::table('stock_actives')
            ->select('sku', DB::raw('SUM(qty) as total_qty'))
            ->groupBy('sku');

        $limit = 'CASE WHEN master_skus.min_stock > 0 THEN master_skus.min_stock'
            . ' WHEN stock_sum.sku IS NOT NULL THEN ' . $default . ' ELSE 0 END';

        return MasterSku::select(
            'master_skus.*',
            DB::raw('COALESCE(stock_sum.total_qty, 0) as current_stock'),
            DB::raw($limit . ' as limit_qty')
        )
            ->leftJoinSub($stockPerSku, 'stock_sum', 'stock_sum.sku', '=', 'master_skus.sku')
            ->where('master_skus.status', 'AKTIF')
            ->whereRaw($limit . ' > 0')
            ->whereRaw('COALESCE(stock_sum.total_qty, 0) <= ' . $limit);
    }

    public static function count(): int
    {
        return self::query()->count();
    }

    /**
     * Saran jumlah pesan ulang: isi sampai dua kali batas, minimal 1.
     */
    public static function suggestedQty(int $currentStock, int $limit): int
    {
        return max(1, $limit * 2 - $currentStock);
    }
}
