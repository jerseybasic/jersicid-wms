<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\SystemSetting;
use App\Models\WhatsAppMessage;
use App\Services\PoNotifier;
use App\Services\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Cetak PO untuk supplier dan notifikasi WhatsApp saat status PO berubah.
 */
class PurchaseOrderPrintAndNotifyTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function makePo(string $status = 'DRAFT'): PurchaseOrder
    {
        $supplier = $this->makeSupplier();
        $supplier->update(['phone' => '0812-1111-2222', 'contact_person' => 'Pak Joko']);
        $this->makeSku('JSC-A-L');

        $this->actingAs($this->userWithRole('OWNER'))->post(route('po.store'), [
            'supplier_id' => $supplier->id,
            'supplier_type' => 'SUBLIMASI',
            'expected_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Jersey musim baru',
            'items' => [['sku' => 'JSC-A-L', 'qty' => 12, 'price' => 50000]],
        ]);

        $po = PurchaseOrder::firstOrFail();
        $po->update(['status' => $status]);

        return $po;
    }

    private function configureWhatsApp(): void
    {
        SystemSetting::set('whatsapp_macro_token', 'hash-token');
        WhatsApp::saveExtraTargets('081200001111');
    }

    public function test_print_page_shows_po_for_supplier(): void
    {
        $po = $this->makePo();

        $this->get(route('po.show', $po->id))->assertSee(route('po.print', $po->id));

        $this->get(route('po.print', $po->id))->assertOk()
            ->assertSee($po->po_number)
            ->assertSee('JB Printing')
            ->assertSee('Pak Joko')
            ->assertSee('JSC-A-L')
            ->assertSee('Rp 600.000')
            ->assertSee('DRAFT · BELUM DISETUJUI')
            ->assertSee('https://wa.me/6281211112222?text=', false);

        $po->update(['status' => 'APPROVED']);
        $this->get(route('po.print', $po->id))->assertDontSee('DRAFT · BELUM DISETUJUI');
    }

    public function test_staff_cannot_open_po_print(): void
    {
        $po = $this->makePo();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('po.print', $po->id))->assertForbidden();
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->get(route('po.print', $po->id))->assertOk();
    }

    public function test_status_change_queues_whatsapp_message(): void
    {
        $this->configureWhatsApp();
        $po = $this->makePo('PENDING');

        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner)->patch(route('po.update-status', $po->id), ['status' => 'APPROVED'])->assertSessionHas('success');

        $message = WhatsAppMessage::sole();
        $this->assertSame('6281200001111', $message->recipient);
        $this->assertStringContainsString("PO {$po->po_number} disetujui", $message->body);
        $this->assertStringContainsString('1 SKU · 12 pcs', $message->body);
        $this->assertStringContainsString('Oleh: ' . $owner->name, $message->body);
    }

    public function test_no_message_when_setting_is_off_or_whatsapp_not_ready(): void
    {
        $po = $this->makePo('PENDING');

        // WhatsApp belum diatur: perubahan status tetap jalan
        $this->patch(route('po.update-status', $po->id), ['status' => 'APPROVED'])->assertSessionHas('success');
        $this->assertSame(0, WhatsAppMessage::count());

        $this->configureWhatsApp();
        $this->put(route('settings.whatsapp'), ['whatsapp_target' => '081200001111'])->assertSessionHas('success');
        $this->assertFalse(PoNotifier::enabled());

        $this->patch(route('po.update-status', $po->id), ['status' => 'IN_PRODUCTION'])->assertSessionHas('success');
        $this->assertSame(0, WhatsAppMessage::count());

        $this->put(route('settings.whatsapp'), ['whatsapp_target' => '081200001111', 'po_auto_notify' => 1]);
        $this->assertTrue(PoNotifier::enabled());
        $this->get(route('settings.index'))->assertSee('Kirim notifikasi WhatsApp saat status PO berubah');
    }

    public function test_received_through_inbound_notifies(): void
    {
        $this->configureWhatsApp();
        $po = $this->makePo('READY_FOR_PICKUP');
        $warehouse = $this->makeWarehouse();

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->post(route('gudang.inbound.store'), [
            'receipt_number' => 'SJ-010',
            'supplier_name' => $po->supplier_name,
            'received_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'po_id' => $po->id,
            'items' => [['sku' => 'JSC-A-L', 'bin_code' => 'A1-01', 'qty_received' => 12]],
        ])->assertRedirectContains('/gudang/inbound/');

        $this->assertSame('RECEIVED', $po->fresh()->status);
        $this->assertStringContainsString('sudah diterima lengkap di gudang', WhatsAppMessage::sole()->body);
    }
}
