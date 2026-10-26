<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackingSession;
use App\Models\PickingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/** Tombol scan pakai kamera HP dan isi resi dari layar packing */
class CameraScanAndResiTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function packingWithoutResi(): PackingSession
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $order = Order::create(['order_number' => 'ORD-1', 'customer_name' => 'Budi', 'shipping_address' => 'Bandung', 'courier' => 'JNE', 'status' => 'CONFIRMED']);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 1, 'price' => 1000]);

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->post(route('gudang.picking-batch.auto'));
        $picking = PickingSession::where('order_id', $order->id)->firstOrFail();
        $item = $picking->items()->first();
        $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => 1, 'bin_code' => 'A-01-01', 'reason' => 'tes']);
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id]);

        return PackingSession::where('order_id', $order->id)->firstOrFail();
    }

    public function test_scan_screens_have_a_camera_button(): void
    {
        $session = $this->packingWithoutResi();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('gudang.packing.show', $session->id))->assertOk()
            ->assertSee('openCamera()', false)
            ->assertSee('Scan barcode resi di label BigSeller')
            ->assertSee("cameraFill('resiInput'", false)
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $this->get(route('gudang.retur.create'))->assertOk()->assertSee("cameraFill('returnOrderCode'", false);
    }

    public function test_staff_fills_missing_resi_and_only_managers_change_it(): void
    {
        $session = $this->packingWithoutResi();
        Order::create(['order_number' => 'ORD-2', 'customer_name' => 'Ani', 'shipping_address' => 'Bogor', 'tracking_number' => 'JNE222', 'status' => 'CONFIRMED']);
        $url = route('gudang.packing.resi', $session->id);

        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->actingAs($staff)->post($url, ['tracking_number' => 'ord-2'])->assertSessionHas('error', 'ORD-2 adalah nomor order, bukan resi. Scan barcode resi di label kirim.');
        $this->post($url, ['tracking_number' => 'jne222'])->assertSessionHas('error', 'Resi JNE222 sudah dipakai order ORD-2. Cek lagi labelnya, mungkin tertukar.');
        $this->post($url, ['tracking_number' => ' jne 111 '])->assertSessionHas('success');

        $order = $session->order->fresh();
        $this->assertSame('JNE111', $order->tracking_number);
        $this->assertSame('JNE111', $session->fresh()->tracking_number);
        $this->assertSame('JNE111', json_decode(AuditTrail::where('action', 'SET_RESI')->firstOrFail()->new_values, true)['tracking_number']);
        $this->assertSame($order->id, Order::findByCode('jne111')->id);

        // Sudah terisi: staf tidak bisa mengganti, pengawas bisa
        $this->post($url, ['tracking_number' => 'JNE333'])->assertSessionHas('error');
        $this->assertSame('JNE111', $order->fresh()->tracking_number);
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->post($url, ['tracking_number' => 'JNE333'])->assertSessionHas('success');
        $this->assertSame('JNE333', $order->fresh()->tracking_number);
        $this->assertTrue(AuditTrail::where('action', 'CHANGE_RESI')->exists());
    }
}
