<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackingSession;
use App\Models\PickingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Gelombang 4: layar picking "Ambil sekarang", input manual dengan alasan, packing tanpa pindah halaman,
 * beranda staf (Tugas), dan bukti serah terima (tanda tangan + foto).
 */
class StaffStationsTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function confirmedOrder(string $number, int $qty = 2): Order
    {
        $order = Order::create(['order_number' => $number, 'customer_name' => 'Budi', 'shipping_address' => 'Bandung', 'courier' => 'JNE', 'tracking_number' => 'JNE-' . $number, 'status' => 'CONFIRMED']);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => $qty, 'price' => 1000]);

        return $order;
    }

    public function test_picking_shows_next_item_and_manual_input_needs_a_manager_and_a_reason(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $order = $this->confirmedOrder('ORD-1');
        $staff = $this->userWithRole('STAFF_GUDANG');

        // Staf: satu ketukan dari Tugas langsung ke picking
        $this->actingAs($staff)->get(route('gudang.tugas'))->assertOk()->assertSee('Picking')->assertSee('Serah Terima');
        $this->post(route('gudang.picking-batch.auto'));
        $session = PickingSession::where('order_id', $order->id)->firstOrFail();
        $item = $session->items()->first();
        $this->get(route('gudang.picking.show', $session->id))->assertOk()->assertSee('Ambil sekarang')->assertSee('pickNext', false);

        // Input jumlah manual: staf ditolak, pengawas wajib menulis alasan
        $this->post(route('gudang.picking.pick', [$session->id, $item->id]), ['qty_picked' => 2, 'bin_code' => 'A-01-01', 'reason' => 'x'])->assertForbidden();
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->actingAs($admin)->post(route('gudang.picking.pick', [$session->id, $item->id]), ['qty_picked' => 2, 'bin_code' => 'A-01-01'])
            ->assertSessionHasErrors('reason');
        $this->post(route('gudang.picking.pick', [$session->id, $item->id]), ['qty_picked' => 2, 'bin_code' => 'A-01-01', 'reason' => 'barcode sobek'])
            ->assertSessionHas('success');
        $audit = AuditTrail::where('action', 'MANUAL_QTY')->where('module', 'PICKING')->firstOrFail();
        $this->assertSame('barcode sobek', json_decode($audit->new_values, true)['reason']);

        // Selesai: langsung lanjut ke packing order ini
        $this->get(route('gudang.picking.show', $session->id))->assertSee('Kemas order ini sekarang');
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id]);
        $packing = PackingSession::where('order_id', $order->id)->firstOrFail();

        // Layar packing menyiapkan scan keranjang berikutnya tanpa memuat ulang halaman
        $this->get(route('gudang.packing.show', $packing->id))->assertOk()
            ->assertSee('Paket selesai dikemas')->assertSee(str_replace('/', '\\/', route('gudang.command')), false);
        $scan = route('gudang.packing.scan', $packing->id);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()->assertJson(['completed' => false]);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()->assertJson(['completed' => true]);
        $this->assertSame('READY_TO_SHIP', $order->fresh()->status);
    }

    public function test_packing_station_button_opens_the_next_basket(): void
    {
        $this->makeSku('JSC-A-L');
        $first = $this->confirmedOrder('ORD-1');
        $first->update(['status' => 'PACKING', 'ship_by' => now()->addDay()]);
        $later = $this->confirmedOrder('ORD-2');
        $later->update(['status' => 'PACKING', 'ship_by' => now()->addDays(3)]);

        $tugas = $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('gudang.tugas'))->assertOk()->assertSee('kemas berikutnya');
        $this->assertSame('ORD-1', $tugas->viewData('toPack')->first()->order_number);
    }

    public function test_courier_signature_and_photo_are_kept_with_the_handover(): void
    {
        Storage::fake('local');
        $this->makeSku('JSC-A-L');
        $order = $this->confirmedOrder('ORD-1');
        $order->update(['status' => 'READY_TO_SHIP', 'courier' => 'SPX Standard', 'tracking_number' => 'SPXID001', 'packed_at' => now()]);
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));

        $this->post(route('gudang.handover.store'), ['courier' => 'SPX']);
        $handover = CourierHandover::firstOrFail();
        $this->get(route('gudang.handover.show', $handover))->assertSee('Tanda tangan kurir');
        $this->postJson(route('gudang.handover.scan', $handover), ['code' => 'SPXID001'])->assertOk();

        // Tanda tangan yang tidak valid ditolak
        $this->post(route('gudang.handover.finish', $handover), ['signature' => 'bukan-gambar'])->assertSessionHasErrors('signature');
        $this->assertSame('OPEN', $handover->fresh()->status);

        $png = 'data:image/png;base64,' . base64_encode(UploadedFile::fake()->image('ttd.png', 300, 100)->getContent());
        $this->post(route('gudang.handover.finish', $handover), [
            'driver_name' => 'Pak Andi',
            'signature' => $png,
            'photo' => UploadedFile::fake()->image('paket.jpg', 800, 600),
        ])->assertRedirect(route('gudang.handover.print', $handover));

        $handover->refresh();
        $this->assertSame('COMPLETED', $handover->status);
        Storage::disk('local')->assertExists([$handover->signature_path, $handover->photo_path]);
        $this->get(route('gudang.handover.print', $handover))->assertOk()
            ->assertSee(route('gudang.handover.proof', [$handover, 'tanda-tangan']), false)->assertSee('Lihat foto tumpukan paket');
        $this->get(route('gudang.handover.proof', [$handover, 'tanda-tangan']))->assertOk();
        $this->get(route('gudang.handover.proof', [$handover, 'foto']))->assertOk();

        // Serah terima yang sudah selesai tidak menimpa buktinya
        $this->post(route('gudang.handover.finish', $handover), ['signature' => $png])->assertSessionHas('error');
        $this->assertSame($handover->signature_path, $handover->fresh()->signature_path);
    }
}
