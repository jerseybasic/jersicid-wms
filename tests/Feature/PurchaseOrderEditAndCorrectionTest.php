<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * PO yang sudah diajukan tetap bisa diedit, statusnya bisa dikoreksi kalau salah karena miskom,
 * dan PO bisa dibatalkan (lalu diaktifkan lagi) dengan alasan yang tercatat.
 */
class PurchaseOrderEditAndCorrectionTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function makePo(string $status = 'APPROVED'): PurchaseOrder
    {
        $supplier = $this->makeSupplier();
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-A-XL');

        $this->post(route('po.store'), [
            'supplier_id' => $supplier->id,
            'supplier_type' => 'SUBLIMASI',
            'expected_date' => now()->addWeek()->toDateString(),
            'items' => [
                ['sku' => 'JSC-A-L', 'qty' => 10, 'price' => 50000],
                ['sku' => 'JSC-A-XL', 'qty' => 6, 'price' => 50000],
            ],
        ])->assertSessionHas('success');

        $po = PurchaseOrder::with('items')->firstOrFail();
        $po->update(['status' => $status]);

        return $po;
    }

    private function payload(PurchaseOrder $po, array $items, array $extra = []): array
    {
        return [
            'supplier_id' => $po->supplier_id,
            'supplier_type' => $po->supplier_type,
            'expected_date' => now()->addWeeks(2)->toDateString(),
            'items' => $items,
        ] + $extra;
    }

    public function test_approved_po_can_be_edited_with_reason_and_keeps_received_qty(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'));
        $po = $this->makePo('IN_PRODUCTION');
        $po->items()->where('sku', 'JSC-A-L')->update(['qty_received' => 4]);

        $this->get(route('po.show', $po->id))->assertSee('Edit PO');
        $this->get(route('po.edit', $po->id))->assertOk()->assertSee('Sudah diterima 4 pcs');

        $this->put(route('po.update', $po->id), $this->payload($po, [
            ['sku' => 'JSC-A-L', 'qty' => 12, 'price' => 52000],
            ['sku' => 'JSC-TEST-NEW', 'qty' => 3, 'price' => 1000],
        ], ['change_reason' => 'Supplier tambah 2 pcs']))->assertSessionHasErrors('items.1.sku');

        $this->makeSku('JSC-B-L');
        $this->put(route('po.update', $po->id), $this->payload($po, [
            ['sku' => 'JSC-A-L', 'qty' => 12, 'price' => 52000],
            ['sku' => 'JSC-B-L', 'qty' => 3, 'price' => 40000],
        ], ['change_reason' => 'Supplier tambah 2 pcs']))->assertRedirect(route('po.show', $po->id));

        $po->refresh()->load('items');
        $this->assertSame('IN_PRODUCTION', $po->status);
        $this->assertEqualsCanonicalizing(['JSC-A-L', 'JSC-B-L'], $po->items->pluck('sku')->all());
        $line = $po->items->firstWhere('sku', 'JSC-A-L');
        $this->assertSame(12, $line->qty_ordered);
        $this->assertSame(4, $line->qty_received);
        $this->assertStringContainsString('Supplier tambah 2 pcs', $po->notes);
        $this->assertTrue(AuditTrail::where('action', 'UPDATE')->where('new_values', 'like', '%Supplier tambah%')->exists());
    }

    public function test_received_items_cannot_be_removed_or_go_below_received(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $po = $this->makePo('APPROVED');
        $po->items()->where('sku', 'JSC-A-L')->update(['qty_received' => 4]);

        $this->put(route('po.update', $po->id), $this->payload($po, [
            ['sku' => 'JSC-A-XL', 'qty' => 6, 'price' => 50000],
        ], ['change_reason' => 'x']))->assertSessionHasErrors('items');

        $this->put(route('po.update', $po->id), $this->payload($po, [
            ['sku' => 'JSC-A-L', 'qty' => 3, 'price' => 50000],
        ], ['change_reason' => 'x']))->assertSessionHasErrors('items');

        $this->assertSame(10, $po->items()->where('sku', 'JSC-A-L')->value('qty_ordered'));

        // Qty dikurangi sampai sama dengan yang sudah diterima dan sisanya dihapus: PO selesai
        $this->put(route('po.update', $po->id), $this->payload($po, [
            ['sku' => 'JSC-A-L', 'qty' => 4, 'price' => 50000],
        ], ['change_reason' => 'Sisanya tidak jadi']))->assertSessionHas('success');
        $this->assertSame('RECEIVED', $po->fresh()->status);
        $this->get(route('po.edit', $po->id))->assertRedirect(route('po.show', $po->id));
    }

    public function test_duplicate_sku_rows_are_rejected(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $po = $this->makePo('DRAFT');

        $this->put(route('po.update', $po->id), $this->payload($po, [
            ['sku' => 'JSC-A-L', 'qty' => 1, 'price' => 1],
            ['sku' => 'JSC-A-L', 'qty' => 2, 'price' => 1],
        ]))->assertSessionHasErrors('items.1.sku');
    }

    public function test_manager_corrects_wrong_status_with_reason(): void
    {
        $owner = $this->userWithRole('OWNER');
        $this->actingAs($owner);
        $po = $this->makePo('IN_PRODUCTION');

        $this->get(route('po.show', $po->id))->assertSee('Koreksi status');

        $this->patch(route('po.correct-status', $po->id), ['status' => 'APPROVED'])->assertSessionHasErrors('reason');
        $this->patch(route('po.correct-status', $po->id), ['status' => 'RECEIVED', 'reason' => 'x'])->assertSessionHasErrors('status');

        $this->patch(route('po.correct-status', $po->id), ['status' => 'APPROVED', 'reason' => 'Salah klik, produksi belum mulai'])
            ->assertSessionHas('success');
        $po->refresh();
        $this->assertSame('APPROVED', $po->status);
        $this->assertSame($owner->id, $po->approved_by);
        $this->assertStringContainsString('dari Sedang Diproduksi ke Disetujui: Salah klik', $po->notes);
        $this->assertTrue(AuditTrail::where('action', 'CORRECT_PO_STATUS')->exists());

        // Kembali ke Draft: data persetujuan dikosongkan
        $this->patch(route('po.correct-status', $po->id), ['status' => 'DRAFT', 'reason' => 'Belum disetujui'])->assertSessionHas('success');
        $this->assertNull($po->fresh()->approved_at);

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->patch(route('po.correct-status', $po->id), ['status' => 'PENDING', 'reason' => 'x'])->assertForbidden();
    }

    public function test_po_can_be_cancelled_with_reason_and_reactivated(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'));
        $po = $this->makePo('READY_FOR_PICKUP');

        $this->get(route('po.show', $po->id))->assertSee('Batalkan PO');
        $this->patch(route('po.update-status', $po->id), ['status' => 'CANCELLED', 'reason' => 'Tidak jadi order'])
            ->assertSessionHas('success');
        $po->refresh();
        $this->assertSame('CANCELLED', $po->status);
        $this->assertStringContainsString('Tidak jadi order', $po->notes);
        $this->get(route('po.edit', $po->id))->assertRedirect(route('po.show', $po->id));

        // Ternyata jadi order: aktifkan lagi lewat koreksi status
        $this->patch(route('po.correct-status', $po->id), ['status' => 'APPROVED', 'reason' => 'Jadi order lagi'])
            ->assertSessionHas('success');
        $this->assertSame('APPROVED', $po->fresh()->status);
    }

    public function test_received_po_status_cannot_be_corrected(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $po = $this->makePo('RECEIVED');

        $this->patch(route('po.correct-status', $po->id), ['status' => 'APPROVED', 'reason' => 'x'])->assertSessionHas('error');
        $this->assertSame('RECEIVED', $po->fresh()->status);
    }

    public function test_create_form_add_item_button_does_not_remove_itself(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->makeSku('JSC-A-L');

        // Tombol hapus baris juga berwarna merah; skrip Tambah Item hanya boleh membuang keterangan baris
        $this->get(route('po.create'))->assertOk()
            ->assertSee('id="add-item"', false)
            ->assertSee(".row-note, .row-received", false)
            ->assertDontSee("querySelectorAll('.text-red-400')", false);
    }
}
