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
 * Hanya beberapa COUNT per status, jadi dihitung langsung (tanpa cache) supaya
 * angka langsung berubah setelah pekerjaan selesai. Dalam satu request hanya dihitung sekali.
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

        if ($isManager) {
            $items[] = [
                'key' => 'po_approve',
                'label' => 'PO menunggu persetujuan',
                'hint' => 'Setujui atau tolak',
                'count' => PurchaseOrder::where('status', 'PENDING')->count(),
                'url' => route('po.index', ['status' => 'PENDING']),
                'color' => 'amber',
            ];

            $items[] = [
                'key' => 'stock_approve',
                'label' => 'Penyesuaian stok menunggu persetujuan',
                'hint' => 'Opname dan barang tidak ada di rak',
                'count' => StockOpname::where('status', 'SUBMITTED')->count() + MissingStockReport::pending()->count(),
                'url' => route('gudang.stock-approvals'),
                'color' => 'amber',
            ];
        }

        $items[] = [
            'key' => 'ship_late',
            'label' => 'Order lewat batas kirim',
            'hint' => 'Kerjakan lebih dulu',
            'count' => Order::whereIn('status', ShipDeadline::OPEN_STATUSES)->where('ship_by', '<', now())->count(),
            'url' => route('gudang.outbound', ['batas' => 'terlambat']),
            'color' => 'red',
        ];

        $items[] = [
            'key' => 'order_confirm',
            'label' => 'Order baru perlu dikonfirmasi',
            'hint' => 'Cek lalu konfirmasi',
            'count' => Order::where('status', 'PENDING')->whereNull('waiting_stock_at')->count(),
            'url' => route('gudang.outbound', ['status' => 'PENDING']),
            'color' => 'blue',
        ];

        $items[] = [
            'key' => 'waiting_stock',
            'label' => 'Order menunggu stok',
            'hint' => $isManager ? 'Buat PO untuk kekurangannya' : 'Masuk antrean otomatis saat barang diterima',
            'count' => Order::waitingStock()->count(),
            'url' => route('gudang.outbound', ['status' => 'WAITING_STOCK']),
            'color' => 'amber',
        ];

        $items[] = [
            'key' => 'to_restock',
            'label' => 'Barang order batal belum kembali ke rak',
            'hint' => 'Scan barang ke raknya',
            'count' => (int) OrderCancellation::pendingItems()->sum('qty_picked'),
            'url' => route('gudang.restock'),
            'color' => 'red',
        ];

        $items[] = [
            'key' => 'to_pick',
            'label' => 'Order perlu diambil (picking)',
            'hint' => 'Ambil barang dari rak',
            'count' => Order::where('status', 'CONFIRMED')->count(),
            'url' => route('gudang.tugas') . '#ambil',
            'color' => 'purple',
        ];

        $items[] = [
            'key' => 'to_pack',
            'label' => 'Order perlu dikemas (packing)',
            'hint' => 'Kemas untuk dikirim',
            'count' => Order::where('status', 'PACKING')->count(),
            'url' => route('gudang.tugas') . '#kemas',
            'color' => 'cyan',
        ];

        $items[] = [
            'key' => 'to_ship',
            'label' => 'Paket siap kirim',
            'hint' => 'Cetak label, isi nomor resi',
            'count' => Order::where('status', 'READY_TO_SHIP')->count(),
            'url' => route('gudang.tugas') . '#kirim',
            'color' => 'emerald',
        ];

        $items[] = [
            'key' => 'po_receive',
            'label' => 'PO siap diterima',
            'hint' => 'Barang sudah siap di supplier',
            'count' => PurchaseOrder::where('status', 'READY_FOR_PICKUP')->count(),
            'url' => route('gudang.inbound.create'),
            'color' => 'teal',
        ];

        $items[] = [
            'key' => 'opname',
            'label' => 'Stock opname belum selesai',
            'hint' => 'Lanjutkan hitung fisik',
            'count' => StockOpname::whereIn('status', ['DRAFT', 'IN_PROGRESS'])->count(),
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
}
