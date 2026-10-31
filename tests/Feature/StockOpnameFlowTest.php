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

        // Staf mengirim untuk disetujui: stok belum berubah, rak tetap terkunci
        $this->post(route('gudang.opname.complete', $opname->id))->assertSessionHas('success', fn ($m) => str_contains($m, 'dikirim untuk disetujui'));
        $this->assertSame('SUBMITTED', $opname->fresh()->status);
        $this->assertSame($staff->id, $opname->fresh()->submitted_by);
        $this->assertSame(10, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id])->assertSessionHas('error');

        // Staf tidak bisa menyetujui sendiri dan tidak bisa membuka halaman persetujuan
        $this->post(route('gudang.opname.approve', $opname->id))->assertForbidden();
        $this->get(route('gudang.stock-approvals'))->assertForbidden();

        // Supervisor mengembalikan dengan alasan: opname dihitung ulang, stok tetap
        $supervisor = $this->userWithRole('SUPERVISOR');
        $this->actingAs($supervisor)->get(route('gudang.stock-approvals'))->assertOk()->assertSee($opname->opname_number);
        $this->post(route('gudang.opname.reject', $opname->id))->assertSessionHasErrors('reason');
        $this->post(route('gudang.opname.reject', $opname->id), ['reason' => 'Hitung ulang rak A1'])->assertSessionHas('success');
        $this->assertSame('IN_PROGRESS', $opname->fresh()->status);
        $this->assertSame(10, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->actingAs($staff)->get(route('gudang.opname.show', $opname->id))->assertSee('Hitung ulang rak A1');

        // Dikirim lagi lalu disetujui: stok disesuaikan
        $this->post(route('gudang.opname.complete', $opname->id))->assertSessionHas('success');
        $this->actingAs($supervisor)->get(route('dashboard.gudang'))->assertSee('Penyesuaian stok menunggu persetujuan');
        $this->post(route('gudang.opname.approve', $opname->id))->assertSessionHas('success');
        $this->post(route('gudang.opname.approve', $opname->id))->assertSessionHas('error');

        $this->assertSame('COMPLETED', $opname->fresh()->status);
        $this->assertSame($supervisor->id, $opname->fresh()->approved_by);
        $this->assertNull($opname->fresh()->rejection_note);
        $this->assertSame(8, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->assertSame(5, $this->stockQty('JSC-A-XL', 'A1-02'));
        $this->assertDatabaseHas('stock_movements', ['sku' => 'JSC-A-L', 'type' => 'ADJUSTMENT', 'qty' => 2, 'qty_after' => 8]);
        $this->assertDatabaseMissing('stock_movements', ['sku' => 'JSC-A-XL', 'type' => 'ADJUSTMENT']);
    }

    public function test_owner_completing_opname_adjusts_stock_directly(): void
    {
        $owner = $this->userWithRole('OWNER');
        $warehouse = $this->makeWarehouse();
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 10);

        $this->actingAs($owner)->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id]);
        $opname = StockOpname::with('items')->firstOrFail();
        $this->post(route('gudang.opname.start', $opname->id));
        $this->post(route('gudang.opname.update', $opname->id), ['items' => [['id' => $opname->items->first()->id, 'physical_qty' => 12]]]);

        $this->post(route('gudang.opname.complete', $opname->id))->assertSessionHas('success');
        $this->assertSame('COMPLETED', $opname->fresh()->status);
        $this->assertSame($owner->id, $opname->fresh()->approved_by);
        $this->assertSame(12, $this->stockQty('JSC-A-L', 'A1-01'));
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
        $this->assertSame(0, $this->stockQty('JSC-X-L', 'A-01-02'));
        $this->actingAs($this->userWithRole('OWNER'))->post(route('gudang.opname.approve', $opname->id))->assertSessionHas('success');

        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(2, $this->stockQty('JSC-X-L', 'A-01-02'));
    }

    public function test_opname_can_count_one_product_or_some_products_only(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $warehouse = $this->makeWarehouse();
        \App\Models\WarehouseRack::create(['code' => 'A']);
        \App\Models\WarehouseRack::create(['code' => 'B']);
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-A-XL');
        $this->makeSku('JSC-C-M');
        $this->putStock('JSC-A-L', 'A-01-01', 10);
        $this->putStock('JSC-A-L', 'B-01-01', 2);
        $this->putStock('JSC-A-XL', 'A-01-02', 5);
        $this->putStock('JSC-C-M', 'B-01-02', 7);

        $this->actingAs($staff)->get(route('gudang.opname.create'))->assertOk()
            ->assertSee('Satu produk')->assertSee('Beberapa produk')->assertSee('Semua produk');

        // Satu produk wajib dipilih, dan hanya satu
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'scope' => 'ONE'])->assertSessionHas('error');
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'scope' => 'ONE', 'skus' => ['JSC-A-L', 'JSC-C-M']])->assertSessionHas('error');
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'scope' => 'ONE', 'skus' => ['TIDAK-ADA']])->assertSessionHas('error');

        // Satu produk di semua rak: hanya lokasi produk itu yang dihitung
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'scope' => 'ONE', 'skus' => ['jsc-a-l — Jersey L']])
            ->assertSessionHas('success');
        $one = StockOpname::with('items')->firstOrFail();
        $this->assertSame('JSC-A-L', $one->sku_codes);
        $this->assertSame([['JSC-A-L', 'A-01-01'], ['JSC-A-L', 'B-01-01']], $one->items->sortBy('bin_code')->map(fn ($i) => [$i->sku, $i->bin_code])->values()->all());
        $this->assertSame('Produk JSC-A-L · semua rak', $one->scope_label);

        // Produk yang sama tidak boleh dihitung dua kali, termasuk lewat opname rak atau seluruh gudang
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'scope' => 'SOME', 'skus' => ['JSC-A-L', 'JSC-A-XL']])->assertSessionHas('error');
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'racks' => ['B']])->assertSessionHas('error');
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id])->assertSessionHas('error');

        // Rak tidak dikunci untuk produk lain: beberapa produk lain di rak A dan B masih boleh
        $this->get(route('gudang.opname.create'))->assertDontSee('sedang diopname')->assertSee($one->opname_number);
        $this->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id, 'scope' => 'SOME', 'skus' => ['JSC-A-XL', 'JSC-C-M'], 'racks' => ['A']])
            ->assertSessionHas('success');
        $some = StockOpname::with('items')->where('id', '!=', $one->id)->firstOrFail();
        $this->assertSame(['JSC-A-XL'], $some->items->pluck('sku')->all());
        $this->assertSame('2 produk (JSC-A-XL, JSC-C-M) · Rak A', $some->scope_label);

        // Barang lain yang ditemukan tidak boleh masuk opname satu produk
        $this->post(route('gudang.opname.start', $one->id));
        $this->post(route('gudang.opname.add-item', $one->id), ['sku' => 'JSC-C-M', 'bin_code' => 'A-01-03'])->assertSessionHas('error');
        $this->post(route('gudang.opname.add-item', $one->id), ['sku' => 'JSC-A-L', 'bin_code' => 'A-01-03'])->assertSessionHas('success');

        // Persetujuan tetap berlaku: staf mengirim, stok baru berubah setelah disetujui
        $items = $one->items()->get();
        $this->post(route('gudang.opname.update', $one->id), ['items' => $items->map(fn ($i) => ['id' => $i->id, 'physical_qty' => $i->bin_code === 'A-01-01' ? 9 : $i->system_qty])->all()]);
        $this->post(route('gudang.opname.complete', $one->id))->assertSessionHas('success', fn ($m) => str_contains($m, 'dikirim untuk disetujui'));
        $this->assertSame(10, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->actingAs($this->userWithRole('SUPERVISOR'))->get(route('gudang.stock-approvals'))->assertSee('Produk JSC-A-L');
        $this->post(route('gudang.opname.approve', $one->id))->assertSessionHas('success');
        $this->assertSame(9, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(7, $this->stockQty('JSC-C-M', 'B-01-02'));
    }
}
