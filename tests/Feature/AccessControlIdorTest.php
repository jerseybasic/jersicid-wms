<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * IDOR / hak akses per data: user tidak boleh membuka atau mengubah data milik orang lain
 * hanya dengan mengganti id di URL.
 */
class AccessControlIdorTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 50);
    }

    private function confirmedOrder(User $by, string $name): Order
    {
        $this->actingAs($by)->post(route('gudang.outbound.store'), [
            'customer_name' => $name,
            'shipping_address' => 'Bandung',
            'courier' => 'JNE',
            'items' => [['sku' => 'JSC-A-L', 'qty' => 1]],
        ]);
        $order = Order::where('customer_name', $name)->firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);

        return $order;
    }

    public function test_owner_dashboard_with_stock_value_is_owner_only(): void
    {
        foreach (['SUPERVISOR', 'ADMIN_GUDANG', 'STAFF_GUDANG'] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('dashboard.owner'))->assertForbidden();
        }

        $this->actingAs($this->userWithRole('OWNER'))->get(route('dashboard.owner'))->assertOk()->assertSee('Nilai Stok');
    }

    public function test_staff_cannot_cancel_another_staffs_picking_session(): void
    {
        $ani = $this->userWithRole('STAFF_GUDANG');
        $budi = $this->userWithRole('STAFF_GUDANG');
        $order = $this->confirmedOrder($ani, 'Pembeli 1');
        $this->actingAs($ani)->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $session = PickingSession::where('order_id', $order->id)->firstOrFail();

        // Budi tidak melihat tombol hapus dan tidak bisa menghapus lewat URL
        $this->actingAs($budi)->get(route('gudang.picking'))->assertOk()
            ->assertDontSee('action="' . route('gudang.picking.destroy', $session->id) . '"', false);
        $this->delete(route('gudang.picking.destroy', $session->id))->assertForbidden();
        $this->assertSame('PENDING', $session->fresh()->status);

        // Pemiliknya sendiri boleh
        $this->actingAs($ani)->get(route('gudang.picking'))->assertSee('action="' . route('gudang.picking.destroy', $session->id) . '"', false);
        $this->delete(route('gudang.picking.destroy', $session->id))->assertSessionHas('success');
    }

    public function test_admin_gudang_can_cancel_any_picking_session(): void
    {
        $ani = $this->userWithRole('STAFF_GUDANG');
        $order = $this->confirmedOrder($ani, 'Pembeli 1');
        $this->actingAs($ani)->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $session = PickingSession::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->delete(route('gudang.picking.destroy', $session->id))->assertSessionHas('success');
    }

    public function test_staff_cannot_cancel_another_staffs_packing_session(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $order = $this->confirmedOrder($admin, 'Pembeli 1');
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $item = $picking->items->first();
        $this->post(route('gudang.picking.start', $picking->id));
        $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => 1, 'bin_code' => 'A1-01', 'reason' => 'tes']);

        $ani = $this->userWithRole('STAFF_GUDANG');
        $this->actingAs($ani)->post(route('gudang.packing.store'), ['order_id' => $order->id, 'courier' => 'JNE']);
        $packing = PackingSession::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->delete(route('gudang.packing.destroy', $packing->id))->assertForbidden();
        $this->assertNotNull($packing->fresh());

        $this->actingAs($ani)->delete(route('gudang.packing.destroy', $packing->id))->assertSessionHas('success');
    }

    public function test_staff_cannot_cancel_another_staffs_picking_batch(): void
    {
        $ani = $this->userWithRole('STAFF_GUDANG');
        $first = $this->confirmedOrder($ani, 'Pembeli 1');
        $second = $this->confirmedOrder($ani, 'Pembeli 2');
        $this->actingAs($ani)->post(route('gudang.picking-batch.store'), ['order_ids' => [$first->id, $second->id]]);
        $batch = PickingBatch::firstOrFail();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->get(route('gudang.picking-batch.show', $batch))->assertOk()
            ->assertDontSee('action="' . route('gudang.picking-batch.destroy', $batch) . '"', false);
        $this->delete(route('gudang.picking-batch.destroy', $batch))->assertForbidden();
        $this->assertSame(2, PickingSession::where('batch_id', $batch->id)->where('status', '!=', 'CANCELLED')->count());

        $this->actingAs($this->userWithRole('SUPERVISOR'))
            ->delete(route('gudang.picking-batch.destroy', $batch))->assertRedirect(route('gudang.picking'));
    }

    public function test_staff_cannot_delete_another_staffs_opname_draft(): void
    {
        $warehouse = $this->makeWarehouse();
        $ani = $this->userWithRole('STAFF_GUDANG');
        $this->actingAs($ani)->post(route('gudang.opname.store'), ['warehouse_id' => $warehouse->id]);
        $opname = StockOpname::firstOrFail();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->delete(route('gudang.opname.destroy', $opname->id))->assertForbidden();
        $this->assertNotNull($opname->fresh());

        $this->actingAs($ani)->delete(route('gudang.opname.destroy', $opname->id))->assertSessionHas('success');
        $this->assertSoftDeleted($opname);
    }

    public function test_staff_cannot_change_shared_printer_templates(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $payload = ['name' => 'Label', 'type' => 'SKU_LABEL', 'zpl_code' => '^XA^XZ', 'width_mm' => 50, 'height_mm' => 30, 'dpi' => 203];

        $this->postJson(route('gudang.printer.save-template'), $payload)->assertForbidden();
        $this->putJson(route('gudang.printer.update-template', 1), $payload)->assertForbidden();
        $this->deleteJson(route('gudang.printer.delete-template', 1))->assertForbidden();
    }

    public function test_whatsapp_queue_does_not_reveal_which_message_ids_exist_without_token(): void
    {
        $message = WhatsAppMessage::create(['recipient' => '6281200001111', 'body' => 'Tes', 'status' => 'PENDING']);

        foreach ([$message->id, 999999] as $id) {
            $this->postJson(route('api.whatsapp.sent', $id))->assertUnauthorized();
            $this->postJson(route('api.whatsapp.failed', $id))->assertUnauthorized();
        }
        $this->assertSame('PENDING', $message->fresh()->status);
    }
}
