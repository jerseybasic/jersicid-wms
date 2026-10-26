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

    /** Jumlah paket "belum di-scan" per ekspedisi di halaman Serah Terima */
    private function waiting($response): array
    {
        return $response->viewData('groups')->filter(fn ($g) => $g['counts']['waiting'])
            ->mapWithKeys(fn ($g) => [$g['courier'] => $g['counts']['waiting']])->sortKeys()->all();
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
        $this->assertSame(['JNE' => 1, 'SPX' => 3], $this->waiting($index));
        $this->assertSame(['SP-4'], $index->viewData('noResi')->pluck('order_number')->all());

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
            'SP-4' => 'SP-4 belum punya nomor resi. Scan resinya di layar packing order ini, atau isi di detail order.',
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

        $this->assertSame(['SPX' => 1, 'TANPA KURIR' => 1], $this->waiting($this->get(route('gudang.handover'))));

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

    public function test_marketplace_courier_names_group_into_one_expedition(): void
    {
        foreach ([
            'SPX Standard' => 'SPX', 'Shopee Xpress Instant' => 'SPX', 'spx  hemat' => 'SPX',
            'J&T Express' => 'J&T', 'JNT' => 'J&T', 'J&T Cargo' => 'J&T CARGO',
            'ID Express' => 'ID EXPRESS', 'JNE REG' => 'JNE', 'Pos Indonesia' => 'POS',
            'Reguler' => 'REGULER', '' => 'TANPA KURIR', null => 'TANPA KURIR',
        ] as $name => $brand) {
            $this->assertSame($brand, CourierHandover::brand($name), "Nama: {$name}");
        }
    }

    public function test_scanning_any_resi_groups_it_by_expedition_and_tracks_what_was_handed_over(): void
    {
        $this->makeSku('JSC-A-L');
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $spx = $this->readyOrder('SP-1', 'SPX Standard', 'SPXID001');
        $this->readyOrder('SP-2', 'Shopee Xpress', 'SPXID002');
        $jnt = $this->readyOrder('TT-1', 'J&T Express', 'JT001');
        $this->readyOrder('TT-2', 'JNT', 'JT002');

        $this->assertSame(['J&T' => 2, 'SPX' => 2], $this->waiting($this->get(route('gudang.handover'))));

        // Tanpa memilih kurir: tiap resi masuk ke serah terima ekspedisinya
        $this->postJson(route('gudang.handover.scan-any'), ['code' => 'spxid001'])->assertOk()
            ->assertJson(['ok' => true, 'items' => [['id' => 'scanned-spx', 'qty' => 1], ['id' => 'waiting-spx', 'qty' => 1], ['id' => 'pkg-' . $spx->id, 'qty' => 'Di-scan', 'class' => 'font-semibold text-cyan-400']]]);
        $this->postJson(route('gudang.handover.scan-any'), ['code' => 'JT001'])->assertOk()
            ->assertJsonPath('message', '✓ J&T · TT-1 · JT001. J&T: 1 paket di-scan, 1 belum.');
        $this->postJson(route('gudang.handover.scan-any'), ['code' => 'JT001'])->assertStatus(422)->assertJson(['message' => 'TT-1 sudah ada di daftar.']);
        $this->assertSame(['J&T', 'SPX'], CourierHandover::where('status', 'OPEN')->orderBy('courier')->pluck('courier')->all());

        $jntHandover = CourierHandover::where('courier', 'J&T')->firstOrFail();
        $this->post(route('gudang.handover.store'), ['courier' => 'J&T'])->assertRedirect(route('gudang.handover.show', $jntHandover));
        $this->post(route('gudang.handover.finish', $jntHandover));

        $groups = $this->get(route('gudang.handover'))->assertOk()->assertSee('JT001')->viewData('groups')->keyBy('courier');
        $this->assertSame(['waiting' => 1, 'scanned' => 0, 'shipped' => 1], $groups['J&T']['counts']);
        $this->assertSame(['waiting' => 1, 'scanned' => 1, 'shipped' => 0], $groups['SPX']['counts']);
        $this->assertSame('shipped', $groups['J&T']['orders']->firstWhere('order.id', $jnt->id)['state']);

        // Tanggal lain: paket yang diserahkan hari ini tidak dihitung
        $yesterday = $this->get(route('gudang.handover', ['tanggal' => today()->subDay()->toDateString()]))->viewData('groups')->keyBy('courier');
        $this->assertSame(0, $yesterday['J&T']['counts']['shipped']);
    }

    public function test_managers_map_courier_names_to_an_expedition(): void
    {
        $this->makeSku('JSC-A-L');
        $order = $this->readyOrder('SP-1', 'Reguler', 'R001');
        $this->readyOrder('SP-2', 'SPX Standard', 'SPXID002');

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('gudang.handover.couriers'))->assertForbidden();

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));
        $this->get(route('gudang.handover'))->assertSee('Atur nama ekspedisi');
        $this->get(route('gudang.handover.couriers'))->assertOk()->assertSee('REGULER')->assertSee('SPX STANDARD');

        $this->post(route('gudang.handover.couriers.save'), ['map' => [
            ['name' => 'REGULER', 'courier' => 'spx'],
            ['name' => 'SPX STANDARD', 'courier' => 'SPX'],
        ]])->assertSessionHas('success', '1 nama jasa kirim dipetakan ulang.');

        $this->assertSame('SPX', CourierHandover::brand('Reguler'));
        $this->assertSame(1, \App\Models\CourierAlias::count());   // SPX Standard sudah SPX dari aturan bawaan
        $this->assertSame(['SPX' => 2], $this->waiting($this->get(route('gudang.handover'))));
        $this->postJson(route('gudang.handover.scan-any'), ['code' => 'R001'])->assertOk()->assertJsonPath('items.0.id', 'scanned-spx');

        // Dikosongkan: kembali ke aturan bawaan
        $this->post(route('gudang.handover.couriers.save'), ['map' => [['name' => 'REGULER', 'courier' => '']]]);
        $this->assertSame('REGULER', CourierHandover::brand($order->courier));
        $this->assertSame(0, \App\Models\CourierAlias::count());
    }

    public function test_every_packed_parcel_is_matched_against_the_handover(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $packed = function (Order $order) use ($staff) {
            \App\Models\PackingSession::create(['order_id' => $order->id, 'user_id' => $staff->id, 'status' => 'COMPLETED', 'started_at' => now(), 'completed_at' => now()]);

            return $order;
        };
        $handed = $packed($this->readyOrder('ORD-1', 'SPX Standard', 'SPX001'));
        $packed($this->readyOrder('ORD-2', 'SPX Standard', 'SPX002'));
        $packed($this->readyOrder('ORD-3', 'J&T Express', 'JT003', 'SHIPPED'));   // dikirim tanpa scan
        $packed($this->readyOrder('ORD-4', 'JNE', 'JNE004', 'CANCELLED'));
        $this->readyOrder('ORD-LAMA', 'JNE', 'JNE005');                             // dikemas tanpa sesi packing hari ini

        $this->actingAs($staff)->postJson(route('gudang.handover.scan-any'), ['code' => 'SPX001'])->assertOk();
        $handover = CourierHandover::where('courier', 'SPX')->firstOrFail();
        $this->post(route('gudang.handover.finish', $handover), ['driver_name' => 'Andi']);
        $this->assertSame('SHIPPED', $handed->fresh()->status);

        $response = $this->get(route('gudang.handover'))->assertOk()
            ->assertSee('4 paket')
            ->assertSee('2 perlu dicek')
            ->assertSee('Berstatus Dikirim tanpa scan serah terima')
            ->assertSee('Order dibatalkan setelah dikemas');
        $summary = $response->viewData('packed');
        $this->assertSame([4, 1, 1], [$summary['total'], $summary['handed'], $summary['waiting']]);
        $this->assertSame(['ORD-3', 'ORD-4'], $summary['check']->pluck('order_number')->sort()->values()->all());
    }
}
