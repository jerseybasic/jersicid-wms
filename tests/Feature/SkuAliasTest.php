<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Pemetaan SKU marketplace ke Master SKU: dipakai saat import BigSeller, bisa dibuat dari pratinjau.
 */
class SkuAliasTest extends TestCase
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

    public function test_alias_is_used_when_importing_and_merges_with_the_same_sku(): void
    {
        $this->makeSku('JER-GOTHIC-M');
        $this->putStock('JER-GOTHIC-M', 'A-01-01', 10);
        SkuAlias::create(['alias' => 'GOTHIC-BLK-M', 'sku' => 'JER-GOTHIC-M']);

        $response = $this->actingAs($this->userWithRole('STAFF_GUDANG'))
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([
                ['A1', 'gothic-blk-m', 'Gothic Hitam M', '2', '150000'],
                ['A1', 'JER-GOTHIC-M', 'Gothic Hitam M', '1', '150000'],
            ])])
            ->assertOk()->assertSee('Import 1 pesanan')->assertSee('dari GOTHIC-BLK-M');

        $items = $response->viewData('orders')[0]['items'];
        $this->assertCount(1, $items);
        $this->assertSame(['JER-GOTHIC-M', 3], [$items[0]['sku'], $items[0]['qty']]);

        $this->post(route('gudang.outbound.import.store'), ['token' => $response->viewData('token')]);
        $order = Order::with('items')->firstOrFail();
        $this->assertSame([['JER-GOTHIC-M', 3]], $order->items->map(fn ($i) => [$i->sku, $i->qty_ordered])->all());
    }

    public function test_unknown_sku_can_be_mapped_from_the_preview_without_uploading_again(): void
    {
        $this->makeSku('JER-GOTHIC-M');
        $this->makeSku('JER-RETRO-L');
        $admin = $this->userWithRole('ADMIN_GUDANG');

        $response = $this->actingAs($admin)
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([
                ['A1', 'JER GOTHIC M', 'Gothic Hitam M', '1', '150000'],
                ['B2', 'RETRO-BIRU-L', 'Retro Biru L', '1', '150000'],
                ['C3', 'TIDAK-TAHU', 'Entah', '1', '150000'],
            ])])
            ->assertOk()
            ->assertSee('SKU belum dikenal (3)')
            ->assertSee('Simpan pemetaan dan periksa ulang')
            ->assertDontSee('Import 1 pesanan');

        // Tebakan otomatis hanya untuk kode yang sama jika tanda baca diabaikan
        $unknown = $response->viewData('unknown')->keyBy('sku');
        $this->assertSame('JER-GOTHIC-M', $unknown['JER GOTHIC M']['suggest']);
        $this->assertNull($unknown['RETRO-BIRU-L']['suggest']);

        $token = $response->viewData('token');
        $mapped = $this->post(route('gudang.outbound.import.map'), ['token' => $token, 'map' => [
            ['alias' => 'JER GOTHIC M', 'sku' => 'JER-GOTHIC-M'],
            ['alias' => 'RETRO-BIRU-L', 'sku' => 'JER-RETRO-L — CLUB TEST L'],
            ['alias' => 'TIDAK-TAHU', 'sku' => ''],
        ]])->assertOk()
            ->assertSee('2 SKU berhasil dipetakan')
            ->assertSee('SKU belum dikenal (1)')
            ->assertSee('Import 2 pesanan');

        $this->assertSame($token, $mapped->viewData('token'));
        $this->assertDatabaseHas('sku_aliases', ['alias' => 'RETRO-BIRU-L', 'sku' => 'JER-RETRO-L', 'created_by' => $admin->id]);
        $this->assertDatabaseHas('audit_trails', ['module' => 'SKU_ALIAS', 'action' => 'CREATE']);

        $this->post(route('gudang.outbound.import.store'), ['token' => $token])
            ->assertSessionHas('success', '2 pesanan berhasil diimpor. 1 pesanan dilewati.');
        $this->assertSame(['JER-GOTHIC-M', 'JER-RETRO-L'], Order::with('items')->orderBy('order_number')->get()
            ->map(fn ($o) => $o->items->first()->sku)->all());
    }

    public function test_invalid_mappings_are_reported_and_staff_cannot_map(): void
    {
        $this->makeSku('JER-GOTHIC-M');
        $admin = $this->userWithRole('SUPERVISOR');
        $token = $this->actingAs($admin)
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([['A1', 'X-1', 'Produk', '1', '1']])])
            ->viewData('token');

        $this->post(route('gudang.outbound.import.map'), ['token' => $token, 'map' => [['alias' => 'X-1', 'sku' => 'BELUM-ADA']]])
            ->assertOk()->assertSee('SKU gudang BELUM-ADA tidak ada di Master SKU.');
        $this->assertSame(0, SkuAlias::count());

        $staff = $this->userWithRole('STAFF_GUDANG');
        $staffToken = $this->actingAs($staff)
            ->post(route('gudang.outbound.import.preview'), ['file' => $this->upload([['A1', 'X-1', 'Produk', '1', '1']])])
            ->assertSee('Minta Owner, Supervisor, atau Admin Gudang')
            ->assertDontSee('Simpan pemetaan dan periksa ulang')
            ->viewData('token');
        $this->post(route('gudang.outbound.import.map'), ['token' => $staffToken, 'map' => [['alias' => 'X-1', 'sku' => 'JER-GOTHIC-M']]])
            ->assertForbidden();
        $this->assertSame(0, SkuAlias::count());
    }

    public function test_manage_aliases_page(): void
    {
        $this->makeSku('JER-GOTHIC-M');
        $this->makeSku('JER-RETRO-L');
        $this->actingAs($this->userWithRole('OWNER'));

        $this->get(route('dashboard.owner'))->assertSee(route('sku-aliases.index'));
        $this->get(route('sku-aliases.index'))->assertOk()->assertSee('Belum ada pemetaan');

        $this->post(route('sku-aliases.store'), ['alias' => ' gothic-blk-m ', 'sku' => 'JER-GOTHIC-M — CLUB TEST M'])
            ->assertRedirect(route('sku-aliases.index'))
            ->assertSessionHas('success', 'GOTHIC-BLK-M sekarang dibaca sebagai JER-GOTHIC-M.');

        // Menyimpan alias yang sama mengganti tujuannya
        $this->post(route('sku-aliases.store'), ['alias' => 'GOTHIC-BLK-M', 'sku' => 'JER-RETRO-L']);
        $this->assertSame('JER-RETRO-L', SkuAlias::where('alias', 'GOTHIC-BLK-M')->value('sku'));
        $this->assertSame(1, SkuAlias::count());

        // Kode yang sudah jadi Master SKU tidak boleh jadi alias
        $this->post(route('sku-aliases.store'), ['alias' => 'JER-RETRO-L', 'sku' => 'JER-GOTHIC-M'])
            ->assertSessionHas('error', 'JER-RETRO-L sudah ada di Master SKU, jadi tidak perlu dipetakan.');

        $this->get(route('sku-aliases.index', ['q' => 'gothic']))->assertSee('GOTHIC-BLK-M');
        $this->get(route('sku-aliases.index', ['q' => 'zzz']))->assertSee('Tidak ada pemetaan yang cocok');

        $alias = SkuAlias::firstOrFail();
        $this->delete(route('sku-aliases.destroy', $alias))->assertSessionHas('success');
        $this->assertSame(0, SkuAlias::count());
        $this->assertDatabaseHas('audit_trails', ['module' => 'SKU_ALIAS', 'action' => 'DELETE']);

        $this->actingAs($this->userWithRole('ADMIN_GUDANG'))->get(route('sku-aliases.index'))->assertForbidden();
    }
}
