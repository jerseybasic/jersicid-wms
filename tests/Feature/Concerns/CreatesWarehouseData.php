<?php

namespace Tests\Feature\Concerns;

use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

trait CreatesWarehouseData
{
    protected function userWithRole(string $role): User
    {
        return User::factory()->role($role)->create();
    }

    protected function makeSku(string $sku = 'JSC-TEST-V1-L', int $price = 150000): MasterSku
    {
        return MasterSku::create([
            'sku' => $sku,
            'article' => 'ART-1',
            'product_name' => 'CLUB TEST',
            'version' => 'V1',
            'size_category' => 'DEWASA',
            'size' => 'L',
            'price' => $price,
            'min_stock' => 0,
            'status' => 'AKTIF',
        ]);
    }

    protected function putStock(string $sku, string $binCode, int $qty): StockActive
    {
        return StockActive::create([
            'sku' => $sku,
            'bin_code' => $binCode,
            'qty' => $qty,
            'reserved_qty' => 0,
        ]);
    }

    protected function stockQty(string $sku, string $binCode): int
    {
        return (int) StockActive::where('sku', $sku)->where('bin_code', $binCode)->value('qty');
    }

    protected function makeSupplier(string $type = 'SUBLIMASI'): Supplier
    {
        return Supplier::create([
            'id' => (string) Str::uuid(),
            'supplier_code' => 'SUP-' . strtoupper(Str::random(4)),
            'name' => 'JB Printing',
            'type' => $type,
            'status' => 'AKTIF',
        ]);
    }

    protected function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            'id' => (string) Str::uuid(),
            'name' => 'Gudang Utama',
            'code' => 'GDG-' . strtoupper(Str::random(3)),
            'status' => 'AKTIF',
        ]);
    }
}
