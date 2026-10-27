<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\MissingStockReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrderItem;
use App\Models\StockActive;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Order yang barangnya belum ada: ditandai "Menunggu stok" (tetap PENDING), keluar dari antrean,
 * kekurangannya bisa langsung dibuatkan PO, dan order dikonfirmasi otomatis begitu stoknya cukup.
 */
class WaitingStock
{
    /** Status PO yang barangnya masih akan datang */
    private const INCOMING_PO_STATUSES = ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'READY'];

    /** Tandai order Pending sebagai menunggu stok (tanggal pertama kali ditunda tetap disimpan) */
    public static function mark(Order $order, ?string $userId, ?string $ip, ?string $reason = null): void
    {
        if ($order->status !== 'PENDING' || $order->waiting_stock_at) {
            return;
        }

        $order->update(['waiting_stock_at' => now()]);

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'WAITING_STOCK',
            'module' => 'OUTBOUND',
            'new_values' => json_encode(array_filter(['order_number' => $order->order_number, 'reason' => $reason])),
            'ip_address' => $ip,
        ]);
    }

    /**
     * Konfirmasi order yang menunggu stok jika stoknya sekarang cukup, batas kirim terdekat dulu
     * supaya stok yang baru masuk jatuh ke order paling mendesak.
     *
     * @return Collection<int, Order> order yang kembali ke antrean picking
     */
    public static function release(?string $userId, ?string $ip): Collection
    {
        $released = collect();

        Order::waitingStock()->orderBy('ship_by')->orderBy('created_at')->get()
            ->each(function (Order $order) use ($userId, $ip, $released) {
                DB::transaction(function () use ($order, $userId, $ip, $released) {
                    $order = Order::lockForUpdate()->find($order->id);
                    if (!$order?->isWaitingStock() || StockAvailability::shortages($order)) {
                        return;
                    }

                    $order->update(['status' => 'CONFIRMED', 'waiting_stock_at' => null]);

                    AuditTrail::create([
                        'user_id' => $userId,
                        'action' => 'UPDATE_STATUS',
                        'module' => 'OUTBOUND',
                        'old_values' => json_encode(['status' => 'PENDING']),
                        'new_values' => json_encode(['status' => 'CONFIRMED', 'order_number' => $order->order_number, 'auto' => 'stok sudah tersedia']),
                        'ip_address' => $ip,
                    ]);

                    $released->push($order);
                });
            });

        return $released;
    }

    /** Kalimat untuk pesan sukses Inbound/Retur, kosong jika tidak ada order yang kembali */
    public static function releasedMessage(Collection $released): string
    {
        if ($released->isEmpty()) {
            return '';
        }

        $numbers = $released->take(5)->pluck('order_number')->implode(', ') . ($released->count() > 5 ? ', dan lainnya' : '');

        return " {$released->count()} order yang menunggu stok kembali ke antrean picking ({$numbers}).";
    }

    /**
     * Kekurangan per SKU untuk semua order yang menunggu stok, dikurangi barang yang sudah dipesan di PO lain.
     *
     * @return Collection<int, array{sku: string, need: int, available: int, incoming: int, short: int, orders: int}>
     */
    public static function needs(): Collection
    {
        $needs = OrderItem::whereHas('order', fn ($q) => $q->waitingStock())
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty_ordered) as need, COUNT(DISTINCT order_id) as orders')
            ->get()
            ->keyBy('sku');
        if ($needs->isEmpty()) {
            return collect();
        }

        $skus = $needs->keys();
        $onHand = StockActive::whereIn('sku', $skus)->groupBy('sku')->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku');
        $committed = OrderItem::whereIn('sku', $skus)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['CONFIRMED', 'PICKING']))
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty_ordered - qty_picked) as qty')
            ->pluck('qty', 'sku');
        $held = MissingStockReport::heldQtyBySku($skus);
        $incoming = PurchaseOrderItem::whereIn('sku', $skus)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', self::INCOMING_PO_STATUSES))
            ->groupBy('sku')
            ->selectRaw('sku, SUM(qty_ordered - qty_received) as qty')
            ->pluck('qty', 'sku');

        return $needs->map(function ($row, $sku) use ($onHand, $held, $committed, $incoming) {
            $available = max(0, (int) ($onHand[$sku] ?? 0) - (int) ($held[$sku] ?? 0) - (int) ($committed[$sku] ?? 0));
            $coming = max(0, (int) ($incoming[$sku] ?? 0));

            return [
                'sku' => $sku,
                'need' => (int) $row->need,
                'available' => $available,
                'incoming' => $coming,
                'short' => max(0, (int) $row->need - $available - $coming),
                'orders' => (int) $row->orders,
            ];
        })->values();
    }
}
