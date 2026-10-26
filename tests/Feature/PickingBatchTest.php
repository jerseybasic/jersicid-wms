<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Picking gabungan: beberapa order diambil sekaligus, tiap order punya keranjang sendiri.
 */
class PickingBatchTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WarehouseRack::create(['code' => 'A']);
        WarehouseRack::create(['code' => 'B']);
        foreach (['JSC-A-L', 'JSC-B-L', 'JSC-C-L'] as $sku) {
            $this->makeSku($sku);
        }
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->putStock('JSC-B-L', 'B-01-01', 5);
    }

    /** Order CONFIRMED; dibuat berurutan supaya nomor keranjangnya bisa ditebak */
    private function confirmedOrder(string $customer, array $items): Order
    {
        $this->travel(1)->minutes();
        $this->post(route('gudang.outbound.store'), [
            'customer_name' => $customer,
            'shipping_address' => 'Bandung',
            'courier' => 'JNE',
            'items' => collect($items)->map(fn ($qty, $sku) => ['sku' => $sku, 'qty' => $qty])->values()->all(),
        ]);
        $order = Order::latest()->firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);

        return $order->fresh();
    }

    public function test_batch_picking_puts_each_scan_into_the_right_basket(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $first = $this->confirmedOrder('Ani', ['JSC-A-L' => 2]);
        $second = $this->confirmedOrder('Budi', ['JSC-A-L' => 1, 'JSC-B-L' => 1]);
        $third = $this->confirmedOrder('Citra', ['JSC-B-L' => 1]);

        $this->get(route('gudang.tugas'))->assertSee('Ambil 3 order sekaligus')->assertSee('Pilih sendiri order yang digabung');
        $this->get(route('gudang.picking-batch.create'))->assertOk()
            ->assertSee($first->order_number)->assertSee($third->order_number);

        $this->post(route('gudang.picking-batch.store'), ['order_ids' => [$first->id]])
            ->assertSessionHasErrors('order_ids');
        $this->assertSame(0, PickingBatch::count());

        // Urutan pilihan tidak penting: keranjang mengikuti order terlama
        $response = $this->post(route('gudang.picking-batch.store'), ['order_ids' => [$third->id, $first->id, $second->id]]);
        $batch = PickingBatch::firstOrFail();
        $response->assertRedirect(route('gudang.picking-batch.show', $batch));
        $this->assertSame('IN_PROGRESS', $batch->status);
        $this->assertSame([$first->id => 1, $second->id => 2, $third->id => 3],
            PickingSession::where('batch_id', $batch->id)->pluck('basket_no', 'order_id')->all());
        $this->assertSame(['PICKING'], Order::pluck('status')->unique()->values()->all());

        $pickList = $this->get(route('gudang.picking-batch.show', $batch))->assertOk()
            ->assertSee('Scan barcode')->assertSee('K1')->viewData('pickList');
        $this->assertSame([['JSC-A-L', 3, 'A-01-01'], ['JSC-B-L', 2, 'B-01-01']],
            $pickList->map(fn ($row) => [$row['sku'], $row['qty_ordered'], $row['bins']->first()])->all());

        // Sesi milik picking gabungan tidak muncul sendiri-sendiri di Tugas
        $this->get(route('gudang.tugas'))->assertSee('Lanjutkan picking gabungan')->assertDontSee('Lanjutkan picking<');

        $scan = route('gudang.picking-batch.scan', $batch);
        $this->postJson($scan, ['code' => 'a-01-01'])->assertJson(['ok' => true, 'type' => 'bin']);
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertJson(['ok' => true, 'basket' => 1]);
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])
            ->assertJson(['basket' => 1, 'completed' => false])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Keranjang 1 lengkap'));
        $this->assertSame('PACKING', $first->fresh()->status);

        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])
            ->assertJson(['basket' => 2, 'items' => [['id' => 'sku-JSC-A-L', 'qty' => 3], ['id' => 'basket-2', 'qty' => 1], ['id' => 'total', 'qty' => 3]]]);
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertStatus(422)
            ->assertJson(['message' => 'SKU JSC-A-L sudah lengkap untuk semua keranjang.']);
        $this->postJson($scan, ['code' => 'JSC-C-L', 'bin_code' => 'A-01-01'])->assertStatus(422)
            ->assertJson(['message' => 'SKU JSC-C-L tidak dipesan di picking ini. Jangan diambil.']);

        $this->postJson($scan, ['code' => 'JSC-B-L', 'bin_code' => 'B-01-01'])->assertJson(['basket' => 2, 'completed' => false]);
        $this->postJson($scan, ['code' => 'JSC-B-L', 'bin_code' => 'B-01-01'])->assertJson(['basket' => 3, 'completed' => true]);

        $this->assertSame('COMPLETED', $batch->fresh()->status);
        $this->assertSame(['PACKING'], Order::pluck('status')->unique()->values()->all());
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(3, $this->stockQty('JSC-B-L', 'B-01-01'));

        // Selesai: scan ditolak, dan packer melihat nomor keranjangnya
        $this->postJson($scan, ['code' => 'JSC-B-L'])->assertStatus(422);
        $this->get(route('gudang.tugas'))->assertSee('KERANJANG 3')->assertDontSee('Lanjutkan picking gabungan');
    }

    public function test_one_tap_batch_takes_orders_with_the_nearest_deadline(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $first = $this->confirmedOrder('Ani', ['JSC-A-L' => 1]);
        $second = $this->confirmedOrder('Budi', ['JSC-B-L' => 1]);
        $urgent = $this->confirmedOrder('Citra', ['JSC-A-L' => 1]);
        $urgent->update(['ship_by' => now()->subDay()]);

        $this->post(route('gudang.picking-batch.auto'), ['jumlah' => 2]);
        $batch = PickingBatch::firstOrFail();
        $this->assertSame([$urgent->id => 1, $first->id => 2],
            PickingSession::where('batch_id', $batch->id)->orderBy('basket_no')->pluck('basket_no', 'order_id')->all());

        // Tersisa satu order: langsung picking biasa
        $this->post(route('gudang.picking-batch.auto'))
            ->assertRedirect(route('gudang.picking.show', PickingSession::where('order_id', $second->id)->value('id')));
        $this->assertSame('IN_PROGRESS', PickingSession::where('order_id', $second->id)->value('status'));
        $this->post(route('gudang.picking-batch.auto'))->assertSessionHas('error', 'Tidak ada order yang menunggu picking.');
    }

    public function test_minus_one_takes_the_piece_back_out_of_the_last_basket(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $first = $this->confirmedOrder('Ani', ['JSC-A-L' => 1]);
        $second = $this->confirmedOrder('Budi', ['JSC-A-L' => 1]);
        $this->post(route('gudang.picking-batch.store'), ['order_ids' => [$first->id, $second->id]]);
        $batch = PickingBatch::firstOrFail();
        $this->get(route('gudang.picking-batch.show', $batch))->assertSee('+1 Ambil');

        $scan = route('gudang.picking-batch.scan', $batch);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertJson(['basket' => 1]);
        // Ketuk +1 di daftar sama dengan scan, tapi tercatat sebagai ambil tanpa scan
        $this->postJson($scan, ['code' => 'JSC-A-L', 'via' => 'ketuk'])->assertJson(['basket' => 2, 'completed' => true]);
        $this->assertSame('COMPLETED', $batch->fresh()->status);
        $this->assertSame(1, \App\Models\AuditTrail::where('action', 'MANUAL_PICK')->count());

        $this->postJson(route('gudang.picking-batch.undo', $batch), ['sku' => 'JSC-A-L'])
            ->assertOk()->assertJson(['type' => 'undo', 'basket' => 2])
            ->assertJsonPath('message', 'Keluarkan 1 pcs JSC-A-L dari keranjang 2, kembalikan ke A-01-01.');
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(['IN_PROGRESS', 'PICKING', 'PACKING'], [$batch->fresh()->status, $second->fresh()->status, $first->fresh()->status]);
        $this->postJson(route('gudang.picking-batch.undo', $batch), ['sku' => 'JSC-B-L'])->assertStatus(422);
    }

    public function test_cancelling_returns_stock_of_unfinished_baskets_only(): void
    {
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));
        $first = $this->confirmedOrder('Ani', ['JSC-A-L' => 1]);
        $second = $this->confirmedOrder('Budi', ['JSC-A-L' => 2]);
        $this->post(route('gudang.picking-batch.store'), ['order_ids' => [$first->id, $second->id]]);
        $batch = PickingBatch::firstOrFail();

        $scan = route('gudang.picking-batch.scan', $batch);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertJson(['basket' => 1]);   // tanpa bin: pakai bin saran
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertJson(['basket' => 2]);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));

        $this->delete(route('gudang.picking-batch.destroy', $batch))
            ->assertRedirect(route('gudang.picking'))
            ->assertSessionHas('success', '1 order dikembalikan ke antrean picking dan stoknya dikembalikan ke rak.');

        // Keranjang 1 sudah lengkap dan tetap lanjut ke packing; keranjang 2 dibatalkan
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame('PACKING', $first->fresh()->status);
        $this->assertSame('CONFIRMED', $second->fresh()->status);
        $this->assertNull($second->fresh()->pickingSession);
        $this->assertSame('COMPLETED', $batch->fresh()->status);

        // Order yang dibatalkan bisa diambil lagi dengan picking biasa
        $this->post(route('gudang.picking.store'), ['order_id' => $second->id])->assertSessionHasNoErrors();
        $this->assertNull(PickingSession::where('order_id', $second->id)->value('batch_id'));
    }

    public function test_batch_without_any_finished_basket_is_removed_when_cancelled(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'));
        $orders = [$this->confirmedOrder('Ani', ['JSC-A-L' => 1]), $this->confirmedOrder('Budi', ['JSC-B-L' => 1])];
        $this->post(route('gudang.picking-batch.store'), ['order_ids' => collect($orders)->pluck('id')->all()]);
        $batch = PickingBatch::firstOrFail();

        // Order yang sudah diambil orang lain tidak bisa ikut picking gabungan baru
        $this->post(route('gudang.picking-batch.store'), ['order_ids' => collect($orders)->pluck('id')->all()])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'harus berstatus CONFIRMED'));
        $this->assertSame(1, PickingBatch::count());

        $this->delete(route('gudang.picking-batch.destroy', $batch));
        $this->assertSame(0, PickingBatch::count());
        $this->assertSame(0, PickingSession::count());
        $this->assertSame(['CONFIRMED'], Order::pluck('status')->unique()->values()->all());
    }
}
