<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Scan barcode langsung di layar kerja (picking, packing, inbound) dan
 * halaman Cek Barang & Lokasi yang hanya membaca.
 */
class ScanWorkflowTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function startedPicking($user, string $sku, int $qty): PickingSession
    {
        $this->actingAs($user)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Budi',
            'shipping_address' => 'Bandung',
            'courier' => 'JNE',
            'items' => [['sku' => $sku, 'qty' => $qty]],
        ]);
        $order = Order::latest()->firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id));

        return $picking;
    }

    public function test_picking_by_scan_reduces_stock_per_scan_and_rejects_wrong_items(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        WarehouseRack::create(['code' => 'A']);
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);
        $this->putStock('JSC-A-L', 'A-02-01', 9);

        $picking = $this->startedPicking($staff, 'JSC-A-L', 2);
        $this->get(route('gudang.picking.show', $picking->id))->assertOk()->assertSee('Scan barcode');
        $scan = route('gudang.picking.scan', $picking->id);

        // Scan lokasi: hanya memilih bin
        $this->postJson($scan, ['code' => 'a-01-01'])
            ->assertOk()->assertJson(['ok' => true, 'type' => 'bin', 'bin_code' => 'A-01-01']);

        // SKU yang bukan pesanan ditolak, kode asing juga
        $this->postJson($scan, ['code' => 'JSC-B-L', 'bin_code' => 'A-01-01'])->assertStatus(422)->assertJson(['ok' => false]);
        $this->postJson($scan, ['code' => 'XYZ'])->assertStatus(422);

        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])
            ->assertOk()->assertJson(['ok' => true, 'completed' => false]);
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Item yang sudah mulai diambil dari A-01-01 tetap dicatat dari A-01-01, dengan peringatan
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-02-01'])
            ->assertOk()->assertJson(['completed' => true, 'active_bin' => 'A-01-01'])
            ->assertJsonPath('warning', fn ($w) => str_contains($w, 'sudah mulai diambil dari A-01-01'));
        $this->assertSame(9, $this->stockQty('JSC-A-L', 'A-02-01'));
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame('COMPLETED', $picking->fresh()->status);
        $this->assertSame('PACKING', $picking->order->fresh()->status);

        // Sudah lengkap: scan lagi ditolak, stok tidak berubah
        $this->postJson($scan, ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])->assertStatus(422);
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_packing_by_scan_rejects_items_outside_the_order(): void
    {
        $staff = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);

        $picking = $this->startedPicking($staff, 'JSC-A-L', 2);
        $item = $picking->items()->first();
        $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => 2, 'bin_code' => 'A-01-01', 'reason' => 'barcode rusak']);

        $this->post(route('gudang.packing.store'), ['order_id' => $picking->order_id, 'courier' => 'JNE']);
        $packing = PackingSession::where('order_id', $picking->order_id)->firstOrFail();
        $this->get(route('gudang.packing.show', $packing->id))->assertOk()->assertSee('Scan barcode');
        $scan = route('gudang.packing.scan', $packing->id);

        $this->postJson($scan, ['code' => 'JSC-B-L'])->assertStatus(422)->assertJson(['ok' => false]);
        $this->postJson($scan, ['code' => 'jsc-a-l'])->assertOk()->assertJson(['completed' => false]);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertOk()->assertJson(['completed' => true]);

        $this->assertSame('READY_TO_SHIP', $picking->order->fresh()->status);
        $this->postJson($scan, ['code' => 'JSC-A-L'])->assertStatus(422);
    }

    public function test_rack_left_from_previous_scan_does_not_block_picking(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        WarehouseRack::create(['code' => 'A']);
        WarehouseRack::create(['code' => 'B']);
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);

        $picking = $this->startedPicking($staff, 'JSC-A-L', 1);

        // Lokasi aktif masih B-01-01 dari barang sebelumnya: dicatat dari rak yang ada stoknya
        $this->postJson(route('gudang.picking.scan', $picking->id), ['code' => 'JSC-A-L', 'bin_code' => 'B-01-01'])
            ->assertOk()->assertJson(['ok' => true, 'active_bin' => 'A-01-01', 'completed' => true])
            ->assertJsonPath('warning', 'Di rak B-01-01 tidak ada JSC-A-L, dicatat dari A-01-01.');
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));

        // Stok habis di semua rak: pesannya jelas, bukan "tidak cukup di bin X"
        $this->putStock('JSC-B-L', 'B-01-01', 1);
        $this->travel(1)->minutes();
        $empty = $this->startedPicking($staff, 'JSC-B-L', 1);
        \App\Models\StockActive::where('sku', 'JSC-B-L')->update(['qty' => 0]);
        $this->postJson(route('gudang.picking.scan', $empty->id), ['code' => 'JSC-B-L'])
            ->assertStatus(422)->assertJson(['message' => 'Stok JSC-B-L kosong di semua rak.']);
    }

    public function test_tapping_items_counts_like_scanning_and_minus_one_corrects_mistakes(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);

        $picking = $this->startedPicking($staff, 'JSC-A-L', 2);
        $item = $picking->items()->first();
        $tap = route('gudang.picking.tap', [$picking->id, $item->id]);
        $this->get(route('gudang.picking.show', $picking->id))->assertSee('+1 Ambil')->assertDontSee('Input jumlah manual');

        $this->postJson($tap, ['delta' => -1])->assertStatus(422);
        $this->postJson($tap, ['delta' => 1])->assertOk()->assertJson(['ok' => true, 'completed' => false]);
        $this->postJson($tap, ['delta' => 1])->assertOk();
        $this->assertSame(3, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(2, \App\Models\AuditTrail::where('action', 'MANUAL_PICK')->count());

        // Salah ambil setelah keranjang lengkap tapi belum dikemas: -1 mengembalikan ke rak dan order kembali ke picking
        $this->assertSame('PACKING', $picking->order->fresh()->status);
        $this->postJson($tap, ['delta' => -1])->assertOk()->assertJson(['type' => 'undo']);
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'A-01-01'));
        $this->assertSame(['IN_PROGRESS', 'PICKING'], [$picking->fresh()->status, $picking->order->fresh()->status]);
        $this->postJson($tap, ['delta' => 1])->assertJson(['completed' => true]);

        // Packing: ketuk +1/-1, koreksi tidak bisa lebih kecil dari 0
        $this->post(route('gudang.packing.store'), ['order_id' => $picking->order_id]);
        $packing = PackingSession::where('order_id', $picking->order_id)->firstOrFail();
        $packTap = route('gudang.packing.tap', [$packing->id, $packing->items()->first()->id]);
        $this->postJson($packTap, ['delta' => -1])->assertStatus(422);
        $this->postJson($packTap, ['delta' => 1])->assertOk()->assertJson(['completed' => false]);
        $this->postJson($packTap, ['delta' => -1])->assertOk()->assertJson(['type' => 'undo']);
        $this->assertSame(1, \App\Models\AuditTrail::where('action', 'PACK_UNDO')->where('user_id', $staff->id)->count());
        $this->postJson($packTap, ['delta' => 1]);
        $this->postJson($packTap, ['delta' => 1])->assertJson(['completed' => true]);
        $this->assertSame('READY_TO_SHIP', $picking->order->fresh()->status);
        $this->postJson($packTap, ['delta' => -1])->assertStatus(422);
    }

    public function test_scan_endpoints_need_a_started_session(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 5);

        $this->actingAs($staff)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Budi', 'shipping_address' => 'Bandung', 'courier' => 'JNE',
            'items' => [['sku' => 'JSC-A-L', 'qty' => 1]],
        ]);
        $order = Order::latest()->firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id]);
        $picking = PickingSession::where('order_id', $order->id)->firstOrFail();

        $this->postJson(route('gudang.picking.scan', $picking->id), ['code' => 'JSC-A-L', 'bin_code' => 'A-01-01'])
            ->assertStatus(422);
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A-01-01'));
    }

    public function test_stock_check_page_only_reads(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        WarehouseRack::create(['code' => 'B']);
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'B-01-01', 4);
        $this->putStock('JSC-A-L', 'B-02-01', 6);

        $this->actingAs($staff)->get(route('gudang.scanner'))->assertOk()->assertSee('Cek Barang');

        $this->getJson(route('gudang.scanner.lookup', ['code' => 'jsc-a-l']))
            ->assertOk()
            ->assertJsonPath('sku.total', 10)
            ->assertJsonPath('rows.0.label', 'B-02-01');

        $this->getJson(route('gudang.scanner.lookup', ['code' => 'B-01-01']))
            ->assertOk()->assertJsonPath('type', 'bin')->assertJsonPath('rows.0.qty', 4);

        $this->getJson(route('gudang.scanner.lookup', ['code' => 'B']))
            ->assertOk()->assertJsonCount(2, 'rows');

        $this->getJson(route('gudang.scanner.lookup', ['code' => 'NOPE']))->assertNotFound();

        $this->assertSame(0, StockMovement::count());
        $this->assertSame(4, $this->stockQty('JSC-A-L', 'B-01-01'));
    }

    public function test_inbound_form_prefills_remaining_po_items_and_suggests_bins(): void
    {
        $owner = $this->userWithRole('OWNER');
        WarehouseRack::create(['code' => 'C']);
        $supplier = $this->makeSupplier();
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-L');
        $this->putStock('JSC-A-L', 'C-03-01', 2);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-1',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'supplier_type' => 'SUBLIMASI',
            'expected_date' => now()->addDay(),
            'status' => 'APPROVED',
            'created_by' => $owner->id,
        ]);
        $po->items()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'sku' => 'JSC-A-L', 'qty_ordered' => 10, 'qty_received' => 4, 'price' => 1000]);
        $po->items()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'sku' => 'JSC-B-L', 'qty_ordered' => 5, 'qty_received' => 5, 'price' => 1000]);

        $response = $this->actingAs($owner)->get(route('gudang.inbound.create', ['po_id' => $po->id]))
            ->assertOk()->assertSee('Scan barcode');

        $items = $response->viewData('poData')[$po->id]['items'];
        $this->assertCount(1, $items);
        $this->assertSame('JSC-A-L', $items[0]['sku']);
        $this->assertSame(6, $items[0]['remaining']);
        $this->assertSame('C-03-01', $items[0]['bin_code']);

        // Bin diketik huruf kecil disimpan huruf besar, reject tercatat
        $warehouse = $this->makeWarehouse();
        $this->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-1',
            'supplier_name' => $supplier->name,
            'received_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'po_id' => $po->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'c-03-01', 'qty_received' => 6, 'qty_rejected' => 1]],
        ])->assertRedirectContains('/gudang/inbound/');

        $this->assertSame(8, $this->stockQty('JSC-A-L', 'C-03-01'));
        $this->assertSame('RECEIVED', $po->fresh()->status);
        $this->assertStringContainsString('reject 1', StockMovement::where('reference_no', 'SJ-1')->value('notes'));
    }
}
