<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\WarehouseRack;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Peta rak gudang.
 *
 * Qty yang tampil selalu diambil dari stock_actives (stok asli per bin),
 * sehingga peta rak sama dengan hasil inbound, picking, dan stock opname.
 * Bin masuk ke sebuah rak bila kodenya diawali kode rak, misalnya A-01-01
 * (atau format lama RAK-A-01) masuk ke rak A.
 * Tabel rack_products hanya menyimpan SKU yang "ditetapkan" ke rak beserta
 * kapasitas maksimalnya, bukan qty.
 */
class WarehouseMappingController extends Controller
{
    /**
     * Denah gudang dengan jumlah SKU dan unit per rak
     */
    public function index()
    {
        $racks = WarehouseRack::orderBy('position_y')->orderBy('position_x')->get();

        $stockByRack = [];
        $stocks = DB::table('stock_actives')
            ->where('qty', '>', 0)
            ->select('sku', 'bin_code', 'qty')
            ->get();

        foreach ($stocks as $stock) {
            $code = WarehouseRack::codeFromBin($stock->bin_code);
            if ($code === null) {
                continue;
            }
            $stockByRack[$code]['skus'][$stock->sku] = true;
            $stockByRack[$code]['qty'] = ($stockByRack[$code]['qty'] ?? 0) + $stock->qty;
        }

        $assignedByRack = DB::table('rack_products')
            ->select('warehouse_rack_id', 'sku')
            ->get()
            ->groupBy('warehouse_rack_id');

        foreach ($racks as $rack) {
            $skus = array_keys($stockByRack[$rack->code]['skus'] ?? []);
            $assigned = ($assignedByRack[$rack->id] ?? collect())->pluck('sku')->all();

            $rack->products_count = count(array_unique(array_merge($skus, $assigned)));
            $rack->total_qty = $stockByRack[$rack->code]['qty'] ?? 0;
        }

        return view('warehouse.mapping.index', compact('racks'));
    }

    /**
     * Detail rak: SKU yang ada stoknya di bin rak ini, ditambah SKU yang
     * ditetapkan ke rak ini walau stoknya sedang kosong.
     */
    public function show($rackCode)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        // Semua SKU aktif dengan total stok (untuk pencarian di keranjang)
        $allSkus = MasterSku::where('status', 'AKTIF')
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->select(
                'master_skus.sku',
                'master_skus.product_name',
                'master_skus.article',
                'master_skus.size',
                'master_skus.size_category',
                'master_skus.version',
                'master_skus.sub_version',
                DB::raw('COALESCE(SUM(stock_actives.qty), 0) as current_stock')
            )
            ->groupBy(
                'master_skus.sku',
                'master_skus.product_name',
                'master_skus.article',
                'master_skus.size',
                'master_skus.size_category',
                'master_skus.version',
                'master_skus.sub_version'
            )
            ->orderBy('master_skus.product_name')
            ->get();

        $rackProducts = $this->rackProducts($rack);

        $groupedProducts = $rackProducts->groupBy(function ($product) {
            return $product->product_name . '|' . ($product->version ?? '');
        });

        $totalSku = $rackProducts->count();
        $totalQty = $rackProducts->sum('current_qty');

        return view('warehouse.mapping.show', compact(
            'rack',
            'allSkus',
            'rackProducts',
            'groupedProducts',
            'totalSku',
            'totalQty'
        ));
    }

    /**
     * Tetapkan SKU ke rak beserta kapasitas maksimalnya.
     * Qty tidak diisi di sini: stok masuk lewat Inbound atau Stock Opname.
     */
    public function assign(Request $request, $rackCode)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.sku' => 'required|exists:master_skus,sku',
            'products.*.max_capacity' => 'required|integer|min:1',
        ]);

        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        try {
            DB::transaction(function () use ($rack, $validated) {
                foreach ($validated['products'] as $product) {
                    $exists = DB::table('rack_products')
                        ->where('warehouse_rack_id', $rack->id)
                        ->where('sku', $product['sku'])
                        ->exists();

                    if ($exists) {
                        DB::table('rack_products')
                            ->where('warehouse_rack_id', $rack->id)
                            ->where('sku', $product['sku'])
                            ->update([
                                'max_capacity' => $product['max_capacity'],
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('rack_products')->insert([
                            'warehouse_rack_id' => $rack->id,
                            'sku' => $product['sku'],
                            'current_qty' => 0,
                            'max_capacity' => $product['max_capacity'],
                            'bin_location' => $rack->code . '-01-01',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'BULK_ASSIGN',
                    'module' => 'WAREHOUSE_MAPPING',
                    'new_values' => json_encode([
                        'rack' => $rack->code,
                        'skus' => array_column($validated['products'], 'sku'),
                    ]),
                    'ip_address' => request()->ip(),
                ]);
            });

            return back()->with('success', count($validated['products']) . ' produk berhasil ditetapkan ke rak ' . $rackCode);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menetapkan produk: ' . safe_error_message($e));
        }
    }

    /**
     * Lepas SKU dari rak. Stok di bin tidak berubah.
     */
    public function unassign($rackCode, $sku)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        try {
            DB::transaction(function () use ($rack, $sku) {
                DB::table('rack_products')
                    ->where('warehouse_rack_id', $rack->id)
                    ->where('sku', $sku)
                    ->delete();

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'UNASSIGN',
                    'module' => 'WAREHOUSE_MAPPING',
                    'new_values' => json_encode(['rack' => $rack->code, 'sku' => $sku]),
                    'ip_address' => request()->ip(),
                ]);
            });

            return back()->with('success', 'Produk dilepas dari rak ' . $rackCode . '. Stok di bin tidak berubah.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal melepas produk: ' . safe_error_message($e));
        }
    }

    /**
     * Baris produk di rak: qty asli dari stock_actives per bin rak ini,
     * digabung dengan SKU yang ditetapkan ke rak (kapasitas).
     */
    private function rackProducts(WarehouseRack $rack): Collection
    {
        $stocks = DB::table('stock_actives')
            ->where('qty', '>', 0)
            ->select('sku', 'bin_code', 'qty')
            ->get()
            ->filter(fn ($stock) => WarehouseRack::codeFromBin($stock->bin_code) === $rack->code)
            ->groupBy('sku');

        $assigned = DB::table('rack_products')
            ->where('warehouse_rack_id', $rack->id)
            ->pluck('max_capacity', 'sku');

        $skuCodes = $stocks->keys()->merge($assigned->keys())->unique()->values();

        if ($skuCodes->isEmpty()) {
            return collect();
        }

        return MasterSku::whereIn('sku', $skuCodes)
            ->orderBy('product_name')
            ->orderBy('size')
            ->get(['id', 'sku', 'product_name', 'article', 'version', 'sub_version', 'size', 'size_category', 'status'])
            ->map(function ($sku) use ($stocks, $assigned) {
                $bins = $stocks->get($sku->sku, collect());

                return (object) [
                    'sku_id' => $sku->id,
                    'sku' => $sku->sku,
                    'product_name' => $sku->product_name,
                    'article' => $sku->article,
                    'version' => $sku->version,
                    'sub_version' => $sku->sub_version,
                    'size' => $sku->size,
                    'size_category' => $sku->size_category,
                    'status' => $sku->status,
                    'current_qty' => (int) $bins->sum('qty'),
                    'max_capacity' => $assigned->get($sku->sku),
                    'bin_location' => $bins->pluck('bin_code')->unique()->sort()->implode(', '),
                    'is_assigned' => $assigned->has($sku->sku),
                ];
            })
            ->values();
    }
}
