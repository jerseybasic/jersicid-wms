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

        $this->get(route('gudang.inbound.show', $first->id))->assertOk()->assertSee('Edit penerimaan')->assertSee('Hapus penerimaan')
            ->assertSee('Dipesan (PO)')->assertSee('Sisa diproses');
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

        // Penerimaan tanpa PO tetap bisa dibuka Owner (dulu error 500 karena teks hapus memakai PO)
        $this->get(route('gudang.inbound.show', $first->id))->assertOk()->assertSee('Hapus penerimaan')->assertDontSee('Sisa diproses');
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

    public function test_edit_can_change_po_ordered_qty(): void
    {
        $first = $this->receive('SJ-5', ['JSC-A-L' => 2]);
        $this->get(route('gudang.inbound.edit', $first->id))->assertOk()->assertSee('Dipesan (PO)')->assertSee('po_qty[JSC-A-L]', false);

        // Ternyata PO-nya cuma 4 pcs dan yang datang 3 pcs
        $this->put(route('gudang.inbound.update', $first->id), [
            'receipt_number' => 'SJ-5', 'reason' => 'PO salah ketik',
            'qty' => [$first->id => 3], 'po_qty' => ['JSC-A-L' => 4],
        ])->assertSessionHas('success');

        $item = $this->po->items()->where('sku', 'JSC-A-L')->first();
        $this->assertSame(4, $item->qty_ordered);
        $this->assertSame(3, $item->qty_received);
        $this->assertStringContainsString('JSC-A-L 5 → 4', $this->po->fresh()->notes);
        $this->assertSame(['JSC-A-L 5 → 4'], json_decode(AuditTrail::where('action', 'UPDATE_INBOUND')->value('new_values'), true)['po_qty']);

        // Qty PO tidak boleh di bawah yang sudah diterima
        $this->put(route('gudang.inbound.update', $first->id), [
            'receipt_number' => 'SJ-5', 'reason' => 'x', 'qty' => [$first->id => 3], 'po_qty' => ['JSC-A-L' => 2],
        ])->assertSessionHasErrors('po_qty');
        $this->assertSame(4, $item->fresh()->qty_ordered);
    }

    public function test_old_receipt_can_be_linked_to_po_without_double_counting(): void
    {
        // Penerimaan lama (sebelum ada riwayat per kedatangan): L sudah tercatat di PO, XL belum
        $owner = auth()->user();
        $old = collect(['JSC-A-L' => 2, 'JSC-A-XL' => 2])->map(fn ($qty, $sku) => StockMovement::create([
            'user_id' => $owner->id, 'sku' => $sku, 'bin_code' => 'GUDANG-UTAMA', 'type' => 'INBOUND',
            'qty' => $qty, 'qty_before' => 0, 'qty_after' => $qty, 'reference_no' => 'JJ637 - JJ639',
            'notes' => 'baru jadi 2pcs per size',
        ]))->values();
        $this->putStock('JSC-A-L', 'GUDANG-UTAMA', 2);
        $this->putStock('JSC-A-XL', 'GUDANG-UTAMA', 2);
        $this->po->items()->where('sku', 'JSC-A-L')->update(['qty_received' => 2]);

        $this->get(route('gudang.inbound.edit', $old[0]->id))->assertOk()
            ->assertSee('belum terhubung ke PO')->assertSee($this->po->po_number);

        $this->put(route('gudang.inbound.update', $old[0]->id), [
            'receipt_number' => 'JJ637 - JJ639', 'reason' => 'hubungkan ke PO',
            'qty' => [$old[0]->id => 2, $old[1]->id => 2], 'po_id' => $this->po->id,
        ])->assertSessionHas('success');

        $po = $this->po->fresh()->load('items');
        $this->assertSame(2, $po->items->firstWhere('sku', 'JSC-A-L')->qty_received);
        $this->assertSame(2, $po->items->firstWhere('sku', 'JSC-A-XL')->qty_received);
        $this->assertSame('PARTIALLY_RECEIVED', $po->status);
        $this->assertSame(2, PurchaseOrderReceipt::where('receipt_number', 'JJ637 - JJ639')->count());
        $this->assertSame(2, $this->stockQty('JSC-A-L', 'GUDANG-UTAMA'));

        // Sekarang detail dan edit menampilkan pesanan PO dan sisanya
        $this->get(route('gudang.inbound.show', $old[0]->id))->assertOk()->assertSee('Dipesan (PO)')->assertSee('Sisa diproses');
        $this->get(route('gudang.inbound.edit', $old[0]->id))->assertOk()->assertSee('po_qty[JSC-A-L]', false);
        $this->get(route('gudang.inbound'))->assertOk()->assertSee('Belum Komplit');
    }

    public function test_receipt_without_po_tracks_ordered_qty_and_status(): void
    {
        // Saat terima barang tanpa PO: kolom Order 5, yang datang 2
        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-MANUAL',
            'supplier_name' => 'JB Printing',
            'received_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'A-01-01', 'qty_received' => 2, 'qty_expected' => 5]],
        ])->assertRedirectContains('/gudang/inbound/');
        $line = StockMovement::where('reference_no', 'SJ-MANUAL')->firstOrFail();
        $this->assertSame(5, $line->qty_expected);

        $this->get(route('gudang.inbound'))->assertOk()->assertSee('2/5 pcs')->assertSee('3 pcs masih diproses')->assertSee('baru sebagian jadi');
        $this->get(route('gudang.inbound', ['status' => 'BELUM_KOMPLIT']))->assertSee('SJ-MANUAL');
        $this->get(route('gudang.inbound', ['status' => 'KOMPLIT']))->assertDontSee('SJ-MANUAL');
        $this->get(route('gudang.inbound.show', $line->id))->assertOk()->assertSee('Sisa diproses')->assertSee('Belum Komplit');
        $this->get(route('gudang.inbound.edit', $line->id))->assertOk()->assertSee('expected[' . $line->id . ']', false);

        // Penerimaan lama tanpa angka pesanan: isi Dipesan lewat Edit
        $old = $this->receive('SJ-LAMA', ['JSC-A-XL' => 4], false);
        $this->assertNull($old->qty_expected);
        $this->put(route('gudang.inbound.update', $old->id), [
            'receipt_number' => 'SJ-LAMA', 'reason' => 'isi pesanan', 'qty' => [$old->id => 4], 'expected' => [$old->id => 10],
        ])->assertSessionHas('success');
        $this->assertSame(10, $old->fresh()->qty_expected);
        $this->get(route('gudang.inbound'))->assertSee('4/10 pcs')->assertSee('6 pcs masih diproses');

        // Dipesan lebih kecil dari yang diterima ditolak
        $this->put(route('gudang.inbound.update', $old->id), [
            'receipt_number' => 'SJ-LAMA', 'reason' => 'x', 'qty' => [$old->id => 4], 'expected' => [$old->id => 3],
        ])->assertSessionHasErrors('expected');

        // Sisanya datang: qty dinaikkan sampai sama dengan pesanan, jadi Komplit
        $this->put(route('gudang.inbound.update', $line->id), [
            'receipt_number' => 'SJ-MANUAL', 'reason' => 'sisa datang', 'qty' => [$line->id => 5], 'expected' => [$line->id => 5],
        ])->assertSessionHas('success');
        $this->get(route('gudang.inbound', ['status' => 'KOMPLIT']))->assertSee('SJ-MANUAL')->assertSee('5/5 pcs');
        $this->assertStringContainsString('(dipesan 5)', AuditTrail::where('action', 'UPDATE_INBOUND')->latest('id')->value('new_values'));
    }
}
