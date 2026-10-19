<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockOpnameController extends Controller
{
    public function index(Request $request)
    {
        $query = StockOpname::with(['creator:id,name', 'warehouse:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $query->where('opname_number', 'like', "%{$request->search}%");
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $opnames = $query->paginate(15);

        // Variabel tambahan untuk view
        $skus = MasterSku::where('status', 'AKTIF')->orderBy('product_name')->get();

        // Pre-fetch stock data di controller agar tidak memanggil @json() dengan expression berisi koma
        // (Blade @json() melakukan explode(',', ...) sehingga koma dalam string ikut dipecah)
        $stockData = StockActive::with('masterSku:sku,product_name')->get();

        return view('warehouse.opname.index', compact('opnames', 'skus', 'stockData'));
    }

    public function create()
    {
        $warehouses = Warehouse::where('status', 'AKTIF')->get();
        return view('warehouse.opname.create', compact('warehouses'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($validated, $request) {
                $user = auth()->user();

                $opname = StockOpname::create([
                    'warehouse_id' => $validated['warehouse_id'],
                    'status' => 'DRAFT',
                    'created_by' => $user->id,
                    'notes' => $validated['notes'],
                ]);

                $activeStocks = StockActive::where('qty', '>', 0)->get();

                foreach ($activeStocks as $stock) {
                    StockOpnameItem::create([
                        'stock_opname_id' => $opname->id,
                        'sku' => $stock->sku,
                        'bin_code' => $stock->bin_code,
                        'system_qty' => $stock->qty,
                        'physical_qty' => null,
                        'difference' => 0,
                    ]);
                }
            });

            return redirect()->route('gudang.opname')
                ->with('success', 'Stock Opname berhasil dibuat! Silakan mulai penghitungan.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal membuat Stock Opname: ' . safe_error_message($e));
        }
    }

    // Di method show()
    public function show($id)
    {
        $opname = StockOpname::with(['creator:id,name', 'warehouse:id,name'])
            ->findOrFail($id);

        // ✅ PASTIKAN TIDAK ADA SPASI
        $items = $opname->items()
            ->with('masterSku:sku,product_name')  // ← TANPA SPASI!
            ->paginate(50);

        return view('warehouse.opname.show', compact('opname', 'items'));
    }

    public function start($id)
    {
        $opname = StockOpname::findOrFail($id);
        if ($opname->status !== 'DRAFT') {
            return back()->with('error', 'Hanya opname DRAFT yang bisa dimulai.');
        }

        $opname->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
        return back()->with('success', 'Opname dimulai. Silakan input stok fisik.');
    }

    public function updatePhysicalQty(Request $request, $id)
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'exists:stock_opname_items,id'],
            'items.*.physical_qty' => ['required', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                foreach ($validated['items'] as $itemData) {
                    $item = StockOpnameItem::findOrFail($itemData['id']);
                    $item->physical_qty = $itemData['physical_qty'];
                    $item->notes = $itemData['notes'] ?? null;
                    $item->calculateDifference();
                    $item->save();
                }
            });
            return back()->with('success', 'Data stok fisik berhasil disimpan sementara.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyimpan data: ' . safe_error_message($e));
        }
    }

    public function complete($id)
    {
        $opname = StockOpname::with('items')->findOrFail($id);
        $user = auth()->user();

        if ($opname->status !== 'IN_PROGRESS') {
            return back()->with('error', 'Hanya opname IN_PROGRESS yang bisa diselesaikan.');
        }

        $unfilled = $opname->items()->whereNull('physical_qty')->count();
        if ($unfilled > 0) {
            return back()->with('error', "Masih ada {$unfilled} item yang belum dihitung fisiknya.");
        }

        try {
            DB::transaction(function () use ($opname, $user) {
                foreach ($opname->items as $item) {
                    $diff = $item->physical_qty - $item->system_qty;

                    if ($diff !== 0) {
                        $stock = StockActive::where('sku', $item->sku)
                            ->where('bin_code', $item->bin_code)
                            ->first();

                        if ($stock) {
                            $oldQty = $stock->qty;
                            $newQty = $oldQty + $diff;
                            if ($newQty < 0) $newQty = 0;

                            $stock->update([
                                'qty' => $newQty,
                                'last_movement_at' => now(),
                            ]);

                            StockMovement::create([
                                'user_id' => $user->id,
                                'sku' => $item->sku,
                                'bin_code' => $item->bin_code,
                                'type' => 'ADJUSTMENT',
                                'qty' => abs($diff),
                                'qty_before' => $oldQty,
                                'qty_after' => $newQty,
                                'reference_no' => $opname->opname_number,
                                'notes' => "Opname Adj: {$diff} (Sys: {$item->system_qty}, Phy: {$item->physical_qty})",
                                'ip_address' => request()->ip(),
                            ]);
                        }
                    }
                }

                $opname->update([
                    'status' => 'COMPLETED',
                    'completed_at' => now(),
                    'approved_by' => $user->id,
                ]);
            });

            return back()->with('success', 'Stock Opname Selesai! Stok sistem telah disesuaikan otomatis.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyelesaikan opname: ' . safe_error_message($e));
        }
    }

    public function destroy($id)
    {
        $opname = StockOpname::findOrFail($id);
        if ($opname->status !== 'DRAFT') {
            return back()->with('error', 'Hanya DRAFT yang bisa dihapus.');
        }
        $opname->items()->delete();
        $opname->delete();
        return back()->with('success', 'Opname dihapus.');
    }
}
