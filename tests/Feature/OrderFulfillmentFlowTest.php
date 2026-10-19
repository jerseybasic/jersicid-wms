<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Alur order lengkap: buat order → konfirmasi → picking → packing → kirim → selesai.
 */
class OrderFulfillmentFlowTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function createOrder($user, string $sku, int $qty): Order
    {
        $this->actingAs($user)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Budi',
            'customer_phone' => '0812',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'courier' => 'JNE',
            'items' => [['sku' => $sku, 'qty' => $qty]],
        ])->assertRedirect(route('gudang.outbound'));

        return Order::with('items')->latest()->firstOrFail();
    }

    public function test_order_runs_from_creation_until_shipped_and_stock_is_reduced(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 10);

        $order = $this->createOrder($staff, 'JSC-A-L', 3);
        $this->assertSame('PENDING', $order->status);

        // Konfirmasi order
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED'])
            ->assertSessionHas('success');
        $this->assertSame('CONFIRMED', $order->fresh()->status);

        // Picking
        $this->get(route('gudang.picking.create'))->assertOk()->assertSee($order->order_number);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id])->assertSessionHas('success');
        $picking = PickingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('PICKING', $order->fresh()->status);
        $this->assertSame('A1-01', $picking->items->first()->bin_code);

        $this->post(route('gudang.picking.start', $picking->id))->assertSessionHas('success');
        $this->get(route('gudang.picking.show', $picking->id))->assertOk();

        // Ambil sebagian dulu, lalu sisanya: stok hanya berkurang total 3
        $item = $picking->items->first();
        $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => 1, 'bin_code' => 'A1-01'])
            ->assertSessionHas('success');
        $this->assertSame(9, $this->stockQty('JSC-A-L', 'A1-01'));

        $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => 3, 'bin_code' => 'A1-01'])
            ->assertSessionHas('success');
        $this->assertSame(7, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->assertSame('COMPLETED', $picking->fresh()->status);

        $order->refresh();
        $this->assertSame('PACKING', $order->status);
        $this->assertSame($staff->id, $order->picked_by);
        $this->assertSame(3, $order->items->first()->fresh()->qty_picked);

        // Packing
        $this->get(route('gudang.packing.create'))->assertOk()->assertSee($order->order_number);
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id, 'courier' => 'JNE'])
            ->assertSessionHas('success');
        $packing = PackingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.packing.start', $packing->id))->assertSessionHas('success');
        $this->get(route('gudang.packing.show', $packing->id))->assertOk();

        $this->post(route('gudang.packing.pack', [$packing->id, $packing->items->first()->id]), ['qty_packed' => 3])
            ->assertSessionHas('success');
        $this->assertSame('COMPLETED', $packing->fresh()->status);
        $this->assertSame('READY_TO_SHIP', $order->fresh()->status);

        // Kirim wajib isi resi
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'SHIPPED'])
            ->assertSessionHasErrors('tracking_number');
        $this->assertSame('READY_TO_SHIP', $order->fresh()->status);

        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'SHIPPED', 'tracking_number' => 'JNE123'])
            ->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('SHIPPED', $order->status);
        $this->assertSame('JNE123', $order->tracking_number);
        $this->assertNotNull($order->shipped_at);

        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'COMPLETED'])
            ->assertSessionHas('success');
        $this->assertSame('COMPLETED', $order->fresh()->status);

        // Halaman daftar order tetap terbuka untuk semua status
        $this->get(route('gudang.outbound'))->assertOk()->assertSee($order->order_number);
    }

    public function test_outbound_list_renders_action_for_every_status(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');

        foreach (array_keys(Order::STATUS_LABELS) as $i => $status) {
            Order::create([
                'order_number' => "ORD-{$i}",
                'customer_name' => 'Budi',
                'shipping_address' => 'Bandung',
                'status' => $status,
            ]);
        }

        $this->actingAs($staff)->get(route('gudang.outbound'))->assertOk()->assertSee('Konfirmasi')->assertSee('Kirim');
    }

    public function test_status_cannot_skip_picking_and_packing(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $order = $this->createOrder($staff, 'JSC-A-L', 1);

        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'SHIPPED', 'tracking_number' => 'X'])
            ->assertSessionHas('error');
        $this->assertSame('PENDING', $order->fresh()->status);
    }

    public function test_picking_more_than_available_stock_is_rejected(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 1);

        $order = $this->createOrder($staff, 'JSC-A-L', 2);
        $order->update(['status' => 'CONFIRMED']);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));

        $this->post(route('gudang.picking.pick', [$picking->id, $picking->items->first()->id]), ['qty_picked' => 2, 'bin_code' => 'A1-01'])
            ->assertSessionHas('error');

        $this->assertSame(1, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->assertSame('PICKING', $order->fresh()->status);
    }

    public function test_deleting_picking_session_returns_stock_and_order(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 5);

        $order = $this->createOrder($staff, 'JSC-A-L', 3);
        $order->update(['status' => 'CONFIRMED']);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));
        $this->post(route('gudang.picking.pick', [$picking->id, $picking->items->first()->id]), ['qty_picked' => 2, 'bin_code' => 'A1-01']);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A1-01'));

        $this->delete(route('gudang.picking.destroy', $picking->id))->assertSessionHas('success');

        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->assertSame('CONFIRMED', $order->fresh()->status);
        $this->assertDatabaseMissing('picking_sessions', ['id' => $picking->id]);
    }
}
