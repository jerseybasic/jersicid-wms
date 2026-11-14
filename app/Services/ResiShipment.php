<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\StockActive;
use App\Models\StockMovement;

/**
 * Input resi yang sudah diproses (dikirim) di BigSeller, tanpa alur picking/packing di gudang.
 * Dipakai saat order sempat tidak diproses di aplikasi (misalnya staf pengganti belum paham),
 * supaya stok di sistem tetap sama dengan barang yang benar-benar ada di rak.
 *
 * Order dicari lewat nomor resi atau nomor pesanan. Order Pending / Siap Picking stoknya langsung
 * dipotong dari rak yang ada isinya, order Siap Kirim cukup ditandai dikirim (barangnya sudah
 * diambil saat picking). Order lain dilewati dengan alasannya.
 */
class ResiShipment
{
    /** Role yang boleh memotong stok tanpa scan picking (sama dengan pengisian manual di picking) */
    public const ROLES = Picking::MANUAL_ROLES;

    /** Paling banyak resi sekali proses, supaya halamannya tetap ringan */
    public const MAX_CODES = 300;

    public const REFERENCE_TYPE = 'RESI_BIGSELLER';

    /**
     * Pisahkan teks tempelan jadi daftar kode (per baris, spasi, koma, atau titik koma).
     * Huruf dibuat besar; kode yang ditempel dua kali hanya dihitung sekali.
     *
     * @return array{codes: string[], repeated: string[]}
     */
    public static function parse(?string $text): array
    {
        $codes = [];
        $repeated = [];
        foreach (preg_split('/[\s,;]+/', strtoupper((string) $text)) ?: [] as $code) {
            $code = trim($code, " \t\"'");
            if ($code === '') {
                continue;
            }
            if (isset($codes[$code])) {
                $repeated[$code] = $code;
                continue;
            }
            $codes[$code] = $code;
        }

        return ['codes' => array_values($codes), 'repeated' => array_values($repeated)];
    }

    /**
     * Periksa tiap kode tanpa mengubah apa pun. Satu baris per kode:
     * [code, result (ship|mark|skip|missing), reason, order, items[sku, qty, available]].
     * ship = stok dipotong lalu dikirim, mark = cukup ditandai dikirim, skip = dilewati, missing = tidak ditemukan.
     */
    public static function check(array $codes): array
    {
        $rows = [];
        $seen = [];
        foreach ($codes as $code) {
            $order = Order::with('items', 'pickingSession')->whereRaw('UPPER(tracking_number) = ?', [$code])->latest()->first()
                ?? Order::with('items', 'pickingSession')->whereRaw('UPPER(order_number) = ?', [$code])->first();

            if (!$order) {
                $rows[] = ['code' => $code, 'result' => 'missing', 'reason' => 'Tidak ada order dengan resi / nomor pesanan ini. Import dulu pesanannya dari BigSeller.', 'order' => null, 'items' => []];
                continue;
            }
            // Resi dan nomor pesanan order yang sama ditempel bersamaan
            if (isset($seen[$order->id])) {
                $rows[] = ['code' => $code, 'result' => 'skip', 'reason' => "Order yang sama dengan {$seen[$order->id]} di daftar ini.", 'order' => $order, 'items' => []];
                continue;
            }
            $seen[$order->id] = $code;

            [$result, $reason] = self::decide($order);
            $items = $result === 'ship' ? self::itemsWithStock($order) : [];
            if ($result === 'ship' && ($short = collect($items)->first(fn ($item) => $item['available'] < $item['qty']))) {
                $result = 'skip';
                $reason = "Stok {$short['sku']} di sistem hanya {$short['available']} pcs (perlu {$short['qty']}). Cek stoknya lewat stock opname dulu.";
            }

            $rows[] = ['code' => $code, 'result' => $result, 'reason' => $reason, 'order' => $order, 'items' => $items];
        }

        return $rows;
    }

    /** @return array{0: string, 1: string} [ship|mark|skip, alasan] */
    private static function decide(Order $order): array
    {
        return match (true) {
            in_array($order->status, Order::SENT_STATUSES, true) => ['skip', 'Sudah tercatat ' . strtolower($order->status_label) . ($order->shipped_at ? ' sejak ' . $order->shipped_at->format('d/m H:i') : '') . '. Stok tidak dipotong lagi.'],
            $order->status === 'CANCELLED' => ['skip', 'Order ini dibatalkan di aplikasi. Aktifkan lagi dulu jika ternyata tetap dikirim.'],
            $order->status === 'READY_TO_SHIP' => ['mark', 'Sudah dikemas, tinggal ditandai dikirim. Stok sudah dipotong saat picking.'],
            in_array($order->status, ['PICKING', 'PACKING'], true) || $order->pickingSession !== null => ['skip', 'Sedang ' . strtolower($order->status_label) . ' di gudang. Selesaikan lewat menu Picking / Packing.'],
            $order->items->isEmpty() => ['skip', 'Order ini belum punya item.'],
            default => ['ship', 'Stok dipotong dari rak, lalu order ditandai dikirim.'],
        };
    }

    /** Item order beserta stok SKU-nya di semua rak */
    private static function itemsWithStock(Order $order): array
    {
        $needed = $order->items->groupBy('sku')->map(fn ($items) => (int) $items->sum(fn ($item) => $item->qty_ordered - $item->qty_picked));
        $stock = StockActive::whereIn('sku', $needed->keys())->where('qty', '>', 0)
            ->groupBy('sku')->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku');

        return $needed->map(fn ($qty, $sku) => ['sku' => $sku, 'qty' => $qty, 'available' => (int) ($stock[$sku] ?? 0)])->values()->all();
    }

    /**
     * Potong stok (jika perlu) dan tandai order dikirim. Harus dipanggil di dalam transaksi DB;
     * order dikunci di sini dan diperiksa ulang karena bisa saja berubah sejak pratinjau.
     *
     * @return string ship atau mark
     *
     * @throws \RuntimeException jika order tidak bisa diproses lagi
     */
    public static function ship(Order $order, string $code, string $userId, ?string $ip): string
    {
        $order = Order::with('items', 'pickingSession')->lockForUpdate()->findOrFail($order->id);
        [$result, $reason] = self::decide($order);
        if ($result === 'skip') {
            throw new \RuntimeException("{$code}: {$reason}");
        }

        $taken = [];
        if ($result === 'ship') {
            foreach ($order->items as $item) {
                $qty = $item->qty_ordered - $item->qty_picked;
                $bins = $qty > 0 ? self::take($item->sku, $qty, $order, $code, $userId, $ip) : [];
                foreach ($bins as $bin => $binQty) {
                    $taken[] = "{$item->sku} ×{$binQty} dari {$bin}";
                }
                $item->update(['qty_picked' => $item->qty_ordered, 'qty_packed' => $item->qty_ordered, 'bin_code' => array_key_first($bins) ?? $item->bin_code]);
            }
        }

        $now = now();
        $oldStatus = $order->status;
        $order->update(array_filter([
            'status' => 'SHIPPED',
            'shipped_at' => $now,
            'waiting_stock_at' => null,
            'picked_at' => $order->picked_at ?? $now,
            'packed_at' => $order->packed_at ?? $now,
            'picked_by' => $order->picked_by ?? $userId,
            'packed_by' => $order->packed_by ?? $userId,
            // Order lama yang diimpor sebelum resinya keluar: resinya ikut disimpan
            'tracking_number' => $order->tracking_number ?: ($code !== strtoupper($order->order_number) ? $code : null),
        ], fn ($value, $key) => $value !== null || $key === 'waiting_stock_at', ARRAY_FILTER_USE_BOTH));

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UPDATE_STATUS',
            'module' => 'OUTBOUND',
            'old_values' => json_encode(['status' => $oldStatus]),
            'new_values' => json_encode(array_filter([
                'status' => 'SHIPPED',
                'order_number' => $order->order_number,
                'reason' => 'Input resi BigSeller ' . $code . ($result === 'ship' ? ' (stok dipotong tanpa picking)' : ''),
                'source' => self::REFERENCE_TYPE,
                'taken' => $taken,
            ])),
            'ip_address' => $ip,
        ]);

        return $result;
    }

    /**
     * Kurangi qty pcs SKU dari rak yang ada isinya, mulai dari yang stoknya paling banyak.
     *
     * @return array<string, int> [bin => qty diambil]
     */
    private static function take(string $sku, int $qty, Order $order, string $code, string $userId, ?string $ip): array
    {
        $stocks = StockActive::where('sku', $sku)->where('qty', '>', 0)
            ->orderByDesc('qty')->orderBy('bin_code')->lockForUpdate()->get();
        if ($stocks->sum('qty') < $qty) {
            throw new \RuntimeException("{$code}: stok {$sku} di sistem hanya {$stocks->sum('qty')} pcs (perlu {$qty}). Cek stoknya lewat stock opname dulu.");
        }

        $taken = [];
        $left = $qty;
        foreach ($stocks as $stock) {
            $take = min($stock->qty, $left);
            $before = $stock->qty;
            $stock->update(['qty' => $before - $take, 'last_movement_at' => now()]);
            StockMovement::create([
                'user_id' => $userId,
                'sku' => $sku,
                'bin_code' => $stock->bin_code,
                'type' => 'OUTBOUND',
                'qty' => $take,
                'qty_before' => $before,
                'qty_after' => $before - $take,
                'reference_no' => $order->order_number,
                'reference_type' => self::REFERENCE_TYPE,
                'notes' => "Resi {$code} sudah dikirim lewat BigSeller (tanpa picking)",
                'ip_address' => $ip,
            ]);
            $taken[$stock->bin_code] = $take;
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        return $taken;
    }
}
