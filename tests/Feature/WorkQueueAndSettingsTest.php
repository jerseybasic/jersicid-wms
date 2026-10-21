<?php

namespace Tests\Feature;

use App\Models\MasterSku;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SystemSetting;
use App\Services\LowStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Tahap 5: Tugas Hari Ini (lonceng + dashboard), stok menipis, PO ditolak,
 * PO dari stok menipis, dan pengaturan data perusahaan.
 */
class WorkQueueAndSettingsTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function pendingPo(string $sku, string $createdBy): PurchaseOrder
    {
        $supplier = $this->makeSupplier();
        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-' . strtoupper(Str::random(4)),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'supplier_type' => 'SUBLIMASI',
            'expected_date' => now()->addWeek(),
            'status' => 'PENDING',
            'notes' => 'Catatan awal',
            'created_by' => $createdBy,
        ]);
        PurchaseOrderItem::create([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $po->id,
            'sku' => $sku,
            'qty_ordered' => 10,
            'qty_received' => 0,
            'price' => 50000,
        ]);

        return $po;
    }

    private function workCount(array $items, string $key): int
    {
        return collect($items)->firstWhere('key', $key)['count'] ?? -1;
    }

    public function test_bell_and_dashboard_show_waiting_work_per_role(): void
    {
        $owner = $this->userWithRole('OWNER');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A-01-01', 20);
        $this->pendingPo('JSC-A-L', $owner->id);

        $this->actingAs($staff)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Siti',
            'customer_phone' => '0813',
            'shipping_address' => 'Bandung',
            'courier' => 'JNT',
            'items' => [['sku' => 'JSC-A-L', 'qty' => 2]],
        ]);
        $this->assertSame('PENDING', Order::firstOrFail()->status);

        // Owner: PO menunggu persetujuan + order baru, langsung terlihat di lonceng dan dashboard
        $response = $this->actingAs($owner)->get(route('dashboard.owner'))->assertOk()
            ->assertSee('Tugas Hari Ini')
            ->assertSee('PO menunggu persetujuan')
            ->assertSee('Order baru perlu dikonfirmasi')
            ->assertSee('data-work-badge', false);
        $items = $response->viewData('workItems');
        $this->assertSame(1, $this->workCount($items, 'po_approve'));
        $this->assertSame(1, $this->workCount($items, 'order_confirm'));

        // Staf gudang tidak melihat persetujuan PO atau stok menipis
        $items = $this->actingAs($staff)->get(route('dashboard.gudang'))->assertOk()
            ->assertDontSee('PO menunggu persetujuan')
            ->viewData('workItems');
        $this->assertSame(-1, $this->workCount($items, 'po_approve'));
        $this->assertSame(-1, $this->workCount($items, 'low_stock'));
        $this->assertSame(1, $this->workCount($items, 'order_confirm'));

        // Setelah dikonfirmasi, angka berpindah ke "perlu diambil" tanpa menunggu cache
        $order = Order::firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED']);
        $items = $this->get(route('dashboard.gudang'))->viewData('workItems');
        $this->assertSame(0, $this->workCount($items, 'order_confirm'));
        $this->assertSame(1, $this->workCount($items, 'to_pick'));

        // Endpoint notifikasi lama sudah dihapus
        $this->get('/api/notifications')->assertNotFound();
    }

    public function test_low_stock_uses_sku_minimum_or_general_threshold(): void
    {
        SystemSetting::set('low_stock_threshold', '10', 'INVENTORY');

        $own = $this->makeSku('JSC-OWN-L');            // min_stock sendiri 30, stok 25 → menipis
        $own->update(['min_stock' => 30]);
        $this->putStock('JSC-OWN-L', 'A-01-01', 25);

        $this->makeSku('JSC-LOW-L');                    // min 0, stok 4 ≤ 10 → menipis
        $this->putStock('JSC-LOW-L', 'A-01-02', 4);

        $this->makeSku('JSC-OK-L');                     // min 0, stok 40 → aman
        $this->putStock('JSC-OK-L', 'A-01-03', 40);

        $this->makeSku('JSC-NEW-L');                    // belum pernah ada stok → tidak dihitung

        $this->assertEqualsCanonicalizing(['JSC-OWN-L', 'JSC-LOW-L'], LowStock::query()->pluck('sku')->all());

        SystemSetting::set('low_stock_threshold', '0', 'INVENTORY');
        $this->assertSame(['JSC-OWN-L'], LowStock::query()->pluck('sku')->all());

        $this->assertSame(35, LowStock::suggestedQty(25, 30));
        $this->assertSame(1, LowStock::suggestedQty(50, 10));
    }

    public function test_owner_creates_po_prefilled_from_low_stock(): void
    {
        $owner = $this->userWithRole('OWNER');
        $sku = $this->makeSku('JSC-LOW-L', 80000);
        $sku->update(['min_stock' => 10]);
        $this->putStock('JSC-LOW-L', 'A-01-01', 3);
        $this->makeSku('JSC-OK-L');
        $this->putStock('JSC-OK-L', 'A-01-02', 100);

        $this->actingAs($owner)->get(route('po.index'))->assertSee('PO dari Stok Menipis');

        $response = $this->get(route('po.create', ['dari' => 'stok-menipis']))->assertOk()
            ->assertSee('1 SKU stok menipis sudah dimasukkan')
            ->assertSee('Stok sekarang 3 pcs, batas 10 pcs');

        $rows = $response->viewData('prefillRows');
        $this->assertCount(1, $rows);
        $this->assertSame(['sku' => 'JSC-LOW-L', 'qty' => 17, 'price' => 80000, 'stock' => 3, 'limit' => 10], $rows[0]);

        // Staf tidak boleh membuat PO
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->get(route('po.create', ['dari' => 'stok-menipis']))->assertForbidden();
    }

    public function test_manager_rejects_pending_po_back_to_draft_with_reason(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->makeSku('JSC-A-L');
        $po = $this->pendingPo('JSC-A-L', $owner->id);

        $this->actingAs($owner)->get(route('po.show', $po->id))->assertSee('Tolak');

        // Alasan wajib
        $this->patch(route('po.update-status', $po->id), ['status' => 'DRAFT'])->assertSessionHasErrors('reason');
        $this->assertSame('PENDING', $po->fresh()->status);

        $this->patch(route('po.update-status', $po->id), ['status' => 'DRAFT', 'reason' => 'Harga terlalu mahal'])
            ->assertSessionHas('success');
        $po->refresh();
        $this->assertSame('DRAFT', $po->status);
        $this->assertStringStartsWith('Ditolak oleh ' . $owner->name, $po->notes);
        $this->assertStringContainsString('Harga terlalu mahal', $po->notes);
        $this->assertStringContainsString('Catatan awal', $po->notes);

        // Bisa diedit lagi lalu diajukan ulang
        $this->get(route('po.edit', $po->id))->assertOk();
        $this->patch(route('po.update-status', $po->id), ['status' => 'PENDING'])->assertSessionHas('success');
        $this->assertSame('PENDING', $po->fresh()->status);

        // ADMIN_GUDANG tidak boleh menolak
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->patch(route('po.update-status', $po->id), ['status' => 'DRAFT', 'reason' => 'x'])
            ->assertForbidden();

        // Hanya PO yang menunggu persetujuan yang bisa ditolak
        $po->update(['status' => 'APPROVED']);
        $this->actingAs($owner)->patch(route('po.update-status', $po->id), ['status' => 'DRAFT', 'reason' => 'x'])
            ->assertSessionHas('error');
    }

    public function test_owner_saves_company_settings_used_on_shipping_label(): void
    {
        $owner = $this->userWithRole('OWNER');

        $this->actingAs($owner)->get(route('settings.index'))->assertOk()->assertSee('Data Perusahaan');

        $this->put(route('settings.update'), [
            'company_name' => 'JERSIC.ID Bandung',
            'company_phone' => '0812-0000-1111',
            'company_address' => 'Jl. Merdeka 1, Bandung',
            'low_stock_threshold' => 5,
        ])->assertSessionHas('success');

        $this->assertSame('JERSIC.ID Bandung', SystemSetting::get('company_name'));
        $this->assertSame(5, LowStock::defaultThreshold());
        $this->assertSame('JERSIC.ID Bandung', setting('company_name'));

        $this->put(route('settings.update'), ['company_name' => '', 'low_stock_threshold' => -1])
            ->assertSessionHasErrors(['company_name', 'low_stock_threshold']);

        $this->actingAs($this->userWithRole('SUPERVISOR'))
            ->put(route('settings.update'), ['company_name' => 'X', 'low_stock_threshold' => 1])
            ->assertForbidden();
    }

    public function test_audit_trail_is_in_the_menu_for_owner_and_supervisor_only(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'))->get(route('dashboard.gudang'))
            ->assertSee('Riwayat Aktivitas');
        $this->get(route('audit-trails.index'))->assertOk();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('dashboard.gudang'))
            ->assertDontSee('Riwayat Aktivitas');
    }
}
