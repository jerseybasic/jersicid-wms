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
            'status' => 'PENDING',
        ]);

        // Ambil items dari picking session
        foreach ($order->pickingSession?->items ?? [] as $pickItem) {
            PackingSessionItem::create([
                'packing_session_id' => $session->id,
                'order_item_id' => $pickItem->order_item_id,
                'sku' => $pickItem->sku,
                'qty_ordered' => $pickItem->qty_ordered,
                'qty_packed' => 0,
                'status' => 'PENDING',
            ]);
        }

        return $session;
    }
}
