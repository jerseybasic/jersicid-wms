<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockMovement;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Transfer stok: scan bin asal, pilih beberapa SKU sekaligus, scan bin tujuan.
 */
class StockTransferTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WarehouseRack::create(['code' => 'A']);
        WarehouseRack::create(['code' => 'B']);
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->putStock('JSC-B-L', 'A-01-01', 3);
        $this->putStock('JSC-A-L', 'B-02-01', 1);
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
    }

    public function test_lookup_shows_bin_contents_or_where_a_sku_is(): void
    {
        $this->get(route('gudang.transfer'))->assertOk()->assertSee('Bin asal');

        $this->getJson(route('gudang.transfer.lookup', ['code' => 'a-01-01']))->assertOk()
            ->assertJson(['type' => 'bin', 'bin_code' => 'A-01-01'])
            ->assertJsonPath('items.0.sku', 'JSC-A-L')
            ->assertJsonPath('items.0.qty', 5)
            ->assertJsonCount(2, 'items');

        $this->getJson(route('gudang.transfer.lookup', ['code' => 'JSC-A-L']))->assertOk()
            ->assertJson(['type' => 'sku'])
            ->assertJsonPath('bins.0.bin_code', 'A-01-01');

        // Bin kosong di rak terdaftar boleh; kode rak saja dan kode asing ditolak
        $this->getJson(route('gudang.transfer.lookup', ['code' => 'B-05-01']))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(route('gudang.transfer.lookup', ['code' => 'B']))->assertNotFound();
        $this->getJson(route('gudang.transfer.lookup', ['code' => 'ZZ-01']))->assertNotFound();
    }

    public function test_moves_several_skus_at_once(): void
    {
        $this->post(route('gudang.transfer.store'), [
            'bin_from' => 'a-01-01',
            'bin_to' => 'b-02-01',
            'items' => [['sku' => 'JSC-A-L', 'qty' => 5], ['sku' => 'JSC-B-L', 'qty' => 2], ['sku' => 'JSC-A-L', 'qty' => 0]],
            'notes' => 'rapikan',
        ])->assertRedirect(route('gudang.transfer'))
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Berhasil memindahkan 7 pcs (2 SKU) dari A-01-01 ke B-02-01.'));

        $this->assertSame([0, 6], [$this->stockQty('JSC-A-L', 'A-01-01'), $this->stockQty('JSC-A-L', 'B-02-01')]);
        $this->assertSame([1, 2], [$this->stockQty('JSC-B-L', 'A-01-01'), $this->stockQty('JSC-B-L', 'B-02-01')]);
        $this->assertSame(1, StockMovement::where('type', 'TRANSFER')->distinct()->count('reference_no'));

        $this->get(route('gudang.transfer', ['cari' => 'JSC-B-L']))->assertOk()
            ->assertSee('B-02-01')->assertSee('JSC-B-L');
    }

    public function test_rejects_bad_destination_too_much_qty_and_stock_waiting_for_picking(): void
    {
        $this->post(route('gudang.transfer.store'), ['bin_from' => 'A-01-01', 'bin_to' => 'ZZ-9', 'items' => [['sku' => 'JSC-A-L', 'qty' => 1]]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak ada di denah'));
        $this->post(route('gudang.transfer.store'), ['bin_from' => 'A-01-01', 'bin_to' => 'B-01-01', 'items' => [['sku' => 'JSC-A-L', 'qty' => 9]]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'hanya 5 pcs'));
        $this->post(route('gudang.transfer.store'), ['bin_from' => 'A-01-01', 'bin_to' => 'B-01-01', 'items' => [['sku' => 'JSC-A-L', 'qty' => 0]]])
            ->assertSessionHas('error', 'Isi jumlah yang dipindah minimal 1 pcs.');

        $order = Order::create(['order_number' => 'SP-1', 'customer_name' => 'Ani', 'status' => 'PICKING']);
        $session = PickingSession::create(['order_id' => $order->id, 'user_id' => auth()->id(), 'status' => 'IN_PROGRESS']);
        PickingSessionItem::create(['picking_session_id' => $session->id, 'sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_ordered' => 3, 'qty_picked' => 1, 'status' => 'PARTIAL']);

        $this->getJson(route('gudang.transfer.lookup', ['code' => 'A-01-01']))->assertJsonPath('items.0.locked', 2);
        $this->post(route('gudang.transfer.store'), ['bin_from' => 'A-01-01', 'bin_to' => 'B-01-01', 'items' => [['sku' => 'JSC-A-L', 'qty' => 4]]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Maksimal yang bisa dipindah: 3 pcs'));
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Format lama (satu SKU) tetap jalan
        $this->post(route('gudang.transfer.store'), ['sku' => 'JSC-A-L', 'bin_from' => 'A-01-01', 'bin_to' => 'B-01-01', 'qty' => 3])
            ->assertSessionHas('success');
        $this->assertSame([2, 3], [$this->stockQty('JSC-A-L', 'A-01-01'), $this->stockQty('JSC-A-L', 'B-01-01')]);
    }
}
