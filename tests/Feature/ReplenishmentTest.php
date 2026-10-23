<?php

namespace Tests\Feature;

use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Isi ulang rak: saran memindahkan stok cadangan ke rak yang hampir kosong.
 */
class ReplenishmentTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    public function test_suggests_refill_from_reserve_bins_and_prefills_transfer(): void
    {
        $a = WarehouseRack::create(['code' => 'A']);
        $b = WarehouseRack::create(['code' => 'B']);
        WarehouseRack::create(['code' => 'G']);
        foreach (['JSC-LOW-L', 'JSC-OK-L', 'JSC-OUT-L', 'JSC-FULL-L', 'JSC-TWO-L'] as $sku) {
            $this->makeSku($sku);
        }
        $assign = fn ($rack, $sku, $cap, $bin = null) => DB::table('rack_products')->insert([
            'warehouse_rack_id' => $rack->id, 'sku' => $sku, 'max_capacity' => $cap, 'current_qty' => 0, 'bin_location' => $bin,
        ]);

        // 2/20 di rak A, cadangan 30 di gudang (G) dan 5 di DEFAULT-01
        $assign($a, 'JSC-LOW-L', 20, 'A-01-02');
        $this->putStock('JSC-LOW-L', 'A-01-02', 2);
        $this->putStock('JSC-LOW-L', 'G-01-01', 30);
        $this->putStock('JSC-LOW-L', 'DEFAULT-01', 5);
        // 15/20: masih cukup
        $assign($a, 'JSC-OK-L', 20);
        $this->putStock('JSC-OK-L', 'A-02-01', 15);
        $this->putStock('JSC-OK-L', 'G-01-01', 9);
        // habis di mana-mana
        $assign($b, 'JSC-OUT-L', 10);
        // melebihi kapasitas
        $assign($b, 'JSC-FULL-L', 10, 'B-01-01');
        $this->putStock('JSC-FULL-L', 'B-01-01', 14);
        // ditetapkan ke dua rak: rak lain tidak dikuras sebagai cadangan
        $assign($a, 'JSC-TWO-L', 10);
        $assign($b, 'JSC-TWO-L', 10);
        $this->putStock('JSC-TWO-L', 'B-03-01', 8);

        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->get(route('gudang.tugas'))->assertSee('SKU di rak hampir habis');

        $page = $this->get(route('gudang.replenishment'))->assertOk();
        $refill = $page->viewData('refill');
        $this->assertSame(['JSC-LOW-L'], $refill->pluck('sku')->all());
        $this->assertSame(['G-01-01', 18, 'A-01-02', 10], [$refill[0]->source->bin, $refill[0]->suggest, $refill[0]->target, $refill[0]->percent]);
        $this->assertSame(['JSC-OUT-L'], $page->viewData('empty')->pluck('sku')->all());
        $this->assertSame(['JSC-FULL-L'], $page->viewData('over')->pluck('sku')->all());

        $this->assertSame(['JSC-LOW-L', 'JSC-OK-L'], $this->get(route('gudang.replenishment', ['batas' => 75]))->viewData('refill')->pluck('sku')->all());

        // Tombol Pindahkan membuka Transfer Stok yang terisi, lalu kembali ke Isi Ulang Rak
        $transfer = $this->get(route('gudang.transfer', ['dari' => 'g-01-01', 'sku' => 'JSC-LOW-L', 'jumlah' => 18, 'ke' => 'A-01-02']))->assertOk();
        $this->assertSame(['from' => 'G-01-01', 'sku' => 'JSC-LOW-L', 'qty' => 18, 'to' => 'A-01-02'], $transfer->viewData('prefill'));
        $this->post(route('gudang.transfer.store'), [
            'bin_from' => 'G-01-01', 'bin_to' => 'A-01-02', 'kembali' => 'isi-ulang',
            'items' => [['sku' => 'JSC-LOW-L', 'qty' => 18]],
        ])->assertRedirect(route('gudang.replenishment'));
        $this->assertSame(20, $this->stockQty('JSC-LOW-L', 'A-01-02'));
        $this->assertTrue($this->get(route('gudang.replenishment'))->viewData('refill')->isEmpty());
    }
}
