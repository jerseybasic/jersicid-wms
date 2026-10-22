<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;

/**
 * Logika picking yang dipakai sesi satu order maupun picking gabungan.
 * Semua method harus dipanggil di dalam transaksi DB.
 */
class Picking
{
    /**
     * Buat sesi picking untuk satu order CONFIRMED (order harus sudah dikunci), lalu order jadi PICKING.
     */
    public static function createForOrder(Order $order, string $userId, array $attributes = []): PickingSession
    {
        if ($order->status !== 'CONFIRMED' || $order->pickingSession()->exists()) {
            throw new \RuntimeException("Order {$order->order_number} harus berstatus CONFIRMED dan belum punya sesi picking.");
        }

        $session = PickingSession::create($attributes + [
            'order_id' => $order->id,
            'user_id' => $userId,
            'status' => 'PENDING',
        ]);

        foreach ($order->items as $item) {
            // Sarankan bin dengan stok terbanyak untuk SKU ini
            $stock = StockActive::where('sku', $item->sku)
                ->where('qty', '>', 0)
                ->orderByDesc('qty')
                ->first();

            PickingSessionItem::create([
                'picking_session_id' => $session->id,
                'order_item_id' => $item->id,
                'sku' => $item->sku,
                'bin_code' => $stock?->bin_code ?? 'UNKNOWN',
                'qty_ordered' => $item->qty_ordered,
                'qty_picked' => 0,
                'status' => 'PENDING',
            ]);
        }

        $order->update(['status' => 'PICKING']);

        return $session;
    }

    /**
     * Set total qty diambil untuk satu item. Stok hanya dikurangi sebesar
     * tambahannya. Sesi dan item harus sudah dikunci.
     */
    public static function apply(PickingSession $session, PickingSessionItem $item, int $qtyPicked, string $binCode, string $userId, ?string $ip): void
    {
        if ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException('Mulai sesi picking dulu sebelum mengambil barang.');
        }

        $newQty = min($qtyPicked, $item->qty_ordered);
        $delta = $newQty - $item->qty_picked;

        if ($delta < 0) {
            throw new \RuntimeException('Jumlah yang sudah diambil tidak bisa dikurangi. Hapus sesi picking untuk mengulang.');
        }

        // Satu item diambil dari satu bin, supaya stok bisa dikembalikan ke bin yang benar
        if ($item->qty_picked > 0 && $binCode !== $item->bin_code) {
            throw new \RuntimeException("{$item->sku} sudah mulai diambil dari bin {$item->bin_code}. Lanjutkan dari bin yang sama.");
        }

        if ($delta > 0) {
            $stock = StockActive::where('sku', $item->sku)
                ->where('bin_code', $binCode)
                ->lockForUpdate()
                ->first();

            if (!$stock || $stock->qty < $delta) {
                throw new \RuntimeException("Stok {$item->sku} di bin {$binCode} tidak cukup (tersedia " . ($stock?->qty ?? 0) . ').');
            }

            $oldQty = $stock->qty;
            $stock->update([
                'qty' => $oldQty - $delta,
                'last_movement_at' => now(),
            ]);

            StockMovement::create([
                'user_id' => $userId,
                'sku' => $item->sku,
                'bin_code' => $binCode,
                'type' => 'OUTBOUND',
                'qty' => $delta,
                'qty_before' => $oldQty,
                'qty_after' => $oldQty - $delta,
                'reference_no' => $session->session_number,
                'reference_type' => 'PICKING',
                'notes' => 'Picking ' . $session->session_number,
                'ip_address' => $ip,
            ]);
        }

        $item->update([
            'qty_picked' => $newQty,
            'bin_code' => $binCode,
            'status' => $newQty >= $item->qty_ordered ? 'COMPLETED' : 'PARTIAL',
        ]);

        $item->orderItem?->update([
            'qty_picked' => $newQty,
            'bin_code' => $binCode,
        ]);

        $allPicked = $session->items()->whereColumn('qty_picked', '<', 'qty_ordered')->doesntExist();

        if ($allPicked) {
            $session->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // Semua barang sudah diambil: order lanjut ke packing
            $session->order->update([
                'status' => 'PACKING',
                'picked_by' => $userId,
                'picked_at' => now(),
            ]);

            if ($session->batch_id) {
                self::refreshBatch($session->batch);
            }
        }
    }

    /**
     * Hapus sesi yang belum selesai: stok yang sudah diambil dikembalikan ke bin-nya
     * dan order kembali ke antrean picking (CONFIRMED).
     */
    public static function cancel(PickingSession $session, string $userId, ?string $ip): void
    {
        foreach ($session->items()->where('qty_picked', '>', 0)->get() as $item) {
            $stock = StockActive::firstOrCreate(
                ['sku' => $item->sku, 'bin_code' => $item->bin_code],
                ['qty' => 0, 'reserved_qty' => 0]
            );
            $oldQty = $stock->qty;
            $stock->update(['qty' => $oldQty + $item->qty_picked, 'last_movement_at' => now()]);

            StockMovement::create([
                'user_id' => $userId,
                'sku' => $item->sku,
                'bin_code' => $item->bin_code,
                'type' => 'RETURN',
                'qty' => $item->qty_picked,
                'qty_before' => $oldQty,
                'qty_after' => $oldQty + $item->qty_picked,
                'reference_no' => $session->session_number,
                'reference_type' => 'PICKING_CANCEL',
                'notes' => 'Sesi picking dihapus, stok dikembalikan',
                'ip_address' => $ip,
            ]);

            $item->orderItem?->update(['qty_picked' => 0]);
        }

        $session->order?->update(['status' => 'CONFIRMED']);
        $session->items()->delete();
        $batch = $session->batch;
        $session->delete();

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'DELETE',
            'module' => 'PICKING',
            'new_values' => json_encode(['session_number' => $session->session_number]),
            'ip_address' => $ip,
        ]);

        if ($batch) {
            self::refreshBatch($batch);
        }
    }

    /**
     * Picking gabungan selesai jika semua pesanannya selesai; dihapus jika tidak ada pesanan tersisa.
     */
    public static function refreshBatch(PickingBatch $batch): void
    {
        $sessions = $batch->sessions()->get(['id', 'status']);

        if ($sessions->isEmpty()) {
            $batch->delete();
        } elseif ($sessions->every(fn ($s) => $s->status === 'COMPLETED') && $batch->status !== 'COMPLETED') {
            $batch->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        }
    }
}
