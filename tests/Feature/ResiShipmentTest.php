<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/** Resi yang sudah dikirim lewat BigSeller tanpa picking: stok dipotong supaya sama dengan rak */
class ResiShipmentTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number, string $status = 'PENDING', ?string $resi = null, int $qty = 2, string $sku = 'JSC-A-L'): Order
    {
        $order = Order::create(['order_number' => $number, 'customer_name' => 'Budi', 'shipping_address' => 'Batam', 'courier' => 'SPX', 'tracking_number' => $resi, 'status' => $status]);
        OrderItem::create(['order_id' => $order->id, 'sku' => $sku, 'qty_ordered' => $qty, 'qty_picked' => 0, 'qty_packed' => 0, 'price' => 1000]);

        return $order;
    }

    public function test_pasted_resi_cut_stock_and_mark_orders_shipped(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 1);
        $this->putStock('JSC-A-L', 'A-01-02', 3);
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $pending = $this->order('ORD-1', 'PENDING', 'SPXID001', 2);
        $confirmed = $this->order('ORD-2', 'CONFIRMED', 'SPXID002', 2);
        $ready = $this->order('ORD-3', 'READY_TO_SHIP', 'SPXID003', 1);
        $sent = $this->order('ORD-4', 'SHIPPED', 'SPXID004', 1);

        $text = "spxid001\nSPXID002, SPXID003\nSPXID004\nSPXID001\nTIDAKADA999";

        // Cek dulu: belum ada yang berubah
        $this->actingAs($admin)->post(route('gudang.outbound.resi.preview'), ['resi' => $text])
            ->assertOk()
            ->assertSee('Potong stok &amp; kirim', false)
            ->assertSee('TIDAKADA999')
            ->assertSee('Proses 3 order')
            ->assertSee('ditempel lebih dari sekali');
        $this->assertSame('PENDING', $pending->fresh()->status);
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01') + $this->stockQty('JSC-A-L', 'A-01-02'));

        $this->post(route('gudang.outbound.resi.store'), ['resi' => $text])
            ->assertRedirect(route('gudang.outbound.resi'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 order ditandai dikirim') && str_contains($m, 'TIDAKADA999'));

        foreach ([$pending, $confirmed, $ready] as $order) {
            $this->assertSame('SHIPPED', $order->fresh()->status);
            $this->assertNotNull($order->fresh()->shipped_at);
        }
        // 2 + 2 pcs dipotong dari rak; Siap Kirim tidak dipotong lagi
        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-01') + $this->stockQty('JSC-A-L', 'A-01-02'));
        $this->assertSame(4, (int) StockMovement::where('reference_type', 'RESI_BIGSELLER')->sum('qty'));
        $this->assertSame(2, $pending->items()->first()->qty_picked);
        $this->assertTrue(AuditTrail::where('action', 'RESI_BIGSELLER')->exists());

        // Tercatat di riwayat order
        $this->get(route('gudang.outbound.show', $pending->id))->assertSee('Input resi BigSeller SPXID001');

        // Diinput lagi: tidak dipotong dua kali
        $this->post(route('gudang.outbound.resi.store'), ['resi' => 'SPXID001'])
            ->assertSessionHas('error', 'Tidak ada order yang diproses.');
        $this->assertSame(4, (int) StockMovement::where('reference_type', 'RESI_BIGSELLER')->sum('qty'));
    }

    public function test_order_number_works_and_resi_is_saved_when_missing(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $order = $this->order('2511ABC', 'PENDING', null, 1);

        $this->actingAs($this->userWithRole('OWNER'))->post(route('gudang.outbound.resi.store'), ['resi' => '2511abc']);

        $this->assertSame('SHIPPED', $order->fresh()->status);
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_short_stock_in_process_and_cancelled_orders_are_skipped(): void
    {
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 1);
        $admin = $this->userWithRole('SUPERVISOR');
        $short = $this->order('ORD-1', 'PENDING', 'R1', 3);
        $picking = $this->order('ORD-2', 'PICKING', 'R2', 1);
        $cancelled = $this->order('ORD-3', 'CANCELLED', 'R3', 1);

        $this->actingAs($admin)->post(route('gudang.outbound.resi.preview'), ['resi' => "R1\nR2\nR3"])
            ->assertOk()
            ->assertSee('hanya 1 pcs (perlu 3)')
            ->assertSee('Selesaikan lewat menu Picking')
            ->assertSee('dibatalkan di aplikasi')
            ->assertDontSee('Proses 0 order');

        $this->post(route('gudang.outbound.resi.store'), ['resi' => "R1\nR2\nR3"])->assertSessionHas('error');

        $this->assertSame('PENDING', $short->fresh()->status);
        $this->assertSame('PICKING', $picking->fresh()->status);
        $this->assertSame('CANCELLED', $cancelled->fresh()->status);
        $this->assertSame(1, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_only_owner_supervisor_and_admin_can_input_resi(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->actingAs($staff)->get(route('gudang.outbound.resi'))->assertForbidden();
        $this->post(route('gudang.outbound.resi.store'), ['resi' => 'R1'])->assertForbidden();
        $this->get(route('gudang.outbound'))->assertDontSee('Input Resi BigSeller');

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->get(route('gudang.outbound.resi'))->assertOk()->assertSee('Cek Resi');
        $this->get(route('gudang.outbound'))->assertSee('Input Resi BigSeller');
    }
}
