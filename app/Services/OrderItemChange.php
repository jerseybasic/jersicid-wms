<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\CourierHandoverItem;
use App\Models\MasterSku;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickingSessionItem;
use App\Models\RestockItem;

/**
 * Ganti item order (misalnya pembeli ganti produk setelah checkout), juga saat order sudah mulai
 * di-picking, di-packing, atau siap kirim. Dipakai form Ubah Order dan import ulang BigSeller.
 *
 * - Belum ada barang yang diambil: sesi picking (jika ada) dihapus, item diganti, order kembali ke antrean.
 * - Sudah ada yang diambil: barang yang masih dipakai tetap di keranjang, barang yang tidak dipakai lagi
 *   masuk daftar "Kembalikan ke Rak" (stok baru bertambah saat ditaruh kembali), item baru ditambahkan ke
 *   sesi picking yang sama, dan packing diulang karena isi paketnya berubah.
 * - Stok untuk item baru harus cukup; jika tidak, tidak ada yang diubah.
 *
 * Harus dipanggil di dalam transaksi DB dengan order yang sudah dikunci.
 */
class OrderItemChange
{
    /** Status order yang itemnya masih bisa diganti (sudah dikirim → pakai retur) */
    public const STATUSES = ['PENDING', 'CONFIRMED', 'PICKING', 'PACKING', 'READY_TO_SHIP'];

    /** Role yang boleh mengganti item order yang barangnya sudah mulai diambil */
    public const ROLES = OrderCancellation::ROLES;

    /** Jumlah pcs yang belum dikembalikan ke rak: order batal + barang lama dari order yang itemnya diganti */
    public static function pendingRestockQty(): int
    {
        return (int) OrderCancellation::pendingItems()->sum('qty_picked') + (int) RestockItem::pending()->sum('qty');
    }

    /** Item order sekarang: [SKU => qty] */
    public static function current(Order $order): array
    {
        return $order->items()->get()->groupBy(fn ($item) => strtoupper($item->sku))
            ->map(fn ($rows) => (int) $rows->sum('qty_ordered'))->sortKeys()->all();
    }

    /** Item lama dan baru sama persis (SKU dan jumlah) */
    public static function same(array $old, array $new): bool
    {
        return self::normalize($old) === self::normalize($new);
    }

    /** Order ini sudah punya barang yang diambil dari rak */
    public static function hasPicked(Order $order): bool
    {
        return (bool) $order->pickingSession?->items()->where('qty_picked', '>', 0)->exists();
    }

    /**
     * Kekurangan stok jika item diganti: hanya sisa yang masih harus diambil yang dihitung, barang yang
     * sudah ada di keranjang dan tetap dipakai tidak perlu stok lagi.
     */
    public static function shortages(Order $order, array $newItems): array
    {
        $newItems = self::normalize($newItems);
        $picked = self::pickedBySku($order);
        $needs = [];
        foreach ($newItems as $sku => $qty) {
            $needs[$sku] = max(0, $qty - array_sum($picked[$sku] ?? []));
        }

        return StockAvailability::shortagesFor($needs, $order->id);
    }

    /**
     * Ganti item order dengan newItems [SKU => qty]. prices [SKU => harga] dipakai untuk SKU yang belum
     * ada di order (harga lama tetap dipakai untuk SKU yang sudah ada). Mengembalikan ringkasan
     * perubahan, atau null jika itemnya sama.
     *
     * @return array{status: string, old: array, new: array, to_return: array, repack: bool}|null
     */
    public static function apply(Order $order, array $newItems, array $prices, string $userId, ?string $ip, string $source): ?array
    {
        if (!in_array($order->status, self::STATUSES, true)) {
            throw new \RuntimeException("Order {$order->order_number} ({$order->status_label}) tidak bisa diganti itemnya." . (in_array($order->status, ['SHIPPED', 'COMPLETED'], true) ? ' Paket sudah dikirim; pakai retur.' : ''));
        }

        $newItems = self::normalize($newItems);
        if (!$newItems) {
            throw new \RuntimeException("Order {$order->order_number} harus punya minimal satu item.");
        }
        $old = self::current($order);
        if (self::same($old, $newItems)) {
            return null;
        }

        $catalog = MasterSku::whereIn('sku', array_keys($newItems))->get(['sku', 'price'])->keyBy(fn ($sku) => strtoupper($sku->sku));
        if ($unknown = array_diff(array_keys($newItems), $catalog->keys()->all())) {
            throw new \RuntimeException('SKU ' . implode(', ', $unknown) . ' belum ada di Master SKU.');
        }

        $order->load('items', 'pickingSession.batch', 'packingSession');
        $oldPrices = $order->items->mapWithKeys(fn ($item) => [strtoupper($item->sku) => $item->price]);
        $prices = array_change_key_case($prices, CASE_UPPER);
        $priceOf = fn ($sku) => $oldPrices[$sku] ?? $prices[$sku] ?? $catalog[$sku]->price ?? 0;
        $oldStatus = $order->status;

        // Order Pending belum memakai stok; yang lain harus tetap bisa dipenuhi
        if ($order->status !== 'PENDING' && $shortages = self::shortages($order, $newItems)) {
            throw new \RuntimeException("Stok tidak cukup untuk item baru order {$order->order_number}: " . StockAvailability::describe($shortages) . '.');
        }

        $picking = $order->pickingSession;
        $toReturn = [];
        $repack = false;

        if (!self::hasPicked($order)) {
            // Belum ada barang yang diambil: mulai lagi dari antrean picking dengan item baru
            if ($picking) {
                $batch = $picking->batch;
                $picking->items()->delete();
                $picking->delete();
                if ($batch) {
                    Picking::refreshBatch($batch);
                }
            }
            $order->items()->delete();
            foreach ($newItems as $sku => $qty) {
                OrderItem::create(['order_id' => $order->id, 'sku' => $catalog[$sku]->sku, 'qty_ordered' => $qty, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => $priceOf($sku)]);
            }
            if ($order->status !== 'PENDING') {
                $order->update(['status' => 'CONFIRMED', 'picked_by' => null, 'picked_at' => null]);
            }
        } else {
            // Isi paket berubah: packing diulang dan paket dikeluarkan dari serah terima yang belum selesai
            if ($packing = $order->packingSession) {
                $packing->items()->delete();
                $packing->delete();
                $repack = true;
            }
            CourierHandoverItem::where('order_id', $order->id)
                ->whereHas('handover', fn ($q) => $q->where('status', 'OPEN'))
                ->delete();

            // Barang di keranjang dipakai lagi untuk SKU yang sama sampai jumlah barunya; sisanya dikembalikan ke rak
            $kept = [];
            foreach (self::pickedBySku($order) as $sku => $bins) {
                $allow = $newItems[$sku] ?? 0;
                foreach ($bins as $bin => $qty) {
                    $take = min($qty, $allow);
                    $allow -= $take;
                    if ($take > 0) {
                        $kept[$sku][$bin] = $take;
                    }
                    if ($qty - $take > 0) {
                        $toReturn[] = ['sku' => $sku, 'bin' => $bin, 'qty' => $qty - $take];
                    }
                }
            }

            $picking->items()->delete();
            $order->items()->delete();
            $allPicked = true;
            foreach ($newItems as $sku => $qty) {
                $keptQty = array_sum($kept[$sku] ?? []);
                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'sku' => $catalog[$sku]->sku,
                    'qty_ordered' => $qty,
                    'qty_picked' => $keptQty,
                    'qty_packed' => 0,
                    'bin_code' => $keptQty ? array_key_last($kept[$sku]) : null,
                    'price' => $priceOf($sku),
                ]);
                foreach ($kept[$sku] ?? [] as $bin => $q) {
                    PickingSessionItem::create(['picking_session_id' => $picking->id, 'order_item_id' => $item->id, 'sku' => $item->sku, 'bin_code' => $bin, 'qty_ordered' => $q, 'qty_picked' => $q, 'status' => 'COMPLETED']);
                }
                if ($qty > $keptQty) {
                    $allPicked = false;
                    foreach (Picking::allocatePlan($item->sku, $qty - $keptQty) as [$bin, $q]) {
                        PickingSessionItem::create(['picking_session_id' => $picking->id, 'order_item_id' => $item->id, 'sku' => $item->sku, 'bin_code' => $bin, 'qty_ordered' => $q, 'qty_picked' => 0, 'status' => 'PENDING']);
                    }
                }
            }

            foreach ($toReturn as $row) {
                RestockItem::create([
                    'order_id' => $order->id,
                    'sku' => $order->items->first(fn ($item) => strtoupper($item->sku) === $row['sku'])?->sku ?? $row['sku'],
                    'bin_code' => $row['bin'] === 'UNKNOWN' ? null : $row['bin'],
                    'qty' => $row['qty'],
                    'qty_initial' => $row['qty'],
                    'reason' => "Item order diganti ({$source})",
                    'created_by' => $userId,
                ]);
            }

            if ($allPicked) {
                // Semua barang baru sudah ada di keranjang: tinggal dikemas ulang
                $picking->update(['status' => 'COMPLETED', 'completed_at' => $picking->completed_at ?? now()]);
                $order->update(['status' => 'PACKING', 'packed_at' => null, 'packed_by' => null, 'picked_at' => $order->picked_at ?? now(), 'picked_by' => $order->picked_by ?? $userId]);
            } else {
                // Masih ada yang harus diambil: picker yang sama melanjutkan sesi ini
                $picking->update(['status' => $picking->started_at ? 'IN_PROGRESS' : 'PENDING', 'completed_at' => null]);
                $order->update(['status' => 'PICKING', 'packed_at' => null, 'packed_by' => null, 'picked_at' => null, 'picked_by' => null]);
                if ($picking->batch && $picking->batch->status === 'COMPLETED') {
                    $picking->batch->update(['status' => 'IN_PROGRESS', 'completed_at' => null]);
                }
            }
        }

        $summary = [
            'status' => $order->status,
            'old' => $old,
            'new' => $newItems,
            'to_return' => $toReturn,
            'repack' => $repack,
        ];

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'CHANGE_ITEMS',
            'module' => 'OUTBOUND',
            'old_values' => json_encode(['status' => $oldStatus, 'items' => $old]),
            'new_values' => json_encode(array_filter([
                'order_number' => $order->order_number,
                'status' => $order->status,
                'items' => $newItems,
                'source' => $source,
                'to_return' => $toReturn,
                'repack' => $repack,
            ])),
            'ip_address' => $ip,
        ]);

        return $summary;
    }

    /**
     * Kalimat untuk pesan sukses: apa yang harus dilakukan staf setelah item diganti.
     */
    public static function describe(Order $order, array $summary): string
    {
        $parts = [];
        if ($summary['to_return']) {
            $parts[] = 'Keluarkan dari keranjang/paket lalu kembalikan ke rak lewat Kembalikan ke Rak: '
                . collect($summary['to_return'])->map(fn ($row) => "{$row['sku']} ×{$row['qty']}" . ($row['bin'] !== 'UNKNOWN' ? " (rak {$row['bin']})" : ''))->implode(', ') . '.';
        }
        $parts[] = match ($summary['status']) {
            'PICKING' => 'Item baru ditambahkan ke sesi picking yang sama, ambil dulu sebelum dikemas.',
            'PACKING' => $summary['repack'] ? 'Paket perlu dikemas ulang.' : 'Barang sudah lengkap di keranjang, lanjut packing.',
            'CONFIRMED' => 'Order kembali ke antrean picking.',
            default => 'Order masih menunggu konfirmasi.',
        };

        return implode(' ', $parts);
    }

    /**
     * Ringkasan perubahan untuk ditampilkan: "A ×1 → B ×1" (hanya SKU yang berubah).
     */
    public static function diffText(array $old, array $new): string
    {
        $old = self::normalize($old);
        $new = self::normalize($new);
        $removed = collect($old)->filter(fn ($qty, $sku) => ($new[$sku] ?? 0) !== $qty)->map(fn ($qty, $sku) => "{$sku} ×{$qty}");
        $added = collect($new)->filter(fn ($qty, $sku) => ($old[$sku] ?? 0) !== $qty)->map(fn ($qty, $sku) => "{$sku} ×{$qty}");

        return ($removed->implode(', ') ?: '-') . ' → ' . ($added->implode(', ') ?: '-');
    }

    /**
     * Barang yang sudah diambil untuk order ini: [SKU => [bin => qty]].
     */
    private static function pickedBySku(Order $order): array
    {
        $picking = $order->pickingSession()->first();
        if (!$picking) {
            return [];
        }

        $picked = [];
        foreach ($picking->items()->where('qty_picked', '>', 0)->orderBy('created_at')->get() as $row) {
            $sku = strtoupper($row->sku);
            $picked[$sku][$row->bin_code] = ($picked[$sku][$row->bin_code] ?? 0) + (int) $row->qty_picked;
        }

        return $picked;
    }

    /** [SKU => qty] dengan SKU huruf besar, jumlah > 0, diurutkan */
    private static function normalize(array $items): array
    {
        $out = [];
        foreach ($items as $sku => $qty) {
            $sku = strtoupper(trim((string) $sku));
            if ($sku !== '' && (int) $qty > 0) {
                $out[$sku] = ($out[$sku] ?? 0) + (int) $qty;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Taruh kembali barang dari order yang itemnya diganti: stok bertambah di bin itu dan sisa yang harus
     * dikembalikan berkurang. Baris harus sudah dikunci.
     */
    public static function putBack(RestockItem $item, string $bin, int $qty, string $userId, ?string $ip): void
    {
        $bin = strtoupper(trim($bin));
        if ($qty < 1 || $qty > $item->qty) {
            throw new \RuntimeException("{$item->sku}: sisa yang perlu dikembalikan {$item->qty} pcs.");
        }
        if ($bin === '') {
            throw new \RuntimeException('Pilih rak tujuan dulu.');
        }

        $order = $item->order;
        OrderCancellation::returnToBin($item->sku, $bin, $qty, $order?->order_number, 'ORDER_CHANGE', "Item order {$order?->order_number} diganti, barang lama dikembalikan ke rak", $userId, $ip);
        $item->update(['qty' => $item->qty - $qty]);
    }
}
