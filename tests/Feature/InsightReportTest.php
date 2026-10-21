<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Laporan lanjutan: kinerja picker/packer, barang lambat laku, akurasi opname, nilai stok.
 */
class InsightReportTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number): Order
    {
        return Order::create(['order_number' => $number, 'customer_name' => 'Budi', 'status' => 'SHIPPED']);
    }

    private function finishedPicking(User $user, int $pcs, int $minutes, $completedAt = null): void
    {
        $completedAt ??= now();
        $session = PickingSession::create([
            'order_id' => $this->order('ORD-' . uniqid())->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'started_at' => $completedAt->copy()->subMinutes($minutes),
            'completed_at' => $completedAt,
        ]);
        PickingSessionItem::create([
            'picking_session_id' => $session->id, 'sku' => 'JSC-A-L', 'bin_code' => 'A-01-01',
            'qty_ordered' => $pcs, 'qty_picked' => $pcs, 'status' => 'COMPLETED',
        ]);
    }

    public function test_performance_report_groups_finished_sessions_per_person(): void
    {
        $owner = $this->userWithRole('OWNER');
        $andi = User::factory()->role('STAFF_GUDANG')->create(['name' => 'Andi Picker']);
        $sari = User::factory()->role('STAFF_GUDANG')->create(['name' => 'Sari Packer']);
        $this->makeSku('JSC-A-L');

        $this->finishedPicking($andi, 3, 10);
        $this->finishedPicking($andi, 5, 20);
        $this->finishedPicking($andi, 9, 5, now()->subDays(60));   // di luar periode 30 hari

        $packing = PackingSession::create([
            'order_id' => $this->order('ORD-PACK')->id, 'user_id' => $sari->id, 'status' => 'COMPLETED',
            'started_at' => now()->subMinutes(4), 'completed_at' => now(),
        ]);
        PackingSessionItem::create([
            'packing_session_id' => $packing->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 2, 'qty_packed' => 2, 'status' => 'COMPLETED',
        ]);

        $response = $this->actingAs($owner)->get(route('reports.performance'))->assertOk()
            ->assertSee('Andi Picker')->assertSee('Sari Packer')->assertSee('15 menit');

        $picking = $response->viewData('picking');
        $this->assertCount(1, $picking);
        $this->assertSame(['name' => 'Andi Picker', 'orders' => 2, 'pcs' => 8, 'avg_minutes' => 15.0], $picking[0]);
        $this->assertSame(2, $response->viewData('packing')[0]['pcs']);

        // Periode diperluas: sesi lama ikut terhitung
        $picking = $this->get(route('reports.performance', ['start_date' => now()->subDays(90)->toDateString()]))->viewData('picking');
        $this->assertSame(17, $picking[0]['pcs']);
    }

    public function test_slow_moving_lists_stock_that_has_not_gone_out(): void
    {
        $owner = $this->userWithRole('OWNER');
        foreach (['JSC-LAKU-L', 'JSC-DIAM-L', 'JSC-LAMA-L', 'JSC-BARU-L'] as $sku) {
            $this->makeSku($sku, 100000);
        }

        // Laku minggu lalu → tidak muncul
        $this->stockSince('JSC-LAKU-L', 10, 200);
        $this->movement('JSC-LAKU-L', 'OUTBOUND', 7);
        // Terakhir keluar 100 hari lalu → muncul
        $this->stockSince('JSC-DIAM-L', 4, 200);
        $this->movement('JSC-DIAM-L', 'OUTBOUND', 100);
        // Belum pernah keluar, stok sejak 120 hari lalu → muncul
        $this->stockSince('JSC-LAMA-L', 6, 120);
        // Baru masuk 5 hari lalu → belum dihitung lambat
        $this->stockSince('JSC-BARU-L', 20, 5);

        $response = $this->actingAs($owner)->get(route('reports.slow-moving'))->assertOk()
            ->assertSee('JSC-DIAM-L')->assertSee('JSC-LAMA-L')->assertSee('Belum pernah')
            ->assertDontSee('JSC-LAKU-L')->assertDontSee('JSC-BARU-L');

        $this->assertSame(['skus' => 2, 'qty' => 10, 'value' => 1000000], $response->viewData('summary'));
        $this->assertSame('JSC-LAMA-L', $response->viewData('items')->first()['sku']);

        // Batas 180 hari: tidak ada yang lolos
        $this->get(route('reports.slow-moving', ['hari' => 180]))->assertSee('Tidak ada SKU yang diam');
    }

    public function test_opname_accuracy_counts_matching_locations(): void
    {
        $owner = $this->userWithRole('OWNER');
        $warehouse = $this->makeWarehouse();

        $opname = StockOpname::create([
            'warehouse_id' => $warehouse->id, 'status' => 'COMPLETED', 'created_by' => $owner->id, 'completed_at' => now(),
        ]);
        foreach ([['JSC-A-L', 10, 10], ['JSC-B-L', 5, 3], ['JSC-C-L', 4, 4], ['JSC-D-L', 2, 3]] as $i => [$sku, $system, $physical]) {
            StockOpnameItem::create([
                'stock_opname_id' => $opname->id, 'sku' => $sku, 'bin_code' => 'A-01-0' . $i,
                'system_qty' => $system, 'physical_qty' => $physical, 'difference' => $physical - $system,
            ]);
        }
        // Opname yang belum selesai tidak ikut
        StockOpname::create(['warehouse_id' => $warehouse->id, 'status' => 'IN_PROGRESS', 'created_by' => $owner->id]);

        $response = $this->actingAs($owner)->get(route('reports.opname-accuracy'))->assertOk()
            ->assertSee($opname->opname_number)->assertSee('50%')->assertSee('JSC-B-L');

        $this->assertSame(['opnames' => 1, 'counted' => 4, 'accuracy' => 50.0, 'plus' => 1, 'minus' => 2], $response->viewData('summary'));
        $this->assertSame(['JSC-B-L', 'JSC-D-L'], $response->viewData('problemSkus')->pluck('sku')->all());
    }

    public function test_stock_value_per_category_and_access(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->makeSku('JSC-A-L', 100000);
        $kids = $this->makeSku('JSC-K-S', 50000);
        $kids->update(['size_category' => 'KIDS', 'product_name' => 'CLUB KIDS']);
        $this->putStock('JSC-A-L', 'A-01-01', 3);
        $this->putStock('JSC-A-L', 'A-01-02', 2);
        $this->putStock('JSC-K-S', 'B-01-01', 4);

        $response = $this->actingAs($owner)->get(route('reports.stock-value'))->assertOk()
            ->assertSee('Rp 700.000');

        $this->assertSame(['skus' => 2, 'qty' => 9, 'value' => 700000], $response->viewData('total'));
        $this->assertSame(['DEWASA', 'KIDS'], $response->viewData('categories')->pluck('name')->all());

        $this->get(route('reports.index'))->assertSee('Barang Lambat Laku')->assertSee('Nilai Stok');

        // Staf gudang tidak bisa membuka laporan
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        foreach (['reports.performance', 'reports.slow-moving', 'reports.opname-accuracy', 'reports.stock-value'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->actingAs($this->userWithRole('SUPERVISOR'))->get(route('reports.performance'))->assertOk();
    }

    private function stockSince(string $sku, int $qty, int $daysAgo): void
    {
        $stock = $this->putStock($sku, 'A-01-01', $qty);
        StockActive::whereKey($stock->getKey())->update(['created_at' => now()->subDays($daysAgo)]);
    }

    private function movement(string $sku, string $type, int $daysAgo): void
    {
        $movement = StockMovement::create(['sku' => $sku, 'bin_code' => 'A-01-01', 'type' => $type, 'qty' => 1]);
        StockMovement::whereKey($movement->id)->update(['created_at' => now()->subDays($daysAgo)]);
    }
}
