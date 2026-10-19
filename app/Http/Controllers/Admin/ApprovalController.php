<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ProductReturn;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    // Halaman untuk Manager: Lihat semua pending approvals
    public function index()
    {
        $approvals = Approval::with(['requestedBy', 'approvedBy'])
            ->where('status', 'pending')
            ->latest()
            ->paginate(20);

        $myApprovals = Approval::with(['requestedBy', 'approvedBy'])
            ->where('requested_by', Auth::id())
            ->latest()
            ->take(10)
            ->get();

        return view('admin.approvals.index', compact('approvals', 'myApprovals'));
    }

    // Halaman detail approval
    public function show(Approval $approval)
    {
        $approval->load(['requestedBy', 'approvedBy']);

        // Load data referensi berdasarkan tipe
        $referenceData = null;
        if ($approval->reference_type === 'user_activation') {
            $referenceData = User::find($approval->reference_id);
        } elseif ($approval->reference_type === 'stock_opname') {
            $referenceData = StockOpname::with(['variant.product', 'warehouse'])->find($approval->reference_id);
        } elseif ($approval->reference_type === 'return') {
            $referenceData = ProductReturn::with(['items.variant.product', 'transaction'])->find($approval->reference_id);
        }

        return view('admin.approvals.show', compact('approval', 'referenceData'));
    }

    // Proses Approve
    public function approve(Request $request, Approval $approval)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if ($approval->status !== 'pending') {
            return back()->withErrors(['error' => 'Approval ini sudah diproses sebelumnya.']);
        }

        DB::beginTransaction();
        try {
            $approval->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'reason' => $validated['reason'] ?? $approval->reason,
            ]);

            // Eksekusi aksi berdasarkan tipe
            $this->executeApproval($approval);

            DB::commit();
            return redirect()->route('approvals.index')->with('success', 'Approval berhasil disetujui.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Gagal memproses approval: ' . safe_error_message($e)]);
        }
    }

    // Proses Reject
    public function reject(Request $request, Approval $approval)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($approval->status !== 'pending') {
            return back()->withErrors(['error' => 'Approval ini sudah diproses sebelumnya.']);
        }

        DB::beginTransaction();
        try {
            $approval->update([
                'status' => 'rejected',
                'approved_by' => Auth::id(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            $this->executeRejection($approval);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Gagal menolak approval: ' . safe_error_message($e)]);
        }

        return redirect()->route('approvals.index')->with('success', 'Approval ditolak.');
    }

    // Eksekusi aksi setelah di-approve
    private function executeApproval(Approval $approval)
    {
        if ($approval->reference_type === 'user_activation') {
            // Aktifkan user
            $user = User::find($approval->reference_id);
            if ($user) {
                $user->update(['is_active' => true]);
            }
        } elseif ($approval->reference_type === 'stock_opname') {
            $opname = StockOpname::with('variant')->find($approval->reference_id);

            if ($opname && $opname->status === 'pending') {
                $opname->variant->updateStock(
                    $opname->actual_stock,
                    'ADJUSTMENT',
                    $opname->warehouse_id,
                    Auth::id(),
                    'stock_opname',
                    $opname->id
                );

                $opname->update([
                    'status' => 'approved',
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);
            }
        } elseif ($approval->reference_type === 'return') {
            $return = ProductReturn::find($approval->reference_id);

            if ($return && $return->status === 'pending') {
                $return->update([
                    'status' => 'approved',
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);
            }
        }
    }

    // Eksekusi aksi setelah ditolak
    private function executeRejection(Approval $approval)
    {
        if ($approval->reference_type === 'stock_opname') {
            $opname = StockOpname::find($approval->reference_id);

            if ($opname && $opname->status === 'pending') {
                $opname->update([
                    'status' => 'rejected',
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]);
            }
        } elseif ($approval->reference_type === 'return') {
            $return = ProductReturn::find($approval->reference_id);

            if ($return && $return->status === 'pending') {
                $return->update([
                    'status' => 'rejected',
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'notes' => $approval->rejection_reason,
                ]);
            }
        }
    }

    // Get count pending approvals (untuk badge notifikasi)
    public static function getPendingCount()
    {
        return Approval::where('status', 'pending')->count();
    }
}
