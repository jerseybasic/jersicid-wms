<?php

namespace App\Services;

use App\Models\MasterSku;
use App\Models\Order;
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
            $item = &$orders[$number]['items'][$sku];
            $item ??= [
                'sku' => $sku,
                'name' => trim(($r['Nama Produk'] ?? '') . ' ' . ($r['Nama Variasi'] ?? '')),
                'qty' => 0,
                'price' => (int) round((float) ($r['Harga Satuan'] ?? 0)),
            ];
            $item['qty'] += $qty;
            unset($item);
        }

        return $this->check(array_values(array_map(function ($order) {
            $order['items'] = array_values($order['items']);

            return $order;
        }, $orders)));
    }

    /**
     * Tandai pesanan yang tidak bisa diimpor (problems) dan yang perlu diperhatikan (warnings).
     */
    private function check(array $orders): array
    {
        $skus = collect($orders)->pluck('items')->flatten(1)->pluck('sku')->unique()->values();
        $known = MasterSku::whereIn('sku', $skus)->pluck('sku')->flip();
        $stock = StockActive::whereIn('sku', $skus)->groupBy('sku')
            ->selectRaw('sku, SUM(qty - reserved_qty) as available')->pluck('available', 'sku');
        $existing = Order::withTrashed()->whereIn('order_number', array_column($orders, 'order_number'))
            ->pluck('order_number')->flip();

        foreach ($orders as &$order) {
            $order['problems'] = [];
            $order['warnings'] = [];

            if (isset($existing[$order['order_number']])) {
                $order['problems'][] = 'Sudah pernah diimpor';
            }
            if ($order['cancelled']) {
                $order['problems'][] = 'Pesanan dibatalkan';
            }
            foreach ($order['items'] as $item) {
                if ($item['qty'] < 1) {
                    $order['problems'][] = "Jumlah {$item['sku']} tidak valid";
                } elseif (!isset($known[$item['sku']])) {
                    $order['problems'][] = "SKU {$item['sku']} belum ada di Master SKU";
                } elseif ((int) ($stock[$item['sku']] ?? 0) < $item['qty']) {
                    $order['warnings'][] = "Stok {$item['sku']} tinggal " . (int) ($stock[$item['sku']] ?? 0) . " pcs";
                }
            }
            $order['importable'] = empty($order['problems']);
        }

        return $orders;
    }

    /** BigSeller mengisi sel kosong dengan "--" */
    private function clean($value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || $value === '--') ? null : $value;
    }
}
