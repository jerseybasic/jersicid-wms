<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Ubah data order: pembeli, alamat, kurir, resi, batas kirim, dan item (sebelum picking).
 */
class OrderEditTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function createOrder($user, array $items): Order
    {
        $this->actingAs($user)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Budi',
            'customer_phone' => '0812',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'courier' => 'JNE',
            'items' => $items,
        ])->assertRedirect(route('gudang.outbound'));

        return Order::with('items')->latest()->firstOrFail();
    }

    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'courier' => $order->courier,
            'tracking_number' => $order->tracking_number,
            'notes' => $order->notes,
            'items' => $order->items->map(fn ($item) => ['sku' => $item->sku, 'qty' => $item->qty_ordered])->all(),
        ], $overrides);
    }

    public function test_customer_data_can_be_changed_and_shows_in_history(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $order = $this->createOrder($admin, [['sku' => 'JSC-A-L', 'qty' => 2]]);

        $this->get(route('gudang.outbound.edit', $order->id))
            ->assertOk()
            ->assertSee('Ubah Order')
            ->assertSee('Jl. Merdeka 1, Bandung');

        $this->put(route('gudang.outbound.update', $order->id), $this->payload($order, [
            'shipping_address' => 'Jl. Asia Afrika 10, Bandung',
            'courier' => 'J&T',
            'ship_by' => '2026-10-25T15:00',
        ]))->assertRedirect(route('gudang.outbound.show', $order->id))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('Jl. Asia Afrika 10, Bandung', $order->shipping_address);
        $this->assertSame('J&T', $order->courier);
        $this->assertSame('2026-10-25 15:00', $order->ship_by->format('Y-m-d H:i'));
        $this->assertSame(1, AuditTrail::where('action', 'UPDATE')->where('module', 'OUTBOUND')->count());

        $this->get(route('gudang.outbound.show', $order->id))
            ->assertOk()
            ->assertSee('Data order diubah')
            ->assertSee('Diubah: alamat, kurir, batas kirim');
    }

    public function test_saving_without_changes_writes_no_history(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $order = $this->createOrder($admin, [['sku' => 'JSC-A-L', 'qty' => 2]]);

        $this->put(route('gudang.outbound.update', $order->id), $this->payload($order))
            ->assertSessionHas('success', 'Tidak ada perubahan.');
        $this->assertSame(0, AuditTrail::where('action', 'UPDATE')->count());
    }

    public function test_items_can_be_changed_before_picking_keeping_old_price(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L', 150000);
        $this->makeSku('JSC-B-M', 120000);
        $order = $this->createOrder($admin, [['sku' => 'JSC-A-L', 'qty' => 2]]);
        $order->items()->update(['price' => 99000]);
        $order->load('items');

        $this->put(route('gudang.outbound.update', $order->id), $this->payload($order, [
            'items' => [['sku' => 'JSC-A-L', 'qty' => 3], ['sku' => 'JSC-B-M', 'qty' => 1]],
        ]))->assertSessionHas('success');

        $items = $order->fresh()->items->keyBy('sku');
        $this->assertCount(2, $items);
        $this->assertSame(3, $items['JSC-A-L']->qty_ordered);
        $this->assertEquals(99000, $items['JSC-A-L']->price);
        $this->assertEquals(120000, $items['JSC-B-M']->price);
    }

    public function test_confirmed_order_items_need_enough_stock(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 3);
        $order = $this->createOrder($admin, [['sku' => 'JSC-A-L', 'qty' => 2]]);
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED'])->assertSessionHas('success');

        $this->from(route('gudang.outbound.edit', $order->id))
            ->put(route('gudang.outbound.update', $order->id), $this->payload($order, ['items' => [['sku' => 'JSC-A-L', 'qty' => 10]]]))
            ->assertRedirect(route('gudang.outbound.edit', $order->id))
            ->assertSessionHas('error');

        $this->assertSame(2, $order->fresh()->items->first()->qty_ordered);
        $this->assertSame(0, AuditTrail::where('action', 'UPDATE')->count());
    }

    public function test_items_are_locked_for_staff_once_picking_started(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 10);
        $order = $this->createOrder($admin, [['sku' => 'JSC-A-L', 'qty' => 2]]);
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id])->assertSessionHas('success');

        // Staf: item terkunci, data lain tetap bisa diubah
        $this->actingAs($staff)->get(route('gudang.outbound.edit', $order->id))
            ->assertOk()
            ->assertSee('Item hanya bisa diganti oleh Owner, Supervisor, atau Admin Gudang.');

        $this->put(route('gudang.outbound.update', $order->id), $this->payload($order, ['items' => [['sku' => 'JSC-A-L', 'qty' => 5]]]))
            ->assertSessionHasErrors('items');

        $payload = $this->payload($order, ['customer_phone' => '0899']);
        unset($payload['items']);
        $this->put(route('gudang.outbound.update', $order->id), $payload)->assertSessionHas('success');
        $this->assertSame('0899', $order->fresh()->customer_phone);
        $this->assertSame(2, $order->fresh()->items->first()->qty_ordered);

        // Admin Gudang tetap bisa mengganti item, dengan peringatan bahwa order sudah diproses
        $this->actingAs($admin)->get(route('gudang.outbound.edit', $order->id))
            ->assertOk()
            ->assertSee('Order ini sudah mulai diproses');
    }

    public function test_completed_or_cancelled_order_cannot_be_changed(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $order = $this->createOrder($admin, [['sku' => 'JSC-A-L', 'qty' => 2]]);
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CANCELLED'])->assertSessionHas('success');

        $this->get(route('gudang.outbound.show', $order->id))->assertOk()->assertDontSee(route('gudang.outbound.edit', $order->id));
        $this->get(route('gudang.outbound.edit', $order->id))->assertForbidden();
        $this->put(route('gudang.outbound.update', $order->id), $this->payload($order, ['customer_name' => 'Andi']))->assertForbidden();
        $this->assertSame('Budi', $order->fresh()->customer_name);
    }
}
