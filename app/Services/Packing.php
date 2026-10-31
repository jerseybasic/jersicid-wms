<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\PackingBatch;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;
use App\Models\PickingBatch;

/**
 * Logika packing yang dipakai layar packing, packing gabungan, dan QR perintah "Mulai Packing".
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

    /**
     * Set total qty dikemas untuk satu item; paket yang lengkap membuat order Siap Kirim.
     * Sesi dan item harus sudah dikunci.
     */
    public static function apply(PackingSession $session, PackingSessionItem $item, int $qtyPacked, string $userId): void
    {
        if ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException($session->status === 'CANCELLED' ? Picking::CANCELLED_MESSAGE : 'Mulai sesi packing dulu sebelum mengemas barang.');
        }
        // Setelah lapor masalah, order kembali ke picking sampai barang penggantinya diambil
        if ($qtyPacked > $item->qty_packed && $session->order?->status === 'PICKING') {
            throw new \RuntimeException('Tunggu barang pengganti diambil dulu, lalu lanjutkan packing.');
        }

        $qtyPacked = min($qtyPacked, $item->qty_ordered);

        $item->update([
            'qty_packed' => $qtyPacked,
            'status' => $qtyPacked >= $item->qty_ordered ? 'COMPLETED' : ($qtyPacked > 0 ? 'PARTIAL' : 'PENDING'),
        ]);

        $item->orderItem?->update(['qty_packed' => $qtyPacked]);

        $allPacked = $session->items()->whereColumn('qty_packed', '<', 'qty_ordered')->doesntExist();

        if ($allPacked) {
            $session->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // Semua barang sudah dikemas: order siap diserahkan ke kurir
            $session->order->update([
                'status' => 'READY_TO_SHIP',
                'packed_by' => $userId,
                'packed_at' => now(),
                'courier' => $session->order->courier ?? $session->courier,
            ]);
        }

        if ($session->packing_batch_id) {
            Packing::refreshBatch($session->batch);
        }
    }

    /** Order yang selesai picking dan belum dikemas, batas kirim paling dekat dulu */
    public static function waitingOrders()
    {
        return Order::where('status', 'PACKING')
            ->whereDoesntHave('packingSession')
            ->orderBy('ship_by')
            ->orderBy('picked_at')
            ->orderBy('created_at');
    }

    /**
     * Order untuk "Kemas sekaligus": keranjang dari picking gabungan yang sama dengan order paling mendesak,
     * supaya nomor keranjangnya tetap sama. Order yang dipicking satu per satu digabung sampai AUTO_ORDERS.
     */
    public static function autoOrders()
    {
        $orders = self::waitingOrders()->with('pickingSession:id,order_id,batch_id,basket_no')->get();
        $batchId = $orders->first()?->pickingSession?->batch_id;

        return $batchId
            ? $orders->filter(fn ($order) => $order->pickingSession?->batch_id === $batchId)->take(PackingBatch::MAX_ORDERS)->values()
            : $orders->filter(fn ($order) => !$order->pickingSession?->batch_id)->take(PickingBatch::AUTO_ORDERS)->values();
    }

    /**
     * Buat packing gabungan yang langsung berjalan untuk order-order PACKING ini (sudah dikunci).
     * Bila semua order berasal dari satu picking gabungan, nomor keranjangnya dipakai lagi;
     * bila tidak, keranjang dinomori ulang mengikuti urutan order.
     */
    public static function createBatch($orders, string $userId, ?string $ip): PackingBatch
    {
        $orders->loadMissing('pickingSession.items');
        $pickingBatches = $orders->map(fn ($order) => $order->pickingSession?->batch_id)->unique();
        $samePicking = $pickingBatches->count() === 1 && $pickingBatches->first() !== null;
        $orders = $samePicking
            ? $orders->sortBy(fn ($order) => $order->pickingSession->basket_no)->values()
            : $orders->values();

        $batch = PackingBatch::create(['user_id' => $userId, 'status' => 'IN_PROGRESS', 'started_at' => now()]);
        foreach ($orders as $i => $order) {
            self::createForOrder($order, $userId, [
                'packing_batch_id' => $batch->id,
                'basket_no' => $samePicking ? $order->pickingSession->basket_no : $i + 1,
                'courier' => $order->courier,
            ]);
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'CREATE',
            'module' => 'PACKING',
            'new_values' => json_encode(['batch_number' => $batch->batch_number, 'orders' => $orders->pluck('order_number')]),
            'ip_address' => $ip,
        ]);

        return $batch;
    }

    /**
     * Packing gabungan selesai jika semua paketnya selesai atau dibatalkan; dihapus jika tidak ada paket tersisa.
     */
    public static function refreshBatch(?PackingBatch $batch): void
    {
        if (!$batch) {
            return;
        }
        $sessions = $batch->sessions()->get(['id', 'status']);

        if ($sessions->isEmpty()) {
            $batch->delete();
        } elseif ($sessions->every(fn ($s) => in_array($s->status, ['COMPLETED', 'CANCELLED'], true))) {
            if ($batch->status !== 'COMPLETED') {
                $batch->update(['status' => 'COMPLETED', 'completed_at' => now(), 'active_session_id' => null]);
            }
        } elseif ($batch->status === 'COMPLETED') {
            $batch->update(['status' => 'IN_PROGRESS', 'completed_at' => null]);
        }
    }
}
