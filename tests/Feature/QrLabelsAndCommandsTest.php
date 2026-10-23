<?php

namespace Tests\Feature;

use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\ShipmentClosing;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Cetak QR master SKU, rak & bin, QR perintah, dan tutup kiriman harian.
 */
class QrLabelsAndCommandsTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number, string $status, array $extra = []): Order
    {
        $this->travel(1)->minutes();
        $order = Order::create($extra + [
            'order_number' => $number,
            'customer_name' => 'Pembeli ' . $number,
            'shipping_address' => 'Bandung',
            'courier' => 'SPX Standard',
            'status' => $status,
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 1, 'price' => 1000]);

        return $order;
    }

    public function test_printer_page_prints_sku_rack_and_command_qr_labels(): void
    {
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-XL');
        WarehouseRack::create(['code' => 'A']);
        WarehouseRack::create(['code' => 'B']);
        $this->putStock('JSC-A-L', 'A-01-01', 3);
        $this->putStock('JSC-B-XL', 'A-02-01', 3);
        $this->putStock('JSC-B-XL', 'B-01-01', 3);

        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->get(route('gudang.printer'))->assertOk()
            ->assertSee('QR Master SKU')->assertSee('QR Rak & Bin', false)->assertSee('MULAI PICKING');

        // Daftar SKU: jumlah dengan *, spasi, atau baris sendiri; SKU tak dikenal dilewati
        $html = $this->get(route('gudang.label.sku', ['daftar' => "jsc-a-l*2\nJSC-B-XL 3, JSC-A-L\nTIDAK-ADA"]))->assertOk();
        $this->assertSame(6, count($html->viewData('labels')));
        $html->assertSee('TIDAK-ADA');

        $this->assertSame(2, count($this->get(route('gudang.label.sku', ['cari' => 'JSC']))->viewData('labels')));

        // Rak A beserta bin yang dipakai, ditambah rentang bin baru
        $bins = $this->get(route('gudang.label.bins', ['rak' => ['A'], 'dengan_bin' => 1, 'bin' => "c-01-08..c-01-10\nZ-9"]))->assertOk();
        $this->assertSame(
            ['A', 'A-01-01', 'A-02-01', 'C-01-08', 'C-01-09', 'C-01-10', 'Z-9'],
            array_column($bins->viewData('labels'), 'code')
        );
        $this->assertSame('stiker', $bins->viewData('size'));
        $this->assertSame(['A'], array_column($this->get(route('gudang.label.bins', ['rak' => ['A']]))->viewData('labels'), 'code'));
        $this->get(route('gudang.label.bins'))->assertNotFound();

        $commands = $this->get(route('gudang.label.commands'))->assertOk()->assertSee('TUTUP KIRIMAN HARI INI');
        $this->assertSame(['CMD-PICKING', 'CMD-PACKING', 'CMD-RETUR', 'CMD-TUTUP'], array_column($commands->viewData('labels'), 'code'));
        $this->assertSame('besar', $commands->viewData('size'));
    }

    public function test_command_qr_starts_picking_and_packing(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $staff = $this->userWithRole('STAFF_GUDANG');
        $other = $this->userWithRole('STAFF_GUDANG');
        $first = $this->order('SP-1', 'CONFIRMED');
        $second = $this->order('SP-2', 'CONFIRMED');

        $this->actingAs($staff)->get(route('gudang.tugas'))->assertSee('Scan QR perintah');

        // Order terlama diambil dan langsung dimulai
        $response = $this->postJson(route('gudang.command'), ['code' => 'cmd-picking'])->assertOk()->assertJson(['ok' => true]);
        $session = PickingSession::where('order_id', $first->id)->firstOrFail();
        $this->assertSame([$staff->id, 'IN_PROGRESS'], [$session->user_id, $session->status]);
        $this->assertSame(route('gudang.picking.show', $session->id), $response->json('redirect'));
        $this->assertSame('PICKING', $first->fresh()->status);

        // Scan lagi melanjutkan sesi yang sama, bukan mengambil order baru
        $this->postJson(route('gudang.command'), ['code' => 'CMD-PICKING'])->assertJsonPath('redirect', route('gudang.picking.show', $session->id));
        $this->assertSame(1, PickingSession::count());

        // Staf lain mendapat order berikutnya, lalu tidak ada lagi
        $this->actingAs($other)->postJson(route('gudang.command'), ['code' => 'CMD-PICKING'])->assertOk();
        $this->assertTrue(PickingSession::where('order_id', $second->id)->where('user_id', $other->id)->exists());
        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->postJson(route('gudang.command'), ['code' => 'CMD-PICKING'])
            ->assertNotFound()->assertJson(['ok' => false, 'message' => 'Tidak ada order yang menunggu picking.']);

        // Packing: order selesai picking yang terlama
        $this->postJson(route('gudang.command'), ['code' => 'CMD-PACKING'])->assertNotFound();
        $picked = $this->order('SP-3', 'PACKING', ['picked_at' => now()->subHour()]);
        $this->order('SP-4', 'PACKING', ['picked_at' => now()]);
        $response = $this->actingAs($staff)->postJson(route('gudang.command'), ['code' => 'CMD-PACKING'])->assertOk();
        $packing = PackingSession::where('order_id', $picked->id)->firstOrFail();
        $this->assertSame(['IN_PROGRESS', $staff->id], [$packing->status, $packing->user_id]);
        $this->assertSame(route('gudang.packing.show', $packing->id), $response->json('redirect'));

        $this->postJson(route('gudang.command'), ['code' => 'CMD-RETUR'])->assertJsonPath('redirect', route('gudang.retur.create'));
        $this->postJson(route('gudang.command'), ['code' => 'CMD-TUTUP'])->assertJsonPath('redirect', route('gudang.closing'));
        $this->postJson(route('gudang.command'), ['code' => 'JSC-A-L'])->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_close_todays_shipments(): void
    {
        $this->makeSku('JSC-A-L');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $spx = $this->order('SP-1', 'SHIPPED');
        $this->order('SP-2', 'SHIPPED');
        $jne = $this->order('TK-1', 'READY_TO_SHIP', ['courier' => 'JNE REG']);
        $leftOver = $this->order('SP-3', 'READY_TO_SHIP');

        $done = CourierHandover::create(['courier' => 'SPX', 'user_id' => $staff->id, 'status' => 'COMPLETED', 'completed_at' => now()]);
        $done->items()->create(['order_id' => $spx->id]);
        $done->items()->create(['order_id' => Order::where('order_number', 'SP-2')->value('id')]);
        $yesterday = CourierHandover::create(['courier' => 'SPX', 'user_id' => $staff->id, 'status' => 'COMPLETED', 'completed_at' => now()->subDay()]);
        $open = CourierHandover::create(['courier' => 'JNE', 'user_id' => $staff->id, 'status' => 'OPEN']);
        $open->items()->create(['order_id' => $jne->id]);

        $this->actingAs($staff);
        $page = $this->get(route('gudang.closing'))->assertOk()->assertSee('Selesaikan dulu serah terima');
        $this->assertSame(['SPX' => 2], $page->viewData('shipped')->all());
        $this->assertSame(['JNE' => 1, 'SPX' => 1], $page->viewData('left')->all());

        $this->post(route('gudang.closing.store'))->assertSessionHas('error');
        $this->assertSame(0, ShipmentClosing::count());

        $open->items()->delete();
        $this->post(route('gudang.closing.store'), ['notes' => 'JNE tidak datang'])->assertSessionHas('success');
        $closing = ShipmentClosing::firstOrFail();
        $this->assertSame([2, 2, ['SPX' => 2], $staff->id], [$closing->shipped_count, $closing->left_count, $closing->couriers, $closing->user_id]);

        // Tutup ulang memperbarui catatan hari yang sama
        $this->get(route('gudang.closing'))->assertSee('Kiriman hari ini sudah ditutup')->assertSee('JNE tidak datang');
        $this->post(route('gudang.closing.store'));
        $this->assertSame(1, ShipmentClosing::count());
        $this->assertNull(ShipmentClosing::first()->notes);
        $this->assertNotNull($yesterday);
        $this->assertNotNull($leftOver);
    }
}
