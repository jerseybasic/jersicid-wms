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
}
