<?php

namespace Tests\Feature;

use App\Models\MasterSku;
use App\Models\RackRowRule;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

class RackRowRulesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWarehouseData;

    private function onePiece(string $size, string $suffix = ''): MasterSku
    {
        return MasterSku::create([
            'sku' => "OP-LUFFY{$suffix}-{$size}",
            'article' => 'OP' . $suffix,
            'product_name' => 'JERSEY ANIME ONE PIECE LUFFY' . $suffix,
            'version' => 'V1',
            'size_category' => 'DEWASA',
            'size' => $size,
            'price' => 150000,
            'min_stock' => 0,
            'status' => 'AKTIF',
        ]);
    }

    public function test_rule_matches_article_or_product_name_and_exact_size(): void
    {
        $sku = (object) ['article' => 'OP', 'product_name' => 'Jersey Anime One Piece Luffy', 'size' => 'm'];

        $this->assertTrue(RackRowRule::matchesValues('one piece', 'M', $sku));
        $this->assertTrue(RackRowRule::matchesValues('OP', 'M', $sku));
        $this->assertTrue(RackRowRule::matchesValues('ONE PIECE', null, $sku));
        $this->assertFalse(RackRowRule::matchesValues('ONE PIECE', 'L', $sku));
        $this->assertFalse(RackRowRule::matchesValues('NARUTO', 'M', $sku));
        $this->assertFalse(RackRowRule::matchesValues('', '', $sku));
        $this->assertSame('A-02-01', RackRowRule::binFor('a', 2));
    }

    public function test_saving_row_rules_replaces_old_rules_and_skips_empty_rows(): void
    {
        $rack = WarehouseRack::create(['code' => 'A', 'rows_count' => 4, 'position_x' => 1, 'position_y' => 1]);
        RackRowRule::create(['warehouse_rack_id' => $rack->id, 'row_index' => 4, 'article' => 'LAMA', 'size' => 'S']);

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->post(route('gudang.mapping.row-rules', 'A'), ['rows' => [
                ['row' => 1, 'article' => ' one  piece ', 'size' => 'm'],
                ['row' => 2, 'article' => 'ONE PIECE', 'size' => 'L'],
                ['row' => 3, 'article' => '', 'size' => ''],
                ['row' => 4, 'article' => '', 'size' => ''],
            ]])
            ->assertSessionHas('success');

        $rules = $rack->rowRules()->get();
        $this->assertSame([1, 2], $rules->pluck('row_index')->all());
        $this->assertSame('ONE PIECE', $rules[0]->article);
        $this->assertSame('M', $rules[0]->size);
        $this->assertDatabaseHas('audit_trails', ['action' => 'ROW_RULES', 'module' => 'WAREHOUSE_MAPPING']);
    }

    public function test_rack_page_tags_matching_skus_with_their_row_bin(): void
    {
        $rack = WarehouseRack::create(['code' => 'A', 'rows_count' => 4, 'position_x' => 1, 'position_y' => 1]);
        RackRowRule::create(['warehouse_rack_id' => $rack->id, 'row_index' => 1, 'article' => 'ONE PIECE', 'size' => 'M']);
        RackRowRule::create(['warehouse_rack_id' => $rack->id, 'row_index' => 2, 'article' => 'ONE PIECE', 'size' => 'L']);
        $this->onePiece('M');
        $this->onePiece('L');
        $this->onePiece('XL');
        $this->makeSku('JSC-TEST-V1-M');
        $this->putStock('OP-LUFFY-L', 'A-02-01', 6);

        $response = $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->get(route('gudang.mapping.show', 'A'))
            ->assertOk()
            ->assertSee('Aturan Baris Rak')
            ->assertSee('Isi sesuai aturan baris');

        $skus = $response->viewData('allSkus')->keyBy('sku');
        $this->assertSame(1, $skus['OP-LUFFY-M']->rule_row);
        $this->assertSame('A-01-01', $skus['OP-LUFFY-M']->rule_bin);
        $this->assertSame('A-02-01', $skus['OP-LUFFY-L']->rule_bin);
        $this->assertNull($skus['OP-LUFFY-XL']->rule_row);
        $this->assertNull($skus['JSC-TEST-V1-M']->rule_row);
        $this->assertSame(['matches' => 1, 'qty' => 6], $response->viewData('rowSummary')[2]);
    }

    public function test_rack_without_rules_works_as_before(): void
    {
        WarehouseRack::create(['code' => 'B', 'position_x' => 1, 'position_y' => 1]);
        $this->makeSku('JSC-TEST-V1-L');

        $response = $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->get(route('gudang.mapping.show', 'B'))
            ->assertOk()
            ->assertDontSee('Isi sesuai aturan baris');

        $this->assertNull($response->viewData('allSkus')->first()->rule_row);
    }

    public function test_assign_puts_each_sku_and_its_unplaced_stock_in_its_own_row_bin(): void
    {
        $rack = WarehouseRack::create(['code' => 'A', 'rows_count' => 4, 'position_x' => 1, 'position_y' => 1]);
        $this->onePiece('M');
        $this->onePiece('L');
        $this->makeSku('JSC-TEST-V1-L');
        $this->putStock('OP-LUFFY-M', 'DEFAULT-01', 4);
        $this->putStock('OP-LUFFY-L', 'DEFAULT-01', 5);
        $this->putStock('JSC-TEST-V1-L', 'DEFAULT-01', 2);

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->post(route('gudang.mapping.assign', 'A'), [
                'products' => [
                    ['sku' => 'OP-LUFFY-M', 'max_capacity' => 50, 'bin_code' => 'A-01-01'],
                    ['sku' => 'OP-LUFFY-L', 'max_capacity' => 50, 'bin_code' => 'a-02-01'],
                    ['sku' => 'JSC-TEST-V1-L', 'max_capacity' => 50, 'bin_code' => ''],
                ],
                'move_unplaced' => 1,
                'bin_code' => 'A-04-01',
            ])
            ->assertSessionHas('success');

        $this->assertSame(4, $this->stockQty('OP-LUFFY-M', 'A-01-01'));
        $this->assertSame(5, $this->stockQty('OP-LUFFY-L', 'A-02-01'));
        $this->assertSame(2, $this->stockQty('JSC-TEST-V1-L', 'A-04-01'));
        $this->assertSame(0, $this->stockQty('OP-LUFFY-L', 'DEFAULT-01'));
        $this->assertSame('A-02-01', DB::table('rack_products')->where('warehouse_rack_id', $rack->id)->where('sku', 'OP-LUFFY-L')->value('bin_location'));
    }

    public function test_assign_rejects_row_bin_from_another_rack(): void
    {
        WarehouseRack::create(['code' => 'A', 'position_x' => 1, 'position_y' => 1]);
        $this->onePiece('M');

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->post(route('gudang.mapping.assign', 'A'), [
                'products' => [['sku' => 'OP-LUFFY-M', 'max_capacity' => 50, 'bin_code' => 'B-01-01']],
            ])
            ->assertSessionHasErrors('bin_code');

        $this->assertSame(0, DB::table('rack_products')->count());
    }

    public function test_inbound_suggests_row_bin_for_sku_without_stock_or_assignment(): void
    {
        $rack = WarehouseRack::create(['code' => 'A', 'rows_count' => 4, 'position_x' => 1, 'position_y' => 1]);
        RackRowRule::create(['warehouse_rack_id' => $rack->id, 'row_index' => 3, 'article' => 'ONE PIECE', 'size' => 'XL']);
        $this->onePiece('XL');
        $this->onePiece('L');
        $this->putStock('OP-LUFFY-L', 'B-01-01', 3);

        $response = $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->get(route('gudang.inbound.create'))
            ->assertOk();

        $bins = $response->viewData('suggestedBins');
        $this->assertSame('A-03-01', $bins['OP-LUFFY-XL']);
        $this->assertSame('B-01-01', $bins['OP-LUFFY-L']);
    }
}
