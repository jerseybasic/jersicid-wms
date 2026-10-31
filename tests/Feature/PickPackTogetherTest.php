<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\PackingBatch;
use App\Models\PackingSession;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\ShipmentClosing;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Alur cepat dari Outbound: centang order yang stoknya siap → picking gabungan → packing gabungan,
 * dan tutup kiriman yang mengembalikan order belum diproses ke Pending.
 */
class PickPackTogetherTest extends TestCase
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

    /** Order Pending baru; dibuat berurutan supaya nomor keranjangnya bisa ditebak */
    private function pendingOrder(string $customer, array $items, ?string $resi = null): Order
    {
        $this->travel(1)->minutes();
        $this->post(route('gudang.outbound.store'), [
            'customer_name' => $customer,
            'shipping_address' => 'Bandung',
            'courier' => 'JNE',
            'items' => collect($items)->map(fn ($qty, $sku) => ['sku' => $sku, 'qty' => $qty])->values()->all(),
        ]);

        $order = Order::latest()->firstOrFail();
        $resi && $order->update(['tracking_number' => $resi]);

        return $order;
    }

    public function test_selected_orders_with_stock_go_straight_into_batch_picking(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $ani = $this->pendingOrder('Ani', ['JSC-A-L' => 2]);
        $budi = $this->pendingOrder('Budi', ['JSC-B-L' => 1]);
        $short = $this->pendingOrder('Citra', ['JSC-C-L' => 1]);
        $confirmed = $this->pendingOrder('Dodi', ['JSC-A-L' => 1]);
        $this->patch(route('gudang.outbound.update-status', $confirmed->id), ['status' => 'CONFIRMED']);

        // Daftar Outbound menandai order Pending yang stoknya siap
        $page = $this->get(route('gudang.outbound'))->assertOk()->assertSee('Stok siap')->assertSee('Stok kurang')
            ->assertSee('Pilih yang stoknya siap')->assertSee('Picking gabungan');
        $this->assertEquals([$ani->id => true, $budi->id => true, $short->id => false], array_intersect_key($page->viewData('stockReady'), array_flip([$ani->id, $budi->id, $short->id])));

        $response = $this->post(route('gudang.outbound.bulk-pick'), ['ids' => [$ani->id, $budi->id, $short->id, $confirmed->id]]);
        $batch = PickingBatch::firstOrFail();
        $response->assertRedirect(route('gudang.picking-batch.show', $batch))
            ->assertSessionHas('error', fn ($m) => str_contains($m, $short->order_number) && str_contains($m, 'menunggu stok'));
        $this->assertSame([$ani->id, $budi->id, $confirmed->id], PickingSession::where('batch_id', $batch->id)->orderBy('basket_no')->pluck('order_id')->all());
        $this->assertTrue($short->fresh()->isWaitingStock());

        // Satu order saja langsung jadi picking biasa yang sudah berjalan
        $solo = $this->pendingOrder('Eka', ['JSC-B-L' => 1]);
        $this->post(route('gudang.outbound.bulk-pick'), ['ids' => [$solo->id]])
            ->assertRedirect(route('gudang.picking.show', PickingSession::where('order_id', $solo->id)->value('id')));
        $this->assertSame('IN_PROGRESS', PickingSession::where('order_id', $solo->id)->value('status'));

        // Tidak ada yang bisa dipicking
        $this->post(route('gudang.outbound.bulk-pick'), ['ids' => [$solo->id]])->assertSessionHas('error', fn ($m) => str_contains($m, 'sudah Sedang Picking'));
    }

    /** Picking gabungan tiga order sampai semua keranjang lengkap */
    private function pickedBatch(): array
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $ani = $this->pendingOrder('Ani', ['JSC-A-L' => 2], 'RESI-ANI');
        $budi = $this->pendingOrder('Budi', ['JSC-A-L' => 1, 'JSC-B-L' => 1], 'RESI-BUDI');
        $citra = $this->pendingOrder('Citra', ['JSC-B-L' => 1]);
        $this->post(route('gudang.outbound.bulk-pick'), ['ids' => [$ani->id, $budi->id, $citra->id]]);
        $picking = PickingBatch::firstOrFail();

        $scan = route('gudang.picking-batch.scan', $picking);
        foreach (['JSC-A-L', 'JSC-A-L', 'JSC-A-L', 'JSC-B-L', 'JSC-B-L'] as $sku) {
            $this->postJson($scan, ['code' => $sku])->assertOk();
        }
        $this->assertSame('COMPLETED', $picking->fresh()->status);

        return [$picking, $ani, $budi, $citra];
    }

    public function test_finished_batch_picking_is_packed_together_on_one_screen(): void
    {
        [$picking, $ani, $budi, $citra] = $this->pickedBatch();
        $this->get(route('gudang.picking-batch.show', $picking))->assertSee('Kemas 3 keranjang sekaligus');
        $this->get(route('gudang.tugas'))->assertSee('Kemas 3 keranjang sekaligus');

        $this->post(route('gudang.packing-batch.from-picking', $picking));
        $batch = PackingBatch::firstOrFail();
        // Nomor keranjang sama dengan picking gabungannya
        $this->assertSame([$ani->id => 1, $budi->id => 2, $citra->id => 3], $batch->sessions()->pluck('basket_no', 'order_id')->all());
        $this->get(route('gudang.packing-batch.show', $batch))->assertOk()->assertSee('Packing Gabungan')->assertSee('Resi belum ada');
        $this->get(route('gudang.tugas'))->assertSee('Lanjutkan packing gabungan');

        $scan = route('gudang.packing-batch.scan', $batch);
        // Langsung scan barang: masuk keranjang terkecil yang butuh, lalu keranjang itu jadi aktif
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()->assertJsonPath('items.3', ['id' => 'active', 'qty' => "Keranjang 1 · {$ani->order_number}"]);
        $this->postJson($scan, ['code' => 'JSC-B-L'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'bukan isi keranjang 1') && str_contains($m, 'keranjang 2'));
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()
            ->assertJson(['redirect' => route('gudang.packing-batch.show', $batch)])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Keranjang 1 selesai') && str_contains($m, 'RESI-ANI'));
        $this->assertSame('READY_TO_SHIP', $ani->fresh()->status);

        // Scan resi keranjang 3 memilihnya, lalu barangnya masuk ke keranjang 3 walau keranjang 2 juga butuh
        $this->postJson($scan, ['code' => 'resi-budi'])->assertJson(['type' => 'basket']);
        $this->postJson($scan, ['code' => $citra->order_number])->assertJson(['type' => 'basket']);
        $this->postJson($scan, ['code' => 'JSC-B-L'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Keranjang 3 selesai'));
        $this->postJson($scan, ['code' => $ani->order_number])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'sudah selesai dikemas'));
        $this->postJson($scan, ['code' => 'JSC-C-L'])->assertStatus(422)->assertJson(['message' => 'JSC-C-L tidak dipesan di packing gabungan ini. Jangan dikemas.']);

        // Ketuk +1 di kartu keranjang juga menyelesaikan packing gabungan
        $session = PackingSession::where('order_id', $budi->id)->firstOrFail();
        foreach ($session->items as $item) {
            $this->postJson(route('gudang.packing.tap', [$session->id, $item->id]), ['delta' => 1])->assertOk();
        }
        $this->assertSame('COMPLETED', $batch->fresh()->status);
        $this->assertSame(['READY_TO_SHIP'], Order::pluck('status')->unique()->values()->all());
        $this->get(route('gudang.packing-batch.show', $batch))->assertSee('Cetak 3 label')->assertSee('Serah terima kurir');
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertStatus(422);
    }

    public function test_selected_packing_orders_and_cancelled_baskets(): void
    {
        [, $ani, $budi, $citra] = $this->pickedBatch();

        // Dari Outbound: yang belum selesai picking dilewati
        $other = $this->pendingOrder('Dodi', ['JSC-A-L' => 1]);
        $this->post(route('gudang.packing-batch.store'), ['order_ids' => [$ani->id, $citra->id, $other->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 order dilewati'));
        $batch = PackingBatch::firstOrFail();
        $this->assertSame([$ani->id => 1, $citra->id => 3], $batch->sessions()->pluck('basket_no', 'order_id')->all());

        // Order dibatalkan di tengah packing: keranjangnya tidak menahan packing gabungan
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));
        $this->post(route('gudang.outbound.cancel', $citra->id))->assertSessionHasNoErrors();
        $this->get(route('gudang.packing-batch.show', $batch))->assertSee('Order dibatalkan');
        $scan = route('gudang.packing-batch.scan', $batch);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk();
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk();
        $this->assertSame('COMPLETED', $batch->fresh()->status);

        // Order yang sudah dikemas tidak bisa masuk packing gabungan lagi; satu order langsung packing biasa
        $this->post(route('gudang.packing-batch.store'), ['order_ids' => [$ani->id]])->assertSessionHas('error');
        $this->post(route('gudang.packing-batch.store'), ['order_ids' => [$budi->id]])
            ->assertRedirect(route('gudang.packing.show', PackingSession::where('order_id', $budi->id)->value('id')));
    }

    public function test_closing_the_day_returns_unprocessed_orders_to_pending(): void
    {
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));
        $waiting = $this->pendingOrder('Ani', ['JSC-A-L' => 1]);
        $this->patch(route('gudang.outbound.update-status', $waiting->id), ['status' => 'CONFIRMED']);
        $untouched = $this->pendingOrder('Budi', ['JSC-A-L' => 1]);
        $started = $this->pendingOrder('Citra', ['JSC-B-L' => 1]);
        $this->post(route('gudang.outbound.bulk-pick'), ['ids' => [$untouched->id, $started->id]]);
        $picking = PickingBatch::firstOrFail();
        // Keranjang Citra sudah mulai diambil, keranjang Budi belum
        $this->postJson(route('gudang.picking-batch.scan', $picking), ['code' => 'JSC-B-L'])->assertOk();
        $fresh = $this->pendingOrder('Dodi', ['JSC-A-L' => 1]);

        $this->get(route('gudang.outbound'))->assertSee('Tutup kiriman hari ini');
        $page = $this->get(route('gudang.closing'))->assertOk()->assertSee('Belum diproses')->assertSee('akan kembali ke Pending', false);
        $this->assertEqualsCanonicalizing([$waiting->id, $untouched->id], $page->viewData('unprocessed')->pluck('id')->all());

        $this->post(route('gudang.closing.store'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 order yang belum diproses dikembalikan ke Pending'));

        $this->assertSame('PENDING', $waiting->fresh()->status);
        $this->assertSame('PENDING', $untouched->fresh()->status);
        $this->assertNull($untouched->fresh()->pickingSession);
        $this->assertSame('PACKING', $started->fresh()->status);
        $this->assertSame('PENDING', $fresh->fresh()->status);
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(2, ShipmentClosing::firstOrFail()->pending_count);
        $this->assertSame(2, AuditTrail::where('action', 'CARRY_OVER')->count());
        $this->get(route('gudang.outbound.show', $waiting->id))->assertSee('Kembali Pending');
        $this->get(route('gudang.closing'))->assertSee('2 kembali Pending');

        // Besok: centang lagi lalu picking gabungan seperti biasa
        $this->post(route('gudang.outbound.bulk-pick'), ['ids' => [$waiting->id, $untouched->id]]);
        $this->assertSame(['PICKING'], Order::whereIn('id', [$waiting->id, $untouched->id])->pluck('status')->unique()->values()->all());
    }
}
