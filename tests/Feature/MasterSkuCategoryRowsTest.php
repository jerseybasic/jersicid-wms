<?php

namespace Tests\Feature;

use App\Models\MasterSku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesWarehouseData;
use Tests\TestCase;

/**
 * Daftar Master SKU dikelompokkan per produk + versi + sub versi + kategori size. Detail size tiap baris
 * hanya berisi kategori itu, supaya produk yang punya size Dewasa dan Kids tidak tampil dobel.
 */
class MasterSkuCategoryRowsTest extends TestCase
{
    use CreatesWarehouseData, RefreshDatabase;

    public function test_master_sku_detail_only_shows_sizes_of_that_row_category(): void
    {
        $owner = $this->userWithRole('OWNER');
        foreach ([['JSC-X-M', 'DEWASA', 'M'], ['JSC-X-L', 'DEWASA', 'L'], ['JSC-X-K8', 'KIDS', '8']] as [$sku, $category, $size]) {
            MasterSku::create(['sku' => $sku, 'article' => 'X', 'product_name' => 'CLUB X', 'version' => 'V1', 'size_category' => $category, 'size' => $size, 'price' => 1, 'min_stock' => 0, 'status' => 'AKTIF']);
        }
        $url = '/master-skus/article/' . rawurlencode('CLUB X') . '/V1/skus';

        $kids = $this->actingAs($owner)->getJson($url . '?kategori=KIDS&sub=')->assertOk()->json();
        $this->assertSame(['JSC-X-K8'], array_column($kids, 'sku'));
        $this->assertSame('KIDS', $kids[0]['size_category']);

        $adult = $this->getJson($url . '?kategori=DEWASA&sub=')->json();
        $this->assertEqualsCanonicalizing(['JSC-X-M', 'JSC-X-L'], array_column($adult, 'sku'));
    }
}
