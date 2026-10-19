<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Illuminate\Database\Eloquent\Model;

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
            $sku->standard_weight_gram,
            $sku->min_stock,
            $sku->status,
            $sku->current_stock ?? 0,
        ];
    }
}

class MasterSkuImport implements ToModel, WithHeadingRow, WithChunkReading, WithBatchInserts
{
    private $stockEntries = [];
    private $stats = [
        'inserted' => 0,
        'skipped' => 0,
        'updated' => 0,
        'stock_inserted' => 0,
    ];

    public function model(array $row): ?Model
    {
        // Skip baris kosong
        if (empty($row['sku']) || empty($row['product_name'])) {
            return null;
        }

        $sku = trim($row['sku'] ?? '');
        $sizeCategory = strtoupper(trim($row['size_category'] ?? ''));
        $status = strtoupper(trim($row['status'] ?? 'AKTIF'));

        // Validasi size_category
        if (!in_array($sizeCategory, ['DEWASA', 'KIDS', 'TEENS'])) {
            $this->stats['skipped']++;
            return null;
        }

        // ✅ FIX: Incremental import - cek apakah SKU sudah ada
        $existingSku = MasterSku::where('sku', $sku)->first();

        if ($existingSku) {
            // ✅ UPDATE jika SKU sudah ada (jika ada perubahan data)
            $hasChanges = false;
            $updateData = [];

            $fields = ['article', 'product_name', 'version', 'sub_version', 'size_category', 'size', 'size_token', 'price', 'standard_weight_gram', 'min_stock', 'status'];

            foreach ($fields as $field) {
                $newValue = $this->normalizeValue($row[$field] ?? null, $field);
                if ($existingSku->$field != $newValue) {
                    $updateData[$field] = $newValue;
                    $hasChanges = true;
                }
            }

            if ($hasChanges) {
                $existingSku->update($updateData);
                $this->stats['updated']++;
            } else {
                $this->stats['skipped']++;
            }

            // Tetap proses stok untuk SKU yang sudah ada
            $currentStock = $this->parseNumber($row['current_stock'] ?? 0);
            if ($currentStock > 0) {
                $this->stockEntries[] = [
                    'sku' => $sku,
                    'qty' => $currentStock,
                ];
            }

            return null; // Return null karena sudah diupdate manual
        }

        // ✅ INSERT SKU baru
        $currentStock = $this->parseNumber($row['current_stock'] ?? 0);

        if ($currentStock > 0) {
            $this->stockEntries[] = [
                'sku' => $sku,
                'qty' => $currentStock,
            ];
        }

        $this->stats['inserted']++;

        return new MasterSku([
            'sku' => $sku,
            'article' => trim($row['article'] ?? ''),
            'product_name' => trim($row['product_name'] ?? ''),
            'version' => trim($row['version'] ?? ''),
            'sub_version' => !empty($row['sub_version']) && $row['sub_version'] !== '-' ? trim($row['sub_version']) : null,
            'size_category' => $sizeCategory,
            'size' => trim($row['size'] ?? ''),
            'size_token' => !empty($row['size_token']) && $row['size_token'] !== '-' ? trim($row['size_token']) : null,
            'price' => $this->parseNumber($row['price'] ?? 0),
            'standard_weight_gram' => (int) ($row['standard_weight_gram'] ?? 250),
            'min_stock' => (int) ($row['min_stock'] ?? 0),
            'status' => in_array($status, ['AKTIF', 'NONAKTIF']) ? $status : 'AKTIF',
        ]);
    }

    private function normalizeValue($value, $field): mixed
    {
        if ($field === 'price' || $field === 'standard_weight_gram' || $field === 'min_stock') {
            return $this->parseNumber($value ?? 0);
        }

        if ($field === 'sub_version' || $field === 'size_token') {
            return !empty($value) && $value !== '-' ? trim($value) : null;
        }

        if ($field === 'size_category' || $field === 'status') {
            return strtoupper(trim($value ?? ''));
        }

        return trim($value ?? '');
    }

    private function parseNumber($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);
        return (int) ($cleaned ?: 0);
    }

    public function getStockEntries(): array
    {
        return $this->stockEntries;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function chunkSize(): int
    {
        return 1000; // ✅ Lebih besar untuk performa
    }

    public function batchSize(): int
    {
        return 1000; // ✅ Batch insert untuk performa
    }
}

class MasterSkuController extends Controller
{
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
            'standard_weight_gram' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'status' => 'required|in:AKTIF,NONAKTIF',
        ]);

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
            'standard_weight_gram' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'status' => 'required|in:AKTIF,NONAKTIF',
        ]);

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
     * ✅ FIX: Incremental Import dengan Optimasi Performa
     */
    public function import(Request $request)
    {
        // ✅ OPTIMASI: Increase execution time untuk file besar
        set_time_limit(300); // 5 menit
        ini_set('memory_limit', '512M');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // 10MB
        ]);

        try {
            DB::beginTransaction();

            // ✅ OPTIMASI: Disable query log untuk performa
            DB::connection()->disableQueryLog();

            // ✅ FIX: Incremental import - TIDAK hapus data lama
            $import = new MasterSkuImport();

            $startTime = microtime(true);

            Excel::import($import, $request->file('file'));

            // ✅ FIX: Insert/update stok ke stock_actives
            $stockEntries = $import->getStockEntries();
            $stats = $import->getStats();
            $stockInserted = 0;
            $stockUpdated = 0;

            // Group by SKU untuk aggregate stok
            $stockBySku = [];
            foreach ($stockEntries as $entry) {
                if (!isset($stockBySku[$entry['sku']])) {
                    $stockBySku[$entry['sku']] = 0;
                }
                $stockBySku[$entry['sku']] += $entry['qty'];
            }

            // ✅ OPTIMASI: Batch upsert stock_actives
            foreach ($stockBySku as $sku => $totalQty) {
                $existingStock = StockActive::where('sku', $sku)->where('bin_code', 'GUDANG-UTAMA')->first();

                if ($existingStock) {
                    // Update stok yang sudah ada
                    $existingStock->update([
                        'qty' => $totalQty,
                        'last_movement_at' => now(),
                    ]);
                    $stockUpdated++;
                } else {
                    // Insert stok baru
                    StockActive::create([
                        'id' => (string) Str::uuid(),
                        'sku' => $sku,
                        'bin_code' => 'GUDANG-UTAMA',
                        'qty' => $totalQty,
                        'reserved_qty' => 0,
                        'received_at' => now(),
                        'last_movement_at' => now(),
                    ]);
                    $stockInserted++;
                }
            }

            DB::commit();

            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);

            $message = sprintf(
                "✅ Import berhasil! (%.2f detik)\n" .
                    "📦 SKU: %d baru, %d diupdate, %d diskip\n" .
                    "📊 Stok: %d insert, %d update",
                $executionTime,
                $stats['inserted'],
                $stats['updated'],
                $stats['skipped'],
                $stockInserted,
                $stockUpdated
            );

            return back()->with('success', $message);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = "Baris {$failure->row()}: " . implode(', ', $failure->errors());
            }
            return back()->withErrors(['error' => 'Import gagal: ' . implode('; ', array_slice($errors, 0, 5))]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import Master SKU failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Gagal import: ' . safe_error_message($e)]);
        }
    }

    public function resetData()
    {
        try {
            DB::transaction(function () {
                MasterSku::query()->forceDelete();
                StockActive::query()->delete();
            });
            return back()->with('success', 'Semua data Master SKU dan stok berhasil dihapus! Silakan import ulang.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Gagal mereset data: ' . safe_error_message($e)]);
        }
    }
}
