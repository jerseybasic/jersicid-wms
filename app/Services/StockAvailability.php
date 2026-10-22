<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockActive;

/**
 * Cek apakah stok cukup untuk sebuah order, dengan memperhitungkan order lain
 * yang sudah dikonfirmasi tapi barangnya belum selesai diambil.
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
        if ($needs->isEmpty()) {
            return [];
        }

        $onHand = StockActive::whereIn('sku', $needs->keys())->groupBy('sku')
            ->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku');

        $committed = OrderItem::whereIn('sku', $needs->keys())
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::COMMITTED_STATUSES)->whereKeyNot($order->id))
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty_ordered - qty_picked) as qty')
            ->pluck('qty', 'sku');

        $short = [];
        foreach ($needs as $sku => $need) {
            $available = max(0, (int) ($onHand[$sku] ?? 0) - (int) ($committed[$sku] ?? 0));
            if ($available < (int) $need) {
                $short[] = ['sku' => $sku, 'need' => (int) $need, 'available' => $available];
            }
        }

        return $short;
    }

    /** Kalimat singkat untuk pesan error, misalnya "JSC-A-L butuh 3, tersedia 1" */
    public static function describe(array $shortages): string
    {
        return collect($shortages)->map(fn ($s) => "{$s['sku']} butuh {$s['need']}, tersedia {$s['available']}")->implode('; ');
    }
}
