<?php

namespace App\Services;

use App\Models\MasterSku;
use App\Models\Order;
use App\Models\SkuAlias;
use App\Models\StockActive;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Membaca file ekspor pesanan BigSeller (Excel/CSV): satu baris per item,
 * pesanan dengan beberapa item mengulang kolom pesanannya (dikelompokkan per "Nomor Pesanan").
 */
class BigSellerOrderImport
{
    /** Kolom yang wajib ada di baris judul */
    public const REQUIRED_COLUMNS = ['Nomor Pesanan', 'SKU', 'Jumlah'];

    /** Kolom batas kirim dari marketplace (nama berbeda per versi ekspor) */
    public const DEADLINE_COLUMNS = ['Batas Waktu Pengiriman', 'Waktu Kirim Paling Lambat', 'Batas Kirim', 'Ship By Date'];

    /**
     * Baca file lalu kembalikan daftar pesanan siap ditinjau:
     * [order_number, marketplace, customer_name, ..., items[], problems[], warnings[]].
     *
     * @throws \InvalidArgumentException jika file bukan ekspor BigSeller
     */
    public function parse(string $path): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if ($missing) {
            throw new \InvalidArgumentException('Kolom ' . implode(', ', $missing) . ' tidak ditemukan. Pastikan file berasal dari ekspor pesanan BigSeller.');
        }

        $orders = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($header as $i => $name) {
                $r[$name] = $this->clean($row[$i] ?? null);
            }
            $number = strtoupper((string) $r['Nomor Pesanan']);
            if ($number === '') {
                continue;
            }

            $data = [
                'order_number' => $number,
                'marketplace' => trim(($r['Marketplace'] ?? '') . ' ' . ($r['Toko Marketplace'] ?? '')),
                'status' => $r['Status Pesanan'] ?? null,
                'cancelled' => !empty($r['Waktu Pembatalan']) || str_contains(strtolower((string) ($r['Status Pesanan'] ?? '')), 'batal'),
                'customer_name' => ($r['Nama Penerima'] ?? null) ?: ($r['Nama Pembeli'] ?? null) ?: 'Pembeli marketplace',
                'buyer' => $r['Nama Pembeli'] ?? null,
                'customer_phone' => $r['Nomor Telepon'] ?? null,
                'shipping_address' => $r['Alamat Lengkap'] ?? null,
                'courier' => $r['Jasa Kirim yang Dipilih Pembeli'] ?? ($r['Nama Jasa Kirim'] ?? null),
                'tracking_number' => $r['Nomor Resi'] ?? null,
                'ordered_at' => $r['Waktu Pesanan Dibuat'] ?? null,
                'ship_by' => $this->firstOf($r, self::DEADLINE_COLUMNS),
                'buyer_message' => $r['Pesan dari Pembeli'] ?? null,
                'seller_note' => $r['Catatan Penjual'] ?? null,
            ];

            if (!isset($orders[$number])) {
                $orders[$number] = $data + ['items' => []];
            } else {
                // Kolom pesanan biasanya berulang di tiap baris; isi yang masih kosong dari baris berikutnya
                foreach ($data as $key => $value) {
                    $orders[$number][$key] ??= $value;
                }
                $orders[$number]['cancelled'] = $orders[$number]['cancelled'] || $data['cancelled'];
            }

            // SKU Gudang (jika diisi di BigSeller) lebih diutamakan daripada SKU marketplace
            $sku = strtoupper((string) (($r['SKU Gudang'] ?? null) ?: $r['SKU']));
            $qty = (int) $r['Jumlah'];
            $item = &$orders[$number]['source_items'][$sku];
            $item ??= [
                'source_sku' => $sku,
                'name' => trim(($r['Nama Produk'] ?? '') . ' ' . ($r['Nama Variasi'] ?? '')),
                'qty' => 0,
                'price' => (int) round((float) ($r['Harga Satuan'] ?? 0)),
            ];
            $item['qty'] += $qty;
            unset($item);
        }

        return $this->check(array_values(array_map(function ($order) {
            $order['source_items'] = array_values($order['source_items']);
            unset($order['items']);

            return $order;
        }, $orders)));
    }

    /**
     * Ubah SKU marketplace ke kode Master SKU lewat pemetaan SKU, lalu tandai pesanan
     * yang tidak bisa diimpor (problems) dan yang perlu diperhatikan (warnings).
     * Bisa dipanggil ulang setelah pemetaan ditambah, karena SKU asli tetap disimpan di source_items.
     */
    public function check(array $orders): array
    {
        $sources = collect($orders)->pluck('source_items')->flatten(1)->pluck('source_sku')->unique()->values();
        $direct = MasterSku::whereIn('sku', $sources)->pluck('sku')->mapWithKeys(fn ($sku) => [strtoupper($sku) => true]);
        $aliases = SkuAlias::whereIn('alias', $sources->reject(fn ($sku) => isset($direct[$sku]))->values())
            ->pluck('sku', 'alias')->mapWithKeys(fn ($sku, $alias) => [strtoupper($alias) => strtoupper($sku)]);

        foreach ($orders as &$order) {
            $items = [];
            foreach ($order['source_items'] as $source) {
                $sku = isset($direct[$source['source_sku']]) ? $source['source_sku'] : ($aliases[$source['source_sku']] ?? $source['source_sku']);
                $item = &$items[$sku];
                $item ??= ['sku' => $sku, 'mapped_from' => [], 'qty' => 0] + $source;
                $item['qty'] += $source['qty'];
                if ($sku !== $source['source_sku']) {
                    $item['mapped_from'][] = $source['source_sku'];
                }
                unset($item);
            }
            $order['items'] = array_values($items);
        }
        unset($order);

        $skus = collect($orders)->pluck('items')->flatten(1)->pluck('sku')->unique()->values();
        $known = MasterSku::whereIn('sku', $skus)->pluck('sku')->mapWithKeys(fn ($sku) => [strtoupper($sku) => true]);
        $stock = StockActive::whereIn('sku', $skus)->groupBy('sku')
            ->selectRaw('sku, SUM(qty - reserved_qty) as available')->pluck('available', 'sku');
        $existing = Order::withTrashed()->with('items:id,order_id,sku,qty_ordered', 'pickingSession:id,order_id')
            ->whereIn('order_number', array_column($orders, 'order_number'))
            ->get()->keyBy(fn ($order) => strtoupper($order->order_number));

        foreach ($orders as &$order) {
            $order['problems'] = [];
            $order['warnings'] = [];
            // create = pesanan baru; change = item pesanan yang sudah ada diganti; cancel = batalkan di WMS
            $order['action'] = null;
            $order['existing'] = null;
            $order['change'] = null;

            if ($current = $existing[$order['order_number']] ?? null) {
                $this->compareExisting($order, $current);
            } elseif ($order['cancelled']) {
                $order['problems'][] = 'Pesanan dibatalkan';
            } else {
                $order['action'] = 'create';
            }

            if ($order['action'] !== 'cancel') {
                foreach ($order['items'] as &$item) {
                    $item['known'] = isset($known[$item['sku']]);
                    if ($item['qty'] < 1) {
                        $order['problems'][] = "Jumlah {$item['sku']} tidak valid";
                    } elseif (!$item['known']) {
                        $order['problems'][] = "SKU {$item['sku']} belum ada di Master SKU";
                    } elseif ($order['action'] === 'create' && (int) ($stock[$item['sku']] ?? 0) < $item['qty']) {
                        $order['warnings'][] = "Stok {$item['sku']} tinggal " . (int) ($stock[$item['sku']] ?? 0) . " pcs";
                    }
                }
                unset($item);
            }

            // Ganti item order yang sudah dikonfirmasi/diproses hanya jika stok item barunya cukup
            if ($order['action'] === 'change' && !$order['problems'] && $current->status !== 'PENDING'
                && $shortages = OrderItemChange::shortages($current, $order['change']['to'])) {
                $order['problems'][] = 'Stok kurang untuk item baru: ' . StockAvailability::describe($shortages) . '. Item belum bisa diganti.';
            }

            $order['importable'] = $order['action'] === 'create' && empty($order['problems']);
            $order['updatable'] = in_array($order['action'], ['change', 'cancel'], true) && empty($order['problems']);
        }
        unset($order);

        return $orders;
    }

    /**
     * Pesanan yang sudah pernah diimpor: dibatalkan di marketplace (bisa ikut dibatalkan di WMS),
     * itemnya berubah (bisa diganti, juga saat sudah di-picking/packing), atau sama (dilewati).
     */
    private function compareExisting(array &$order, Order $current): void
    {
        $order['existing'] = [
            'id' => $current->id,
            'status' => $current->status,
            'status_label' => $current->trashed() ? 'Dihapus' : $current->status_label,
            'in_process' => $current->itemsInProcess(),
        ];
        $active = !$current->trashed() && in_array($current->status, Order::ACTIVE_STATUSES, true);

        if ($order['cancelled']) {
            if ($active) {
                $order['action'] = 'cancel';
                $order['warnings'][] = "Dibatalkan di marketplace, tapi di WMS masih {$order['existing']['status_label']}.";
                if (in_array($current->status, Order::IN_PROCESS_STATUSES, true)) {
                    $order['warnings'][] = 'Barang yang sudah diambil masuk daftar Kembalikan ke Rak.';
                }
            } else {
                $order['problems'][] = 'Pesanan dibatalkan';
            }

            return;
        }

        $from = $current->items->groupBy(fn ($item) => strtoupper($item->sku))->map(fn ($rows) => (int) $rows->sum('qty_ordered'))->all();
        $to = collect($order['items'])->mapWithKeys(fn ($item) => [$item['sku'] => (int) $item['qty']])->all();
        if (OrderItemChange::same($from, $to)) {
            $order['problems'][] = 'Sudah pernah diimpor. Untuk mengirim ulang, buka order-nya lalu pakai Proses ulang';

            return;
        }
        if (!$active || !in_array($current->status, OrderItemChange::STATUSES, true)) {
            $order['problems'][] = "Sudah pernah diimpor dan berstatus {$order['existing']['status_label']}, jadi itemnya tidak bisa diganti lagi.";

            return;
        }

        $order['action'] = 'change';
        $order['change'] = ['from' => $from, 'to' => $to, 'text' => OrderItemChange::diffText($from, $to)];
        if ($order['existing']['in_process']) {
            $order['warnings'][] = "Sudah {$order['existing']['status_label']}: barang lama yang tidak dipakai lagi masuk daftar Kembalikan ke Rak, item baru ditambahkan ke picking, dan paket dikemas ulang.";
        }
    }

    private function firstOf(array $row, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (!empty($row[$column])) {
                return $row[$column];
            }
        }

        return null;
    }

    /** BigSeller mengisi sel kosong dengan "--" */
    private function clean($value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || $value === '--') ? null : $value;
    }
}
