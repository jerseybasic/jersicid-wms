<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Aksi massal di daftar Outbound: konfirmasi, selesai, batalkan, dan cetak label beberapa order sekaligus.
 */
class OrderBulkActionTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number, string $status, string $sku = 'JSC-A-L', int $qty = 1, ?string $shipBy = null): Order
    {
        $order = Order::create([
            'order_number' => $number,
            'customer_name' => 'Budi',
            'shipping_address' => 'Bandung',
            'status' => $status,
            'ship_by' => $shipBy ?? now()->addDay(),
        ]);
        $order->items()->create(['sku' => $sku, 'qty_ordered' => $qty, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 0]);

        return $order;
    }

    public function test_list_shows_checkboxes_and_per_page_option(): void
    {
        $this->makeSku('JSC-A-L');
        $order = $this->order('BULK-1', 'PENDING');

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->get(route('gudang.outbound', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('value="' . $order->id . '"', false)
            ->assertSee('50 / halaman')
            ->assertSee(route('gudang.outbound.bulk-status'));
    }

    public function test_bulk_confirm_gives_limited_stock_to_most_urgent_order_and_skips_the_rest(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 3);
        $later = $this->order('BULK-LATER', 'PENDING', qty: 2, shipBy: now()->addDays(2));
        $urgent = $this->order('BULK-URGENT', 'PENDING', qty: 2, shipBy: now()->addHour());
        $shipped = $this->order('BULK-SHIPPED', 'SHIPPED');

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->from(route('gudang.outbound'))
            ->post(route('gudang.outbound.bulk-status'), ['ids' => [$later->id, $urgent->id, $shipped->id], 'status' => 'CONFIRMED'])
            ->assertRedirect(route('gudang.outbound'))
            ->assertSessionHas('success', '1 order dikonfirmasi.')
            ->assertSessionHas('error', fn ($message) => str_starts_with($message, '2 order dilewati:')
                && str_contains($message, 'BULK-LATER belum bisa dikonfirmasi, stok kurang')
                && str_contains($message, 'BULK-SHIPPED tidak bisa diubah'));

        $this->assertSame('CONFIRMED', $urgent->fresh()->status);
        $this->assertSame('PENDING', $later->fresh()->status);
        $this->assertSame('SHIPPED', $shipped->fresh()->status);
        $this->assertSame(1, AuditTrail::where('action', 'UPDATE_STATUS')->count());
    }

    public function test_bulk_complete_and_cancel(): void
    {
        $this->makeSku('JSC-A-L');
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $a = $this->order('BULK-S1', 'SHIPPED');
        $b = $this->order('BULK-S2', 'SHIPPED');
        $c = $this->order('BULK-P1', 'PENDING');

        $this->actingAs($admin)->post(route('gudang.outbound.bulk-status'), ['ids' => [$a->id, $b->id], 'status' => 'COMPLETED'])
            ->assertSessionHas('success', '2 order diselesaikan.');
        $this->post(route('gudang.outbound.bulk-status'), ['ids' => [$c->id], 'status' => 'CANCELLED'])
            ->assertSessionHas('success', '1 order dibatalkan.');

        $this->assertSame(['COMPLETED', 'COMPLETED', 'CANCELLED'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
    }

    public function test_bulk_rejects_shipping_without_tracking_and_empty_selection(): void
    {
        $this->makeSku('JSC-A-L');
        $order = $this->order('BULK-R1', 'READY_TO_SHIP');

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->post(route('gudang.outbound.bulk-status'), ['ids' => [$order->id], 'status' => 'SHIPPED'])
            ->assertSessionHasErrors('status');
        $this->post(route('gudang.outbound.bulk-status'), ['status' => 'CONFIRMED'])
            ->assertSessionHasErrors(['ids' => 'Pilih minimal satu order.']);
        $this->assertSame('READY_TO_SHIP', $order->fresh()->status);
    }

    public function test_bulk_label_prints_only_selected_packed_orders(): void
    {
        $this->makeSku('JSC-A-L');
        $ready = $this->order('BULK-RTS-1', 'READY_TO_SHIP');
        $other = $this->order('BULK-RTS-2', 'READY_TO_SHIP');
        $pending = $this->order('BULK-PEND', 'PENDING');
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));

        $this->get(route('gudang.label.shipping-bulk', ['ids' => [$ready->id, $pending->id]]))
            ->assertOk()
            ->assertSee('BULK-RTS-1')
            ->assertDontSee('BULK-RTS-2')
            ->assertDontSee('BULK-PEND');

        $this->get(route('gudang.label.shipping-bulk', ['ids' => [$pending->id]]))
            ->assertRedirect(route('gudang.outbound'))
            ->assertSessionHas('error');

        // Tanpa pilihan: semua yang siap kirim
        $this->get(route('gudang.label.shipping-bulk'))->assertOk()->assertSee('BULK-RTS-1')->assertSee('BULK-RTS-2');
    }
}
