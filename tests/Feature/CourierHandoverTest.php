<?php

namespace Tests\Feature;

use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Serah terima paket ke kurir: scan resi, tandai Dikirim, cetak manifest.
 */
class CourierHandoverTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function readyOrder(string $number, ?string $courier, ?string $resi, string $status = 'READY_TO_SHIP', int $qty = 2): Order
    {
        $order = Order::create([
            'order_number' => $number,
            'customer_name' => 'Pembeli ' . $number,
            'shipping_address' => 'Bandung',
            'courier' => $courier,
            'tracking_number' => $resi,
            'status' => $status,
            'packed_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => $qty, 'qty_picked' => $qty, 'qty_packed' => $qty, 'price' => 1000]);

        return $order;
    }

    public function test_scan_parcels_and_hand_them_over(): void
    {
        $this->makeSku('JSC-A-L');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $a = $this->readyOrder('SP-1', 'SPX Standard', 'SPXID001');
        $b = $this->readyOrder('SP-2', 'SPX Instant', 'SPXID002', qty: 1);
        $c = $this->readyOrder('SP-3', 'SPX Standard', 'SPXID003');
        $jne = $this->readyOrder('TK-1', 'JNE REG', 'JNE001');
        $noResi = $this->readyOrder('SP-4', 'SPX Standard', null);
        $packing = $this->readyOrder('SP-5', 'SPX Standard', 'SPXID005', 'PACKING');
        $shipped = $this->readyOrder('SP-6', 'SPX Standard', 'SPXID006', 'SHIPPED');

        $this->actingAs($staff)->get(route('gudang.tugas'))->assertSee('Serahkan ke kurir (scan resi)');
        $index = $this->get(route('gudang.handover'))->assertOk();
        $this->assertSame(['JNE' => 1, 'SPX' => 4], $index->viewData('couriers')->all());

        $this->post(route('gudang.handover.store'), ['courier' => 'SPX']);
        $handover = CourierHandover::firstOrFail();
        $this->assertSame(['OPEN', 'SPX'], [$handover->status, $handover->courier]);

        // Mulai lagi untuk kurir yang sama membuka serah terima yang sama
        $this->post(route('gudang.handover.store'), ['courier' => 'SPX Standard'])->assertRedirect(route('gudang.handover.show', $handover));
        $this->assertSame(1, CourierHandover::count());

        $this->get(route('gudang.handover.show', $handover))->assertOk()
            ->assertSee('SPXID001')->assertSee('Belum di-scan')->assertDontSee('JNE001')->assertDontSee('SPXID005');

        $scan = route('gudang.handover.scan', $handover);
        $this->postJson($scan, ['code' => 'spxid001'])->assertOk()
            ->assertJson(['ok' => true, 'items' => [['id' => 'count', 'qty' => 1], ['id' => 'row-' . $a->id, 'qty' => 'Sudah di-scan']]]);
        $this->postJson($scan, ['code' => 'SP-2'])->assertOk()->assertJsonPath('items.0.qty', 2);   // nomor order juga bisa

        foreach ([
            'SPXID001' => 'SP-1 sudah ada di daftar.',
            'JNE001' => 'TK-1 untuk kurir JNE, bukan SPX. Pisahkan paket ini.',
            'SP-4' => 'SP-4 belum punya nomor resi. Isi resinya dulu di Outbound.',
            'SPXID005' => 'SP-5 belum siap kirim (status: Siap/Sedang Packing). Jangan diserahkan.',
            'XXX' => 'Resi atau nomor order XXX tidak ditemukan.',
        ] as $code => $message) {
            $this->postJson($scan, ['code' => $code])->assertStatus(422)->assertJson(['ok' => false, 'message' => $message]);
        }
        $this->postJson($scan, ['code' => 'SPXID006'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'sudah dikirim sebelumnya'));

        // Kurir menolak satu paket: keluarkan dari daftar
        $this->postJson($scan, ['code' => 'SPXID003'])->assertOk();
        $this->delete(route('gudang.handover.remove', [$handover, $c]))->assertSessionHas('success');

        $this->post(route('gudang.handover.finish', $handover), ['driver_name' => 'Pak Andi'])
            ->assertRedirect(route('gudang.handover.print', $handover));

        $this->assertSame('SHIPPED', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->shipped_at);
        $this->assertSame('SHIPPED', $b->fresh()->status);
        $this->assertSame('READY_TO_SHIP', $c->fresh()->status);
        $this->assertSame(['COMPLETED', 'Pak Andi'], [$handover->fresh()->status, $handover->fresh()->driver_name]);
        $this->assertDatabaseHas('audit_trails', ['module' => 'OUTBOUND', 'action' => 'HANDOVER']);

        $this->get(route('gudang.handover.print', $handover))->assertOk()
            ->assertSee('Manifest Serah Terima Paket')->assertSee('SPXID001')->assertSee('SPXID002')
            ->assertDontSee('SPXID003')->assertSee('2 paket · 3 pcs')->assertSee('Pak Andi');

        // Selesai: scan ditolak, halaman scan diarahkan ke manifest, muncul di riwayat
        $this->postJson($scan, ['code' => 'SPXID003'])->assertStatus(422);
        $this->get(route('gudang.handover.show', $handover))->assertRedirect(route('gudang.handover.print', $handover));
        $this->get(route('gudang.handover'))->assertSee('SPX · 2 paket');
    }

    public function test_finish_requires_parcels_and_cancel_keeps_orders_ready(): void
    {
        $this->makeSku('JSC-A-L');
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));
        $order = $this->readyOrder('SP-1', 'SPX', 'SPXID001');
        $this->readyOrder('X-1', null, 'R-1');

        $this->assertSame(['SPX' => 1, 'TANPA KURIR' => 1], $this->get(route('gudang.handover'))->viewData('couriers')->all());

        $this->post(route('gudang.handover.store'), ['courier' => 'TANPA KURIR']);
        $this->assertSame('TANPA KURIR', CourierHandover::firstOrFail()->courier);
        $this->postJson(route('gudang.handover.scan', CourierHandover::first()), ['code' => 'R-1'])->assertOk();

        $this->post(route('gudang.handover.store'), ['courier' => 'SPX']);
        $handover = CourierHandover::where('courier', 'SPX')->firstOrFail();
        $this->post(route('gudang.handover.finish', $handover))->assertSessionHas('error', 'Belum ada paket yang di-scan.');

        $this->postJson(route('gudang.handover.scan', $handover), ['code' => 'SPXID001'])->assertOk();
        $this->delete(route('gudang.handover.destroy', $handover))->assertRedirect(route('gudang.handover'));
        $this->assertSame('READY_TO_SHIP', $order->fresh()->status);
        $this->assertNull($order->fresh()->handoverItem);
        $this->assertSame(1, CourierHandover::count());
    }
}
