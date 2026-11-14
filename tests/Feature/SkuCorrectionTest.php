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
 * Koreksi SKU yang salah input pada PO dan penerimaan yang sudah selesai, dan pengecekan terakhir
 * sebelum penerimaan disimpan.
 */
class SkuCorrectionTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private PurchaseOrder $po;
    private $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = $this->makeWarehouse();
        $supplier = $this->makeSupplier();
        foreach (['JSC-A-L', 'JSC-A-XL', 'JSC-B-M'] as $sku) {
            $this->makeSku($sku);
        }

        $this->actingAs($this->userWithRole('OWNER'))->post(route('po.store'), [
            'supplier_id' => $supplier->id,
            'supplier_type' => 'KONVEKSI',
            'expected_date' => now()->addDays(7)->toDateString(),
            'items' => [['sku' => 'JSC-A-L', 'qty' => 5, 'price' => 1000], ['sku' => 'JSC-A-XL', 'qty' => 5, 'price' => 2000]],
        ]);
        $this->po = PurchaseOrder::with('items')->firstOrFail();
        $this->po->update(['status' => 'IN_PRODUCTION']);
    }

    private function receive(string $no, array $qtys, bool $withPo = true, string $bin = 'A-01-01'): StockMovement
    {
        $this->post(route('gudang.inbound.store'), [
            'final_check' => '1',
            'receipt_number' => $no,
            'supplier_name' => 'JB Printing',
            'received_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $withPo ? $this->po->id : null,
            'items' => collect($qtys)->map(fn ($qty, $sku) => ['sku' => $sku, 'bin_code' => $bin, 'qty_received' => $qty])->values()->all(),
        ])->assertRedirectContains('/gudang/inbound/');

        return StockMovement::where('reference_no', $no)->orderBy('id')->firstOrFail();
    }

    public function test_inbound_requires_final_check(): void
    {
        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-X',
            'supplier_name' => 'JB Printing',
            'received_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2]],
        ])->assertSessionHasErrors('final_check');

        $this->assertSame(0, StockMovement::count());
        $this->get(route('gudang.inbound.create'))->assertOk()->assertSee('Pengecekan Terakhir')->assertSee('final_check', false);
        $this->get(route('po.create'))->assertOk()->assertSee('Pengecekan Terakhir');
    }

    public function test_corrects_sku_of_completed_po_and_its_receipts(): void
    {
        // PO lengkap diterima di dua kedatangan, tapi XL seharusnya B-M
        $this->receive('SJ-1', ['JSC-A-L' => 5, 'JSC-A-XL' => 3]);
        $this->receive('SJ-2', ['JSC-A-XL' => 2], true, 'A-01-02');
        $this->assertSame('RECEIVED', $this->po->fresh()->status);
        $xl = $this->po->items->firstWhere('sku', 'JSC-A-XL');

        $this->get(route('po.show', $this->po->id))->assertOk()->assertSee('Koreksi SKU');
        $this->get(route('po.correct-sku.form', $this->po->id))->assertOk()->assertSee('Pengecekan Terakhir')->assertSee('A-01-02');

        // Tanpa centang pengecekan terakhir ditolak
        $this->put(route('po.correct-sku', $this->po->id), ['sku' => [$xl->id => 'JSC-B-M'], 'reason' => 'salah ketik'])
            ->assertSessionHasErrors('final_check');

        $this->put(route('po.correct-sku', $this->po->id), [
            'sku' => [$xl->id => 'jsc-b-m'], 'reason' => 'salah ketik SKU saat buat PO', 'final_check' => '1',
        ])->assertRedirect(route('po.show', $this->po->id))->assertSessionHas('success');

        $this->assertSame(0, $this->stockQty('JSC-A-XL', 'A-01-01'));
        $this->assertSame(0, $this->stockQty('JSC-A-XL', 'A-01-02'));
        $this->assertSame(3, $this->stockQty('JSC-B-M', 'A-01-01'));
        $this->assertSame(2, $this->stockQty('JSC-B-M', 'A-01-02'));
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));

        $po = $this->po->fresh()->load('items');
        $this->assertSame('RECEIVED', $po->status);
        $this->assertSame(['JSC-A-L', 'JSC-B-M'], $po->items->pluck('sku')->sort()->values()->all());
        $item = $po->items->firstWhere('sku', 'JSC-B-M');
        $this->assertSame([5, 5, 2000], [$item->qty_ordered, $item->qty_received, $item->price]);
        $this->assertStringContainsString('JSC-A-XL → JSC-B-M', $po->notes);

        $this->assertSame(0, StockMovement::where('sku', 'JSC-A-XL')->count());
        $this->assertSame(2, StockMovement::where('type', 'INBOUND')->where('sku', 'JSC-B-M')->count());
        $this->assertSame(0, PurchaseOrderReceipt::where('sku', 'JSC-A-XL')->count());

        $audit = AuditTrail::where('action', 'CORRECT_PO_SKU')->firstOrFail();
        $this->assertStringContainsString('JSC-A-XL x5', $audit->old_values);
        $this->assertStringContainsString('salah ketik SKU saat buat PO', $audit->new_values);

        // Riwayat koreksi tampil di detail penerimaan
        $line = StockMovement::where('reference_no', 'SJ-2')->firstOrFail();
        $this->get(route('gudang.inbound.show', $line->id))->assertOk()->assertSee('Riwayat Koreksi SKU')->assertSee('JSC-A-XL → JSC-B-M', false);
    }

    public function test_po_correction_merges_into_existing_line(): void
    {
        $this->receive('SJ-1', ['JSC-A-L' => 2, 'JSC-A-XL' => 5]);
        $l = $this->po->items->firstWhere('sku', 'JSC-A-L');

        $this->put(route('po.correct-sku', $this->po->id), [
            'sku' => [$l->id => 'JSC-A-XL'], 'reason' => 'semua seharusnya XL', 'final_check' => '1',
        ])->assertSessionHas('success');

        $po = $this->po->fresh()->load('items');
        $this->assertCount(1, $po->items);
        $this->assertSame([10, 7], [$po->items[0]->qty_ordered, $po->items[0]->qty_received]);
        $this->assertSame('PARTIALLY_RECEIVED', $po->status);
        $this->assertSame(7, $this->stockQty('JSC-A-XL', 'A-01-01'));
        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_corrects_sku_of_completed_inbound_without_po(): void
    {
        $first = $this->receive('SJ-M', ['JSC-A-L' => 4, 'JSC-A-XL' => 1], false);
        $lines = StockMovement::where('reference_no', 'SJ-M')->orderBy('id')->get();

        $this->get(route('gudang.inbound.show', $first->id))->assertOk()->assertSee('Koreksi SKU');
        $this->get(route('gudang.inbound.correct-sku.form', $first->id))->assertOk()->assertSee('SKU benar');

        $this->put(route('gudang.inbound.correct-sku', $first->id), [
            'sku' => [$lines[0]->id => 'JSC-B-M', $lines[1]->id => 'JSC-A-XL'],
            'reason' => 'salah pilih produk',
            'final_check' => '1',
        ])->assertRedirect(route('gudang.inbound.show', $first->id))->assertSessionHas('success');

        $this->assertSame(0, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(4, $this->stockQty('JSC-B-M', 'A-01-01'));
        $this->assertSame(1, $this->stockQty('JSC-A-XL', 'A-01-01'));
        $this->assertSame('JSC-B-M', $lines[0]->fresh()->sku);
        $this->assertStringContainsString('SKU dikoreksi dari JSC-A-L', $lines[0]->fresh()->notes);

        $audit = AuditTrail::where('action', 'CORRECT_INBOUND_SKU')->firstOrFail();
        $this->assertStringContainsString('JSC-A-L x4 di A-01-01', $audit->old_values);
        $this->assertStringContainsString('JSC-B-M x4 di A-01-01', $audit->new_values);
        $this->assertStringContainsString('salah pilih produk', $audit->new_values);

        // SKU yang tidak ada ditolak
        $this->put(route('gudang.inbound.correct-sku', $first->id), [
            'sku' => [$lines[0]->id => 'TIDAK-ADA'], 'reason' => 'x', 'final_check' => '1',
        ])->assertSessionHasErrors('sku');
    }

    public function test_inbound_correction_moves_po_received_qty_between_items(): void
    {
        // PO benar, tapi saat terima barang L dan XL tertukar jumlahnya: L 2 seharusnya XL
        $first = $this->receive('SJ-3', ['JSC-A-L' => 2]);
        $this->put(route('gudang.inbound.correct-sku', $first->id), [
            'sku' => [$first->id => 'JSC-A-XL'], 'reason' => 'salah scan', 'final_check' => '1',
        ])->assertSessionHas('success');

        $po = $this->po->fresh()->load('items');
        $this->assertSame(0, $po->items->firstWhere('sku', 'JSC-A-L')->qty_received);
        $this->assertSame(2, $po->items->firstWhere('sku', 'JSC-A-XL')->qty_received);
        $this->assertSame('JSC-A-XL', PurchaseOrderReceipt::firstOrFail()->sku);
        $this->assertSame($po->items->firstWhere('sku', 'JSC-A-XL')->id, PurchaseOrderReceipt::firstOrFail()->purchase_order_item_id);
        $this->assertSame(2, $this->stockQty('JSC-A-XL', 'A-01-01'));

        // SKU di luar PO ditolak, diarahkan ke Koreksi SKU di PO
        $this->put(route('gudang.inbound.correct-sku', $first->id), [
            'sku' => [$first->id => 'JSC-B-M'], 'reason' => 'x', 'final_check' => '1',
        ])->assertSessionHasErrors('sku');
        $this->assertSame(2, $this->stockQty('JSC-A-XL', 'A-01-01'));
    }

    public function test_refuses_when_stock_already_committed_and_for_staff(): void
    {
        $first = $this->receive('SJ-4', ['JSC-A-L' => 3], false);
        $order = Order::create([
            'id' => (string) Str::uuid(), 'order_number' => 'ORD-1', 'customer_name' => 'Budi',
            'status' => 'CONFIRMED', 'created_by' => auth()->id(),
        ]);
        OrderItem::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'sku' => 'JSC-A-L', 'qty_ordered' => 2, 'qty_picked' => 0, 'price' => 1000]);

        $this->put(route('gudang.inbound.correct-sku', $first->id), [
            'sku' => [$first->id => 'JSC-B-M'], 'reason' => 'x', 'final_check' => '1',
        ])->assertSessionHasErrors('sku');
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(0, $this->stockQty('JSC-B-M', 'A-01-01'));

        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->actingAs($staff)->get(route('gudang.inbound.correct-sku.form', $first->id))->assertForbidden();
        $this->actingAs($staff)->put(route('po.correct-sku', $this->po->id), [
            'sku' => [], 'reason' => 'x', 'final_check' => '1',
        ])->assertForbidden();
    }
}
