<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exports\MovementReportExport;
use App\Exports\OpnameReportExport;
use App\Exports\StockReportExport;
use App\Exports\TemplateExport;
use App\Imports\MasterSkuImport;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\MasterSku;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;


class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index');
    }

    public function stock(Request $request)
    {
        $query = StockActive::query()
            ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
            ->select('stock_actives.*', 'master_skus.product_name', 'master_skus.size_category', 'master_skus.size', 'master_skus.min_stock', 'master_skus.status as sku_status');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('stock_actives.sku', 'like', "%{$request->search}%")
                    ->orWhere('master_skus.product_name', 'like', "%{$request->search}%");
            });
        }
        if ($request->filled('category')) {
            $query->where('master_skus.size_category', $request->category);
        }

        $stocks = $query->orderBy('stock_actives.sku')->paginate(20);

        // Export Excel
        if ($request->has('export') && $request->export === 'excel') {
            return Excel::download(new StockReportExport($request), 'Laporan_Stok_' . date('Ymd_His') . '.xlsx');
        }

        return view('reports.stock', compact('stocks'));
    }

    public function movement(Request $request)
    {
        $query = StockMovement::query()
            ->join('master_skus', 'stock_movements.sku', '=', 'master_skus.sku')
            ->join('users', 'stock_movements.user_id', '=', 'users.id')
            ->select('stock_movements.*', 'master_skus.product_name', 'users.name as user_name');

        // Filter Default: 7 hari terakhir jika tidak ada filter
        if (!$request->filled('start_date') && !$request->filled('end_date')) {
            $query->where('stock_movements.created_at', '>=', now()->subDays(7));
        } else {
            if ($request->filled('start_date')) {
                $query->where('stock_movements.created_at', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->where('stock_movements.created_at', '<=', $request->end_date . ' 23:59:59');
            }
        }

        if ($request->filled('type')) {
            $query->where('stock_movements.type', $request->type);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('stock_movements.reference_no', 'like', "%{$request->search}%")
                    ->orWhere('stock_movements.sku', 'like', "%{$request->search}%");
            });
        }

        $movements = $query->orderBy('stock_movements.created_at', 'desc')->paginate(20);

        // Export Excel
        if ($request->has('export') && $request->export === 'excel') {
            return Excel::download(new MovementReportExport($request), 'Laporan_Pergerakan_Stok_' . date('Ymd_His') . '.xlsx');
        }

        return view('reports.movement', compact('movements'));
    }

    public function opname(Request $request)
    {
        $query = StockOpname::with(['creator:id,name', 'warehouse:id,name', 'approver:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        $opnames = $query->paginate(20);

        // Export Excel
        if ($request->has('export') && $request->export === 'excel') {
            return Excel::download(new OpnameReportExport($request), 'Laporan_Stock_Opname_' . date('Ymd_His') . '.xlsx');
        }

        return view('reports.opname', compact('opnames'));
    }
    public function importSku(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        try {
            $import = new MasterSkuImport();

            DB::transaction(function () use ($request, $import) {
                Excel::import($import, $request->file('file'));
            });

            $imported = $import->getImportedCount();
            $updated = $import->getUpdatedCount();
            $skipped = $import->getSkippedCount();
            $stockCreated = $import->getStockCreatedCount();

            $message = "Import selesai! ";
            if ($imported > 0) $message .= "{$imported} SKU baru ditambahkan. ";
            if ($updated > 0) $message .= "{$updated} SKU diupdate. ";
            if ($stockCreated > 0) $message .= "{$stockCreated} stok diimport. ";
            if ($skipped > 0) $message .= "({$skipped} baris dilewati).";

            return back()->with('success', $message);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = "Baris {$failure->row()}: " . implode(', ', $failure->errors());
            }
            $errorMsg = implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $errorMsg .= " ... dan " . (count($errors) - 5) . " error lainnya";
            }
            return back()->with('error', 'Gagal import: ' . $errorMsg);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal import: ' . safe_error_message($e));
        }
    }

    public function downloadTemplate(Request $request)
    {
        $includeStock = $request->has('with_stock');

        $filename = $includeStock
            ? 'MasterSKU_With_Stock_' . date('Ymd_His') . '.xlsx'
            : 'MasterSKU_Template_' . date('Ymd_His') . '.xlsx';

        return Excel::download(
            new TemplateExport($includeStock),
            $filename
        );
    }
}
