<?php

namespace Tests\Feature;

use App\Models\MissingStockReport;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackingSession;
use App\Models\PickingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Satu item order diambil dari beberapa rak: dipecah saat sesi dibuat jika tidak ada rak yang cukup,
 * atau saat rak pertama habis di tengah jalan.
 */
class MultiBinPickingTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function startedPicking(string $sku, int $qty): PickingSession
    {
        $order = Order::create(['order_number' => 'ORD-MULTI', 'customer_name' => 'Budi', 'shipping_address' => 'Bandung', 'courier' => 'JNE', 'status' => 'CONFIRMED']);
        OrderItem::create(['order_id' => $order->id, 'sku' => $sku, 'qty_ordered' => $qty, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 1000]);

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));

        return $picking;
    }

    private function lines(PickingSession $picking): array
    {
        return $picking->items()->orderBy('bin_code')->get()->map(fn ($row) => [$row->bin_code, $row->qty_ordered, $row->qty_picked])->all();
    }

    public function test_item_is_split_across_bins_when_no_single_bin_is_enough(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 3);
        $this->putStock('JSC-A-L', 'B-01-01', 4);

        $picking = $this->startedPicking('JSC-A-L', 5);
        $this->assertSame([['A-01-01', 1, 0], ['B-01-01', 4, 0]], $this->lines($picking));
        $this->get(route('gudang.picking.show', $picking->id))->assertOk()->assertSee('A-01-01')->assertSee('B-01-01');

        $scan = route('gudang.picking.scan', $picking->id);
        foreach (['B-01-01', 'B-01-01', 'A-01-01', 'B-01-01', 'B-01-01'] as $bin) {
            $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => $bin])->assertOk()->assertJson(['active_bin' => $bin]);
        }

        $this->assertSame([['A-01-01', 1, 1], ['B-01-01', 4, 4]], $this->lines($picking));
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(0, $this->stockQty('JSC-A-L', 'B-01-01'));
        $this->assertSame('PACKING', $picking->order->fresh()->status);
        $this->assertSame(5, $picking->order->items()->value('qty_picked'));

        // Packing tetap satu baris per item order
        $this->post(route('gudang.packing.store'), ['order_id' => $picking->order_id]);
        $packing = PackingSession::where('order_id', $picking->order_id)->firstOrFail();
        $this->assertSame([5], $packing->items()->pluck('qty_ordered')->all());
        foreach (range(1, 5) as $i) {
            $this->postJson(route('gudang.packing.scan', $packing->id), ['code' => 'JSC-A-L'])->assertOk();
        }
        $this->assertSame('COMPLETED', $packing->fresh()->status);
    }

    public function test_remaining_qty_moves_to_another_bin_when_first_bin_runs_out(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->putStock('JSC-A-L', 'B-01-01', 3);

        $picking = $this->startedPicking('JSC-A-L', 4);
        $item = $picking->items()->firstOrFail();
        $this->assertSame('A-01-01', $item->bin_code);

        $scan = route('gudang.picking.scan', $picking->id);
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertOk();
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertOk();

        // Sisanya tidak ada di A-01-01: yang sudah diambil tetap, sisanya diarahkan ke B-01-01
        $this->post(route('gudang.picking.missing', [$picking->id, $item->id]))
            ->assertSessionHas('success', 'JSC-A-L di A-01-01 habis. Ambil sisanya (2 pcs) di rak B-01-01.');
        $this->assertSame([['A-01-01', 2, 2], ['B-01-01', 2, 0]], $this->lines($picking));
        $this->assertSame(3, MissingStockReport::pending()->where('bin_code', 'A-01-01')->value('qty'));
        $this->assertSame('PICKING', $picking->order->fresh()->status);

        // Tanpa scan lokasi, sisa diambil dari baris B-01-01
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()->assertJson(['active_bin' => 'B-01-01']);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()->assertJson(['completed' => true]);
        $this->assertSame(1, $this->stockQty('JSC-A-L', 'B-01-01'));
        $this->assertSame(4, $picking->order->items()->value('qty_picked'));

        // Ketuk -1 mengembalikan barang ke rak barisnya sendiri
        $lineB = $picking->items()->where('bin_code', 'B-01-01')->firstOrFail();
        $this->postJson(route('gudang.picking.tap', [$picking->id, $lineB->id]), ['delta' => -1])->assertOk();
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'B-01-01'));
        $this->assertSame(3, $picking->order->items()->value('qty_picked'));
        $this->assertSame('PICKING', $picking->order->fresh()->status);
    }

    public function test_bin_that_runs_out_during_scan_switches_to_bin_with_stock(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 3);
        $this->putStock('JSC-A-L', 'B-01-01', 2);

        $picking = $this->startedPicking('JSC-A-L', 3);
        $scan = route('gudang.picking.scan', $picking->id);
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertOk();

        // Stok A-01-01 terpakai di tempat lain (mis. dipindah), lalu scan berikutnya tanpa lokasi
        \App\Models\StockActive::where('sku', 'JSC-A-L')->where('bin_code', 'A-01-01')->update(['qty' => 0]);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()
            ->assertJson(['active_bin' => 'B-01-01'])
            ->assertJsonPath('warning', fn ($w) => str_contains($w, 'Rak A-01-01 sudah habis'));
        $this->assertSame([['A-01-01', 1, 1], ['B-01-01', 2, 1]], $this->lines($picking));
    }

    public function test_packing_problem_on_split_item_returns_last_picked_piece(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 1);
        $this->putStock('JSC-A-L', 'B-01-01', 1);

        $picking = $this->startedPicking('JSC-A-L', 2);
        $scan = route('gudang.picking.scan', $picking->id);
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertOk();
        $this->travel(1)->seconds();
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'B-01-01'])->assertOk()->assertJson(['completed' => true]);

        $this->post(route('gudang.packing.store'), ['order_id' => $picking->order_id]);
        $packing = PackingSession::where('order_id', $picking->order_id)->firstOrFail();
        $this->postJson(route('gudang.packing.scan', $packing->id), ['code' => 'JSC-A-L'])->assertOk();
        $packItem = $packing->items()->firstOrFail();

        // Salah barang: 1 pcs kembali ke rak yang terakhir diambil (B-01-01)
        $this->post(route('gudang.packing.problem', [$packing->id, $packItem->id]), ['reason' => 'SALAH'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'di rak B-01-01'));
        $this->assertSame(1, $this->stockQty('JSC-A-L', 'B-01-01'));
        $this->assertSame([['A-01-01', 1, 1], ['B-01-01', 1, 0]], $this->lines($picking));
        $this->assertSame(1, $packItem->fresh()->qty_packed);
        $this->assertSame(1, $picking->order->items()->value('qty_picked'));
        $this->assertSame('PICKING', $picking->order->fresh()->status);
    }
}
