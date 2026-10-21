<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Alur PO: buat → edit → ajukan → approve → produksi → siap diambil → diterima lewat Inbound.
 */
class PurchaseOrderFlowTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function poPayload(string $supplierId, string $sku, int $qty = 10): array
    {
        return [
            'supplier_id' => $supplierId,
            'supplier_type' => 'SUBLIMASI',
            'expected_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Jersey musim baru',
            'items' => [['sku' => $sku, 'qty' => $qty, 'price' => 50000]],
        ];
    }

    public function test_po_full_flow_until_received_through_inbound(): void
    {
        $owner = $this->userWithRole('OWNER');
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-A-XL');

        // Buat PO
        $this->actingAs($owner)->get(route('po.create'))->assertOk();
        $this->post(route('po.store'), $this->poPayload($supplier->id, 'JSC-A-L'))->assertSessionHas('success');
        $po = PurchaseOrder::with('items')->firstOrFail();
        $this->assertSame('DRAFT', $po->status);
        $this->assertSame('JB Printing', $po->supplier_name);
        $this->assertSame($owner->id, $po->created_by);
        $this->assertSame(10, $po->items->first()->qty_ordered);

        $this->get(route('po.index'))->assertOk()->assertSee($po->po_number)->assertSee('Rp 500.000');
        $this->get(route('po.show', $po->id))->assertOk()->assertSee('Ajukan untuk Approval');

        // Edit PO draft
        $this->get(route('po.edit', $po->id))->assertOk()->assertSee('JSC-A-L');
        $payload = $this->poPayload($supplier->id, 'JSC-A-L', 4);
        $payload['items'][] = ['sku' => 'JSC-A-XL', 'qty' => 6, 'price' => 50000];
        $this->put(route('po.update', $po->id), $payload)->assertRedirect(route('po.show', $po->id));
        $this->assertSame(2, $po->items()->count());

        // Ajukan & approve
        $this->patch(route('po.update-status', $po->id), ['status' => 'PENDING'])->assertSessionHas('success');
        $this->patch(route('po.update-status', $po->id), ['status' => 'APPROVED'])->assertSessionHas('success');
        $po->refresh();
        $this->assertSame('APPROVED', $po->status);
        $this->assertSame($owner->id, $po->approved_by);

        // Setelah approve, PO tidak bisa diedit lagi
        $this->get(route('po.edit', $po->id))->assertRedirect(route('po.show', $po->id));

        // ADMIN_GUDANG memperbarui tahap produksi
        $this->actingAs($admin)->patch(route('po.update-status', $po->id), ['status' => 'IN_PRODUCTION'])->assertSessionHas('success');
        $this->patch(route('po.update-status', $po->id), ['status' => 'READY_FOR_PICKUP', 'defect_count' => 2])->assertSessionHas('success');
        $po->refresh();
        $this->assertSame('READY_FOR_PICKUP', $po->status);
        $this->assertSame(2, $po->defect_count);
        $this->assertNotNull($po->ready_at);
        $this->get(route('po.show', $po->id))->assertOk()->assertSee('Terima Barang');

        // Staf inbound menerima barang dari PO
        $this->actingAs($staff)->get(route('gudang.inbound.create', ['po_id' => $po->id]))->assertOk()->assertSee($po->po_number);
        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-001',
            'supplier_name' => $po->supplier_name,
            'received_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'po_id' => $po->id,
            'items' => [
                ['sku' => 'JSC-A-L', 'bin_code' => 'A1-01', 'qty_received' => 4],
                ['sku' => 'JSC-A-XL', 'bin_code' => 'A1-02', 'qty_received' => 6],
            ],
        ])->assertRedirectContains('/gudang/inbound/');

        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A1-01'));
        $this->assertSame(6, $this->stockQty('JSC-A-XL', 'A1-02'));
        $this->assertSame('RECEIVED', $po->fresh()->status);
        $this->assertSame(4, $po->items()->where('sku', 'JSC-A-L')->value('qty_received'));
        $this->assertDatabaseHas('stock_movements', ['sku' => 'JSC-A-L', 'type' => 'INBOUND', 'qty' => 4, 'reference_no' => 'SJ-001']);
    }

    public function test_partial_inbound_keeps_po_open(): void
    {
        $owner = $this->userWithRole('OWNER');
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $this->makeSku('JSC-A-L');

        $this->actingAs($owner)->post(route('po.store'), $this->poPayload($supplier->id, 'JSC-A-L', 10));
        $po = PurchaseOrder::firstOrFail();
        $po->update(['status' => 'APPROVED']);

        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-002',
            'supplier_name' => $po->supplier_name,
            'received_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'po_id' => $po->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'A1-01', 'qty_received' => 7]],
        ]);

        $this->assertSame('APPROVED', $po->fresh()->status);
        $this->assertSame(7, $po->items()->value('qty_received'));
    }

    public function test_only_owner_and_supervisor_can_create_or_approve_po(): void
    {
        $owner = $this->userWithRole('OWNER');
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $supplier = $this->makeSupplier();
        $this->makeSku('JSC-A-L');

        $this->actingAs($admin)->get(route('po.index'))->assertOk();
        $this->get(route('po.create'))->assertForbidden();
        $this->post(route('po.store'), $this->poPayload($supplier->id, 'JSC-A-L'))->assertForbidden();

        $this->actingAs($owner)->post(route('po.store'), $this->poPayload($supplier->id, 'JSC-A-L'));
        $po = PurchaseOrder::firstOrFail();
        $po->update(['status' => 'PENDING']);

        $this->actingAs($admin)->patch(route('po.update-status', $po->id), ['status' => 'APPROVED'])->assertForbidden();
        $this->assertSame('PENDING', $po->fresh()->status);

        // STAFF_GUDANG tidak mengurus PO
        $this->actingAs($staff)->get(route('po.index'))->assertForbidden();
    }

    public function test_po_cannot_skip_approval(): void
    {
        $owner = $this->userWithRole('OWNER');
        $supplier = $this->makeSupplier();
        $this->makeSku('JSC-A-L');

        $this->actingAs($owner)->post(route('po.store'), $this->poPayload($supplier->id, 'JSC-A-L'));
        $po = PurchaseOrder::firstOrFail();

        $this->patch(route('po.update-status', $po->id), ['status' => 'IN_PRODUCTION'])->assertSessionHas('error');
        $this->assertSame('DRAFT', $po->fresh()->status);
    }

    public function test_owner_can_delete_draft_po(): void
    {
        $owner = $this->userWithRole('OWNER');
        $supervisor = $this->userWithRole('SUPERVISOR');
        $supplier = $this->makeSupplier();
        $this->makeSku('JSC-A-L');

        $this->actingAs($supervisor)->post(route('po.store'), $this->poPayload($supplier->id, 'JSC-A-L'));
        $po = PurchaseOrder::firstOrFail();

        $this->delete(route('po.destroy', $po->id))->assertForbidden();
        $this->actingAs($owner)->delete(route('po.destroy', $po->id))->assertRedirect(route('po.index'));
        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
        $this->assertDatabaseCount('purchase_order_items', 0);
    }
}
