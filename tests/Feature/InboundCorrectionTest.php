<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Edit dan hapus penerimaan barang: stok dan qty diterima PO ikut disesuaikan.
 */
class InboundCorrectionTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private PurchaseOrder $po;
    private $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = $this->makeWarehouse();
        $supplier = $this->makeSupplier();
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-A-XL');

        $this->actingAs($this->userWithRole('OWNER'))->post(route('po.store'), [
            'supplier_id' => $supplier->id,
            'supplier_type' => 'KONVEKSI',
            'expected_date' => now()->addDays(7)->toDateString(),
            'items' => [['sku' => 'JSC-A-L', 'qty' => 5, 'price' => 1000], ['sku' => 'JSC-A-XL', 'qty' => 5, 'price' => 1000]],
        ]);
        $this->po = PurchaseOrder::firstOrFail();
        $this->po->update(['status' => 'IN_PRODUCTION']);
    }

    private function receive(string $no, array $qtys, bool $withPo = true): StockMovement
    {
        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => $no,
            'supplier_name' => 'JB Printing',
            'received_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $withPo ? $this->po->id : null,
            'items' => collect($qtys)->map(fn ($qty, $sku) => ['sku' => $sku, 'bin_code' => 'A-01-01', 'qty_received' => $qty])->values()->all(),
        ])->assertRedirectContains('/gudang/inbound/');

        return StockMovement::where('reference_no', $no)->orderBy('id')->firstOrFail();
    }

    public function test_owner_edits_qty_and_receipt_number(): void
    {
        $first = $this->receive('SJ-1', ['JSC-A-L' => 3, 'JSC-A-XL' => 2]);
        $this->assertSame('PARTIALLY_RECEIVED', $this->po->fresh()->status);
        $lines = StockMovement::where('reference_no', 'SJ-1')->orderBy('id')->get();

        $this->get(route('gudang.inbound.show', $first->id))->assertOk()->assertSee('Edit penerimaan')->assertSee('Hapus penerimaan');
        $this->get(route('gudang.inbound.edit', $first->id))->assertOk()->assertSee('Qty benar');

        // L sebenarnya 2 (bukan 3), XL ternyata 5, nomor receipt salah ketik
        $this->put(route('gudang.inbound.update', $first->id), [
            'receipt_number' => 'SJ-01',
            'notes' => 'dikoreksi',
            'reason' => 'salah hitung',
            'qty' => [$lines[0]->id => 2, $lines[1]->id => 5],
        ])->assertRedirect(route('gudang.inbound.show', $first->id))->assertSessionHas('success');

        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(5, $this->stockQty('JSC-A-XL', 'A-01-01'));
        $po = $this->po->fresh()->load('items');
        $this->assertSame(2, $po->items->firstWhere('sku', 'JSC-A-L')->qty_received);
        $this->assertSame(5, $po->items->firstWhere('sku', 'JSC-A-XL')->qty_received);
        $this->assertSame('PARTIALLY_RECEIVED', $po->status);
        $this->assertSame(2, StockMovement::where('reference_no', 'SJ-01')->count());
        $this->assertSame(0, StockMovement::where('reference_no', 'SJ-1')->count());
        $this->assertSame(7, (int) PurchaseOrderReceipt::where('receipt_number', 'SJ-01')->sum('qty'));

        $audit = AuditTrail::where('action', 'UPDATE_INBOUND')->firstOrFail();
        $this->assertStringContainsString('JSC-A-L x3', $audit->old_values);
        $this->assertStringContainsString('JSC-A-L x2', $audit->new_values);
        $this->assertStringContainsString('salah hitung', $audit->new_values);

        // Daftar tetap menampilkan supplier untuk nomor receipt yang baru
        $this->get(route('gudang.inbound'))->assertOk()->assertSee('SJ-01')->assertSee('JB Printing');

        // Tidak boleh melebihi sisa PO
        $this->put(route('gudang.inbound.update', $first->id), [
            'receipt_number' => 'SJ-01', 'reason' => 'x',
            'qty' => [$lines[0]->id => 6, $lines[1]->id => 5],
        ])->assertSessionHasErrors('items');
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_deleting_receipt_reverses_stock_and_po(): void
    {
        $first = $this->receive('SJ-2', ['JSC-A-L' => 5, 'JSC-A-XL' => 5]);
        $this->assertSame('RECEIVED', $this->po->fresh()->status);

        $this->delete(route('gudang.inbound.destroy', $first->id), ['reason' => 'salah pilih PO'])
            ->assertRedirect(route('gudang.inbound'))->assertSessionHas('success');

        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(0, StockMovement::where('reference_no', 'SJ-2')->count());
        $this->assertSame(0, PurchaseOrderReceipt::count());
        $po = $this->po->fresh()->load('items');
        $this->assertSame(0, $po->qty_received_total);
        $this->assertSame('IN_PRODUCTION', $po->status);
        $this->assertDatabaseHas('audit_trails', ['action' => 'DELETE_INBOUND']);
    }

    public function test_cannot_remove_stock_already_committed_to_orders(): void
    {
        $first = $this->receive('SJ-3', ['JSC-A-L' => 4], false);
        $order = Order::create([
            'id' => (string) Str::uuid(), 'order_number' => 'ORD-1', 'customer_name' => 'Budi',
            'status' => 'CONFIRMED', 'created_by' => auth()->id(),
        ]);
        OrderItem::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 3, 'qty_picked' => 0, 'price' => 1000]);

        $this->delete(route('gudang.inbound.destroy', $first->id), ['reason' => 'x'])->assertSessionHasErrors('items');
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Mengurangi 1 pcs masih boleh (4 - 3 dijatah = 1 bebas)
        $this->put(route('gudang.inbound.update', $first->id), [
            'receipt_number' => 'SJ-3', 'reason' => 'kelebihan hitung', 'qty' => [$first->id => 3],
        ])->assertSessionHas('success');
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_only_owner_and_supervisor_can_correct(): void
    {
        $first = $this->receive('SJ-4', ['JSC-A-L' => 1]);

        foreach (['ADMIN_GUDANG', 'STAFF_GUDANG'] as $role) {
            $this->actingAs($this->userWithRole($role));
            $this->get(route('gudang.inbound.show', $first->id))->assertOk()->assertDontSee('Hapus penerimaan');
            $this->get(route('gudang.inbound.edit', $first->id))->assertForbidden();
            $this->delete(route('gudang.inbound.destroy', $first->id), ['reason' => 'x'])->assertForbidden();
        }
        $this->assertSame(1, $this->stockQty('JSC-A-L', 'A-01-01'));
    }
}
