<?php

namespace App\Services;

use App\Models\MissingStockReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockActive;

/**
 * Cek apakah stok cukup untuk sebuah order, dengan memperhitungkan order lain
 * yang sudah dikonfirmasi tapi barangnya belum selesai diambil, dan stok yang ditahan
 * karena dilaporkan tidak ada di rak (menunggu persetujuan).
 */
class StockAvailability
{
    /** Status order yang barangnya sudah "dipesan" dari stok tapi belum semuanya diambil */
    private const COMMITTED_STATUSES = ['CONFIRMED', 'PICKING'];

    /**
     * SKU yang stoknya kurang: [['sku' => ..., 'need' => ..., 'available' => ...], ...]. Kosong berarti cukup.
     */
    public static function shortages(Order $order): array
    {
        $needs = $order->items()->groupBy('sku')->selectRaw('sku, SUM(qty_ordered) as need')->pluck('need', 'sku');

        return self::shortagesFor($needs->all(), $order->id);
    }

    /**
     * Sama seperti shortages, untuk kebutuhan [sku => qty] yang belum tersimpan di order
     * (misalnya sisa yang masih harus diambil saat item order diganti). Order exceptOrderId tidak
     * dihitung sebagai pemakai stok.
     */
    public static function shortagesFor(array $needs, ?string $exceptOrderId = null): array
    {
        $needs = collect($needs)->filter(fn ($qty) => (int) $qty > 0);
        if ($needs->isEmpty()) {
            return [];
        }

        $onHand = StockActive::whereIn('sku', $needs->keys())->groupBy('sku')
            ->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku');

        $committed = OrderItem::whereIn('sku', $needs->keys())
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::COMMITTED_STATUSES)->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId)))
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty_ordered - qty_picked) as qty')
            ->pluck('qty', 'sku');
        $held = MissingStockReport::heldQtyBySku($needs->keys());

        $short = [];
        foreach ($needs as $sku => $need) {
            $available = max(0, (int) ($onHand[$sku] ?? 0) - (int) ($held[$sku] ?? 0) - (int) ($committed[$sku] ?? 0));
            if ($available < (int) $need) {
                $short[] = ['sku' => $sku, 'need' => (int) $need, 'available' => $available];
            }
        }

        return $short;
    }

    /**
     * Penanda "stok siap" untuk daftar order: [order_id => true/false] untuk order Pending di daftar ini.
     * Tiap order dicek sendiri-sendiri terhadap stok yang belum dijatah (seperti shortages), jadi dua order
     * yang berebut stok terakhir sama-sama tampil siap; saat dikonfirmasi, yang paling mendesak yang dapat.
     */
    public static function readyMap($orders): array
    {
        $pending = collect($orders)->where('status', 'PENDING');
        if ($pending->isEmpty()) {
            return [];
        }

        $needs = OrderItem::whereIn('order_id', $pending->pluck('id'))
            ->groupBy('order_id', 'sku')->selectRaw('order_id, sku, SUM(qty_ordered) as need')->get()
            ->groupBy('order_id');
        $skus = $needs->flatten(1)->pluck('sku')->unique()->values();
        $onHand = StockActive::whereIn('sku', $skus)->groupBy('sku')->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku');
        $committed = OrderItem::whereIn('sku', $skus)
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::COMMITTED_STATUSES))
            ->groupBy('sku')->selectRaw('sku, SUM(qty_ordered - qty_picked) as qty')->pluck('qty', 'sku');
        $held = MissingStockReport::heldQtyBySku($skus);

        return $pending->mapWithKeys(fn ($order) => [$order->id => ($needs[$order->id] ?? collect())->isNotEmpty()
            && ($needs[$order->id])->every(fn ($row) => (int) ($onHand[$row->sku] ?? 0) - (int) ($held[$row->sku] ?? 0) - (int) ($committed[$row->sku] ?? 0) >= (int) $row->need)])
            ->all();
    }

    /** Kalimat singkat untuk pesan error, misalnya "JSC-A-L butuh 3, tersedia 1" */
    public static function describe(array $shortages): string
    {
        return collect($shortages)->map(fn ($s) => "{$s['sku']} butuh {$s['need']}, tersedia {$s['available']}")->implode('; ');
    }
}
