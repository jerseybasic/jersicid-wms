<?php

namespace Tests\Feature;

use App\Models\CourierHandover;
use App\Models\CourierHandoverItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Gelombang 3: order yang stoknya kurang (Menunggu stok), barang tidak ada di rak saat picking,
 * pembatalan order yang sedang diproses, dan masalah barang saat packing.
 */
class StockShortageAndCancellationTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number, string $sku, int $qty, string $status = 'PENDING'): Order
    {
        $order = Order::create([
            'order_number' => $number,
            'customer_name' => 'Pembeli ' . $number,
            'shipping_address' => 'Bandung',
            'courier' => 'JNE',
            'tracking_number' => 'RESI-' . $number,
            'status' => $status,
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => $sku, 'qty_ordered' => $qty, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 1000]);

        return $order;
    }

    /** Order CONFIRMED yang langsung diambil lewat picking biasa sampai lengkap, lalu mulai dikemas */
    private function packingOrder(string $number, string $sku, int $qty): array
    {
        $order = $this->order($number, $sku, $qty, 'CONFIRMED');
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));
        $item = $picking->items->first();
        for ($i = 0; $i < $qty; $i++) {
            $this->postJson(route('gudang.picking.tap', [$picking->id, $item->id]), ['delta' => 1])->assertOk();
        }
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id])->assertSessionHas('success');

        return [$order->fresh(), $picking->fresh('items'), PackingSession::with('items')->where('order_id', $order->id)->firstOrFail()];
    }

    public function test_short_order_waits_for_stock_and_returns_to_queue_when_goods_arrive(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 1);
        $order = $this->order('ORD-KURANG', 'JSC-A-L', 3);

        $this->actingAs($owner)->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'menunggu stok'));
        $order->refresh();
        $this->assertTrue($order->isWaitingStock());
        $this->assertSame('Menunggu Stok', $order->status_label);

        // Bukan lagi "perlu dikonfirmasi", tapi muncul di filter Menunggu stok beserta kekurangannya
        $this->assertEmpty($this->get(route('gudang.outbound', ['status' => 'PENDING']))->viewData('orders')->items());
        $response = $this->get(route('gudang.outbound', ['status' => 'WAITING_STOCK']))->assertOk()->assertSee('ORD-KURANG');
        $this->assertSame([['sku' => 'JSC-A-L', 'need' => 3, 'available' => 1, 'incoming' => 0, 'short' => 2, 'orders' => 1]], $response->viewData('needs')->all());

        // PO dari kebutuhan order terisi sejumlah kekurangannya
        $rows = $this->get(route('po.create', ['dari' => 'kebutuhan-order']))->assertOk()->viewData('prefillRows');
        $this->assertSame('JSC-A-L', $rows[0]['sku']);
        $this->assertSame(2, $rows[0]['qty']);

        // Barang diterima di Inbound: order otomatis dikonfirmasi
        $warehouse = $this->makeWarehouse();
        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-001',
            'supplier_name' => 'JB Printing',
            'received_date' => today()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2]],
        ])->assertSessionHas('success', fn ($m) => str_contains($m, '1 order yang menunggu stok kembali ke antrean picking (ORD-KURANG)'));

        $order->refresh();
        $this->assertSame('CONFIRMED', $order->status);
        $this->assertNull($order->waiting_stock_at);
        $this->get(route('gudang.outbound.show', $order->id))->assertOk()->assertSee('Menunggu stok')->assertSee('Otomatis: stok sudah tersedia');
    }

    public function test_picking_allocates_bins_so_two_pickers_are_not_sent_to_the_same_short_bin(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 3);
        $this->putStock('JSC-A-L', 'B-01-01', 3);
        $first = $this->order('ORD-1', 'JSC-A-L', 3, 'CONFIRMED');
        $second = $this->order('ORD-2', 'JSC-A-L', 3, 'CONFIRMED');

        $this->actingAs($staff)->post(route('gudang.picking.store'), ['order_id' => $first->id]);
        $this->post(route('gudang.picking.store'), ['order_id' => $second->id]);

        $bins = PickingSession::with('items')->get()->map(fn ($s) => $s->items->first()->bin_code)->sort()->values()->all();
        $this->assertSame(['A-01-01', 'B-01-01'], $bins);
    }

    public function test_missing_item_moves_picker_to_another_bin_or_puts_order_on_hold(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->putStock('JSC-A-L', 'B-01-01', 2);
        $this->putStock('JSC-B-L', 'C-01-01', 1);
        $order = Order::create(['order_number' => 'ORD-HILANG', 'customer_name' => 'Budi', 'shipping_address' => 'Bandung', 'status' => 'CONFIRMED']);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-B-L', 'qty_ordered' => 1, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 1000]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 2, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 1000]);

        $this->actingAs($staff)->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));
        $itemA = $picking->items->firstWhere('sku', 'JSC-A-L');
        $itemB = $picking->items->firstWhere('sku', 'JSC-B-L');
        $this->assertSame('A-01-01', $itemA->bin_code);

        // B sudah diambil, lalu A ternyata tidak ada di A-01-01: stoknya dinolkan, picker diarahkan ke B-01-01
        $this->postJson(route('gudang.picking.tap', [$picking->id, $itemB->id]), ['delta' => 1])->assertOk();
        $this->post(route('gudang.picking.missing', [$picking->id, $itemA->id]))
            ->assertSessionHas('success', 'JSC-A-L tidak ada di A-01-01. Coba ambil di rak B-01-01.');
        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame('B-01-01', $itemA->fresh()->bin_code);
        $this->assertTrue(StockMovement::where('reference_type', 'PICKING_MISSING')->where('qty', 5)->exists());

        // Di B-01-01 juga tidak ada: order ditunda, barang B yang sudah diambil kembali ke raknya
        $this->post(route('gudang.picking.missing', [$picking->id, $itemA->id]))
            ->assertRedirect(route('gudang.tugas'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ORD-HILANG ditunda (Menunggu stok)') && str_contains($m, 'Kembalikan 1 pcs'));
        $order->refresh();
        $this->assertTrue($order->isWaitingStock());
        $this->assertNull($order->pickingSession);
        $this->assertSame(1, $this->stockQty('JSC-B-L', 'C-01-01'));
        $this->assertSame(0, $this->stockQty('JSC-A-L', 'B-01-01'));
    }

    public function test_cancelling_an_order_in_process_sends_goods_back_to_the_rack(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->actingAs($admin);
        [$order, $picking, $packing] = $this->packingOrder('ORD-BATAL', 'JSC-A-L', 2);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Staf biasa tidak boleh membatalkan order yang sedang diproses
        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->post(route('gudang.outbound.cancel', $order->id))->assertForbidden();

        $this->actingAs($admin)->post(route('gudang.outbound.cancel', $order->id), ['reason' => 'Dibatalkan pembeli di Shopee'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Kembalikan 2 pcs'));
        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->assertSame('CANCELLED', $packing->fresh()->status);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));   // belum bertambah sampai barangnya ditaruh
        $this->get(route('gudang.outbound.show', $order->id))->assertSee('Dibatalkan pembeli di Shopee')->assertSee('2 pcs barang order ini');

        // Packing dan scan order batal ditolak
        $packItem = $packing->items->first();
        $this->postJson(route('gudang.packing.tap', [$packing->id, $packItem->id]), ['delta' => 1])->assertStatus(422);
        $this->postJson(route('gudang.command'), ['code' => 'ORD-BATAL'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'dibatalkan'));

        // Kembalikan ke rak: ketuk ke rak asal, scan rak lain untuk sisanya
        $orderItem = $order->items()->first();
        $this->get(route('gudang.restock'))->assertOk()->assertSee('ORD-BATAL');
        $this->postJson(route('gudang.restock.tap', $orderItem->id))->assertOk()->assertJsonPath('items.0.qty', 1);
        $this->postJson(route('gudang.restock.scan'), ['code' => 'JSC-A-L', 'bin_code' => 'B-02-01'])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(1, $this->stockQty('JSC-A-L', 'B-02-01'));
        $this->assertSame(0, $orderItem->fresh()->qty_picked);
        $this->postJson(route('gudang.restock.scan'), ['code' => 'JSC-A-L'])->assertStatus(422);
    }

    public function test_cancelled_order_leaves_open_handover_and_batch_keeps_going(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 10);
        $ready = $this->order('ORD-SIAP', 'JSC-A-L', 1, 'READY_TO_SHIP');
        $ready->items()->update(['qty_picked' => 1, 'qty_packed' => 1, 'bin_code' => 'A-01-01']);
        $handover = CourierHandover::create(['courier' => 'JNE', 'user_id' => $admin->id, 'status' => 'OPEN']);
        CourierHandoverItem::create(['courier_handover_id' => $handover->id, 'order_id' => $ready->id]);

        $this->actingAs($admin)->post(route('gudang.outbound.cancel', $ready->id))->assertSessionHas('success');
        $this->assertSame(0, $handover->items()->count());

        // Picking gabungan: keranjang yang dibatalkan dilewati, keranjang lain selesai seperti biasa
        $one = $this->order('ORD-K1', 'JSC-A-L', 1, 'CONFIRMED');
        $two = $this->order('ORD-K2', 'JSC-A-L', 1, 'CONFIRMED');
        $this->post(route('gudang.picking-batch.store'), ['order_ids' => [$one->id, $two->id]]);
        $kept = PickingSession::where('order_id', $one->id)->firstOrFail();
        $batch = $kept->batch;
        $this->post(route('gudang.outbound.cancel', $two->id))->assertSessionHas('success', 'Order ORD-K2 dibatalkan.');

        $this->postJson(route('gudang.picking-batch.scan', $batch), ['code' => 'JSC-A-L'])->assertOk()->assertJsonPath('basket', $kept->basket_no);
        $this->assertSame('COMPLETED', $batch->fresh()->status);
        $this->assertSame('PACKING', $one->fresh()->status);
        $this->get(route('gudang.picking-batch.show', $batch))->assertOk()->assertSee('Order dibatalkan');
    }

    public function test_problem_while_packing_sends_one_piece_back_for_a_replacement(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->actingAs($staff);
        [$order, $picking, $packing] = $this->packingOrder('ORD-RUSAK', 'JSC-A-L', 2);
        $packItem = $packing->items->first();
        $pickItem = $picking->items->first();
        $this->postJson(route('gudang.packing.tap', [$packing->id, $packItem->id]), ['delta' => 1])->assertOk();

        // Rusak: tidak kembali ke stok, order kembali ke picking untuk ambil pengganti
        $this->post(route('gudang.packing.problem', [$packing->id, $packItem->id]), ['reason' => 'RUSAK'])
            ->assertRedirect(route('gudang.packing.show', $packing->id))
            ->assertSessionHas('success', 'Sisihkan barang yang rusak. Ambil 1 pcs JSC-A-L pengganti di rak A-01-01, lalu lanjutkan packing.');
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame('PICKING', $order->fresh()->status);
        $this->assertSame(1, $pickItem->fresh()->qty_picked);
        $this->assertSame(1, $packItem->fresh()->qty_packed);
        $this->get(route('gudang.packing.show', $packing->id))->assertOk()->assertSee('Menunggu barang pengganti');
        $this->postJson(route('gudang.packing.tap', [$packing->id, $packItem->id]), ['delta' => 1])->assertStatus(422);

        // Ambil pengganti: order kembali ke meja packing, packing dilanjutkan sampai selesai
        $this->postJson(route('gudang.picking.tap', [$picking->id, $pickItem->id]), ['delta' => 1])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('PACKING', $order->fresh()->status);
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->postJson(route('gudang.packing.tap', [$packing->id, $packItem->id]), ['delta' => 1])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('READY_TO_SHIP', $order->fresh()->status);

        // Salah barang: SKU yang dipesan masih di rak, jadi stoknya dikembalikan
        [$other, , $otherPacking] = $this->packingOrder('ORD-SALAH', 'JSC-A-L', 1);
        $this->post(route('gudang.packing.problem', [$otherPacking->id, $otherPacking->items->first()->id]), ['reason' => 'SALAH'])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Kembalikan barang yang salah ke raknya.'));
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame('PICKING', $other->fresh()->status);
    }

    public function test_order_marked_done_without_handover_goes_back_to_ready_to_ship(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $order = $this->order('ORD-TANPA-SCAN', 'JSC-A-L', 1, 'COMPLETED');
        $order->update(['shipped_at' => now()]);

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->post(route('gudang.outbound.reprocess', $order->id))->assertForbidden();
        $this->actingAs($admin)->get(route('gudang.outbound.show', $order->id))->assertSee('Proses ulang');
        $this->post(route('gudang.outbound.reprocess', $order->id))->assertSessionHas('success', fn ($m) => str_contains($m, 'kembali ke Siap Kirim'));
        $order->refresh();
        $this->assertSame('READY_TO_SHIP', $order->status);
        $this->assertNull($order->shipped_at);

        // Resi yang sama bisa langsung di-scan di serah terima
        $this->postJson(route('gudang.handover.scan-any'), ['code' => 'RESI-ORD-TANPA-SCAN'])->assertOk();

        // Paket yang sudah diserahkan ke kurir hanya bisa diproses ulang lewat retur
        $handover = CourierHandover::where('status', 'OPEN')->firstOrFail();
        $this->post(route('gudang.handover.finish', $handover), ['driver_name' => 'Andi']);
        $this->post(route('gudang.outbound.reprocess', $order->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'buat retur dulu'));
    }

    public function test_returned_order_is_reprocessed_with_the_same_resi_and_wrong_returns_can_be_fixed(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->actingAs($admin);
        [$order, , $packing] = $this->packingOrder('ORD-ULANG', 'JSC-A-L', 2);
        $this->postJson(route('gudang.packing.tap', [$packing->id, $packing->items->first()->id]), ['delta' => 1]);
        $this->postJson(route('gudang.packing.tap', [$packing->id, $packing->items->first()->id]), ['delta' => 1]);
        $order->update(['status' => 'COMPLETED', 'shipped_at' => now()->subMinute()]);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $item = $order->items()->first();

        // Retur salah jumlah: dihapus dan stoknya dikoreksi, lalu dibuat ulang
        $this->post(route('gudang.retur.store'), ['order_id' => $order->id, 'reason' => 'LAINNYA', 'items' => [$item->id => ['qty_good' => 1, 'bin_code' => 'A-01-01']]]);
        $wrong = \App\Models\OrderReturn::firstOrFail();
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->post(route('gudang.outbound.reprocess', $order->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'belum mencakup semua barangnya'));
        $this->put(route('gudang.retur.update', $wrong->id), ['reason' => 'SALAH_KIRIM', 'notes' => 'Salah klik'])->assertSessionHas('success');
        $this->assertSame('SALAH_KIRIM', $wrong->fresh()->reason);
        $this->delete(route('gudang.retur.destroy', $wrong->id))->assertSessionHas('success');
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(0, \App\Models\OrderReturn::count());

        $this->post(route('gudang.retur.store'), ['order_id' => $order->id, 'reason' => 'LAINNYA', 'items' => [$item->id => ['qty_good' => 2, 'bin_code' => 'A-01-01']]]);
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Semua barang sudah kembali: order masuk antrean picking lagi dengan resi yang sama
        $this->post(route('gudang.outbound.reprocess', $order->id))->assertSessionHas('success', fn ($m) => str_contains($m, 'antrean picking'));
        $order->refresh();
        $this->assertSame(['CONFIRMED', 'RESI-ORD-ULANG'], [$order->status, $order->tracking_number]);
        $this->assertNull($order->pickingSession);
        $this->assertSame(0, $item->fresh()->qty_picked);

        // Retur lama tidak bisa dihapus lagi karena barangnya akan dipakai untuk order ini
        $this->delete(route('gudang.retur.destroy', \App\Models\OrderReturn::firstOrFail()->id))->assertSessionHas('error');
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Picking baru bisa dibuat untuk order yang sama
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id])->assertSessionHas('success');
        $this->assertSame('PICKING', $order->fresh()->status);
    }
}
