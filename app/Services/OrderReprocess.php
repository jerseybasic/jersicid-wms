<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderReturnItem;

/**
 * Proses ulang order yang sudah Dikirim/Selesai dengan resi yang sama, misalnya karena statusnya
 * berubah tanpa scan serah terima, atau paketnya kembali (retur) dan perlu dikirim ulang.
 * Harus dipanggil di dalam transaksi DB.
 */
class OrderReprocess
{
    /** Role yang boleh memproses ulang order */
    public const ROLES = ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'];

    /**
     * Ke mana order akan dikembalikan: READY_TO_SHIP jika paketnya masih di gudang (belum pernah
     * diserahkan ke kurir), CONFIRMED jika semua barangnya sudah kembali ke rak lewat retur.
     * Mengembalikan [status tujuan, null] atau [null, alasan tidak bisa].
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function target(Order $order): array
    {
        if (!in_array($order->status, ['SHIPPED', 'COMPLETED'], true)) {
            return [null, 'Hanya order Dikirim atau Selesai yang bisa diproses ulang.'];
        }

        $order->loadMissing('items', 'handoverItem.handover');
        $returned = self::returnedQty($order);
        if ($returned->sum() > 0) {
            $complete = $order->items->every(fn ($item) => ($returned[$item->id] ?? 0) >= $item->qty_ordered);

            return $complete
                ? ['CONFIRMED', null]
                : [null, 'Retur order ini belum mencakup semua barangnya. Lengkapi atau hapus returnya dulu.'];
        }

        if ($order->handoverItem?->handover?->status === 'COMPLETED') {
            return [null, 'Paket ini sudah diserahkan ke kurir (' . $order->handoverItem->handover->handover_number . '). Kalau paketnya kembali, buat retur dulu.'];
        }

        return ['READY_TO_SHIP', null];
    }

    /** Proses ulang order (sudah dikunci). Mengembalikan status barunya. */
    public static function reprocess(Order $order, string $userId, ?string $ip): string
    {
        [$target, $error] = self::target($order);
        if (!$target) {
            throw new \RuntimeException($error);
        }

        $oldStatus = $order->status;
        $order->handoverItem?->delete();

        if ($target === 'READY_TO_SHIP') {
            // Paket masih di gudang: cukup di-scan lagi saat serah terima
            $order->update(['status' => 'READY_TO_SHIP', 'shipped_at' => null]);
        } else {
            // Barang sudah kembali ke rak lewat retur: diambil dan dikemas lagi dari awal
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
            $order->update(['status' => 'PENDING', 'shipped_at' => null, 'packed_at' => null, 'picked_at' => null, 'picked_by' => null, 'packed_by' => null]);

            if ($shortages = StockAvailability::shortages($order)) {
                WaitingStock::mark($order, $userId, $ip, StockAvailability::describe($shortages));
                $target = 'PENDING';
            } else {
                $order->update(['status' => 'CONFIRMED']);
            }
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UPDATE_STATUS',
            'module' => 'OUTBOUND',
            'old_values' => json_encode(['status' => $oldStatus]),
            'new_values' => json_encode(['status' => $target, 'order_number' => $order->order_number, 'reason' => 'Diproses ulang dengan resi yang sama']),
            'ip_address' => $ip,
        ]);

        return $target;
    }

    /**
     * Jumlah yang sudah diretur per item order sejak pengiriman terakhir. Retur dari pengiriman
     * sebelumnya (sebelum order diproses ulang) tidak dihitung lagi.
     */
    public static function returnedQty(Order $order)
    {
        return OrderReturnItem::whereIn('order_item_id', $order->items->pluck('id'))
            ->whereHas('orderReturn', fn ($q) => $q->when($order->shipped_at, fn ($q) => $q->where('created_at', '>=', $order->shipped_at)))
            ->selectRaw('order_item_id, SUM(qty_good + qty_damaged) as total')
            ->groupBy('order_item_id')
            ->pluck('total', 'order_item_id');
    }
}
