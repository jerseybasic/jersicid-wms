<?php

namespace Tests\Feature;

use App\Models\SkuAlias;
use App\Models\UnregisteredSku;
use App\Models\WarehouseRack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * SKU terhambat: kode yang belum terdaftar saat import/pemetaan langsung disarankan dibuat jadi SKU baru,
 * dan semua yang terhambat (belum terdaftar / belum punya rak) bisa dilihat di satu halaman.
 */
class BlockedSkuTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function upload(array $rows): UploadedFile
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([['Nomor Pesanan', 'SKU', 'Nama Produk', 'Jumlah', 'Harga Satuan'], ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'bs') . '.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'pesanan.xlsx', null, null, true);
    }

    private function skuData(string $sku): array
    {
        return [
            'sku' => $sku, 'article' => 'GOTHIC', 'product_name' => 'Jersey Gothic Hitam', 'version' => '1.0',
            'size_category' => 'DEWASA', 'size' => 'M', 'price' => 150000, 'standard_weight_gram' => 250,
            'min_stock' => 0, 'status' => 'AKTIF',
        ];
    }

    public function test_unknown_sku_from_import_is_listed_and_can_be_created_as_new_sku_from_the_preview(): void
    {
        $owner = $this->userWithRole('OWNER');

        $response = $this->actingAs($owner)
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([
                ['A1', 'GOTHIC-BLK-M', 'Gothic Hitam M', '1', '150000'],
                ['A2', 'GOTHIC-BLK-M', 'Gothic Hitam M', '2', '150000'],
                ['A3', 'ONGKIR', 'Ongkos kirim', '1', '0'],
            ])])
            ->assertOk()->assertSee('Buat SKU baru');
        $token = $response->viewData('token');

        $row = UnregisteredSku::where('code', 'GOTHIC-BLK-M')->firstOrFail();
        $this->assertSame(2, $row->orders_count);
        $this->assertSame('Gothic Hitam M', $row->product_name);

        $this->get(route('blocked-skus.index'))->assertOk()
            ->assertSee('GOTHIC-BLK-M')->assertSee('2 pesanan tertahan')->assertSee('ONGKIR');

        // Alur cepat: form SKU baru sudah terisi dari kode marketplace
        $back = route('gudang.outbound.import.show', $token, false);
        $this->get(route('master-skus.create', ['kode' => 'GOTHIC-BLK-M', 'nama' => 'Gothic Hitam M', 'kembali' => $back]))
            ->assertOk()->assertSee('Membuat SKU baru untuk kode')->assertSee('value="GOTHIC-BLK"', false)->assertSee('value="M"', false);

        // Kode gudang dibuat berbeda: kode marketplace otomatis dipetakan, lalu kembali ke pratinjau yang sama
        $this->post(route('master-skus.store'), $this->skuData('JER-GOTHIC-HITAM-M') + ['from_code' => 'GOTHIC-BLK-M', 'return_to' => $back])
            ->assertRedirect($back)->assertSessionHas('success', fn ($m) => str_contains($m, 'GOTHIC-BLK-M sekarang dibaca sebagai JER-GOTHIC-HITAM-M'));
        $this->assertSame('JER-GOTHIC-HITAM-M', SkuAlias::where('alias', 'GOTHIC-BLK-M')->value('sku'));

        $preview = $this->get($back)->assertOk();
        $this->assertSame(2, collect($preview->viewData('orders'))->where('importable', true)->count());

        // Sudah dipetakan: tidak tampil lagi; ONGKIR bisa diabaikan
        $this->get(route('blocked-skus.index'))->assertDontSee('GOTHIC-BLK-M');
        $this->post(route('blocked-skus.ignore', UnregisteredSku::where('code', 'ONGKIR')->first()))->assertSessionHas('success');
        $this->assertSame(0, UnregisteredSku::pending()->count());
    }

    public function test_unknown_code_can_be_mapped_from_the_blocked_list_and_return_url_must_stay_in_the_app(): void
    {
        $this->makeSku('JER-RETRO-L');
        UnregisteredSku::record('retro-l', 'Retro L', 'IMPORT_BIGSELLER', 'x.xlsx', 3);
        $admin = $this->userWithRole('ADMIN_GUDANG');

        // Admin Gudang bisa memetakan, tapi tidak membuat SKU baru
        $this->actingAs($admin)->get(route('blocked-skus.index'))->assertOk()->assertSee('RETRO-L')->assertDontSee('>Buat SKU baru<', false);
        $this->post(route('blocked-skus.map'), ['alias' => 'RETRO-L', 'sku' => 'JER-RETRO-L — Retro L'])->assertSessionHas('success');
        $this->assertSame(0, UnregisteredSku::pending()->count());

        $this->actingAs($this->userWithRole('STAFF_GUDANG'))->get(route('blocked-skus.index'))->assertForbidden();

        $this->actingAs($this->userWithRole('SUPERVISOR'))
            ->post(route('master-skus.store'), $this->skuData('JER-X-M') + ['return_to' => 'https://contoh.com/'])
            ->assertRedirect(route('master-skus.index'));
    }

    public function test_active_skus_without_a_rack_are_listed(): void
    {
        $rack = WarehouseRack::create(['code' => 'A']);
        $this->makeSku('JSC-PLACED-L');
        $this->makeSku('JSC-ASSIGNED-L');
        $this->makeSku('JSC-LOOSE-L');
        $this->makeSku('JSC-NEW-L');
        $this->putStock('JSC-PLACED-L', 'A-01-01', 4);
        $this->putStock('JSC-LOOSE-L', 'LANTAI', 6);
        DB::table('rack_products')->insert(['warehouse_rack_id' => $rack->id, 'sku' => 'JSC-ASSIGNED-L', 'max_capacity' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($this->userWithRole('SUPERVISOR'))->get(route('blocked-skus.index'))->assertOk()
            ->assertSee('Stok 6 di luar rak');
        $this->assertSame(['JSC-LOOSE-L', 'JSC-NEW-L'], $response->viewData('unplaced')->pluck('sku')->all());

        // Dari denah rak: produk tidak ditemukan → tombol buat SKU baru
        $this->get(route('gudang.mapping.show', 'A'))->assertOk()->assertSee('Buat SKU baru');
        $this->get(route('gudang.mapping'))->assertSee('Lihat SKU terhambat');
    }
}
