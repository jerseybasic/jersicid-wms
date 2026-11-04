<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Penerimaan PO bertahap: PO 5 pcs datang 2 pcs hari ini dan 3 pcs beberapa hari kemudian.
 */
class PurchaseOrderPartialReceivingTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function approvedPo(array $items): PurchaseOrder
    {
        $owner = $this->userWithRole('OWNER');
        $supplier = $this->makeSupplier();
        foreach (array_keys($items) as $sku) {
            $this->makeSku($sku);
        }

        $this->actingAs($owner)->post(route('po.store'), [
            'supplier_id' => $supplier->id,
            'supplier_type' => 'KONVEKSI',
            'expected_date' => now()->addDays(7)->toDateString(),
            'items' => collect($items)->map(fn ($qty, $sku) => ['sku' => $sku, 'qty' => $qty, 'price' => 40000])->values()->all(),
        ])->assertSessionHas('success');

        $po = PurchaseOrder::firstOrFail();
        $po->update(['status' => 'IN_PRODUCTION']);

        return $po;
    }

    private function receive(PurchaseOrder $po, string $receiptNo, array $rows, ?string $date = null)
    {
        return $this->post(route('gudang.inbound.store'), [
            'receipt_number' => $receiptNo,
            'supplier_name' => $po->supplier_name,
            'received_date' => $date ?? now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $po->id,
            'items' => $rows,
        ]);
    }

    private $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = $this->makeWarehouse();
    }

    public function test_po_received_in_two_arrivals(): void
    {
        $po = $this->approvedPo(['JSC-A-L' => 5]);
        $staff = $this->userWithRole('STAFF_GUDANG');

        // Kedatangan pertama: 2 dari 5 pcs
        $this->actingAs($staff);
        $this->receive($po, 'SJ-101', [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2]], now()->subDays(3)->toDateString())
            ->assertRedirectContains('/gudang/inbound/')
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'sisa 3 pcs'));

        $po->refresh()->load('items');
        $this->assertSame('PARTIALLY_RECEIVED', $po->status);
        $this->assertSame(2, $po->qty_received_total);
        $this->assertSame(3, $po->qty_remaining_total);
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Inbound masih menawarkan PO ini dengan sisa 3 pcs
        $this->get(route('gudang.inbound.create', ['po_id' => $po->id]))->assertOk()->assertSee($po->po_number);

        // Detail PO: sisa dan riwayat kedatangan
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->get(route('po.show', $po->id))->assertOk()
            ->assertSee('Diterima Sebagian')
            ->assertSee('Riwayat Penerimaan')
            ->assertSee('SJ-101')
            ->assertSee('Terima Sisa Barang')
            ->assertSee($staff->name);

        // Kedatangan kedua: 3 pcs sisanya, dibagi ke dua bin
        $this->actingAs($staff);
        $this->receive($po, 'SJ-102', [
            ['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 1],
            ['sku' => 'JSC-A-L', 'bin_code' => 'A-01-02', 'qty_received' => 2],
        ])->assertSessionHas('success', fn ($msg) => str_contains($msg, 'diterima lengkap'));

        $po->refresh()->load('items');
        $this->assertSame('RECEIVED', $po->status);
        $this->assertSame(5, $po->qty_received_total);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-02'));

        $receipts = PurchaseOrderReceipt::where('purchase_order_id', $po->id)->get();
        $this->assertCount(3, $receipts);
        $this->assertSame(2, $receipts->where('receipt_number', 'SJ-101')->sum('qty'));
        $this->assertSame(3, $receipts->where('receipt_number', 'SJ-102')->sum('qty'));
        $this->assertSame(now()->subDays(3)->toDateString(), $receipts->firstWhere('receipt_number', 'SJ-101')->received_date->toDateString());
        $this->assertTrue($receipts->every(fn ($r) => $r->received_by === $staff->id && $r->stock_movement_id));

        // Audit trail per kedatangan
        $audits = AuditTrail::where('action', 'RECEIVE_PO')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('PARTIALLY_RECEIVED', json_decode($audits[0]->new_values, true)['status']);
        $this->assertSame(['JSC-A-L' => 3], json_decode($audits[0]->new_values, true)['qty_remaining']);
        $this->assertSame('RECEIVED', json_decode($audits[1]->new_values, true)['status']);

        // PO lengkap tidak bisa diterima lagi
        $this->receive($po, 'SJ-103', [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 1]])
            ->assertSessionHasErrors('po_id');
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_receiving_more_than_remaining_is_rejected_without_touching_stock(): void
    {
        $po = $this->approvedPo(['JSC-A-L' => 5]);
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));

        $this->receive($po, 'SJ-201', [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2]]);

        // Sisa 3, tapi diterima 4 (dibagi dua bin): ditolak
        $this->receive($po, 'SJ-202', [
            ['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2],
            ['sku' => 'JSC-A-L', 'bin_code' => 'A-01-02', 'qty_received' => 2],
        ])->assertSessionHasErrors(['items' => 'JSC-A-L diterima 4 pcs, melebihi sisa PO 3 pcs (dipesan 5, sudah diterima 2). Jika supplier memang mengirim lebih, minta Owner/Supervisor menambah qty di PO dulu.']);

        $this->assertSame(2, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-02'));
        $this->assertSame(2, $po->items()->value('qty_received'));
        $this->assertSame(1, PurchaseOrderReceipt::count());
        $this->assertDatabaseMissing('stock_movements', ['reference_no' => 'SJ-202']);
        $this->assertSame('PARTIALLY_RECEIVED', $po->fresh()->status);
    }

    public function test_sku_outside_po_is_rejected(): void
    {
        $po = $this->approvedPo(['JSC-A-L' => 5]);
        $this->makeSku('JSC-LAIN-M');
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));

        $this->receive($po, 'SJ-301', [
            ['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 1],
            ['sku' => 'JSC-LAIN-M', 'bin_code' => 'A-01-02', 'qty_received' => 1],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame('IN_PRODUCTION', $po->fresh()->status);
    }

    public function test_partially_received_po_shows_outstanding_and_can_be_closed_by_cancel(): void
    {
        $po = $this->approvedPo(['JSC-A-L' => 5, 'JSC-A-XL' => 4]);
        $po->update(['expected_date' => now()->subDays(2)->toDateString()]);
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->receive($po, 'SJ-401', [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 5]]);

        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner)->get(route('po.index'))->assertOk()
            ->assertSee('Menunggu Sisa Barang')
            ->assertSee('5/9')
            ->assertSee('Lewat target');
        $this->get(route('po.index', ['status' => 'PARTIALLY_RECEIVED']))->assertOk()->assertSee($po->po_number);
        $this->get(route('po.show', $po->id))->assertOk()
            ->assertSee('Salin Teks Tagihan Sisa')
            ->assertSee('JSC-A-XL (CLUB TEST L): 4 pcs (dari 4)', false)
            ->assertSee('Lewat 2 hari');

        // Sisa yang tidak jadi dikirim dibatalkan; barang yang sudah datang tetap di stok
        $this->patch(route('po.update-status', $po->id), ['status' => 'CANCELLED', 'reason' => 'Sisa tidak bisa diproduksi'])
            ->assertSessionHas('success');
        $this->assertSame('CANCELLED', $po->fresh()->status);
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_editing_partially_received_po_keeps_status(): void
    {
        $po = $this->approvedPo(['JSC-A-L' => 5]);
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->receive($po, 'SJ-501', [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2]]);

        $owner = $this->userWithRole('OWNER');
        $payload = fn (int $qty) => [
            'supplier_id' => $po->supplier_id,
            'supplier_type' => 'KONVEKSI',
            'expected_date' => now()->addDays(3)->toDateString(),
            'change_reason' => 'Supplier hanya sanggup sebagian',
            'items' => [['sku' => 'JSC-A-L', 'qty' => $qty, 'price' => 40000]],
        ];

        // Qty dinaikkan: tetap menunggu sisa
        $this->actingAs($owner)->put(route('po.update', $po->id), $payload(6))->assertSessionHas('success');
        $this->assertSame('PARTIALLY_RECEIVED', $po->fresh()->status);

        // Qty diturunkan sampai sama dengan yang sudah datang: PO selesai
        $this->put(route('po.update', $po->id), $payload(2))->assertSessionHas('success');
        $this->assertSame('RECEIVED', $po->fresh()->status);
    }

    public function test_inbound_detail_shows_po_remaining(): void
    {
        $po = $this->approvedPo(['JSC-A-L' => 5]);
        $this->actingAs($this->userWithRole('STAFF_GUDANG'));
        $this->receive($po, 'SJ-601', [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2]]);

        $movementId = \App\Models\StockMovement::where('reference_no', 'SJ-601')->value('id');
        $this->get(route('gudang.inbound.show', $movementId))->assertOk()
            ->assertSee($po->po_number)
            ->assertSee('sisa 3 pcs belum datang');
    }
}
