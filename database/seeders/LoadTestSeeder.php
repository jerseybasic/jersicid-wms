<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data contoh dalam jumlah besar untuk uji beban k6 (tests/load).
 * JANGAN dijalankan di database production: menolak jalan jika APP_ENV=production.
 *
 *   LOADTEST_ORDERS=5000 php artisan db:seed --class=LoadTestSeeder
 *
 * Membuat: 8 user (password "password"), 20 rak, 600 SKU dengan stok di 1-2 bin,
 * order dengan item di semua status, pergerakan stok, PO, dan audit trail.
 */
class LoadTestSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production') && !env('LOADTEST_ALLOW_PRODUCTION')) {
            $this->command?->error('LoadTestSeeder ditolak di APP_ENV=production. Pakai database salinan/staging.');

            return;
        }

        $orderCount = (int) env('LOADTEST_ORDERS', 5000);
        $now = now();
        $password = Hash::make(self::PASSWORD);

        // ---- User: 1 owner, 1 supervisor, 1 admin, 5 staf ----
        $users = [];
        $roles = ['owner' => 'OWNER', 'supervisor' => 'SUPERVISOR', 'admin' => 'ADMIN_GUDANG'] + array_combine(
            array_map(fn ($i) => "staf{$i}", range(1, 5)),
            array_fill(0, 5, 'STAFF_GUDANG')
        );
        foreach ($roles as $key => $role) {
            $email = "loadtest-{$key}@jersic.test";
            $id = DB::table('users')->where('email', $email)->value('id') ?? (string) Str::uuid();
            DB::table('users')->updateOrInsert(['email' => $email], [
                'id' => $id, 'name' => 'Uji ' . ucfirst($key), 'password' => $password, 'role' => $role,
                'workspace_default' => $role === 'STAFF_GUDANG' || $role === 'ADMIN_GUDANG' ? 'GUDANG' : 'UTAMA',
                'status' => 'AKTIF', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $users[$role][] = $id;
        }
        $warehouseUsers = array_merge($users['ADMIN_GUDANG'], $users['STAFF_GUDANG']);

        // ---- Gudang, rak, SKU, stok ----
        if (!DB::table('warehouses')->exists()) {
            DB::table('warehouses')->insert(['id' => (string) Str::uuid(), 'name' => 'Gudang Utama', 'code' => 'GDG-01', 'status' => 'AKTIF', 'created_at' => $now, 'updated_at' => $now]);
        }
        $rackCodes = array_map(fn ($i) => chr(65 + $i), range(0, 19)); // A..T
        foreach ($rackCodes as $i => $code) {
            DB::table('warehouse_racks')->updateOrInsert(['code' => $code], [
                'id' => DB::table('warehouse_racks')->where('code', $code)->value('id') ?? (string) Str::uuid(),
                'aisle_number' => intdiv($i, 4) + 1, 'rows_count' => 5, 'position_x' => $i % 4, 'position_y' => intdiv($i, 4),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $sizes = ['S', 'M', 'L', 'XL', 'XXL', '3XL'];
        $skus = [];
        $skuRows = [];
        $stockRows = [];
        for ($a = 1; $a <= 100; $a++) {
            foreach ($sizes as $size) {
                $sku = sprintf('LT-ART%03d-V1-%s', $a, $size);
                $skus[] = $sku;
                $skuRows[] = [
                    'sku' => $sku, 'article' => sprintf('ART%03d', $a), 'product_name' => "JERSEY UJI {$a}", 'version' => 'V1',
                    'size_category' => 'DEWASA', 'size' => $size, 'price' => 150000, 'cost_price' => 60000, 'min_stock' => 5,
                    'status' => 'AKTIF', 'created_at' => $now, 'updated_at' => $now,
                ];
                $rack = $rackCodes[$a % 20];
                $stockRows[] = ['id' => (string) Str::uuid(), 'sku' => $sku, 'bin_code' => sprintf('%s-%02d-%02d', $rack, ($a % 5) + 1, array_search($size, $sizes) + 1), 'qty' => random_int(20, 200), 'reserved_qty' => 0, 'created_at' => $now, 'updated_at' => $now];
                if ($a % 3 === 0) {
                    $stockRows[] = ['id' => (string) Str::uuid(), 'sku' => $sku, 'bin_code' => sprintf('%s-%02d-%02d', $rackCodes[($a + 7) % 20], 1, array_search($size, $sizes) + 1), 'qty' => random_int(5, 50), 'reserved_qty' => 0, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        DB::table('master_skus')->whereIn('sku', $skus)->delete();
        DB::table('stock_actives')->whereIn('sku', $skus)->delete();
        foreach (array_chunk($skuRows, 500) as $chunk) {
            DB::table('master_skus')->insert($chunk);
        }
        foreach (array_chunk($stockRows, 500) as $chunk) {
            DB::table('stock_actives')->insert($chunk);
        }
        $bins = collect($stockRows)->groupBy('sku')->map(fn ($rows) => $rows->first()['bin_code']);

        // ---- Order + item ----
        $statuses = ['COMPLETED' => 45, 'SHIPPED' => 25, 'READY_TO_SHIP' => 5, 'PACKING' => 5, 'PICKING' => 3, 'CONFIRMED' => 7, 'PENDING' => 7, 'CANCELLED' => 3];
        $pool = [];
        foreach ($statuses as $status => $weight) {
            $pool = array_merge($pool, array_fill(0, $weight, $status));
        }
        $couriers = ['JNE', 'J&T', 'SICEPAT', 'SPX', 'ANTERAJA'];
        $orders = [];
        $items = [];
        $moves = [];
        $trails = [];
        $flush = function () use (&$orders, &$items, &$moves, &$trails) {
            foreach ([['orders', &$orders], ['order_items', &$items], ['stock_movements', &$moves], ['audit_trails', &$trails]] as [$table, &$rows]) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                $rows = [];
            }
        };

        for ($i = 1; $i <= $orderCount; $i++) {
            $status = $pool[array_rand($pool)];
            $created = $now->copy()->subMinutes(random_int(10, 60 * 24 * 90));
            $orderId = (string) Str::uuid();
            $shipped = in_array($status, ['SHIPPED', 'COMPLETED'], true);
            $picked = $shipped || in_array($status, ['PACKING', 'READY_TO_SHIP'], true);
            $staff = $warehouseUsers[array_rand($warehouseUsers)];
            $courier = $couriers[array_rand($couriers)];
            $orders[] = [
                'id' => $orderId, 'order_number' => sprintf('LT-%s-%05d', $created->format('ymd'), $i), 'customer_name' => "Pembeli Uji {$i}",
                'customer_phone' => '0812' . random_int(10000000, 99999999), 'shipping_address' => "Jl. Uji No. {$i}, Bandung",
                'courier' => $courier, 'tracking_number' => $shipped || $status === 'READY_TO_SHIP' ? strtoupper(Str::random(12)) : null,
                'status' => $status, 'created_by' => $users['ADMIN_GUDANG'][0], 'picked_by' => $picked ? $staff : null, 'packed_by' => $shipped ? $staff : null,
                'picked_at' => $picked ? $created->copy()->addHours(2) : null, 'packed_at' => $shipped ? $created->copy()->addHours(3) : null,
                'shipped_at' => $shipped ? $created->copy()->addHours(6) : null, 'ship_by' => $created->copy()->endOfDay(),
                'created_at' => $created, 'updated_at' => $created,
            ];
            foreach ((array) array_rand(array_flip($skus), random_int(1, 3)) as $sku) {
                $qty = random_int(1, 3);
                $items[] = [
                    'id' => (string) Str::uuid(), 'order_id' => $orderId, 'sku' => $sku, 'bin_code' => $bins[$sku], 'qty_ordered' => $qty,
                    'qty_picked' => $picked ? $qty : 0, 'qty_packed' => $shipped ? $qty : 0, 'price' => 150000, 'created_at' => $created, 'updated_at' => $created,
                ];
                if ($picked) {
                    $moves[] = ['user_id' => $staff, 'sku' => $sku, 'bin_code' => $bins[$sku], 'type' => 'OUTBOUND', 'qty' => $qty, 'qty_before' => 100, 'qty_after' => 100 - $qty, 'reference_no' => end($orders)['order_number'], 'reference_type' => 'ORDER', 'created_at' => $created->copy()->addHours(2), 'updated_at' => $created];
                }
            }
            if ($picked) {
                $trails[] = ['user_id' => $staff, 'action' => 'PICK', 'module' => 'PICKING', 'new_values' => json_encode(['order' => end($orders)['order_number']]), 'ip_address' => '127.0.0.1', 'created_at' => $created->copy()->addHours(2), 'updated_at' => $created];
            }
            if (count($orders) >= 500) {
                $flush();
            }
        }
        $flush();

        // ---- Barang masuk (inbound) ----
        for ($i = 1; $i <= (int) ($orderCount / 10); $i++) {
            $sku = $skus[array_rand($skus)];
            $at = $now->copy()->subMinutes(random_int(10, 60 * 24 * 90));
            $moves[] = ['user_id' => $warehouseUsers[array_rand($warehouseUsers)], 'sku' => $sku, 'bin_code' => $bins[$sku], 'type' => 'INBOUND', 'qty' => 24, 'qty_before' => 10, 'qty_after' => 34, 'reference_no' => sprintf('LT-RCV-%05d', $i), 'reference_type' => 'INBOUND', 'created_at' => $at, 'updated_at' => $at];
        }
        $flush();

        $this->command?->info("LoadTestSeeder: {$orderCount} order, " . count($skus) . ' SKU, ' . count($stockRows) . ' baris stok. Login: loadtest-owner@jersic.test / ' . self::PASSWORD);
    }
}
