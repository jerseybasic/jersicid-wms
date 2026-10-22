<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Layar Tugas Saya (ambil tugas langsung mulai) dan cetak label dari browser.
 */
class TasksAndLabelsTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function confirmedOrder($user, string $sku, int $qty): Order
    {
        $this->actingAs($user)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Siti Aminah',
            'customer_phone' => '0813',
            'shipping_address' => 'Jl. Asia Afrika 5, Bandung',
            'courier' => 'JNT',
            'items' => [['sku' => $sku, 'qty' => $qty]],
        ]);
        $order = Order::latest()->firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);

        return $order->fresh();
    }

    public function test_staff_takes_tasks_from_the_task_screen_and_prints_shipping_label(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $order = $this->confirmedOrder($staff, 'JSC-A-L', 1);

        $this->get(route('gudang.tugas'))->assertOk()->assertSee($order->order_number)->assertSee('Ambil & mulai picking', false);

        // Label kirim belum boleh sebelum packing selesai
        $this->get(route('gudang.label.shipping', $order->id))->assertStatus(422);

        $response = $this->post(route('gudang.picking.store'), ['order_id' => $order->id, 'start_now' => 1]);
        $picking = PickingSession::where('order_id', $order->id)->firstOrFail();
        $response->assertRedirect(route('gudang.picking.show', $picking->id));
        $this->assertSame('IN_PROGRESS', $picking->status);

        $this->get(route('gudang.tugas'))->assertOk()->assertSee('Lanjutkan picking');
        $this->postJson(route('gudang.picking.scan', $picking->id), ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])
            ->assertJson(['completed' => true]);

        $this->get(route('gudang.tugas'))->assertSee('Ambil & mulai packing', false);
        $response = $this->post(route('gudang.packing.store'), ['order_id' => $order->id, 'courier' => 'JNT', 'start_now' => 1]);
        $packing = PackingSession::where('order_id', $order->id)->firstOrFail();
        $response->assertRedirect(route('gudang.packing.show', $packing->id));
        $this->postJson(route('gudang.packing.scan', $packing->id), ['code' => 'JSC-A-L'])->assertJson(['completed' => true]);

        $this->get(route('gudang.packing.show', $packing->id))->assertSee('Cetak label kirim');
        $this->get(route('gudang.tugas'))->assertSee('Siap kirim')->assertSee('Cetak label kirim');

        $this->get(route('gudang.label.shipping', $order->id))
            ->assertOk()
            ->assertSee('Siti Aminah')
            ->assertSee('Jl. Asia Afrika 5, Bandung')
            ->assertSee($order->order_number)
            ->assertSee('<svg', false);
    }

    public function test_inbound_receipt_prints_one_sku_label_per_piece(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $warehouse = $this->makeWarehouse();

        $response = $this->actingAs($staff)->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-LBL',
            'supplier_name' => 'JB Printing',
            'received_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 3]],
        ]);
        $movement = StockMovement::where('reference_no', 'SJ-LBL')->firstOrFail();
        $response->assertRedirect(route('gudang.inbound.show', $movement->id));

        $this->get(route('gudang.inbound.show', $movement->id))->assertOk()->assertSee('Cetak label SKU (3)');

        $html = $this->get(route('gudang.label.sku', ['ref' => 'SJ-LBL']))->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'class="label"'));
        $this->assertStringContainsString('Bin A-01-01', $html);

        $html = $this->get(route('gudang.label.sku', ['sku' => 'JSC-A-L', 'qty' => 2, 'ukuran' => 'a4']))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'class="label"'));
        $this->assertStringContainsString('size: A4', $html);

        $this->get(route('gudang.label.sku', ['ref' => 'TIDAK-ADA']))->assertNotFound();
    }

    public function test_all_ready_to_ship_labels_print_at_once(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->actingAs($staff)->get(route('gudang.label.shipping-bulk'))
            ->assertRedirect(route('gudang.outbound'))->assertSessionHas('error');
        $this->get(route('gudang.outbound'))->assertDontSee('Label Siap Kirim');

        foreach (['ORD-SIAP-1' => 'READY_TO_SHIP', 'ORD-SIAP-2' => 'READY_TO_SHIP', 'ORD-PACK' => 'PACKING'] as $number => $status) {
            \App\Models\Order::create(['order_number' => $number, 'customer_name' => "Pembeli {$number}", 'status' => $status, 'tracking_number' => "RESI-{$number}"]);
        }

        $this->get(route('gudang.outbound'))->assertSee('Cetak 2 Label Siap Kirim');
        $this->get(route('gudang.label.shipping-bulk'))->assertOk()
            ->assertSee('Label kirim 2 order')
            ->assertSee('RESI-ORD-SIAP-1')->assertSee('RESI-ORD-SIAP-2')
            ->assertDontSee('RESI-ORD-PACK');
    }
}
