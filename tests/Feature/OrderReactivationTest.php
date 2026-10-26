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

/** Order yang dibatalkan (salah klik atau uji coba) bisa diaktifkan lagi sebagai order baru */
class OrderReactivationTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number, string $status = 'PENDING'): Order
    {
        $order = Order::create(['order_number' => $number, 'customer_name' => 'Budi', 'shipping_address' => 'Batam', 'courier' => 'SPX', 'tracking_number' => 'SPX-' . $number, 'status' => $status]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 2, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 1000]);

        return $order;
    }

    public function test_cancelled_order_comes_back_as_pending_and_can_be_processed_again(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $order = $this->order('ORD-1');

        $this->actingAs($admin)->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CANCELLED']);
        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->get(route('gudang.outbound.show', $order->id))->assertSee('Aktifkan lagi');

        // Staf biasa tidak bisa
        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->post(route('gudang.outbound.reactivate', $order->id))->assertForbidden();

        $this->actingAs($admin)->post(route('gudang.outbound.reactivate', $order->id))->assertSessionHas('success');
        $this->assertSame('PENDING', $order->fresh()->status);
        $this->assertTrue(AuditTrail::where('action', 'REACTIVATE')->exists());
        $this->get(route('gudang.outbound.show', $order->id))->assertSee('Diaktifkan lagi');

        // Order yang tidak dibatalkan ditolak
        $this->post(route('gudang.outbound.reactivate', $order->id))->assertSessionHas('error');

        // Diproses lagi seperti order baru sampai dikemas
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);
        $this->assertSame('CONFIRMED', $order->fresh()->status);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $this->assertSame(1, PickingSession::where('order_id', $order->id)->count());
    }

    public function test_cancelled_order_in_process_needs_goods_back_on_the_rack_first(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $order = $this->order('ORD-2', 'CONFIRMED');

        $this->actingAs($admin)->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));
        $item = $picking->items->first();
        $this->postJson(route('gudang.picking.tap', [$picking->id, $item->id]), ['delta' => 1]);
        $this->postJson(route('gudang.picking.tap', [$picking->id, $item->id]), ['delta' => 1]);
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id]);
        $this->post(route('gudang.outbound.cancel', $order->id));
        $this->assertSame('CANCELLED', $order->fresh()->status);

        // Barang masih di keranjang: kembalikan ke rak dulu
        $this->post(route('gudang.outbound.reactivate', $order->id))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '2 pcs barangnya belum dikembalikan'));

        $orderItem = $order->items()->first();
        $this->postJson(route('gudang.restock.tap', $orderItem->id))->assertOk();
        $this->postJson(route('gudang.restock.tap', $orderItem->id))->assertOk();
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Lewat aksi massal: sesi lama dihapus, order kembali Pending
        $this->post(route('gudang.outbound.bulk-status'), ['ids' => [$order->id], 'status' => 'REACTIVATE'])
            ->assertSessionHas('success', '1 order diaktifkan lagi (Pending).');
        $this->assertSame('PENDING', $order->fresh()->status);
        $this->assertSame(0, PickingSession::where('order_id', $order->id)->count());
        $this->assertSame(0, PackingSession::where('order_id', $order->id)->count());
        $this->assertSame(0, (int) $orderItem->fresh()->qty_packed);
    }
}
