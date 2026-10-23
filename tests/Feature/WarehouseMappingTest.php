<?php

namespace Tests\Feature;

use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

class WarehouseMappingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWarehouseData;

    public function test_bin_code_maps_to_rack_code(): void
    {
        $this->assertSame('A', WarehouseRack::codeFromBin('A-01-01'));
        $this->assertSame('A', WarehouseRack::codeFromBin('rak-a-01'));
        $this->assertSame('B', WarehouseRack::codeFromBin('B'));
        $this->assertNull(WarehouseRack::codeFromBin(''));
        $this->assertNull(WarehouseRack::codeFromBin(null));
    }

    public function test_rack_map_shows_real_stock_from_bins(): void
    {
        WarehouseRack::create(['code' => 'A', 'position_x' => 1, 'position_y' => 1]);
        WarehouseRack::create(['code' => 'B', 'position_x' => 2, 'position_y' => 1]);

        $this->makeSku('JSC-TEST-V1-L');
        $this->makeSku('JSC-TEST-V1-XL');
        $this->putStock('JSC-TEST-V1-L', 'A-01-01', 7);
        $this->putStock('JSC-TEST-V1-L', 'A-02-01', 3);
        $this->putStock('JSC-TEST-V1-XL', 'B-01-01', 5);

        $user = $this->userWithRole('STAFF_GUDANG');

        $this->actingAs($user)->get(route('gudang.mapping'))
            ->assertOk()
            ->assertSee('1 SKU · 10 pcs')
            ->assertSee('1 SKU · 5 pcs');

        $response = $this->actingAs($user)->get(route('gudang.mapping.show', 'A'))->assertOk();
        $this->assertSame(1, $response->viewData('totalSku'));
        $this->assertSame(10, $response->viewData('totalQty'));

        $row = $response->viewData('rackProducts')->first();
        $this->assertSame('JSC-TEST-V1-L', $row->sku);
        $this->assertSame('A-01-01, A-02-01', $row->bin_location);
    }

    public function test_assigning_sku_sets_capacity_but_not_stock(): void
    {
        $rack = WarehouseRack::create(['code' => 'C', 'position_x' => 1, 'position_y' => 1]);
        $this->makeSku('JSC-TEST-V1-L');

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->post(route('gudang.mapping.assign', 'C'), [
                'products' => [['sku' => 'JSC-TEST-V1-L', 'current_qty' => 999, 'max_capacity' => 50]],
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('rack_products', [
            'warehouse_rack_id' => $rack->id,
            'sku' => 'JSC-TEST-V1-L',
            'max_capacity' => 50,
        ]);
        $this->assertSame(0, DB::table('stock_actives')->count());

        $response = $this->get(route('gudang.mapping.show', 'C'))->assertOk();
        $row = $response->viewData('rackProducts')->first();
        $this->assertSame(0, $row->current_qty);
        $this->assertSame(50, (int) $row->max_capacity);
    }

    public function test_assigning_article_moves_stock_without_rack_into_the_rack(): void
    {
        WarehouseRack::create(['code' => 'A', 'position_x' => 1, 'position_y' => 1]);
        WarehouseRack::create(['code' => 'B', 'position_x' => 2, 'position_y' => 1]);
        $this->makeSku('JSC-GHOTIC-M');
        $this->makeSku('JSC-GHOTIC-L');
        // Stok hasil import Master SKU / form SKU belum punya rak, sebagian sudah ada di rak B
        $this->putStock('JSC-GHOTIC-M', 'DEFAULT-01', 7);
        $this->putStock('JSC-GHOTIC-M', 'GUDANG-UTAMA', 3);
        $this->putStock('JSC-GHOTIC-M', 'B-01-01', 4);
        $this->putStock('JSC-GHOTIC-L', 'DEFAULT-01', 2);
        $user = $this->userWithRole('ADMIN_GUDANG');

        $this->actingAs($user)->get(route('gudang.mapping'))->assertSee('12 pcs')->assertSee('belum punya lokasi rak');
        $skus = $this->get(route('gudang.mapping.show', 'A'))->viewData('allSkus')->keyBy('sku');
        $this->assertSame(10, $skus['JSC-GHOTIC-M']->unplaced_stock);

        // Lokasi tujuan harus bagian dari rak ini
        $this->post(route('gudang.mapping.assign', 'A'), [
            'products' => [['sku' => 'JSC-GHOTIC-M', 'max_capacity' => 50]],
            'move_unplaced' => '1',
            'bin_code' => 'B-01-01',
        ])->assertSessionHasErrors('bin_code');

        $this->post(route('gudang.mapping.assign', 'A'), [
            'products' => [['sku' => 'JSC-GHOTIC-M', 'max_capacity' => 50], ['sku' => 'JSC-GHOTIC-L', 'max_capacity' => 50]],
            'move_unplaced' => '1',
            'bin_code' => 'a-02-01',
        ])->assertSessionHas('success', '2 produk berhasil ditetapkan ke rak A. 12 pcs stok yang belum punya rak dipindah ke A-02-01.');

        $this->assertSame(10, $this->stockQty('JSC-GHOTIC-M', 'A-02-01'));
        $this->assertSame(2, $this->stockQty('JSC-GHOTIC-L', 'A-02-01'));
        $this->assertSame(4, $this->stockQty('JSC-GHOTIC-M', 'B-01-01'));   // stok di rak lain tidak disentuh
        $this->assertDatabaseMissing('stock_actives', ['bin_code' => 'DEFAULT-01']);
        $this->assertSame(3, DB::table('stock_movements')->where('reference_type', 'MAPPING')->count());

        $response = $this->get(route('gudang.mapping.show', 'A'));
        $this->assertSame(12, $response->viewData('totalQty'));
        $this->get(route('gudang.mapping'))->assertDontSee('belum punya lokasi rak');

        // Tanpa centang: hanya ditetapkan, stok tidak dipindah
        $this->putStock('JSC-GHOTIC-L', 'DEFAULT-01', 5);
        $this->post(route('gudang.mapping.assign', 'B'), ['products' => [['sku' => 'JSC-GHOTIC-L', 'max_capacity' => 10]]]);
        $this->assertSame(5, $this->stockQty('JSC-GHOTIC-L', 'DEFAULT-01'));
    }

    public function test_bulk_edit_fixes_capacity_and_moves_wrong_bins(): void
    {
        $a = WarehouseRack::create(['code' => 'A', 'position_x' => 1, 'position_y' => 1]);
        $b = WarehouseRack::create(['code' => 'B', 'position_x' => 2, 'position_y' => 1]);
        foreach (['JSC-X-M', 'JSC-X-L', 'JSC-Y-L'] as $sku) {
            $this->makeSku($sku);
        }
        $this->putStock('JSC-X-M', 'A-01-01', 4);
        $this->putStock('JSC-X-M', 'A-02-01', 1);
        $this->putStock('JSC-X-L', 'A-01-01', 3);
        DB::table('rack_products')->insert([
            ['warehouse_rack_id' => $a->id, 'sku' => 'JSC-X-L', 'max_capacity' => 10, 'current_qty' => 0, 'bin_location' => 'A-01-01'],
            ['warehouse_rack_id' => $a->id, 'sku' => 'JSC-Y-L', 'max_capacity' => 10, 'current_qty' => 0, 'bin_location' => 'A-01-01'],
        ]);

        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->get(route('gudang.mapping.show', 'A'))->assertOk()->assertSee('Edit semua')->assertSee('Hapus terpilih');

        // Bin di luar denah ditolak tanpa mengubah apa pun
        $this->post(route('gudang.mapping.bulk-update', 'A'), ['items' => [['sku' => 'JSC-X-M', 'bin_code' => 'Z-01-01']]])
            ->assertSessionHas('error');
        $this->assertSame(4, $this->stockQty('JSC-X-M', 'A-01-01'));

        $this->post(route('gudang.mapping.bulk-update', 'A'), ['items' => [
            ['sku' => 'JSC-X-M', 'max_capacity' => 30, 'bin_code' => 'a-02-01'],
            ['sku' => 'JSC-X-L', 'max_capacity' => '', 'bin_code' => 'B-01-01'],
            ['sku' => 'JSC-Y-L', 'max_capacity' => 25, 'bin_code' => ''],
        ]])->assertSessionHas('success', '3 SKU diperbarui. 7 pcs stok dipindah ke lokasi baru.');

        // Semua stok X-M di rak A pindah ke A-02-01, X-L pindah ke rak B beserta penetapannya
        $this->assertSame([0, 5], [$this->stockQty('JSC-X-M', 'A-01-01'), $this->stockQty('JSC-X-M', 'A-02-01')]);
        $this->assertSame([0, 3], [$this->stockQty('JSC-X-L', 'A-01-01'), $this->stockQty('JSC-X-L', 'B-01-01')]);
        $this->assertDatabaseHas('rack_products', ['warehouse_rack_id' => $a->id, 'sku' => 'JSC-X-M', 'max_capacity' => 30, 'bin_location' => 'A-02-01']);
        $this->assertDatabaseHas('rack_products', ['warehouse_rack_id' => $b->id, 'sku' => 'JSC-X-L', 'max_capacity' => 10, 'bin_location' => 'B-01-01']);
        $this->assertDatabaseMissing('rack_products', ['warehouse_rack_id' => $a->id, 'sku' => 'JSC-X-L']);
        $this->assertDatabaseHas('rack_products', ['warehouse_rack_id' => $a->id, 'sku' => 'JSC-Y-L', 'max_capacity' => 25]);
        $this->assertSame(2, DB::table('stock_movements')->where('type', 'TRANSFER')->where('reference_type', 'MAPPING')->count());
    }

    public function test_bulk_delete_unassigns_selected_or_all_without_touching_stock(): void
    {
        $a = WarehouseRack::create(['code' => 'A', 'position_x' => 1, 'position_y' => 1]);
        foreach (['JSC-X-M', 'JSC-X-L', 'JSC-Y-L'] as $sku) {
            $this->makeSku($sku);
            DB::table('rack_products')->insert(['warehouse_rack_id' => $a->id, 'sku' => $sku, 'max_capacity' => 10, 'current_qty' => 0]);
        }
        $this->putStock('JSC-Y-L', 'A-01-01', 2);

        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->post(route('gudang.mapping.bulk-delete', 'A'), ['skus' => ['JSC-X-M']])->assertSessionHas('success');
        $this->assertSame(['JSC-X-L', 'JSC-Y-L'], DB::table('rack_products')->orderBy('sku')->pluck('sku')->all());

        $this->post(route('gudang.mapping.bulk-delete', 'A'), ['skus' => ['JSC-X-L', 'JSC-Y-L']])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 SKU masih tampil'));
        $this->assertSame(0, DB::table('rack_products')->count());
        $this->assertSame(2, $this->stockQty('JSC-Y-L', 'A-01-01'));
    }
}
