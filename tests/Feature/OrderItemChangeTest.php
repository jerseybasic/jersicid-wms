<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\RestockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Pembeli ganti produk setelah checkout: item order diganti dari form Ubah Order atau lewat import
 * ulang BigSeller, juga saat order sudah di-picking/packing. Barang lama masuk Kembalikan ke Rak.
 */
class OrderItemChangeTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function confirmedOrder($user, array $items): Order
    {
        $this->actingAs($user)->post(route('gudang.outbound.store'), [
            'customer_name' => 'Budi',
            'shipping_address' => 'Jl. Merdeka 1, Bandung',
            'items' => collect($items)->map(fn ($qty, $sku) => ['sku' => $sku, 'qty' => $qty])->values()->all(),
        ])->assertRedirect(route('gudang.outbound'));
        $order = Order::latest()->firstOrFail();
        $this->patch(route('gudang.outbound.update-status', $order->id), ['status' => 'CONFIRMED'])->assertSessionHas('success');

        return $order->fresh();
    }

    /** Ambil semua item order dari rak (sesi picking satu order) */
    private function pickAll(Order $order): PickingSession
    {
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id])->assertSessionHas('success');
        $picking = PickingSession::with('items')->where('order_id', $order->id)->firstOrFail();
        $this->post(route('gudang.picking.start', $picking->id))->assertSessionHas('success');
        foreach ($picking->items as $item) {
            $this->post(route('gudang.picking.pick', [$picking->id, $item->id]), ['qty_picked' => $item->qty_ordered, 'bin_code' => $item->bin_code, 'reason' => 'barcode rusak'])
                ->assertSessionHas('success');
        }

        return $picking->fresh();
    }

    private function changeItems(Order $order, array $items)
    {
        return $this->put(route('gudang.outbound.update', $order->id), [
            'customer_name' => $order->customer_name,
            'shipping_address' => $order->shipping_address,
            'items' => collect($items)->map(fn ($qty, $sku) => ['sku' => $sku, 'qty' => $qty])->values()->all(),
        ]);
    }

    public function test_item_change_while_picking_not_started_returns_order_to_queue(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-M');
        $this->putStock('JSC-A-L', 'A1-01', 5);
        $this->putStock('JSC-B-M', 'B1-01', 5);
        $order = $this->confirmedOrder($admin, ['JSC-A-L' => 1]);
        $this->post(route('gudang.picking.store'), ['order_id' => $order->id])->assertSessionHas('success');

        $this->changeItems($order, ['JSC-B-M' => 1])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('CONFIRMED', $order->status);
        $this->assertSame(['JSC-B-M'], $order->items->pluck('sku')->all());
        $this->assertSame(0, PickingSession::count());
        $this->assertSame(0, RestockItem::count());
        $this->assertSame(5, $this->stockQty('JSC-A-L', 'A1-01'));
    }

    public function test_item_change_after_picked_and_packing_keeps_basket_and_returns_old_item(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-M');
        $this->makeSku('JSC-C-XL', 175000);
        $this->putStock('JSC-A-L', 'A1-01', 5);
        $this->putStock('JSC-B-M', 'B1-01', 5);
        $this->putStock('JSC-C-XL', 'C1-01', 5);
        $order = $this->confirmedOrder($admin, ['JSC-A-L' => 1, 'JSC-B-M' => 1]);
        $picking = $this->pickAll($order);
        $this->assertSame('PACKING', $order->fresh()->status);
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id, 'courier' => 'JNE'])->assertSessionHas('success');
        $this->assertSame(4, $this->stockQty('JSC-B-M', 'B1-01'));

        // Pembeli ganti JSC-B-M dengan JSC-C-XL
        $this->changeItems($order, ['JSC-A-L' => 1, 'JSC-C-XL' => 1])
            ->assertRedirect(route('gudang.outbound.show', $order->id))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'JSC-B-M ×1 (rak B1-01)'));

        $order->refresh()->load('items');
        $this->assertSame('PICKING', $order->status);
        $this->assertSame(0, PackingSession::count(), 'Paket dikemas ulang karena isinya berubah');
        $items = $order->items->keyBy('sku');
        $this->assertSame(['JSC-A-L', 'JSC-C-XL'], $items->keys()->sort()->values()->all());
        $this->assertSame(1, $items['JSC-A-L']->qty_picked, 'Barang yang tetap dipakai tidak perlu diambil lagi');
        $this->assertSame(0, $items['JSC-C-XL']->qty_picked);
        $this->assertEquals(175000, $items['JSC-C-XL']->price);

        // Sesi picking yang sama dilanjutkan dengan item baru
        $picking->refresh()->load('items');
        $this->assertSame('IN_PROGRESS', $picking->status);
        $this->assertSame('C1-01', $picking->items->firstWhere('sku', 'JSC-C-XL')->bin_code);

        // Barang lama belum menambah stok sampai ditaruh kembali
        $restock = RestockItem::firstOrFail();
        $this->assertSame(['JSC-B-M', 'B1-01', 1], [$restock->sku, $restock->bin_code, $restock->qty]);
        $this->assertSame(4, $this->stockQty('JSC-B-M', 'B1-01'));
        $this->get(route('gudang.restock'))->assertOk()->assertSee($order->order_number)->assertSee('item diganti');
        $this->postJson(route('gudang.restock.tap-change', $restock->id))->assertOk()->assertJson(['ok' => true, 'completed' => true]);
        $this->assertSame(5, $this->stockQty('JSC-B-M', 'B1-01'));
        $this->assertSame(0, $restock->fresh()->qty);

        // Ambil item baru, lalu order lanjut ke packing lagi
        $new = $picking->items->firstWhere('sku', 'JSC-C-XL');
        $this->post(route('gudang.picking.pick', [$picking->id, $new->id]), ['qty_picked' => 1, 'bin_code' => 'C1-01', 'reason' => 'barcode rusak'])
            ->assertSessionHas('success');
        $this->assertSame('PACKING', $order->fresh()->status);
        $this->assertSame(4, $this->stockQty('JSC-C-XL', 'C1-01'));

        $this->assertSame(1, AuditTrail::where('action', 'CHANGE_ITEMS')->count());
        $this->get(route('gudang.outbound.show', $order->id))->assertOk()
            ->assertSee('Item diganti')
            ->assertSee('JSC-B-M ×1 → JSC-C-XL ×1');
    }

    public function test_reducing_qty_of_picked_item_keeps_order_in_packing(): void
    {
        $admin = $this->userWithRole('SUPERVISOR');
        $this->makeSku('JSC-A-L');
        $this->putStock('JSC-A-L', 'A1-01', 5);
        $order = $this->confirmedOrder($admin, ['JSC-A-L' => 3]);
        $this->pickAll($order);

        $this->changeItems($order, ['JSC-A-L' => 2])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('PACKING', $order->status);
        $this->assertSame(2, $order->items->first()->qty_picked);
        $this->assertSame(1, (int) RestockItem::sum('qty'));
    }

    public function test_item_change_needs_stock_and_leaves_order_untouched_otherwise(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-M');
        $this->putStock('JSC-A-L', 'A1-01', 5);
        $order = $this->confirmedOrder($admin, ['JSC-A-L' => 1]);
        $this->pickAll($order);

        $this->from(route('gudang.outbound.edit', $order->id));
        $this->changeItems($order, ['JSC-B-M' => 1])
            ->assertRedirect(route('gudang.outbound.edit', $order->id))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'JSC-B-M butuh 1, tersedia 0'));

        $order->refresh();
        $this->assertSame('PACKING', $order->status);
        $this->assertSame(['JSC-A-L'], $order->items->pluck('sku')->all());
        $this->assertSame(0, RestockItem::count());
        $this->assertSame(0, AuditTrail::where('action', 'CHANGE_ITEMS')->count());
    }

    public function test_ready_to_ship_order_is_taken_out_of_open_handover(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JSC-A-L');
        $this->makeSku('JSC-B-M');
        $this->putStock('JSC-A-L', 'A1-01', 5);
        $this->putStock('JSC-B-M', 'B1-01', 5);
        $order = $this->confirmedOrder($admin, ['JSC-A-L' => 1]);
        $this->pickAll($order);
        $this->post(route('gudang.packing.store'), ['order_id' => $order->id, 'courier' => 'JNE'])->assertSessionHas('success');
        $packing = PackingSession::with('items')->firstOrFail();
        $this->post(route('gudang.packing.pack', [$packing->id, $packing->items->first()->id]), ['qty_packed' => 1, 'reason' => 'barcode rusak'])->assertSessionHas('success');
        $order->update(['tracking_number' => 'JNE123']);
        $this->post(route('gudang.handover.store'), ['courier' => 'JNE']);
        $handover = CourierHandover::firstOrFail();
        $this->postJson(route('gudang.handover.scan', $handover), ['code' => 'JNE123'])->assertOk();
        $this->assertSame(1, $handover->items()->count());

        $this->changeItems($order->fresh(), ['JSC-B-M' => 1])->assertSessionHas('success');

        $this->assertSame('PICKING', $order->fresh()->status);
        $this->assertSame(0, $handover->items()->count(), 'Paket lama tidak boleh ikut diserahkan ke kurir');
        $this->assertSame(1, (int) RestockItem::where('sku', 'JSC-A-L')->sum('qty'));
    }

    // ---------- Import ulang BigSeller ----------

    private const HEADER = ['Nomor Pesanan', 'Status Pesanan', 'Nama Penerima', 'Alamat Lengkap', 'SKU', 'Jumlah', 'Harga Satuan', 'Nomor Resi', 'Waktu Pembatalan'];

    private function upload(array $rows): UploadedFile
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([self::HEADER, ...array_map(fn ($row) => array_values(array_merge(array_fill_keys(self::HEADER, '--'), [
            'Status Pesanan' => 'Menunggu Dicetak', 'Nama Penerima' => 'Ely', 'Alamat Lengkap' => 'Batam', 'Harga Satuan' => '150000', 'Nomor Resi' => 'SPX-1',
        ], $row)), $rows)], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bs') . '.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'Order.xlsx', null, null, true);
    }

    private function preview(array $rows)
    {
        return $this->post(route('gudang.outbound.import.preview'), ['file' => $this->upload($rows)])->assertOk();
    }

    public function test_reimport_with_changed_product_replaces_items_of_order_in_picking(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JER-A-M');
        $this->makeSku('JER-B-M');
        $this->makeSku('JER-C-L');
        $this->putStock('JER-A-M', 'A-01-01', 5);
        $this->putStock('JER-B-M', 'A-01-02', 5);
        $this->putStock('JER-C-L', 'A-01-03', 5);

        $first = $this->actingAs($admin)->preview([
            ['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-A-M', 'Jumlah' => '1'],
            ['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-B-M', 'Jumlah' => '1'],
        ]);
        $this->post(route('gudang.outbound.import.store'), ['token' => $first->viewData('token'), 'confirm_now' => '1'])->assertSessionHas('success');
        $order = Order::where('order_number', '2610AAA')->firstOrFail();
        $this->pickAll($order);

        // File baru dari BigSeller: JER-B-M diganti JER-C-L
        $rows = [
            ['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-A-M', 'Jumlah' => '1'],
            ['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-C-L', 'Jumlah' => '1'],
        ];
        $again = $this->preview($rows)
            ->assertSee('Pesanan yang sudah ada tapi berubah')
            ->assertSee('JER-B-M ×1 → JER-C-L ×1')
            ->assertSee('Terapkan yang dicentang');

        // Tanpa dicentang tidak ada yang berubah
        $this->post(route('gudang.outbound.import.store'), ['token' => $again->viewData('token')])->assertSessionHas('error');
        $this->assertSame(['JER-A-M', 'JER-B-M'], $order->fresh()->items->pluck('sku')->sort()->values()->all());

        $again = $this->preview($rows);
        $this->post(route('gudang.outbound.import.store'), ['token' => $again->viewData('token'), 'apply' => ['2610AAA']])
            ->assertRedirect(route('gudang.outbound'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 pesanan diganti itemnya (2610AAA)') && str_contains($message, '1 pcs barang lama'));

        $order->refresh();
        $this->assertSame(1, Order::count(), 'Tidak ada pesanan dobel');
        $this->assertSame('PICKING', $order->status);
        $this->assertSame(['JER-A-M', 'JER-C-L'], $order->items->pluck('sku')->sort()->values()->all());
        $this->assertSame(1, (int) RestockItem::where('sku', 'JER-B-M')->sum('qty'));
        $this->assertDatabaseHas('audit_trails', ['action' => 'CHANGE_ITEMS', 'module' => 'OUTBOUND']);
        $this->get(route('gudang.outbound.show', $order->id))->assertSee('import ulang BigSeller');

        // Upload sekali lagi: sudah sama, jadi dilewati
        $this->preview($rows)->assertSee('Sudah pernah diimpor')->assertDontSee('Terapkan yang dicentang');
    }

    public function test_staff_cannot_apply_item_change_to_order_in_process(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JER-A-M');
        $this->makeSku('JER-C-L');
        $this->putStock('JER-A-M', 'A-01-01', 5);
        $this->putStock('JER-C-L', 'A-01-03', 5);
        $first = $this->actingAs($admin)->preview([['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-A-M', 'Jumlah' => '1']]);
        $this->post(route('gudang.outbound.import.store'), ['token' => $first->viewData('token'), 'confirm_now' => '1']);
        $order = Order::firstOrFail();
        $this->pickAll($order);

        $again = $this->actingAs($staff)->preview([['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-C-L', 'Jumlah' => '1']])
            ->assertSee('hanya Owner, Supervisor, atau Admin Gudang yang bisa menerapkannya');
        $this->post(route('gudang.outbound.import.store'), ['token' => $again->viewData('token'), 'apply' => ['2610AAA']])
            ->assertSessionHas('error');
        $this->assertSame(['JER-A-M'], $order->fresh()->items->pluck('sku')->all());
    }

    public function test_reimport_of_order_cancelled_in_marketplace_can_cancel_it_in_wms(): void
    {
        $admin = $this->userWithRole('ADMIN_GUDANG');
        $this->makeSku('JER-A-M');
        $this->putStock('JER-A-M', 'A-01-01', 5);
        $first = $this->actingAs($admin)->preview([['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-A-M', 'Jumlah' => '1']]);
        $this->post(route('gudang.outbound.import.store'), ['token' => $first->viewData('token'), 'confirm_now' => '1']);
        $order = Order::firstOrFail();
        $this->pickAll($order);

        $again = $this->preview([['Nomor Pesanan' => '2610AAA', 'SKU' => 'JER-A-M', 'Jumlah' => '1', 'Waktu Pembatalan' => '31 Okt 2026 08:00']])
            ->assertSee('Batalkan di WMS')
            ->assertSee('Dibatalkan di marketplace');
        $this->post(route('gudang.outbound.import.store'), ['token' => $again->viewData('token'), 'apply' => ['2610AAA']])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 pesanan dibatalkan'));

        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->get(route('gudang.restock'))->assertSee($order->order_number)->assertSee('dibatalkan');
    }
}
