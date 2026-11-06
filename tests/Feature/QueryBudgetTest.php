<?php

namespace Tests\Feature;

use App\Models\CourierHandover;
use App\Models\StockMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\PurchaseOrder;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Penjaga N+1: jumlah query setiap halaman tidak boleh ikut naik ketika datanya bertambah.
 *
 * Data dibuat lewat alur aplikasi yang sebenarnya (order → picking → packing → serah terima, PO → inbound,
 * opname, retur, transfer), lalu setiap halaman dibuka dua kali: dengan sedikit data dan dengan data 4x lebih banyak.
 * Halaman yang query-nya ikut bertambah berarti ada query di dalam perulangan.
 *
 * Jalankan dengan QUERY_REPORT=1 untuk mencetak tabel jumlah query per halaman.
 */
class QueryBudgetTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $staff;
    private Warehouse $warehouse;
    private int $seeded = 0;

    /**
     * Query berulang yang memang jumlahnya tetap (tidak ikut jumlah baris):
     * detail order memuat nama user untuk 5 bagian riwayat (pembuat, picker, packer, serah terima, retur),
     * laporan kinerja menghitung 3 jenis aksi berbeda dengan query yang bentuknya sama.
     */
    private const ALLOWED_REPEATS = [
        'gudang.outbound.show' => ['select id, name from users where users.id in (?) and users.deleted_at is null'],
        'reports.performance' => ['select user_id, COUNT(*) as total from audit_trails where action in (?) and created_at between ? and ? group by user_id'],
    ];
    private array $repeated = [];

    public function test_page_query_count_does_not_grow_with_data(): void
    {
        $this->owner = $this->userWithRole('OWNER');
        $this->admin = $this->userWithRole('ADMIN_GUDANG');
        $this->staff = $this->userWithRole('STAFF_GUDANG');
        $this->warehouse = $this->makeWarehouse();
        $supplier = $this->makeSupplier();

        $this->seedWorld(3, $supplier->id);
        $small = $this->measureAll();

        $this->seedWorld(12, $supplier->id);
        $large = $this->measureAll();

        $grown = [];
        $rows = [];
        foreach ($small as $page => $count) {
            $after = $large[$page] ?? null;
            $rows[] = sprintf('%-60s %5d %5d%s', $page, $count, $after, $after > $count ? '  <-- naik' : '');
            if ($after > $count) {
                $grown[$page] = "{$count} → {$after}";
            }
        }

        if (getenv('QUERY_REPORT')) {
            fwrite(STDERR, "\nQuery berulang:\n" . json_encode($this->repeated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            fwrite(STDERR, "\n" . sprintf('%-60s %5s %5s', 'Halaman', '3 ord', '12 ord') . "\n" . implode("\n", $rows) . "\n");
        }

        $this->assertSame([], $this->repeated, 'Query yang sama berulang di satu halaman (N+1 per baris): ' . json_encode($this->repeated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $grown, 'Halaman ini menjalankan query di dalam perulangan (N+1): ' . json_encode($grown, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Buka setiap halaman dan hitung query-nya. Cache dashboard dikosongkan dulu supaya query-nya ikut terhitung. */
    private function measureAll(): array
    {
        $order = Order::whereIn('status', Order::SENT_STATUSES)->latest('id')->first() ?? Order::latest('id')->firstOrFail();
        $pages = [
            ['owner', 'dashboard.owner'],
            ['admin', 'dashboard.gudang'],
            ['staff', 'gudang.tugas'],
            // Daftar Master SKU memakai GROUP_CONCAT ... SEPARATOR khusus MySQL
            ...(config('database.default') === 'mysql' ? [['owner', 'master-skus.index']] : []),
            ['owner', 'suppliers.index'],
            ['owner', 'po.index'],
            ['owner', 'po.show', PurchaseOrder::latest('id')->value('id')],
            ['owner', 'po.print', PurchaseOrder::latest('id')->value('id')],
            ['owner', 'po.create'],
            ['admin', 'gudang.inbound'],
            ['admin', 'gudang.inbound.create'],
            ['admin', 'gudang.inbound.show', StockMovement::where('type', 'INBOUND')->latest('id')->value('id')],
            ['admin', 'gudang.outbound'],
            ['admin', 'gudang.outbound.create'],
            ['admin', 'gudang.outbound.show', $order->id],
            ['admin', 'gudang.retur'],
            ['admin', 'gudang.retur.create'],
            ['admin', 'gudang.retur.show', OrderReturn::latest('id')->value('id')],
            ['admin', 'gudang.picking'],
            ['admin', 'gudang.picking.create'],
            // id-nya UUID, jadi latest('id') memilih sesi acak; ambil sesi dari order yang sudah dikirim supaya bentuk halamannya selalu sama
            ['admin', 'gudang.picking.show', PickingSession::whereHas('order', fn ($q) => $q->whereIn('status', Order::SENT_STATUSES))->orderBy('id')->value('id')],
            ['admin', 'gudang.picking-batch.create'],
            ['admin', 'gudang.packing'],
            ['admin', 'gudang.packing.create'],
            ['admin', 'gudang.packing.show', PackingSession::latest('id')->value('id')],
            ['admin', 'gudang.handover'],
            ['admin', 'gudang.handover.show', CourierHandover::where('status', 'OPEN')->value('id')],
            ['admin', 'gudang.handover.print', CourierHandover::where('status', '!=', 'OPEN')->value('id')],
            ['admin', 'gudang.restock'],
            ['admin', 'gudang.transfer'],
            ['admin', 'gudang.opname'],
            ['admin', 'gudang.opname.create'],
            ['admin', 'gudang.opname.show', StockOpname::latest('id')->value('id')],
            ['owner', 'gudang.stock-approvals'],
            ['admin', 'gudang.mapping'],
            ['admin', 'gudang.mapping.show', 'R1'],
            ['admin', 'gudang.scanner'],
            ['admin', 'gudang.printer'],
            ['admin', 'gudang.replenishment'],
            ['admin', 'gudang.closing'],
            ['admin', 'gudang.label.sku', ['ref' => StockMovement::where('type', 'INBOUND')->latest('id')->value('reference_no')]],
            ['admin', 'gudang.label.bins', ['rak' => ['R1'], 'dengan_bin' => 1]],
            ['admin', 'gudang.label.shipping-bulk'],
            ['owner', 'blocked-skus.index'],
            ['owner', 'sku-aliases.index'],
            ['owner', 'reports.index'],
            ['owner', 'reports.stock'],
            ['owner', 'reports.movement'],
            ['owner', 'reports.opname'],
            ['owner', 'reports.performance'],
            ['owner', 'reports.slow-moving'],
            ['owner', 'reports.opname-accuracy'],
            ['owner', 'reports.stock-value'],
            ['owner', 'reports.shipping'],
            ['owner', 'audit-trails.index'],
            ['owner', 'users.index'],
            ['owner', 'settings.index'],
            ['admin', 'search', ['q' => 'JSC']],
        ];

        $counts = [];
        foreach ($pages as $page) {
            [$who, $name] = $page;
            $params = $page[2] ?? [];
            Cache::flush();
            $this->actingAs($this->{$who});
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get(route($name, $params));
            DB::disableQueryLog();
            $this->assertContains($response->getStatusCode(), [200], "{$name} gagal dibuka ({$response->getStatusCode()})");
            $counts["{$who} {$name}"] = count(DB::getQueryLog());
            // Query yang sama persis berulang 3x atau lebih di satu halaman = query di dalam perulangan
            foreach (collect(DB::getQueryLog())->countBy(fn ($q) => str_replace(['`', '"'], '', $q['query']))->filter(fn ($n) => $n >= 3)->except(self::ALLOWED_REPEATS[$name] ?? []) as $sql => $n) {
                $this->repeated["{$who} {$name}"][] = "{$n}x " . \Illuminate\Support\Str::limit($sql, 160);
            }
            if (getenv('QUERY_DUMP') && in_array($name, explode(',', getenv('QUERY_DUMP')), true)) {
                fwrite(STDERR, "\n== {$name}\n" . collect(DB::getQueryLog())->map(fn ($q) => round($q['time'], 2) . 'ms  ' . $q['query'])->implode("\n") . "\n");
            }
        }

        return $counts;
    }

    /** Tambah data sampai ada $target order di setiap tahap, lewat alur aplikasi yang sebenarnya */
    private function seedWorld(int $target, string $supplierId): void
    {
        for ($i = $this->seeded + 1; $i <= $target; $i++) {
            $rack = WarehouseRack::create(['code' => "R{$i}"]);
            $skuA = "JSC-{$i}-L";
            $skuB = "JSC-{$i}-XL";
            $this->makeSku($skuA);
            $this->makeSku($skuB);
            $this->putStock($skuA, "R{$i}-01-01", 50);
            $this->putStock($skuB, "R{$i}-01-02", 50);

            // PO → inbound
            $this->actingAs($this->owner)->post(route('po.store'), [
                'supplier_id' => $supplierId,
                'supplier_type' => 'SUBLIMASI',
                'expected_date' => now()->addWeek()->toDateString(),
                'items' => [['sku' => $skuA, 'qty' => 10, 'price' => 50000], ['sku' => $skuB, 'qty' => 5, 'price' => 50000]],
            ])->assertSessionHas('success');
            $po = PurchaseOrder::whereHas('items', fn ($q) => $q->where('sku', $skuA))->firstOrFail();
            $po->update(['status' => 'APPROVED']);
            $this->actingAs($this->admin)->post(route('gudang.inbound.store'), [
                'receipt_number' => "SJ-{$i}",
                'supplier_name' => $po->supplier_name,
                'received_date' => now()->toDateString(),
                'warehouse_id' => $this->warehouse->id,
                'po_id' => $po->id,
                'items' => [['sku' => $skuA, 'bin_code' => "R{$i}-01-01", 'qty_received' => 4], ['sku' => $skuB, 'bin_code' => "R{$i}-01-02", 'qty_received' => 2]],
            ]);

            // Order lengkap sampai dikirim + satu order berhenti di setiap tahap
            $shipped = $this->orderUntil($i, 'SHIPPED', $skuA, $skuB);
            $this->orderUntil($i, 'PENDING', $skuA, $skuB);
            $this->orderUntil($i, 'CONFIRMED', $skuA, $skuB);
            $this->orderUntil($i, 'PICKING', $skuA, $skuB);
            $this->orderUntil($i, 'READY_TO_SHIP', $skuA, $skuB);

            // Retur dari order yang sudah dikirim (order-nya jadi Diretur)
            $item = $shipped->items()->firstOrFail();
            $this->post(route('gudang.retur.store'), [
                'order_id' => $shipped->id,
                'reason' => 'RUSAK',
                'items' => [$item->id => ['qty_good' => 1, 'bin_code' => "R{$i}-01-01"]],
            ])->assertSessionHasNoErrors();

            // Transfer antar bin
            $this->post(route('gudang.transfer.store'), ['bin_from' => "R{$i}-01-01", 'bin_to' => "R{$i}-02-01", 'items' => [['sku' => $skuA, 'qty' => 1]]]);

            // Opname per rak, dikirim staf untuk disetujui
            $this->actingAs($this->staff)->post(route('gudang.opname.store'), ['warehouse_id' => $this->warehouse->id, 'racks' => [$rack->code]]);
            $opname = StockOpname::where('rack_codes', $rack->code)->firstOrFail();
            $this->post(route('gudang.opname.start', $opname->id));
            $this->post(route('gudang.opname.update', $opname->id), [
                'items' => $opname->items()->get()->map(fn ($it) => ['id' => $it->id, 'physical_qty' => max(0, $it->system_qty - 1)])->all(),
            ]);
            $this->post(route('gudang.opname.complete', $opname->id));
        }

        $this->seeded = $target;
    }

    private function orderUntil(int $i, string $until, string $skuA, string $skuB): Order
    {
        $this->actingAs($this->admin)->post(route('gudang.outbound.store'), [
            'customer_name' => "Pembeli {$i} {$until}",
            'customer_phone' => '0812',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'courier' => $courier = $until === 'READY_TO_SHIP' ? 'SICEPAT' : 'JNE',
            'items' => [['sku' => $skuA, 'qty' => 1], ['sku' => $skuB, 'qty' => 1]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect(route('gudang.outbound'));
        $order = Order::where('customer_name', "Pembeli {$i} {$until}")->firstOrFail();
        if ($until === 'PENDING') {
            return $order;
        }

        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);
        if ($until === 'CONFIRMED') {
            return $order;
        }

        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));
        if ($until === 'PICKING') {
            return $order;
        }
        foreach ($picking->items as $item) {
            $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => $item->qty_ordered, 'bin_code' => $item->bin_code, 'reason' => 'tes']);
        }

        $this->post(route('gudang.packing.store'), ['order_id' => $order->id, 'courier' => $courier]);
        $packing = PackingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        foreach ($packing->items as $item) {
            $this->post(route('gudang.packing.pack', [$packing->id, $item->id]), ['qty_packed' => $item->qty_ordered, 'reason' => 'tes']);
        }
        $order->update(['tracking_number' => "{$courier}{$i}X" . substr($order->id, 0, 8)]);
        $this->post(route('gudang.handover.store'), ['courier' => $courier]);
        $handover = CourierHandover::where('status', 'OPEN')->where('courier', $courier)->firstOrFail();
        if ($until === 'READY_TO_SHIP') {
            // Masuk daftar serah terima yang masih terbuka, belum diserahkan
            $this->postJson(route('gudang.handover.scan', $handover), ['code' => $order->tracking_number]);

            return $order->fresh();
        }

        $this->postJson(route('gudang.handover.scan', $handover), ['code' => $order->tracking_number]);
        $this->post(route('gudang.handover.finish', $handover));
        $this->assertSame('SHIPPED', $order->fresh()->status);

        return $order->fresh();
    }
}
