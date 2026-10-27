<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\MissingStockReport;
use App\Models\Order;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\WarehouseRack;
use Illuminate\Support\Facades\DB;

/**
 * Logika picking yang dipakai sesi satu order maupun picking gabungan.
 * Semua method harus dipanggil di dalam transaksi DB.
 */
class Picking
{
    /** Role yang boleh mengisi jumlah diambil/dikemas secara manual (melewati scan) */
    public const MANUAL_ROLES = ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'];

    public const CANCELLED_MESSAGE = 'Order ini sudah dibatalkan. Jangan diambil atau dikemas; kembalikan barangnya lewat menu Kembalikan ke Rak.';

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

        // Satu baris per rak: jika tidak ada satu rak yang cukup, item dipecah ke beberapa rak
        foreach ($order->items as $item) {
            foreach (self::allocatePlan($item->sku, (int) $item->qty_ordered) as [$bin, $qty]) {
                PickingSessionItem::create([
                    'picking_session_id' => $session->id,
                    'order_item_id' => $item->id,
                    'sku' => $item->sku,
                    'bin_code' => $bin,
                    'qty_ordered' => $qty,
                    'qty_picked' => 0,
                    'status' => 'PENDING',
                ]);
            }
        }

        $order->update(['status' => 'PICKING']);

        return $session;
    }

    /**
     * Bin saran untuk mengambil qty pcs SKU ini. Stok yang sudah dijatah ke sesi picking lain yang
     * masih berjalan tidak dihitung, supaya dua picker tidak diarahkan ke rak yang isinya hanya cukup
     * untuk satu. Urutan: bin yang cukup untuk semua qty, bin di rak tempat SKU ditetapkan (rak ambil),
     * lalu sisa stok terbanyak. Null jika SKU ini tidak ada stoknya di mana pun.
     */
    public static function allocateBin(string $sku, int $qty, array $exceptBins = []): ?string
    {
        return self::rankBins($sku, $qty, $exceptBins)->first()['bin'] ?? null;
    }

    /**
     * Rencana ambil qty pcs SKU ini: [[bin, qty], ...]. Satu bin jika ada yang cukup. Jika tidak,
     * diambil dari beberapa bin (urutan sama dengan allocateBin) sampai qty terpenuhi. Kekurangan yang
     * tidak tertutup stok bebas mana pun ditambahkan ke baris pertama, supaya jumlah total tetap sama.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function allocatePlan(string $sku, int $qty): array
    {
        $ranked = self::rankBins($sku, $qty);
        if ($ranked->isEmpty()) {
            return [['UNKNOWN', $qty]];
        }
        if ($ranked->first()['free'] >= $qty) {
            return [[$ranked->first()['bin'], $qty]];
        }

        $plan = [];
        $left = $qty;
        foreach ($ranked->where('free', '>', 0) as $row) {
            $take = min($row['free'], $left);
            $plan[] = [$row['bin'], $take];
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        if ($plan === []) {
            return [[$ranked->first()['bin'], $qty]];
        }
        $plan[0][1] += $left;

        return $plan;
    }

    /** Bin yang punya stok SKU ini, urut dari yang paling cocok untuk diambil qty pcs */
    private static function rankBins(string $sku, int $qty, array $exceptBins = [])
    {
        // Bin yang dilaporkan kosong dan belum diputuskan supervisor tidak dijatah
        $exceptBins = array_merge($exceptBins, MissingStockReport::heldBins($sku));
        $stock = StockActive::where('sku', $sku)->where('qty', '>', 0)
            ->whereNotIn('bin_code', $exceptBins)
            ->pluck('qty', 'bin_code');
        if ($stock->isEmpty()) {
            return collect();
        }

        $allocated = PickingSessionItem::where('sku', $sku)
            ->whereIn('picking_session_id', PickingSession::whereIn('status', ['PENDING', 'IN_PROGRESS'])->select('id'))
            ->groupBy('bin_code')
            ->selectRaw('bin_code, SUM(qty_ordered - qty_picked) as qty')
            ->pluck('qty', 'bin_code');
        $pickRacks = DB::table('rack_products')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'rack_products.warehouse_rack_id')
            ->where('rack_products.sku', $sku)
            ->pluck('warehouse_racks.code')
            ->all();

        return $stock->map(fn ($qtyInBin, $bin) => [
            'bin' => $bin,
            'free' => (int) $qtyInBin - (int) ($allocated[$bin] ?? 0),
            'pick' => in_array(WarehouseRack::codeFromBin($bin), $pickRacks, true),
        ])
            ->sortBy([
                fn ($a, $b) => ($b['free'] >= $qty) <=> ($a['free'] >= $qty),
                fn ($a, $b) => ($b['free'] > 0) <=> ($a['free'] > 0),
                fn ($a, $b) => $b['pick'] <=> $a['pick'],
                fn ($a, $b) => $b['free'] <=> $a['free'],
            ])
            ->values();
    }

    /**
     * Sisa item yang belum diambil dipindah ke baris baru di bin lain (atau digabung ke baris bin itu
     * yang belum mulai), dan baris lama ditutup sebesar yang sudah diambil. Dipakai saat bin pertama
     * habis di tengah jalan. Item harus sudah dikunci.
     */
    public static function splitRemainder(PickingSessionItem $item, string $binCode): PickingSessionItem
    {
        $remaining = $item->qty_ordered - $item->qty_picked;
        if ($remaining < 1 || $item->qty_picked < 1) {
            throw new \RuntimeException("{$item->sku} tidak bisa dipecah ke rak lain.");
        }

        $item->update(['qty_ordered' => $item->qty_picked, 'status' => 'COMPLETED']);

        $sibling = PickingSessionItem::where('picking_session_id', $item->picking_session_id)
            ->where('order_item_id', $item->order_item_id)
            ->where('bin_code', $binCode)
            ->whereKeyNot($item->id)
            ->lockForUpdate()
            ->first();
        if ($sibling) {
            $sibling->update(['qty_ordered' => $sibling->qty_ordered + $remaining, 'status' => $sibling->qty_picked > 0 ? 'PARTIAL' : 'PENDING']);

            return $sibling;
        }

        return PickingSessionItem::create([
            'picking_session_id' => $item->picking_session_id,
            'order_item_id' => $item->order_item_id,
            'sku' => $item->sku,
            'bin_code' => $binCode,
            'qty_ordered' => $remaining,
            'qty_picked' => 0,
            'status' => 'PENDING',
        ]);
    }

    /** Jumlah diambil di item order = total semua baris rak untuk item itu di sesi ini */
    private static function syncOrderItem(PickingSessionItem $item, array $extra = []): void
    {
        $orderItem = $item->orderItem;
        if (!$orderItem) {
            return;
        }

        $picked = (int) PickingSessionItem::where('picking_session_id', $item->picking_session_id)
            ->where('order_item_id', $item->order_item_id)
            ->sum('qty_picked');
        $orderItem->update(['qty_picked' => $picked, 'bin_code' => $item->bin_code] + $extra);
    }

    /**
     * Set total qty diambil untuk satu item. Stok hanya dikurangi sebesar
     * tambahannya. Jika item sudah mulai diambil dari bin lain, sisanya dipecah ke baris baru di bin ini
     * (qtyPicked tetap dihitung sebagai total item). Mengembalikan baris yang dipakai.
     * Sesi dan item harus sudah dikunci.
     */
    public static function apply(PickingSession $session, PickingSessionItem $item, int $qtyPicked, string $binCode, string $userId, ?string $ip): PickingSessionItem
    {
        $binCode = strtoupper(trim($binCode));
        if ($session->status === 'CANCELLED') {
            throw new \RuntimeException(self::CANCELLED_MESSAGE);
        }
        if ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException('Mulai sesi picking dulu sebelum mengambil barang.');
        }

        $newQty = min($qtyPicked, $item->qty_ordered);
        $delta = $newQty - $item->qty_picked;

        if ($delta < 0) {
            throw new \RuntimeException('Jumlah yang sudah diambil tidak bisa dikurangi. Hapus sesi picking untuk mengulang.');
        }

        // Satu baris = satu bin, supaya stok bisa dikembalikan ke bin yang benar. Pindah bin di tengah jalan
        // memecah sisanya ke baris baru.
        if ($delta > 0 && $item->qty_picked > 0 && $binCode !== $item->bin_code) {
            $item = self::splitRemainder($item, $binCode);
            $newQty = min($item->qty_picked + $delta, $item->qty_ordered);
            $delta = $newQty - $item->qty_picked;
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

        self::syncOrderItem($item);

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

        return $item;
    }

    /**
     * Bin untuk mengambil 1 pcs item ini. Lokasi aktif (rak yang terakhir di-scan) dipakai jika
     * stoknya ada di sana; bila tidak, pakai bin saran atau bin lain yang masih ada stoknya,
     * supaya lokasi yang tertinggal dari scan sebelumnya tidak membuat picker macet.
     *
     * @return array{0: string, 1: ?string} [bin, peringatan untuk picker]
     */
    public static function resolveBin(PickingSessionItem $item, ?string $activeBin): array
    {
        $activeBin = $activeBin ? strtoupper(trim($activeBin)) : null;

        $stock = StockActive::where('sku', $item->sku)->where('qty', '>', 0)->pluck('qty', 'bin_code');

        // Sudah mulai dari bin lain: tetap di bin itu, kecuali picker sekarang di rak lain yang ada
        // stoknya atau bin itu habis. Sisanya lalu dipecah ke bin baru (lihat apply).
        if ($item->qty_picked > 0) {
            if (isset($stock[$item->bin_code]) && (!$activeBin || $activeBin === $item->bin_code || !isset($stock[$activeBin]))) {
                return [$item->bin_code, $activeBin && $activeBin !== $item->bin_code
                    ? "Di rak {$activeBin} tidak ada {$item->sku}, dicatat dari {$item->bin_code}."
                    : null];
            }

            $bin = $activeBin && isset($stock[$activeBin]) ? $activeBin : $stock->sortDesc()->keys()->first();
            if (!$bin) {
                throw new \RuntimeException("Stok {$item->sku} kosong di semua rak.");
            }

            return [$bin, $activeBin === $bin ? null : "Rak {$item->bin_code} sudah habis, sisa {$item->sku} dicatat dari {$bin}."];
        }

        $bin = collect([$activeBin, $item->bin_code])->filter()->first(fn ($bin) => isset($stock[$bin]))
            ?? $stock->sortDesc()->keys()->first();

        if (!$bin) {
            throw new \RuntimeException("Stok {$item->sku} kosong di semua rak.");
        }

        return [$bin, $activeBin && $activeBin !== $bin
            ? "Di rak {$activeBin} tidak ada {$item->sku}, dicatat dari {$bin}."
            : null];
    }

    /**
     * Kembalikan 1 pcs yang salah diambil (salah scan/salah ketuk) ke bin asalnya.
     * Jika keranjang order sudah lengkap tapi belum mulai dikemas, order kembali ke picking.
     * Sesi dan item harus sudah dikunci.
     */
    public static function unpick(PickingSession $session, PickingSessionItem $item, string $userId, ?string $ip): void
    {
        if ($item->qty_picked < 1) {
            throw new \RuntimeException("{$item->sku} belum ada yang diambil.");
        }

        $order = $session->order;
        if ($session->status === 'COMPLETED') {
            if ($order?->status !== 'PACKING' || $order->packingSession()->exists()) {
                throw new \RuntimeException('Order ini sudah mulai dikemas, koreksi lewat packing.');
            }
        } elseif ($session->status === 'CANCELLED') {
            throw new \RuntimeException(self::CANCELLED_MESSAGE);
        } elseif ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException('Mulai sesi picking dulu.');
        }

        $stock = StockActive::firstOrCreate(
            ['sku' => $item->sku, 'bin_code' => $item->bin_code],
            ['qty' => 0, 'reserved_qty' => 0]
        );
        $oldQty = $stock->qty;
        $stock->update(['qty' => $oldQty + 1, 'last_movement_at' => now()]);

        StockMovement::create([
            'user_id' => $userId,
            'sku' => $item->sku,
            'bin_code' => $item->bin_code,
            'type' => 'RETURN',
            'qty' => 1,
            'qty_before' => $oldQty,
            'qty_after' => $oldQty + 1,
            'reference_no' => $session->session_number,
            'reference_type' => 'PICKING_CANCEL',
            'notes' => 'Koreksi picking: 1 pcs dikembalikan ke rak',
            'ip_address' => $ip,
        ]);

        $newQty = $item->qty_picked - 1;
        $item->update(['qty_picked' => $newQty, 'status' => $newQty > 0 ? 'PARTIAL' : 'PENDING']);
        self::syncOrderItem($item);

        if ($session->status === 'COMPLETED') {
            $session->update(['status' => 'IN_PROGRESS', 'completed_at' => null]);
            $order->update(['status' => 'PICKING', 'picked_by' => null, 'picked_at' => null]);

            if ($session->batch && $session->batch->status === 'COMPLETED') {
                $session->batch->update(['status' => 'IN_PROGRESS', 'completed_at' => null]);
            }
        }
    }

    /**
     * Hapus sesi yang belum selesai: stok yang sudah diambil dikembalikan ke bin-nya
     * dan order kembali ke antrean picking (CONFIRMED).
     */
    public static function cancel(PickingSession $session, string $userId, ?string $ip): void
    {
        if ($session->status === 'CANCELLED') {
            throw new \RuntimeException('Order sesi ini sudah dibatalkan. Barangnya dikembalikan lewat Kembalikan ke Rak.');
        }

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
     * Picker melaporkan barang tidak ada di rak saran. Stok bin itu belum diubah: laporannya menunggu
     * persetujuan Owner/Supervisor, dan selama itu stok di bin tersebut ditahan (tidak dijatah ke picking
     * dan tidak dihitung tersedia). Jika bin lain masih punya stok yang belum dijatah, picker diarahkan ke sana.
     * Jika tidak, order ditunda sebagai "Menunggu stok": barang yang sudah diambil untuk order ini
     * dikembalikan ke raknya dan order keluar dari picking. Sesi dan item harus sudah dikunci.
     *
     * @return array{0: string, 1: ?string} [pesan untuk picker, bin baru atau null jika order ditunda]
     */
    public static function reportMissing(PickingSession $session, PickingSessionItem $item, string $userId, ?string $ip): array
    {
        if ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException('Mulai sesi picking dulu.');
        }
        if ($item->qty_picked >= $item->qty_ordered) {
            throw new \RuntimeException("{$item->sku} sudah lengkap diambil.");
        }

        $bin = $item->bin_code;
        $stock = StockActive::where('sku', $item->sku)->where('bin_code', $bin)->first();
        if ($stock && $stock->qty > 0
            && !MissingStockReport::pending()->where('sku', $item->sku)->where('bin_code', $bin)->exists()) {
            MissingStockReport::create([
                'sku' => $item->sku,
                'bin_code' => $bin,
                'qty' => $stock->qty,
                'reference_no' => $session->session_number,
                'order_id' => $session->order_id,
                'reported_by' => $userId,
            ]);
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'REPORT_MISSING',
            'module' => 'PICKING',
            'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku, 'bin' => $bin, 'qty_in_system' => (int) ($stock?->qty ?? 0)]),
            'ip_address' => $ip,
        ]);

        // Pindah ke bin lain yang masih ada stoknya. Jika sebagian sudah diambil dari bin ini,
        // yang sudah diambil tetap tercatat di bin ini dan sisanya dipecah ke baris bin baru.
        $need = $item->qty_ordered - $item->qty_picked;
        if ($other = self::allocateBin($item->sku, $need, [$bin])) {
            if ($item->qty_picked === 0) {
                $item->update(['bin_code' => $other]);

                return ["{$item->sku} tidak ada di {$bin}. Coba ambil di rak {$other}.", $other];
            }

            self::splitRemainder($item, $other);

            return ["{$item->sku} di {$bin} habis. Ambil sisanya ({$need} pcs) di rak {$other}.", $other];
        }

        $order = $session->order;
        $basket = $session->basket_no;
        $picked = (int) $session->items()->sum('qty_picked');

        // Packing yang sudah mulai (menunggu barang pengganti) ikut diulang
        if ($packing = $order->packingSession()->where('status', '!=', 'COMPLETED')->first()) {
            $order->items()->update(['qty_packed' => 0]);
            $packing->items()->delete();
            $packing->delete();
        }

        self::cancel($session, $userId, $ip);
        $order->refresh()->update(['status' => 'PENDING', 'picked_by' => null, 'picked_at' => null]);
        WaitingStock::mark($order, $userId, $ip, "{$item->sku} tidak ada di rak saat picking");

        $message = "{$item->sku} tidak ditemukan di rak. Order {$order->order_number} ditunda (Menunggu stok) dan otomatis kembali ke antrean saat barangnya ada. Laporan dikirim ke supervisor untuk dicek.";
        if ($picked > 0) {
            $message .= ' Kembalikan ' . $picked . ' pcs yang sudah diambil' . ($basket ? " dari keranjang {$basket}" : '') . ' ke raknya masing-masing.';
        }

        return [$message, null];
    }

    /**
     * Masalah saat packing (barang rusak, salah ukuran/barang, atau kurang di keranjang): 1 pcs item ini
     * dikeluarkan dan order kembali ke picking untuk mengambil penggantinya. Barang salah/kurang
     * dikembalikan ke stok bin asalnya; barang rusak hanya dicatat (tidak menambah stok), sama seperti retur rusak.
     * Packing tetap berjalan dan bisa dilanjutkan setelah penggantinya diambil.
     */
    public static function replaceOne(PickingSession $session, PickingSessionItem $item, string $reason, string $userId, ?string $ip): void
    {
        if ($item->qty_picked < 1) {
            throw new \RuntimeException("{$item->sku} belum ada yang diambil.");
        }

        if ($reason !== 'RUSAK') {
            $stock = StockActive::firstOrCreate(['sku' => $item->sku, 'bin_code' => $item->bin_code], ['qty' => 0, 'reserved_qty' => 0]);
            $oldQty = $stock->qty;
            $stock->update(['qty' => $oldQty + 1, 'last_movement_at' => now()]);

            StockMovement::create([
                'user_id' => $userId,
                'sku' => $item->sku,
                'bin_code' => $item->bin_code,
                'type' => 'RETURN',
                'qty' => 1,
                'qty_before' => $oldQty,
                'qty_after' => $oldQty + 1,
                'reference_no' => $session->session_number,
                'reference_type' => 'PACKING_PROBLEM',
                'notes' => 'Masalah saat packing, 1 pcs dikembalikan ke rak',
                'ip_address' => $ip,
            ]);
        }

        $newQty = $item->qty_picked - 1;
        $item->update(['qty_picked' => $newQty, 'status' => $newQty > 0 ? 'PARTIAL' : 'PENDING']);
        self::syncOrderItem($item);
        if ($orderItem = $item->orderItem?->fresh()) {
            $orderItem->update(['qty_packed' => min((int) $orderItem->qty_packed, (int) $orderItem->qty_picked)]);
        }

        $session->update(['status' => 'IN_PROGRESS', 'completed_at' => null]);
        $session->order->update(['status' => 'PICKING', 'picked_by' => null, 'picked_at' => null]);
        if ($session->batch && $session->batch->status === 'COMPLETED') {
            $session->batch->update(['status' => 'IN_PROGRESS', 'completed_at' => null]);
        }
    }

    /**
     * Picking gabungan selesai jika semua pesanannya selesai atau dibatalkan; dihapus jika tidak ada pesanan tersisa.
     */
    public static function refreshBatch(PickingBatch $batch): void
    {
        $sessions = $batch->sessions()->get(['id', 'status']);

        if ($sessions->isEmpty()) {
            $batch->delete();
        } elseif ($sessions->every(fn ($s) => in_array($s->status, ['COMPLETED', 'CANCELLED'], true)) && $batch->status !== 'COMPLETED') {
            $batch->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        }
    }
}
