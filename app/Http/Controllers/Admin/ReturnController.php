<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductReturn as ReturnModel;
use App\Models\ReturnItem;
use App\Models\QcLog;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ReturnController extends Controller
{
    public function index()
    {
        $returns = ReturnModel::with(['transaction', 'items.variant.product'])
            ->latest()
            ->paginate(15);

        return view('admin.returns.index', compact('returns'));
    }

    public function create()
    {
        // Ambil transaksi yang statusnya completed dalam 30 hari terakhir (bisa diadjust)
        $transactions = Transaction::where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.returns.create', compact('transactions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'customer_name' => 'required|string|max:100',
            'reason' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.transaction_item_id' => 'required|exists:transaction_items,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $originalTransaction = Transaction::findOrFail($validated['transaction_id']);

            $return = ReturnModel::create([
                'transaction_id' => $validated['transaction_id'],
                'customer_name' => $validated['customer_name'],
                'reason' => $validated['reason'],
                'status' => 'pending',
            ]);

            $totalRefund = 0;

            foreach ($validated['items'] as $item) {
                $transItem = TransactionItem::findOrFail($item['transaction_item_id']);

                // Validasi qty retur tidak lebih dari qty beli
                $alreadyReturned = ReturnItem::whereHas('return', function ($q) use ($validated) {
                    $q->where('transaction_id', $validated['transaction_id']);
                })->where('variant_id', $transItem->variant_id)->sum('quantity');

                if (($alreadyReturned + $item['quantity']) > $transItem->quantity) {
                    throw new \Exception("Jumlah retur untuk {$transItem->product_name} melebihi batas pembelian.");
                }

                ReturnItem::create([
                    'return_id' => $return->id,
                    'variant_id' => $transItem->variant_id,
                    'quantity' => $item['quantity'],
                    'condition' => 'used_good', // Default sementara, akan diupdate saat QC
                    'qc_status' => 'pending',
                    'restock' => false,
                ]);

                $totalRefund += ($transItem->unit_price * $item['quantity']);
            }

            $return->update(['refund_amount' => $totalRefund]);

            DB::commit();
            return redirect()->route('returns.index')->with('success', 'Permintaan retur berhasil dibuat! Menunggu inspeksi QC.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => safe_error_message($e)]);
        }
    }

    // Halaman Inspeksi QC untuk barang retur
    public function inspect($id)
    {
        $return = ReturnModel::with(['items.variant.product', 'transaction'])->findOrFail($id);
        return view('admin.returns.inspect', compact('return'));
    }

    // Proses hasil inspeksi QC
    public function processInspection(Request $request, $id)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:return_items,id',
            'items.*.condition' => 'required|in:new,used_good,used_damaged,defective',
            'items.*.qc_status' => 'required|in:passed,failed',
            'items.*.restock' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $return = ReturnModel::findOrFail($id);
            $allPassed = true;

            foreach ($validated['items'] as $itemData) {
                $returnItem = ReturnItem::findOrFail($itemData['id']);

                $returnItem->update([
                    'condition' => $itemData['condition'],
                    'qc_status' => $itemData['qc_status'],
                    'restock' => $itemData['restock'] ?? false,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // Jika QC Passed dan diputuskan untuk di-restock, tambahkan stok
                if ($itemData['qc_status'] === 'passed' && !empty($itemData['restock'])) {
                    $returnItem->variant->updateStock(
                        $returnItem->quantity,
                        'IN',
                        $return->transaction->warehouse_id,
                        Auth::id(),
                        'return_restock',
                        $return->id
                    );
                }

                if ($itemData['qc_status'] === 'failed') {
                    $allPassed = false;
                }
            }

            // Update status Return utama
            $newStatus = $allPassed ? 'approved' : 'rejected';
            // Jika ada yang direstock, kita anggap sudah diproses (refunded/exchanged)
            if ($request->has('process_refund')) {
                $newStatus = 'refunded';
            }

            $return->update([
                'status' => $newStatus,
                'inspected_by' => Auth::id(),
                'inspected_at' => now(),
            ]);

            DB::commit();
            return redirect()->route('returns.index')->with('success', 'Inspeksi QC selesai dan stok telah disesuaikan.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => safe_error_message($e)]);
        }
    }
}
