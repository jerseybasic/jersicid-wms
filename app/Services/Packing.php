<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;

/**
 * Logika packing yang dipakai layar packing dan QR perintah "Mulai Packing".
 * Harus dipanggil di dalam transaksi DB.
 */
class Packing
{
    /**
     * Buat sesi packing untuk order yang picking-nya selesai (status PACKING, order sudah dikunci).
     * Sesi langsung berjalan: yang membuat sesi adalah yang mengemas.
     */
    public static function createForOrder(Order $order, string $userId, array $attributes = []): PackingSession
    {
        $order->loadMissing('pickingSession.items');
        if ($order->status !== 'PACKING' || $order->packingSession()->exists()) {
            throw new \RuntimeException('Order harus sudah selesai picking dan belum punya sesi packing.');
        }

        $session = PackingSession::create($attributes + [
            'order_id' => $order->id,
            'picking_session_id' => $order->pickingSession?->id,
            'user_id' => $userId,
            'status' => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        // Satu baris per item order (satu item bisa diambil dari beberapa rak saat picking)
        foreach (($order->pickingSession?->items ?? collect())->groupBy('order_item_id') as $pickItems) {
            PackingSessionItem::create([
                'packing_session_id' => $session->id,
                'order_item_id' => $pickItems->first()->order_item_id,
                'sku' => $pickItems->first()->sku,
                'qty_ordered' => $pickItems->sum('qty_ordered'),
                'qty_packed' => 0,
                'status' => 'PENDING',
            ]);
        }

        return $session;
    }
}
