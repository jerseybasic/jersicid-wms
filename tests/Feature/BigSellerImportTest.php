<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Import pesanan dari file ekspor BigSeller: pratinjau dulu, lalu simpan yang lolos.
 */
class BigSellerImportTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private const HEADER = [
        'Nomor Pesanan', 'Nomor Paket', 'Status Pesanan', 'Marketplace', 'Toko Marketplace', 'Nama Pembeli',
        'Nama Penerima', 'Nomor Telepon', 'Alamat Lengkap', 'SKU', 'Nama Produk', 'Nama Variasi', 'Jumlah',
        'Harga Satuan', 'SKU Gudang', 'Jasa Kirim yang Dipilih Pembeli', 'Nomor Resi', 'Waktu Pesanan Dibuat',
        'Waktu Pembatalan', 'Pesan dari Pembeli', 'Catatan Penjual',
    ];

    /** Baris contoh dengan format BigSeller: semua teks, sel kosong berisi "--" */
    private function row(string $order, string $sku, string $qty, array $override = []): array
    {
        return array_values(array_merge(array_fill_keys(self::HEADER, '--'), [
            'Nomor Pesanan' => $order,
            'Status Pesanan' => 'Menunggu Dicetak',
            'Marketplace' => 'Shopee',
            'Toko Marketplace' => 'Jersic',
            'Nama Pembeli' => 'ely_tanaya123',
            'Nama Penerima' => 'E******a',
            'Nomor Telepon' => '******23',
            'Alamat Lengkap' => 'Blok G No. 1, KOTA BATAM, KEPULAUAN RIAU, 29464',
            'SKU' => $sku,
            'Nama Produk' => 'JERSIC - Jersey',
            'Nama Variasi' => 'M',
            'Jumlah' => $qty,
            'Harga Satuan' => '154440',
            'SKU Gudang' => '',
            'Jasa Kirim yang Dipilih Pembeli' => 'SPX Standard',
            'Nomor Resi' => 'SPX-' . $order,
            'Waktu Pesanan Dibuat' => '19 Okt 2026 00:34',
            'Waktu Pembatalan' => '',
            'Pesan dari Pembeli' => '',
        ], $override));
    }

    private function upload(array $rows, array $header = self::HEADER): UploadedFile
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([$header, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bs') . '.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'Order-SKU-inprocess.xlsx', null, null, true);
    }

    public function test_preview_then_import_creates_confirmed_orders(): void
    {
        $staff = $this->userWithRole('STAFF_GUDANG');
        $this->makeSku('JER-GHOTIC-HITAM-M');
        $this->makeSku('JER-RETRO-NAVY-M');
        $this->putStock('JER-GHOTIC-HITAM-M', 'A-01-01', 10);
        $this->putStock('JER-RETRO-NAVY-M', 'A-01-02', 1);

        $file = $this->upload([
            $this->row('261019191AR592', 'JER-GHOTIC-HITAM-M', '2'),
            $this->row('261019191AR592', 'JER-RETRO-NAVY-M', '1', ['Pesan dari Pembeli' => 'Tolong cepat']),
            $this->row('261019191AR592', 'jer-ghotic-hitam-m', '1'),                // SKU sama di baris lain → digabung
            $this->row('261020AAA', 'JER-TIDAK-ADA-L', '1'),                         // SKU belum ada di Master SKU
            $this->row('261021BBB', 'JER-GHOTIC-HITAM-M', '1', ['Waktu Pembatalan' => '20 Okt 2026 10:00']),
            $this->row('261022CCC', 'JER-GHOTIC-HITAM-M', '1', ['SKU Gudang' => 'JER-RETRO-NAVY-M']),
        ]);

        $this->actingAs($staff)->get(route('gudang.outbound'))->assertSee('Import BigSeller');
        $this->get(route('gudang.outbound.import'))->assertOk();

        $response = $this->post(route('gudang.outbound.import.preview'), ['file' => $file])->assertOk()
            ->assertSee('Import 2 pesanan')
            ->assertSee('SKU JER-TIDAK-ADA-L belum ada di Master SKU')
            ->assertSee('Pesanan dibatalkan')
            ->assertDontSee('tinggal');
        $this->assertSame(0, Order::count(), 'Pratinjau belum boleh menyimpan apa pun');

        $orders = collect($response->viewData('orders'))->keyBy('order_number');
        $this->assertSame([['JER-GHOTIC-HITAM-M', 3], ['JER-RETRO-NAVY-M', 1]],
            collect($orders['261019191AR592']['items'])->map(fn ($i) => [$i['sku'], $i['qty']])->all());
        $this->assertSame('JER-RETRO-NAVY-M', $orders['261022CCC']['items'][0]['sku']);

        $this->post(route('gudang.outbound.import.store'), ['token' => $response->viewData('token'), 'confirm_now' => '1'])
            ->assertRedirect(route('gudang.outbound'))
            ->assertSessionHas('success', '2 pesanan berhasil diimpor. 1 langsung masuk daftar picking, 1 tetap Pending karena stoknya kurang. 2 pesanan dilewati.');

        // Stok RETRO-NAVY cuma 1 dan sudah dipakai pesanan pertama, jadi pesanan kedua menunggu stok
        $this->assertSame('PENDING', Order::where('order_number', '261022CCC')->value('status'));

        $order = Order::with('items')->where('order_number', '261019191AR592')->firstOrFail();
        $this->assertSame('CONFIRMED', $order->status);
        $this->assertSame('E******a', $order->customer_name);
        $this->assertSame('SPX Standard', $order->courier);
        $this->assertSame('SPX-261019191AR592', $order->tracking_number);
        $this->assertStringContainsString('Shopee Jersic', $order->notes);
        $this->assertStringContainsString('Pesan pembeli: Tolong cepat', $order->notes);
        $this->assertSame(154440, (int) $order->items->firstWhere('sku', 'JER-GHOTIC-HITAM-M')->price);
        $this->assertSame(2, Order::count());
        $this->assertDatabaseHas('audit_trails', ['module' => 'OUTBOUND', 'action' => 'IMPORT']);

        // Token sekali pakai, dan upload ulang file yang sama tidak membuat pesanan dobel
        $this->post(route('gudang.outbound.import.store'), ['token' => $response->viewData('token')])
            ->assertRedirect(route('gudang.outbound.import'))->assertSessionHas('error');

        $again = $this->post(route('gudang.outbound.import.preview'), [
            'file' => $this->upload([$this->row('261019191AR592', 'JER-GHOTIC-HITAM-M', '2')]),
        ])->assertSee('Sudah pernah diimpor')->assertDontSee('Import 1 pesanan');
        $this->post(route('gudang.outbound.import.store'), ['token' => $again->viewData('token')])
            ->assertSessionHas('error');
        $this->assertSame(2, Order::count());
    }

    public function test_import_without_confirm_keeps_orders_pending(): void
    {
        $this->makeSku('JER-A-M');
        $response = $this->actingAs($this->userWithRole('ADMIN_GUDANG'))
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([$this->row('X1', 'JER-A-M', '1')])]);

        $this->post(route('gudang.outbound.import.store'), ['token' => $response->viewData('token')])
            ->assertSessionHas('success', '1 pesanan berhasil diimpor.');
        $this->assertSame('PENDING', Order::firstOrFail()->status);
    }

    public function test_rejects_files_that_are_not_bigseller_exports(): void
    {
        $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->from(route('gudang.outbound.import'))
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([['a', 'b']], ['Kolom A', 'Kolom B'])])
            ->assertRedirect(route('gudang.outbound.import'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Nomor Pesanan, SKU, Jumlah'));

        $this->post(route('gudang.outbound.import.preview'), ['file' => UploadedFile::fake()->create('foto.png', 10, 'image/png')])
            ->assertSessionHasErrors('file');
    }

    public function test_supervisor_menu_shows_master_data(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'))->get(route('dashboard.gudang'))
            ->assertSee(route('master-skus.index'))
            ->assertSee(route('suppliers.index'));
        $this->get(route('suppliers.index'))->assertOk();

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->get(route('dashboard.gudang'))
            ->assertDontSee(route('master-skus.index'));
    }
}
