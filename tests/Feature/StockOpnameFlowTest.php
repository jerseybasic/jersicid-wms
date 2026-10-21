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

    public function test_opname_per_rack_counts_only_that_rack_and_blocks_overlap(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $warehouse = $this->makeWarehouse();
        \App\Models\WarehouseRack::create(['code' => 'A']);
        \App\Models\WarehouseRack::create(['code' => 'B']);
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 10);
        $this->putStock('JSC-B-L', 'B-01-01', 4);

        $this->actingAs($staff)->get(route('gudang.opname.create'))->assertOk()->assertSee('Rak A')->assertSee('Rak B');

        $response = $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'racks' => ['a']]);
        $opname = StockOpname::with('items')->firstOrFail();
        $response->assertRedirect(route('gudang.opname.show', $opname->id));
        $this->assertSame('A', $opname->rack_codes);
        $this->assertSame(['JSC-A-L'], $opname->items->pluck('sku')->all());

        // Rak A sedang dihitung: tidak boleh dibuat lagi, juga tidak boleh opname seluruh gudang
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'racks' => ['A', 'B']])->assertSessionHas('error');
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id])->assertSessionHas('error');
        $this->get(route('gudang.opname.create'))->assertSee('sedang diopname');

        // Rak B masih boleh
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'racks' => ['B']])->assertSessionHas('success');
        $this->assertSame(2, StockOpname::count());

        $this->get(route('gudang.opname'))->assertOk()->assertSee('Rak A')->assertSee($opname->opname_number);
        $this->get(route('gudang.opname.show', $opname->id))->assertOk()->assertSee('Rak A');
    }

    public function test_items_found_on_the_rack_can_be_added_and_become_stock(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $warehouse = $this->makeWarehouse();
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-X-L');
        $this->putStock('JSC-A-L', 'A-01-01', 3);

        $this->actingAs($staff)->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'racks' => ['A']]);
        $opname = StockOpname::firstOrFail();

        // Belum dimulai: belum bisa diisi atau ditambah
        $item = $opname->items()->firstOrFail();
        $this->post(route('gudang.opname.update', $opname->id), ['items' => [['id' => $item->id, 'physical_qty' => 3]]])
            ->assertSessionHas('error');
        $this->post(route('gudang.opname.add-item', $opname->id), ['sku' => 'JSC-X-L', 'bin_code' => 'A-01-02'])
            ->assertSessionHas('error');

        $this->post(route('gudang.opname.start', $opname->id));

        // Lokasi di luar rak opname ditolak, SKU tidak terdaftar ditolak
        $this->post(route('gudang.opname.add-item', $opname->id), ['sku' => 'JSC-X-L', 'bin_code' => 'B-01-01'])
            ->assertSessionHas('error');
        $this->post(route('gudang.opname.add-item', $opname->id), ['sku' => 'TIDAK-ADA', 'bin_code' => 'A-01-02'])
            ->assertSessionHasErrors('sku');

        $this->post(route('gudang.opname.add-item', $opname->id), ['sku' => 'JSC-X-L', 'bin_code' => 'a-01-02'])
            ->assertSessionHas('success');
        $found = $opname->items()->where('sku', 'JSC-X-L')->firstOrFail();
        $this->assertSame('A-01-02', $found->bin_code);
        $this->assertSame(0, $found->system_qty);

        // Item opname lain tidak bisa diubah lewat opname ini
        $other = StockOpname::create(['warehouse_id' => $warehouse->id, 'rack_codes' => 'Z', 'status' => 'IN_PROGRESS', 'created_by' => $staff->id]);
        $otherItem = \App\Models\StockOpnameItem::create(['stock_opname_id' => $other->id, 'sku' => 'JSC-A-L', 'bin_code' => 'Z-01-01', 'system_qty' => 1]);
        $this->post(route('gudang.opname.update', $opname->id), ['items' => [['id' => $otherItem->id, 'physical_qty' => 99]]])
            ->assertSessionHas('error');
        $this->assertNull($otherItem->fresh()->physical_qty);

        $this->post(route('gudang.opname.update', $opname->id), [
            'items' => [
                ['id' => $item->id, 'physical_qty' => 3],
                ['id' => $found->id, 'physical_qty' => 2],
            ],
        ])->assertSessionHas('success');
        $this->post(route('gudang.opname.complete', $opname->id))->assertSessionHas('success');

        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(2, $this->stockQty('JSC-X-L', 'A-01-02'));
    }
}
