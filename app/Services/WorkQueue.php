<?php

namespace App\Services;

use App\Models\MissingStockReport;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StockOpname;
use App\Models\User;

/**
 * "Tugas hari ini": pekerjaan yang sedang menunggu seseorang, sesuai role.
 * Dipakai di lonceng notifikasi (semua halaman) dan di dashboard.
 *
 * Dihitung langsung (tanpa cache) supaya angka langsung berubah setelah pekerjaan selesai.
 * Dalam satu request hanya dihitung sekali, dan semua angka per tabel diambil dengan satu
 * query (SUM CASE), karena lonceng ini ikut dimuat di setiap halaman.
 */
class WorkQueue
{
    private const MANAGER_ROLES = ['OWNER', 'SUPERVISOR'];

    /**
     * @return array<int, array{key:string, label:string, hint:string, count:int, url:string, color:string}>
     */
    public static function for(User $user): array
    {
        $cache = request()->attributes;

        if (!$cache->has('work-queue')) {
            $cache->set('work-queue', self::build($user->role));
        }

        return $cache->get('work-queue');
    }

    public static function total(User $user): int
    {
        return array_sum(array_column(self::for($user), 'count'));
    }

    private static function build(string $role): array
    {
        $isManager = in_array($role, self::MANAGER_ROLES);
        $items = [];
        $orders = self::orderCounts();
        $po = PurchaseOrder::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END), 0) as approve")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'READY_FOR_PICKUP' THEN 1 ELSE 0 END), 0) as receive")
            ->whereIn('status', ['PENDING', 'READY_FOR_PICKUP'])
            ->first();
        $opnames = StockOpname::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'SUBMITTED' THEN 1 ELSE 0 END), 0) as submitted")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('DRAFT', 'IN_PROGRESS') THEN 1 ELSE 0 END), 0) as open")
            ->whereIn('status', ['SUBMITTED', 'DRAFT', 'IN_PROGRESS'])
            ->first();

        if ($isManager) {
            $items[] = [
                'key' => 'po_approve',
                'label' => 'PO menunggu persetujuan',
                'hint' => 'Setujui atau tolak',
                'count' => (int) $po->approve,
                'url' => route('po.index', ['status' => 'PENDING']),
                'color' => 'amber',
            ];

            $items[] = [
                'key' => 'stock_approve',
                'label' => 'Penyesuaian stok menunggu persetujuan',
                'hint' => 'Opname dan barang tidak ada di rak',
                'count' => (int) $opnames->submitted + MissingStockReport::pending()->count(),
                'url' => route('gudang.stock-approvals'),
                'color' => 'amber',
            ];
        }

        $items[] = [
            'key' => 'ship_late',
            'label' => 'Order lewat batas kirim',
            'hint' => 'Kerjakan lebih dulu',
            'count' => $orders['late'],
            'url' => route('gudang.outbound', ['batas' => 'terlambat']),
            'color' => 'red',
        ];

        $items[] = [
            'key' => 'order_confirm',
            'label' => 'Order baru perlu dikonfirmasi',
            'hint' => 'Cek lalu konfirmasi',
            'count' => $orders['confirm'],
            'url' => route('gudang.outbound', ['status' => 'PENDING']),
            'color' => 'blue',
        ];

        $items[] = [
            'key' => 'waiting_stock',
            'label' => 'Order menunggu stok',
            'hint' => $isManager ? 'Buat PO untuk kekurangannya' : 'Masuk antrean otomatis saat barang diterima',
            'count' => $orders['waiting_stock'],
            'url' => route('gudang.outbound', ['status' => 'WAITING_STOCK']),
            'color' => 'amber',
        ];

        $items[] = [
            'key' => 'to_restock',
            'label' => 'Barang order batal/diganti belum kembali ke rak',
            'hint' => 'Scan barang ke raknya',
            'count' => OrderItemChange::pendingRestockQty(),
            'url' => route('gudang.restock'),
            'color' => 'red',
        ];

        $items[] = [
            'key' => 'to_pick',
            'label' => 'Order perlu diambil (picking)',
            'hint' => 'Ambil barang dari rak',
            'count' => $orders['pick'],
            'url' => route('gudang.tugas') . '#ambil',
            'color' => 'purple',
        ];

        $items[] = [
            'key' => 'to_pack',
            'label' => 'Order perlu dikemas (packing)',
            'hint' => 'Kemas untuk dikirim',
            'count' => $orders['pack'],
            'url' => route('gudang.tugas') . '#kemas',
            'color' => 'cyan',
        ];

        $items[] = [
            'key' => 'to_ship',
            'label' => 'Paket siap kirim',
            'hint' => 'Cetak label, isi nomor resi',
            'count' => $orders['ship'],
            'url' => route('gudang.tugas') . '#kirim',
            'color' => 'emerald',
        ];

        $items[] = [
            'key' => 'po_receive',
            'label' => 'PO siap diterima',
            'hint' => 'Barang sudah siap di supplier',
            'count' => (int) $po->receive,
            'url' => route('gudang.inbound.create'),
            'color' => 'teal',
        ];

        $items[] = [
            'key' => 'opname',
            'label' => 'Stock opname belum selesai',
            'hint' => 'Lanjutkan hitung fisik',
            'count' => (int) $opnames->open,
            'url' => route('gudang.opname'),
            'color' => 'slate',
        ];

        if ($isManager) {
            $items[] = [
                'key' => 'low_stock',
                'label' => 'SKU stok menipis',
                'hint' => 'Buat PO untuk mengisi ulang',
                'count' => LowStock::count(),
                'url' => route('po.create', ['dari' => 'stok-menipis']),
                'color' => 'red',
            ];
        }

        return $items;
    }

    /** Semua angka order untuk lonceng dalam satu query */
    private static function orderCounts(): array
    {
        $open = "'" . implode("', '", ShipDeadline::OPEN_STATUSES) . "'";
        $row = Order::query()
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ({$open}) AND ship_by < ? THEN 1 ELSE 0 END), 0) as late", [now()])
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PENDING' AND waiting_stock_at IS NULL THEN 1 ELSE 0 END), 0) as confirm")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PENDING' AND waiting_stock_at IS NOT NULL THEN 1 ELSE 0 END), 0) as waiting_stock")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END), 0) as pick")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'PACKING' THEN 1 ELSE 0 END), 0) as pack")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'READY_TO_SHIP' THEN 1 ELSE 0 END), 0) as ship")
            ->whereIn('status', ShipDeadline::OPEN_STATUSES)
            ->first();

        return array_map('intval', $row->only(['late', 'confirm', 'waiting_stock', 'pick', 'pack', 'ship']));
    }
}
