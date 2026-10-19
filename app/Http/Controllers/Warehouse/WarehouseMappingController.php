<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\WarehouseRack;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseMappingController extends Controller
{
    /**
     * Tampilkan denah gudang
     */
    public function index()
    {
        $racks = WarehouseRack::select('warehouse_racks.*')
            ->selectSub(function ($query) {
                $query->selectRaw('COUNT(*)')
                    ->from('rack_products')
                    ->whereColumn('rack_products.warehouse_rack_id', 'warehouse_racks.id');
            }, 'products_count')
            ->orderBy('position_y', 'asc')
            ->orderBy('position_x', 'asc')
            ->get();

        return view('warehouse.mapping.index', compact('racks'));
    }

    /**
     * Tampilkan detail rak
     */
    public function show($rackCode)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        Log::info('Showing rack', [
            'code' => $rackCode,
            'rack_id' => $rack->id,
        ]);

        // Ambil semua SKU aktif dengan stok saat ini (untuk dropdown keranjang)
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
            ->groupBy('master_skus.id')
            ->orderBy('master_skus.product_name')
            ->get();

        // ✅ FIX: Ambil produk di rak ini dengan info lengkap
        $rackProducts = DB::table('rack_products')
            ->join('master_skus', 'rack_products.sku', '=', 'master_skus.sku')
            ->where('rack_products.warehouse_rack_id', '=', $rack->id)
            ->select(
                'master_skus.id as sku_id',
                'master_skus.sku',
                'master_skus.product_name',
                'master_skus.article',
                'master_skus.version',
                'master_skus.sub_version',
                'master_skus.size',
                'master_skus.size_category',
                'master_skus.status',
                'rack_products.current_qty',
                'rack_products.max_capacity',
                'rack_products.bin_location'
            )
            ->orderBy('master_skus.product_name')
            ->orderBy('master_skus.size')
            ->get();

        // ✅ FIX: Group by product_name + version untuk accordion
        $groupedProducts = $rackProducts->groupBy(function ($product) {
            return $product->product_name . '|' . ($product->version ?? '');
        });

        // Hitung total SKU dan total qty
        $totalSku = $rackProducts->count();
        $totalQty = $rackProducts->sum('current_qty');

        // ✅ FIX: Kirim SEMUA variabel yang dibutuhkan view
        return view('warehouse.mapping.show', compact(
            'rack',
            'allSkus',
            'rackProducts',        // ✅ Untuk kompatibilitas
            'groupedProducts',     // ✅ Untuk accordion baru
            'totalSku',
            'totalQty'
        ));
    }

    /**
     * ✅ NEW: Get SKU details for a product in specific rack (AJAX)
     */
    public function getRackProductSkus(Request $request, $rackCode, $productName, $version)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        $skus = DB::table('rack_products')
            ->join('master_skus', 'rack_products.sku', '=', 'master_skus.sku')
            ->where('rack_products.warehouse_rack_id', '=', $rack->id)
            ->where('master_skus.product_name', '=', $productName)
            ->where('master_skus.version', '=', $version)
            ->select(
                'master_skus.id as sku_id',
                'master_skus.sku',
                'master_skus.product_name',
                'master_skus.article',
                'master_skus.version',
                'master_skus.sub_version',
                'master_skus.size',
                'master_skus.size_category',
                'master_skus.status',
                'rack_products.current_qty',
                'rack_products.max_capacity',
                'rack_products.bin_location'
            )
            ->orderBy('master_skus.size')
            ->get();

        return response()->json($skus);
    }

    /**
     * ✅ FIX: Assign multiple produk ke rak dengan UUID yang benar
     */
    public function assign(Request $request, $rackCode)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.sku' => 'required|exists:master_skus,sku',
            'products.*.current_qty' => 'required|integer|min:0',
            'products.*.max_capacity' => 'required|integer|min:1',
        ]);

        // ✅ FIX: Pastikan mengambil rak dengan benar
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        // ✅ FIX: Validasi bahwa ID rak adalah UUID yang valid
        if (empty($rack->id) || $rack->id === '0') {
            Log::error('Invalid rack ID', [
                'rack_code' => $rackCode,
                'rack_id' => $rack->id,
            ]);
            return back()->with('error', 'ID rak tidak valid. Silakan cek data rak di database.');
        }

        Log::info('Assigning products to rack', [
            'rack_code' => $rackCode,
            'rack_id' => $rack->id,
            'rack_id_type' => gettype($rack->id),
            'products_count' => count($validated['products']),
        ]);

        try {
            DB::transaction(function () use ($rack, $validated) {
                $assignedCount = 0;

                foreach ($validated['products'] as $product) {
                    // ✅ FIX: Cek dengan warehouse_rack_id yang benar (UUID)
                    $exists = DB::table('rack_products')
                        ->where('warehouse_rack_id', '=', $rack->id)
                        ->where('sku', '=', $product['sku'])
                        ->exists();

                    if ($exists) {
                        // Update qty
                        DB::table('rack_products')
                            ->where('warehouse_rack_id', '=', $rack->id)
                            ->where('sku', '=', $product['sku'])
                            ->update([
                                'current_qty' => $product['current_qty'],
                                'max_capacity' => $product['max_capacity'],
                                'updated_at' => now(),
                            ]);
                    } else {
                        // ✅ FIX: Insert dengan UUID yang benar
                        DB::table('rack_products')->insert([
                            'warehouse_rack_id' => $rack->id, // ✅ UUID, bukan 0
                            'sku' => $product['sku'],
                            'current_qty' => $product['current_qty'],
                            'max_capacity' => $product['max_capacity'],
                            'bin_location' => $rack->code . '-01-01',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $assignedCount++;
                }

                // Audit trail
                if (class_exists(AuditTrail::class)) {
                    AuditTrail::create([
                        'user_id' => auth()->id(),
                        'action' => 'BULK_ASSIGN',
                        'module' => 'WAREHOUSE_MAPPING',
                        'new_values' => json_encode([
                            'rack' => $rack->code,
                            'rack_id' => $rack->id,
                            'products_count' => $assignedCount,
                            'skus' => array_column($validated['products'], 'sku'),
                        ]),
                        'ip_address' => request()->ip(),
                    ]);
                }
            });

            return back()->with('success', count($validated['products']) . ' produk berhasil di-assign ke rak ' . $rackCode);
        } catch (\Exception $e) {
            Log::error('Assign failed', [
                'error' => $e->getMessage(),
                'rack_id' => $rack->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Gagal assign produk: ' . safe_error_message($e));
        }
    }

    /**
     * Unassign produk dari rak
     */
    public function unassign($rackCode, $sku)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        try {
            DB::transaction(function () use ($rack, $sku) {
                DB::table('rack_products')
                    ->where('warehouse_rack_id', '=', $rack->id)
                    ->where('sku', '=', $sku)
                    ->delete();

                if (class_exists(AuditTrail::class)) {
                    AuditTrail::create([
                        'user_id' => auth()->id(),
                        'action' => 'UNASSIGN',
                        'module' => 'WAREHOUSE_MAPPING',
                        'new_values' => json_encode([
                            'rack' => $rack->code,
                            'rack_id' => $rack->id,
                            'sku' => $sku,
                        ]),
                        'ip_address' => request()->ip(),
                    ]);
                }
            });

            return back()->with('success', 'Produk berhasil dihapus dari rak ' . $rackCode);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menghapus produk: ' . safe_error_message($e));
        }
    }

    /**
     * Bulk delete produk dari rak
     */
    public function bulkDelete(Request $request, $rackCode)
    {
        $validated = $request->validate([
            'skus' => 'required|array|min:1',
            'skus.*' => 'exists:master_skus,sku',
        ]);

        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        try {
            DB::transaction(function () use ($rack, $validated) {
                foreach ($validated['skus'] as $sku) {
                    DB::table('rack_products')
                        ->where('warehouse_rack_id', '=', $rack->id)
                        ->where('sku', '=', $sku)
                        ->delete();
                }

                if (class_exists(AuditTrail::class)) {
                    AuditTrail::create([
                        'user_id' => auth()->id(),
                        'action' => 'BULK_UNASSIGN',
                        'module' => 'WAREHOUSE_MAPPING',
                        'new_values' => json_encode([
                            'rack' => $rack->code,
                            'rack_id' => $rack->id,
                            'skus' => $validated['skus'],
                            'count' => count($validated['skus']),
                        ]),
                        'ip_address' => request()->ip(),
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => count($validated['skus']) . ' produk berhasil dihapus dari rak ' . $rackCode,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus produk: ' . safe_error_message($e),
            ], 500);
        }
    }
}
