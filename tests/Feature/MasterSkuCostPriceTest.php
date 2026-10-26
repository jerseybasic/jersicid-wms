<?php

namespace Tests\Feature;

use App\Models\MasterSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Harga modal (HPP) per SKU: form, import, dan harga awal PO.
 */
class MasterSkuCostPriceTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    private function skuForm(array $overrides = []): array
    {
        return $overrides + [
            'sku' => 'JSC-NEW-L', 'article' => 'NEW', 'product_name' => 'CLUB NEW', 'version' => '1.0',
            'size_category' => 'DEWASA', 'size' => 'L', 'price' => 150000,
            'standard_weight_gram' => 250, 'min_stock' => 0, 'status' => 'AKTIF',
        ];
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sku.csv', $content);
    }

    public function test_cost_price_is_saved_from_the_form(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));

        $this->post(route('master-skus.store'), $this->skuForm(['cost_price' => 85000]))->assertRedirect(route('master-skus.index'));
        $sku = MasterSku::where('sku', 'JSC-NEW-L')->first();
        $this->assertSame(85000, $sku->cost_price);

        // Kosong berarti 0, bukan error
        $this->put(route('master-skus.update', $sku->id), $this->skuForm(['cost_price' => '']))->assertRedirect(route('master-skus.index'));
        $this->assertSame(0, $sku->fresh()->cost_price);

        $this->get(route('master-skus.edit', $sku->id))->assertSee('Harga Modal / HPP');
    }

    /** Import sekarang dua langkah: upload → pratinjau → simpan */
    private function import(string $route, string $content): void
    {
        $token = $this->post(route($route), ['file' => $this->csv($content)])->assertOk()->viewData('token');
        $this->post(route('master-skus.import.store'), ['token' => $token])->assertRedirect(route('master-skus.index'));
    }

    public function test_import_reads_cost_column_and_keeps_it_when_column_is_missing(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->makeSku('JSC-A-L', 100000)->update(['cost_price' => 55000]);

        $header = "sku,article,product_name,version,size_category,size,price,standard_weight_gram,min_stock,status";

        // File lama tanpa kolom HPP: HPP yang tersimpan tidak terhapus
        $this->import('reports.import-sku', "{$header}\nJSC-A-L,A,CLUB TEST,1.0,DEWASA,L,110000,250,0,AKTIF\n");
        $this->assertSame(110000, MasterSku::where('sku', 'JSC-A-L')->value('price'));
        $this->assertSame(55000, MasterSku::where('sku', 'JSC-A-L')->value('cost_price'));

        // Kolom HARGA_MODAL ikut dibaca, termasuk SKU baru
        $this->import('reports.import-sku', "{$header},harga_modal\nJSC-A-L,A,CLUB TEST,1.0,DEWASA,L,110000,250,0,AKTIF,60000\nJSC-B-L,B,CLUB B,1.0,DEWASA,L,90000,250,0,AKTIF,\"Rp 45.000\"\n");
        $this->assertSame(60000, MasterSku::where('sku', 'JSC-A-L')->value('cost_price'));
        $this->assertSame(45000, MasterSku::where('sku', 'JSC-B-L')->value('cost_price'));

        // Import dari halaman Master SKU memakai kolom COST_PRICE
        $this->import('master-skus.import', "{$header},cost_price\nJSC-A-L,A,CLUB TEST,1.0,DEWASA,L,110000,250,0,AKTIF,62000\n");
        $this->assertSame(62000, MasterSku::where('sku', 'JSC-A-L')->value('cost_price'));
        $this->post(route('master-skus.import'), ['file' => $this->csv("{$header}\nJSC-A-L,A,CLUB TEST,1.0,DEWASA,L,110000,250,0,AKTIF\n")])
            ->assertOk()->assertViewHas('hasWork', false);
        $this->assertSame(62000, MasterSku::where('sku', 'JSC-A-L')->value('cost_price'));
    }

    public function test_purchase_order_prefills_cost_price(): void
    {
        $this->actingAs($this->userWithRole('OWNER'));
        $this->makeSku('JSC-A-L', 100000)->update(['cost_price' => 60000, 'min_stock' => 5]);
        $this->makeSku('JSC-B-L', 90000)->update(['min_stock' => 5]);   // HPP kosong: tetap pakai harga jual

        // Kolom SKU bisa diketik (daftar pilihan dari datalist)
        $this->get(route('po.create'))->assertSee('data-price="60000"', false)->assertSee('data-price="90000"', false)
            ->assertSee('list="sku-options"', false)->assertSee('<option value="JSC-A-L"', false);

        $rows = collect($this->get(route('po.create', ['dari' => 'stok-menipis']))->viewData('prefillRows'))->keyBy('sku');
        $this->assertSame(60000, $rows['JSC-A-L']['price']);
        $this->assertSame(90000, $rows['JSC-B-L']['price']);
    }
}
