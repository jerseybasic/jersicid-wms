<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Services\MasterSkuSync;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class MasterSkuExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    public function collection(): Collection
    {
        return MasterSku::select('master_skus.*')
            ->selectRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) as current_stock')
            ->orderBy('master_skus.sku')
            ->get();
    }

    public function headings(): array
    {
        return [
            'SKU',
            'ARTICLE',
            'PRODUCT_NAME',
            'VERSION',
            'SUB_VERSION',
            'SIZE_CATEGORY',
            'SIZE',
            'SIZE_TOKEN',
            'PRICE',
            'COST_PRICE',
            'STANDARD_WEIGHT_GRAM',
            'MIN_STOCK',
            'STATUS',
            'CURRENT_STOCK',
        ];
    }

    public function map($sku): array
    {
        return [
            $sku->sku,
            $sku->article,
            $sku->product_name,
            $sku->version,
            $sku->sub_version ?? '-',
            $sku->size_category,
            $sku->size,
            $sku->size_token ?? '-',
            $sku->price,
            $sku->cost_price,
            $sku->standard_weight_gram,
            $sku->min_stock,
            $sku->status,
            $sku->current_stock ?? 0,
        ];
    }
}

class MasterSkuController extends Controller
{
    private const IMPORT_CACHE_MINUTES = 60;

    public function index(Request $request)
    {
        // ✅ FIX: Group by product_name + version (lebih akurat daripada article)
        $articleQuery = DB::table('master_skus')
            ->select(
                'master_skus.product_name',
                'master_skus.version',
                'master_skus.sub_version',
                'master_skus.size_category',
                'master_skus.status',
                DB::raw('COUNT(DISTINCT master_skus.sku) as size_count'),
                DB::raw('COALESCE(SUM(stock_actives.qty), 0) as total_stock'),
                DB::raw('MIN(master_skus.min_stock) as min_stock'),
                DB::raw('GROUP_CONCAT(DISTINCT master_skus.article ORDER BY master_skus.article SEPARATOR ", ") as articles'),
                DB::raw('GROUP_CONCAT(DISTINCT master_skus.size ORDER BY master_skus.size SEPARATOR ", ") as sizes')
            )
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->where('master_skus.status', 'AKTIF')
            ->groupBy(
                'master_skus.product_name',
                'master_skus.version',
                'master_skus.sub_version',
                'master_skus.size_category',
                'master_skus.status'
            );

        // Filter search
        if ($request->filled('search')) {
            $search = $request->search;
            $articleQuery->where(function ($q) use ($search) {
                $q->where('master_skus.product_name', 'like', "%{$search}%")
                    ->orWhere('master_skus.article', 'like', "%{$search}%")
                    ->orWhere('master_skus.version', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $articleQuery->where('master_skus.status', $request->status);
        }

        if ($request->filled('size_category')) {
            $articleQuery->where('master_skus.size_category', $request->size_category);
        }

        $articles = $articleQuery->orderBy('master_skus.product_name')->paginate(20);

        // Stats
        $stats = [
            'total' => MasterSku::count(),
            'aktif' => MasterSku::where('status', 'AKTIF')->count(),
            'nonaktif' => MasterSku::where('status', 'NONAKTIF')->count(),
            'low_stock' => MasterSku::where('status', 'AKTIF')
                ->whereRaw('min_stock > 0')
                ->whereRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) <= min_stock')
                ->count(),
        ];

        // Top 10 Stok Terbanyak
        $topProducts = DB::table('master_skus')
            ->select(
                'master_skus.product_name',
                'master_skus.version',
                DB::raw('COALESCE(SUM(stock_actives.qty), 0) as total_stock')
            )
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->where('master_skus.status', 'AKTIF')
            ->groupBy('master_skus.product_name', 'master_skus.version')
            ->orderByDesc('total_stock')
            ->limit(10)
            ->get();

        // Low Stock
        $lowStockProducts = DB::table('master_skus')
            ->select(
                'master_skus.product_name',
                'master_skus.version',
                DB::raw('COALESCE(SUM(stock_actives.qty), 0) as total_stock'),
                DB::raw('MIN(master_skus.min_stock) as min_stock')
            )
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->where('master_skus.status', 'AKTIF')
            ->whereRaw('master_skus.min_stock > 0')
            ->groupBy('master_skus.product_name', 'master_skus.version')
            ->havingRaw('COALESCE(SUM(stock_actives.qty), 0) <= MIN(master_skus.min_stock)')
            ->orderBy('total_stock')
            ->limit(20)
            ->get();

        return view('admin.master-skus.index', compact('articles', 'stats', 'topProducts', 'lowStockProducts'));
    }

    /**
     * ✅ FIX: Get SKU details by product_name + version (bukan article)
     */
    public function getArticleSkus(Request $request, $productName, $version)
    {
        $skus = MasterSku::select('master_skus.*')
            ->selectRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) as current_stock')
            ->where('master_skus.product_name', $productName)
            ->where('master_skus.version', $version)
            ->where('master_skus.status', 'AKTIF')
            ->orderBy('master_skus.size')
            ->get();

        return response()->json($skus);
    }

    public function create()
    {
        return view('admin.master-skus.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sku' => 'required|string|unique:master_skus,sku|max:50',
            'article' => 'required|string|max:100',
            'product_name' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'sub_version' => 'nullable|string|max:50',
            'size_category' => 'required|in:DEWASA,KIDS,TEENS',
            'size' => 'required|string|max:20',
            'size_token' => 'nullable|string|max:50',
            'price' => 'required|integer|min:0',
            'cost_price' => 'nullable|integer|min:0',
            'standard_weight_gram' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'status' => 'required|in:AKTIF,NONAKTIF',
        ]);

        $validated['cost_price'] = (int) ($validated['cost_price'] ?? 0);

        try {
            DB::transaction(function () use ($validated) {
                MasterSku::create($validated);
            });
            return redirect()->route('master-skus.index')->with('success', 'SKU berhasil ditambahkan!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Gagal menambah SKU: ' . safe_error_message($e)])->withInput();
        }
    }

    public function edit($id)
    {
        $sku = MasterSku::findOrFail($id);
        $relatedVariants = MasterSku::where('article', $sku->article)
            ->where('id', '!=', $sku->id)
            ->where('status', 'AKTIF')
            ->orderBy('size')
            ->get();
        $currentStock = StockActive::where('sku', $sku->sku)->sum('qty');
        $stockLocations = StockActive::where('sku', $sku->sku)->orderBy('bin_code')->get();

        return view('admin.master-skus.edit', compact('sku', 'relatedVariants', 'currentStock', 'stockLocations'));
    }

    public function update(Request $request, $id)
    {
        $sku = MasterSku::findOrFail($id);
        $validated = $request->validate([
            'sku' => 'required|string|max:50|unique:master_skus,sku,' . $sku->id,
            'article' => 'required|string|max:100',
            'product_name' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'sub_version' => 'nullable|string|max:50',
            'size_category' => 'required|in:DEWASA,KIDS,TEENS',
            'size' => 'required|string|max:20',
            'size_token' => 'nullable|string|max:50',
            'price' => 'required|integer|min:0',
            'cost_price' => 'nullable|integer|min:0',
            'standard_weight_gram' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'status' => 'required|in:AKTIF,NONAKTIF',
        ]);

        $validated['cost_price'] = (int) ($validated['cost_price'] ?? 0);

        try {
            DB::transaction(function () use ($sku, $validated) {
                $sku->update($validated);
            });
            return redirect()->route('master-skus.index')->with('success', 'SKU berhasil diupdate!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Gagal mengupdate SKU: ' . safe_error_message($e)])->withInput();
        }
    }

    public function destroy($id)
    {
        $sku = MasterSku::findOrFail($id);
        try {
            DB::transaction(function () use ($sku) {
                $sku->delete();
            });
            return back()->with('success', 'SKU berhasil dihapus!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Gagal menghapus SKU: ' . safe_error_message($e)]);
        }
    }

    public function export()
    {
        return Excel::download(new MasterSkuExport, 'MasterSKU_' . date('Ymd') . '.xlsx');
    }

    /**
     * Import ulang langkah 1: baca file lalu tampilkan pratinjau perubahan dibanding data saat ini.
     */
    public function import(Request $request, MasterSkuSync $sync)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ], [
            'file.mimes' => 'File harus Excel (.xlsx/.xls) atau CSV.',
        ]);

        set_time_limit(300);

        try {
            $result = $sync->compare($sync->parse($request->file('file')->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Import Master SKU: file tidak bisa dibaca', ['error' => $e->getMessage()]);
            return back()->with('error', 'File tidak bisa dibaca: ' . safe_error_message($e));
        }

        if (!$result['rows']) {
            return back()->with('error', 'Tidak ada baris SKU di file ini.');
        }

        $token = (string) Str::uuid();
        $fileName = $request->file('file')->getClientOriginalName();
        Cache::put($this->importCacheKey($token), $result + ['fileName' => $fileName], now()->addMinutes(self::IMPORT_CACHE_MINUTES));

        return view('admin.master-skus.import-preview', $result + [
            'token' => $token,
            'fileName' => $fileName,
            'summary' => MasterSkuSync::summary($result['rows']),
            'hasWork' => MasterSkuSync::hasWork($result['rows']),
        ]);
    }

    /**
     * Import ulang langkah 2: simpan perubahan yang sudah ditinjau.
     */
    public function storeImport(Request $request, MasterSkuSync $sync)
    {
        $validated = $request->validate(['token' => ['required', 'string']]);

        $cached = Cache::pull($this->importCacheKey($validated['token']));
        if (!$cached) {
            return redirect()->route('master-skus.index')->with('error', 'Pratinjau sudah kedaluwarsa. Upload ulang filenya.');
        }

        try {
            $done = DB::transaction(fn () => $sync->apply($cached['rows'], auth()->id(), $request->ip(), $cached['fileName']));
        } catch (\Throwable $e) {
            Log::error('Import Master SKU gagal disimpan', ['error' => $e->getMessage()]);
            return redirect()->route('master-skus.index')->with('error', 'Gagal menyimpan import: ' . safe_error_message($e));
        }

        $parts = array_filter([
            $done['new'] ? "{$done['new']} SKU baru" : null,
            $done['up'] ? "{$done['up']} SKU stoknya bertambah" : null,
            $done['down'] ? "{$done['down']} SKU stoknya berkurang" : null,
            $done['changed'] ? "{$done['changed']} SKU data produknya diperbarui" : null,
        ]);
        $message = $parts
            ? 'Import selesai: ' . implode(', ', $parts) . ". Perubahan stok tercatat di Laporan Pergerakan Stok dengan referensi {$done['reference']}."
            : 'Import selesai, tidak ada perubahan.';

        return redirect()->route('master-skus.index')->with('success', $message);
    }

    private function importCacheKey(string $token): string
    {
        return 'master-sku-import:' . auth()->id() . ':' . $token;
    }
}
