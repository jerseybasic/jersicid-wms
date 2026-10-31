<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\CourierHandoverItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockActive;
use App\Models\StockMovement;

/**
 * Pembatalan order yang sudah mulai diproses. Barang yang sudah keluar dari rak tidak langsung
 * dikembalikan ke stok: barangnya masih di keranjang atau paket, jadi masuk daftar
 * "Kembalikan ke rak" dan stok bertambah saat staf menaruhnya kembali (lihat putBack).
 * Semua method harus dipanggil di dalam transaksi DB.
 */
class OrderCancellation
{
    /** Role yang boleh membatalkan order yang sedang diproses */
    public const ROLES = ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'];

    /** Batalkan order PICKING, PACKING, atau READY_TO_SHIP (order sudah dikunci) */
    public static function cancel(Order $order, ?string $reason, string $userId, ?string $ip): void
    {
        if (!in_array($order->status, Order::IN_PROCESS_STATUSES, true)) {
            throw new \RuntimeException("Order {$order->order_number} ({$order->status_label}) tidak bisa dibatalkan dari sini.");
        }

        $oldStatus = $order->status;

        // Sesi yang masih berjalan dihentikan; sesi yang sudah selesai tetap jadi riwayat
        $picking = $order->pickingSession()->lockForUpdate()->first();
        if ($picking && $picking->status !== 'COMPLETED') {
            $picking->update(['status' => 'CANCELLED']);
            if ($picking->batch) {
                Picking::refreshBatch($picking->batch);
            }
        }
        $packing = $order->packingSession()->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->first();
        if ($packing) {
            $packing->update(['status' => 'CANCELLED']);
            Packing::refreshBatch($packing->batch);
        }

        // Paket yang sudah di-scan untuk kurir tapi belum diserahkan dikeluarkan dari daftarnya
        CourierHandoverItem::where('order_id', $order->id)
            ->whereHas('handover', fn ($q) => $q->where('status', 'OPEN'))
            ->delete();

        $order->update(['status' => 'CANCELLED']);

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UPDATE_STATUS',
            'module' => 'OUTBOUND',
            'old_values' => json_encode(['status' => $oldStatus]),
            'new_values' => json_encode(array_filter([
                'status' => 'CANCELLED',
                'order_number' => $order->order_number,
                'reason' => $reason,
                'to_return' => (int) $order->items()->sum('qty_picked'),
            ])),
            'ip_address' => $ip,
        ]);
    }

    /**
     * Aktifkan lagi order yang dibatalkan (misalnya batal karena salah klik atau uji coba).
     * Sesi picking/packing lamanya dihapus dan order kembali Pending seperti order baru.
     * Order sudah dikunci.
     */
    public static function reactivate(Order $order, string $userId, ?string $ip): void
    {
        if ($order->status !== 'CANCELLED') {
            throw new \RuntimeException("Order {$order->order_number} ({$order->status_label}) tidak sedang dibatalkan.");
        }
        $toReturn = (int) $order->items()->sum('qty_picked');
        if ($toReturn > 0) {
            throw new \RuntimeException("Order {$order->order_number}: {$toReturn} pcs barangnya belum dikembalikan ke rak. Kembalikan dulu lewat Kembalikan ke Rak, lalu aktifkan lagi.");
        }

        // Sesi lama yang sudah dibatalkan dihapus supaya order bisa diambil dan dikemas dari awal
        if ($packing = $order->packingSession) {
            $packingBatch = $packing->batch;
            $packing->items()->delete();
            $packing->delete();
            Packing::refreshBatch($packingBatch);
        }
        if ($picking = $order->pickingSession) {
            $batch = $picking->batch;
            $picking->items()->delete();
            $picking->delete();
            if ($batch) {
                Picking::refreshBatch($batch);
            }
        }
        $order->items()->update(['qty_picked' => 0, 'qty_packed' => 0, 'bin_code' => null]);
        // Kembali seperti order baru: Pending, lalu dikonfirmasi seperti biasa (stok dicek saat konfirmasi)
        $order->update(['status' => 'PENDING', 'waiting_stock_at' => null, 'picked_at' => null, 'packed_at' => null, 'picked_by' => null, 'packed_by' => null, 'shipped_at' => null]);

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'REACTIVATE',
            'module' => 'OUTBOUND',
            'old_values' => json_encode(['status' => 'CANCELLED']),
            'new_values' => json_encode(['status' => 'PENDING', 'order_number' => $order->order_number]),
            'ip_address' => $ip,
        ]);
    }

    /** Barang order batal yang belum dikembalikan ke rak */
    public static function pendingItems()
    {
        return OrderItem::where('qty_picked', '>', 0)
            ->whereHas('order', fn ($q) => $q->where('status', 'CANCELLED'));
    }

    /**
     * Taruh kembali barang order batal ke rak: stok bertambah di bin itu dan sisa yang harus
     * dikembalikan berkurang. Item order harus sudah dikunci.
     */
    public static function putBack(OrderItem $item, string $bin, int $qty, string $userId, ?string $ip): void
    {
        $bin = strtoupper(trim($bin));
        if ($qty < 1 || $qty > $item->qty_picked) {
            throw new \RuntimeException("{$item->sku}: sisa yang perlu dikembalikan {$item->qty_picked} pcs.");
        }
        if ($bin === '') {
            throw new \RuntimeException('Pilih rak tujuan dulu.');
        }

        $order = $item->order;
        self::returnToBin($item->sku, $bin, $qty, $order?->order_number, 'ORDER_CANCEL', "Order batal {$order?->order_number}, barang dikembalikan ke rak", $userId, $ip);

        $left = $item->qty_picked - $qty;
        $item->update(['qty_picked' => $left, 'qty_packed' => min((int) $item->qty_packed, $left)]);
    }

    /** Tambah stok qty pcs SKU di bin ini dan catat pergerakannya (barang ditaruh kembali ke rak) */
    public static function returnToBin(string $sku, string $bin, int $qty, ?string $referenceNo, string $referenceType, string $notes, string $userId, ?string $ip): void
    {
        $stock = StockActive::where('sku', $sku)->where('bin_code', $bin)->lockForUpdate()->first()
            ?? StockActive::create(['sku' => $sku, 'bin_code' => $bin, 'qty' => 0, 'reserved_qty' => 0]);
        $before = $stock->qty;
        $stock->update(['qty' => $before + $qty, 'last_movement_at' => now()]);

        StockMovement::create([
            'user_id' => $userId,
            'sku' => $sku,
            'bin_code' => $bin,
            'type' => 'RETURN',
            'qty' => $qty,
            'qty_before' => $before,
            'qty_after' => $before + $qty,
            'reference_no' => $referenceNo,
            'reference_type' => $referenceType,
            'notes' => $notes,
            'ip_address' => $ip,
        ]);
    }
}
