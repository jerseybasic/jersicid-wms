<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MissingStockReport;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Services\WaitingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Persetujuan Stok (Owner/Supervisor): opname yang dikirim staf dan laporan "barang tidak ada di rak".
 * Stok baru berubah setelah disetujui di sini.
 */
class StockApprovalController extends Controller
{
    public function index()
    {
        $opnames = StockOpname::with(['submitter:id,name', 'creator:id,name'])
            ->where('status', 'SUBMITTED')
            ->withCount('items')
            ->withCount(['items as diff_count' => fn ($q) => $q->where('difference', '!=', 0)])
            ->withSum('items as diff_total', 'difference')
            ->orderBy('submitted_at')
            ->get();

        $reports = MissingStockReport::with(['reporter:id,name', 'order:id,order_number', 'masterSku:sku,product_name,size'])
            ->pending()
            ->orderBy('created_at')
            ->get();
        $currentQty = StockActive::whereIn('sku', $reports->pluck('sku'))->whereIn('bin_code', $reports->pluck('bin_code'))
            ->get(['sku', 'bin_code', 'qty'])
            ->mapWithKeys(fn ($s) => [$s->sku . '|' . $s->bin_code => (int) $s->qty]);

        $decided = MissingStockReport::with(['reporter:id,name', 'decider:id,name'])
            ->where('status', '!=', 'PENDING')
            ->latest('decided_at')
            ->limit(20)
            ->get();

        return view('warehouse.stock-approvals.index', compact('opnames', 'reports', 'currentQty', 'decided'));
    }

    /** Barang memang tidak ada: stok rak dikurangi sebanyak yang dilaporkan (tidak lebih dari stok rak sekarang) */
    public function approveMissing(Request $request, MissingStockReport $report)
    {
        $result = DB::transaction(function () use ($report, $request) {
            $report = MissingStockReport::lockForUpdate()->find($report->id);
            if ($report->status !== 'PENDING') {
                return null;
            }

            $stock = StockActive::where('sku', $report->sku)->where('bin_code', $report->bin_code)->lockForUpdate()->first();
            $reduce = min($report->qty, (int) ($stock?->qty ?? 0));
            if ($reduce > 0) {
                StockMovement::create([
                    'user_id' => auth()->id(),
                    'sku' => $report->sku,
                    'bin_code' => $report->bin_code,
                    'type' => 'ADJUSTMENT',
                    'qty' => $reduce,
                    'qty_before' => $stock->qty,
                    'qty_after' => $stock->qty - $reduce,
                    'reference_no' => $report->reference_no,
                    'reference_type' => 'PICKING_MISSING',
                    'notes' => 'Tidak ada di rak (dilaporkan saat picking, disetujui)',
                    'ip_address' => $request->ip(),
                ]);
                $stock->update(['qty' => $stock->qty - $reduce, 'last_movement_at' => now()]);
            }

            $report->update(['status' => 'APPROVED', 'decided_by' => auth()->id(), 'decided_at' => now()]);
            $this->audit('APPROVE_MISSING', $report, ['qty_reduced' => $reduce], $request->ip());

            return $reduce;
        });

        if ($result === null) {
            return back()->with('error', 'Laporan ini sudah diputuskan.');
        }

        return back()->with('success', "Disetujui. Stok {$report->sku} di {$report->bin_code} dikurangi {$result} pcs.");
    }

    /** Barang ternyata ada: stok tidak diubah, dan order yang tadi ditunda bisa kembali ke antrean */
    public function rejectMissing(Request $request, MissingStockReport $report)
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $done = DB::transaction(function () use ($report, $validated, $request) {
            $report = MissingStockReport::lockForUpdate()->find($report->id);
            if ($report->status !== 'PENDING') {
                return false;
            }

            $report->update([
                'status' => 'REJECTED',
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_note' => $validated['note'] ?? null,
            ]);
            $this->audit('REJECT_MISSING', $report, array_filter(['note' => $validated['note'] ?? null]), $request->ip());

            return true;
        });

        if (!$done) {
            return back()->with('error', 'Laporan ini sudah diputuskan.');
        }

        $released = WaitingStock::release(auth()->id(), $request->ip());

        return back()->with('success', "Ditolak. Stok {$report->sku} di {$report->bin_code} tetap, dan rak itu bisa dijatah ke picking lagi." . WaitingStock::releasedMessage($released));
    }

    private function audit(string $action, MissingStockReport $report, array $values, ?string $ip): void
    {
        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => 'PICKING',
            'new_values' => json_encode(['sku' => $report->sku, 'bin' => $report->bin_code, 'qty_reported' => $report->qty, 'reference_no' => $report->reference_no] + $values),
            'ip_address' => $ip,
        ]);
    }
}
