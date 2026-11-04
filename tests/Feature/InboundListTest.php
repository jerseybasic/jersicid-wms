<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Daftar Inbound: satu baris per nomor receipt, status Belum Komplit selama sisa PO masih ditunggu.
 */
class InboundListTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    public function test_receipt_with_many_skus_is_one_row_with_po_status(): void
    {
        $owner = $this->userWithRole('OWNER');
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        foreach (['JSC-A-M', 'JSC-A-L', 'JSC-A-XL'] as $sku) {
            $this->makeSku($sku);
        }

        $this->actingAs($owner)->post(route('po.store'), [
            'supplier_id' => $supplier->id,
            'supplier_type' => 'KONVEKSI',
            'expected_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['sku' => 'JSC-A-M', 'qty' => 5, 'price' => 1000],
                ['sku' => 'JSC-A-L', 'qty' => 5, 'price' => 1000],
                ['sku' => 'JSC-A-XL', 'qty' => 5, 'price' => 1000],
            ],
        ]);
        $po = PurchaseOrder::firstOrFail();
        $po->update(['status' => 'IN_PRODUCTION']);

        $receive = fn (string $no, int $qty, ?string $poId) => $this->post(route('gudang.inbound.store'), [
            'receipt_number' => $no,
            'supplier_name' => $poId ? $po->supplier_name : 'Toko Kain Sejahtera',
            'received_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'po_id' => $poId,
            'notes' => $poId ? 'baru jadi 2pcs per size, sisanya masih proses' : null,
            'items' => collect(['JSC-A-M', 'JSC-A-L', 'JSC-A-XL'])
                ->map(fn ($sku, $i) => ['sku' => $sku, 'bin_code' => 'A-01-0' . ($i + 1), 'qty_received' => $qty])->all(),
        ])->assertRedirectContains('/gudang/inbound/');

        $this->actingAs($admin);
        $receive('JJ637 - JJ639', 2, $po->id);
        $receive('TK-001', 1, null);

        $page = $this->get(route('gudang.inbound'))->assertOk();
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, '>JJ637 - JJ639</a>'));
        $page->assertSee('2 total')
            ->assertSee('+6 pcs')
            ->assertSee('3 SKU')
            ->assertSee($po->po_number)
            ->assertSee('JB Printing')
            ->assertSee('Toko Kain Sejahtera')
            ->assertSee('Belum Komplit')
            ->assertSee('baru sebagian jadi · sisa 9 pcs')
            ->assertDontSee('COMPLETED');

        $this->get(route('gudang.inbound', ['status' => 'BELUM_KOMPLIT']))->assertOk()
            ->assertSee('JJ637 - JJ639')->assertDontSee('TK-001');
        $this->get(route('gudang.inbound', ['status' => 'KOMPLIT']))->assertOk()
            ->assertSee('TK-001')->assertDontSee('JJ637 - JJ639');
        $this->get(route('gudang.inbound', ['search' => $po->po_number]))->assertOk()
            ->assertSee('JJ637 - JJ639')->assertDontSee('TK-001');

        // Sisanya datang: receipt lama ikut menjadi Komplit
        $receive('JJ640', 3, $po->id);
        $this->assertSame('RECEIVED', $po->fresh()->status);
        $this->get(route('gudang.inbound', ['status' => 'BELUM_KOMPLIT']))->assertOk()
            ->assertDontSee('JJ637 - JJ639')->assertSee('0 total');
    }
}
