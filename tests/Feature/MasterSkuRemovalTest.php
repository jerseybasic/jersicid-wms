<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Services\MasterSkuRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Salah import Master SKU: batalkan import (hapus SKU baru, kembalikan stok dan data produk)
 * dan hapus SKU yang belum dipakai dari daftar Master SKU.
 */
class MasterSkuRemovalTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function import(array $rows): void
    {
        $csv = "SKU,Nama Produk,Kategori Size,Size,Total Stok Saat Ini\n" . implode("\n", array_map(fn ($r) => implode(',', $r), $rows)) . "\n";
        $token = $this->post(route('master-skus.import'), ['file' => UploadedFile::fake()->createWithContent('salah.csv', $csv)])
            ->assertOk()->viewData('token');
        $this->post(route('master-skus.import.store'), ['token' => $token])->assertSessionHas('success');
    }

    /** Pergerakan stok di luar import, misalnya picking */
    private function pick(string $sku, string $bin, int $qty): void
    {
        $stock = StockActive::where('sku', $sku)->where('bin_code', $bin)->first();
        StockMovement::create([
            'sku' => $sku, 'bin_code' => $bin, 'type' => 'OUTBOUND', 'qty' => $qty,
            'qty_before' => $stock->qty, 'qty_after' => $stock->qty - $qty,
            'reference_no' => 'ORD-1', 'reference_type' => 'PICKING',
        ]);
        $stock->update(['qty' => $stock->qty - $qty]);
    }

    public function test_wrong_import_can_be_undone(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->makeSku('JER-OLD-M');
        $this->putStock('JER-OLD-M', 'A-01-01', 10);
        $this->makeSku('JER-NAME-M');

        $this->import([
            ['JER-TYPO-M', 'SALAH KETIK', 'DEWASA', 'M', 5],
            ['JER-USED-L', 'SUDAH DIPAKAI', 'DEWASA', 'L', 5],
            ['JER-OLD-M', 'CLUB TEST', 'DEWASA', 'L', 15],
            ['JER-NAME-M', 'NAMA SALAH', 'DEWASA', 'L', ''],
        ]);
        $this->assertSame('NAMA SALAH', MasterSku::where('sku', 'JER-NAME-M')->value('product_name'));

        // Setelah import, sebagian barang sudah terambil
        $this->pick('JER-USED-L', 'DEFAULT-01', 1);
        $this->pick('JER-OLD-M', 'A-01-01', 2);

        $import = AuditTrail::where('module', 'MASTER_SKU')->where('action', 'IMPORT')->first();
        $this->assertFalse(MasterSkuRemoval::recentImports()->first()->undone);

        $preview = $this->get(route('master-skus.import.undo', $import))->assertOk()
            ->assertSee('JER-TYPO-M')->assertSee('sudah ada pergerakan stok', false);
        $plan = $preview->viewData('plan');
        $this->assertSame(['JER-TYPO-M' => 5], $plan['remove']);
        $this->assertArrayHasKey('JER-USED-L', $plan['kept']);
        $this->assertSame(['CLUB TEST', 'NAMA SALAH'], [$plan['fields']['JER-NAME-M']['product_name'][1], $plan['fields']['JER-NAME-M']['product_name'][0]]);

        $this->post(route('master-skus.import.undo.store', $import))
            ->assertRedirect(route('master-skus.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 SKU dihapus') && str_contains($m, 'JER-USED-L'));

        // SKU salah ketik terhapus permanen beserta stoknya, dan tercatat di pergerakan stok
        $this->assertNull(MasterSku::withTrashed()->where('sku', 'JER-TYPO-M')->first());
        $this->assertSame(0, StockActive::where('sku', 'JER-TYPO-M')->count());
        $this->assertSame(1, StockMovement::where('sku', 'JER-TYPO-M')->where('reference_type', 'DELETE_SKU')->count());

        // SKU yang sudah dipakai tetap ada, stok dari import dibalik sampai 0
        $this->assertNotNull(MasterSku::where('sku', 'JER-USED-L')->first());
        $this->assertSame(0, $this->stockQty('JER-USED-L', 'DEFAULT-01'));

        // Stok lama: +5 dari import dibalik, picking setelahnya tetap terhitung
        $this->assertSame(8, $this->stockQty('JER-OLD-M', 'A-01-01'));
        $this->assertSame(1, StockMovement::where('sku', 'JER-OLD-M')->where('reference_type', 'IMPORT_SKU_UNDO')->count());
        $this->assertSame('CLUB TEST', MasterSku::where('sku', 'JER-NAME-M')->value('product_name'));

        // Hanya sekali
        $this->post(route('master-skus.import.undo.store', $import))->assertSessionHas('error');
        $this->assertTrue(MasterSkuRemoval::recentImports()->last()->undone);

        // Kode SKU yang dihapus bisa diimport lagi dengan benar
        $this->import([['JER-TYPO-M', 'NAMA BENAR', 'DEWASA', 'M', 3]]);
        $this->assertSame('NAMA BENAR', MasterSku::where('sku', 'JER-TYPO-M')->value('product_name'));
        $this->assertSame(3, $this->stockQty('JER-TYPO-M', 'DEFAULT-01'));
    }

    public function test_unused_skus_can_be_deleted_from_the_list(): void
    {
        $this->actingAs($this->userWithRole('SUPERVISOR'));
        $this->makeSku('JER-A-M');
        $this->putStock('JER-A-M', 'DEFAULT-01', 4);
        $this->makeSku('JER-B-M');
        $used = $this->makeSku('JER-C-M');
        $this->putStock('JER-C-M', 'A-01-01', 6);
        $this->pick('JER-C-M', 'A-01-01', 1);

        $this->from(route('master-skus.index'))
            ->post(route('master-skus.bulk-destroy'), ['skus' => ['JER-A-M', 'JER-B-M', 'JER-C-M']])
            ->assertRedirect(route('master-skus.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 SKU dihapus') && str_contains($m, 'JER-C-M') && str_contains($m, 'NONAKTIF'));

        $this->assertSame(['JER-C-M'], MasterSku::withTrashed()->pluck('sku')->all());
        $this->assertSame(['qty_before' => 4, 'qty_after' => 0], StockMovement::where('sku', 'JER-A-M')->where('reference_type', 'DELETE_SKU')->first()->only(['qty_before', 'qty_after']));
        $this->assertSame(5, $this->stockQty('JER-C-M', 'A-01-01'));

        // Satu SKU yang sudah dipakai: ditolak
        $this->from(route('master-skus.index'))->delete(route('master-skus.destroy', $used->id))->assertSessionHas('error');
        $this->assertNotNull($used->fresh());
    }

    public function test_only_owner_and_supervisor_can_delete_or_undo(): void
    {
        $this->actingAs($this->userWithRole('ADMIN_GUDANG'));
        $this->makeSku('JER-A-M');

        $this->post(route('master-skus.bulk-destroy'), ['skus' => ['JER-A-M']])->assertForbidden();
        $this->assertNotNull(MasterSku::where('sku', 'JER-A-M')->first());
    }
}
