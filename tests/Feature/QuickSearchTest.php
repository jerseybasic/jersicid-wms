<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PurchaseOrder;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Pencarian cepat di header dan scan no. order / resi untuk membuka detail order.
 */
class QuickSearchTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function order(string $number, string $customer, ?string $resi = null): Order
    {
        return Order::create(['order_number' => $number, 'customer_name' => $customer, 'status' => 'SHIPPED', 'tracking_number' => $resi]);
    }

    private function titles(array $groups, string $label): array
    {
        return collect(collect($groups)->firstWhere('label', $label)['items'] ?? [])->pluck('title')->all();
    }

    public function test_suggestions_cover_orders_skus_locations_po_and_returns(): void
    {
        $owner = $this->userWithRole('OWNER');
        $order = $this->order('ORD-2610-001', 'Budi Santoso', 'JNE777888');
        $this->order('ORD-2610-002', 'Siti');
        $this->makeSku('JSC-BUDI-L');
        $this->putStock('JSC-BUDI-L', 'A-01-01', 4);
        WarehouseRack::create(['code' => 'A']);
        $supplier = $this->makeSupplier();
        PurchaseOrder::create(['po_number' => 'PO-2610-77', 'supplier_id' => $supplier->id, 'supplier_name' => 'JB Printing', 'supplier_type' => 'SUBLIMASI', 'status' => 'DRAFT', 'expected_date' => now()->addWeek(), 'created_by' => $owner->id]);
        $return = OrderReturn::create(['return_number' => 'RTR-2610-01', 'order_id' => $order->id, 'reason' => 'RUSAK', 'received_by' => $owner->id]);

        $this->actingAs($owner);

        // Nama pembeli dan nama SKU sama-sama cocok
        $groups = $this->getJson(route('search', ['q' => 'budi']))->assertOk()->json('groups');
        $this->assertSame(['ORD-2610-001'], $this->titles($groups, 'Order'));
        $this->assertSame(['JSC-BUDI-L'], $this->titles($groups, 'SKU'));
        $this->assertStringContainsString('stok 4 pcs', collect($groups)->firstWhere('label', 'SKU')['items'][0]['subtitle']);

        $groups = $this->getJson(route('search', ['q' => '2610']))->json('groups');
        $this->assertCount(2, $this->titles($groups, 'Order'));
        $this->assertSame(['PO-2610-77'], $this->titles($groups, 'Purchase Order'));
        $this->assertSame(['RTR-2610-01'], $this->titles($groups, 'Retur'));

        $this->assertSame(['A-01-01'], $this->titles($this->getJson(route('search', ['q' => 'a-01-01']))->json('groups'), 'Lokasi'));
        $this->assertSame([], $this->getJson(route('search', ['q' => 'x']))->json('groups'));

        // Enter dengan kode persis (no. resi) langsung membuka detail order
        $this->get(route('search', ['q' => 'jne777888']))->assertRedirect(route('gudang.outbound.show', $order->id));
        $this->get(route('search', ['q' => 'RTR-2610-01']))->assertRedirect(route('gudang.retur.show', $return->id));
        $this->get(route('search', ['q' => 'JSC-BUDI-L']))->assertRedirect(route('gudang.scanner', ['kode' => 'JSC-BUDI-L']));

        // Banyak hasil: halaman hasil pencarian
        $this->get(route('search', ['q' => '2610']))->assertOk()->assertSee('Hasil Pencarian')->assertSee('ORD-2610-002')->assertSee('PO-2610-77');
        $this->get(route('search', ['q' => 'tidakada']))->assertOk()->assertSee('Tidak ada yang cocok');

        // Kotak cari ada di header setiap halaman
        $this->get(route('dashboard.owner'))->assertSee('Cari order, resi, SKU, rak...');
    }

    public function test_staff_does_not_see_purchase_orders(): void
    {
        $owner = $this->userWithRole('OWNER');
        $supplier = $this->makeSupplier();
        PurchaseOrder::create(['po_number' => 'PO-2610-77', 'supplier_id' => $supplier->id, 'supplier_name' => 'JB Printing', 'supplier_type' => 'SUBLIMASI', 'status' => 'DRAFT', 'expected_date' => now()->addWeek(), 'created_by' => $owner->id]);

        $groups = $this->actingAs($this->userWithRole('STAFF_GUDANG'))->getJson(route('search', ['q' => 'PO-2610']))->json('groups');
        $this->assertSame([], $this->titles($groups, 'Purchase Order'));
    }

    public function test_scanning_order_or_resi_opens_order_detail(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $order = $this->order('ORD-2610-001', 'Budi', 'SPX123');

        $this->actingAs($staff)->getJson(route('gudang.scanner.lookup', ['code' => 'spx123']))
            ->assertOk()->assertJson(['type' => 'order', 'redirect' => route('gudang.outbound.show', $order->id)]);

        // Kotak scan di Tugas Saya: QR perintah atau nomor order
        $this->postJson(route('gudang.command'), ['code' => 'ORD-2610-001'])
            ->assertOk()->assertJson(['redirect' => route('gudang.outbound.show', $order->id)]);
        $this->postJson(route('gudang.command'), ['code' => 'ACAK-123'])->assertStatus(422);

        $this->get(route('gudang.scanner', ['kode' => 'SPX123']))->assertOk()->assertSee('SPX123');
    }
}
