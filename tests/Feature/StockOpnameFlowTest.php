<?php

namespace Tests\Feature;

use App\Models\StockOpname;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

class StockOpnameFlowTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    public function test_completed_opname_adjusts_system_stock_to_physical_count(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $warehouse = $this->makeWarehouse();
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-A-XL');
        $this->putStock('JSC-A-L', 'A1-01', 10);
        $this->putStock('JSC-A-XL', 'A1-02', 5);

        $this->actingAs($staff)->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id])
            ->assertSessionHas('success');
        $opname = StockOpname::with('items')->firstOrFail();
        $this->assertCount(2, $opname->items);

        $this->post(route('gudang.opname.start', $opname->id))->assertSessionHas('success');
        $this->get(route('gudang.opname.show', $opname->id))->assertOk();

        $items = $opname->items->keyBy('sku');
        $this->post(route('gudang.opname.update', $opname->id), [
            'items' => [
                ['id' => $items['JSC-A-L']->id, 'physical_qty' => 8],
                ['id' => $items['JSC-A-XL']->id, 'physical_qty' => 5],
            ],
        ])->assertSessionHas('success');

        $this->post(route('gudang.opname.complete', $opname->id))->assertSessionHas('success');

        $this->assertSame('COMPLETED', $opname->fresh()->status);
        $this->assertSame(8, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->assertSame(5, $this->stockQty('JSC-A-XL', 'A1-02'));
        $this->assertDatabaseHas('stock_movements', ['sku' => 'JSC-A-L', 'type' => 'ADJUSTMENT', 'qty' => 2, 'qty_after' => 8]);
        $this->assertDatabaseMissing('stock_movements', ['sku' => 'JSC-A-XL', 'type' => 'ADJUSTMENT']);
    }

    public function test_opname_cannot_complete_until_every_item_is_counted(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $warehouse = $this->makeWarehouse();
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 10);

        $this->actingAs($staff)->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id]);
        $opname = StockOpname::firstOrFail();
        $this->post(route('gudang.opname.start', $opname->id));

        $this->post(route('gudang.opname.complete', $opname->id))->assertSessionHas('error');
        $this->assertSame('IN_PROGRESS', $opname->fresh()->status);
        $this->assertSame(10, $this->stockQty('JSC-A-L', 'A1-01'));
    }
}
