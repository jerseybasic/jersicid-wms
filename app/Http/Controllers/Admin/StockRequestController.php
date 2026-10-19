<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockRequestController extends Controller
{
    public function create()
    {
        $products = Product::with(['variants' => function ($q) {
            $q->where('is_active', true);
        }])
            ->where('is_active', true)
            ->orderBy('name', 'desc')  // ✅ Z-A
            ->get();

        return view('admin.stock-requests.create', compact('products'));
    }

    public function index(Request $request)
    {
        $query = StockRequest::with(['product', 'variant', 'requestedBy', 'approvedBy', 'sampleApprovedBy'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('product')) {
            $query->where('product_id', $request->product);
        }

        $requests = $query->paginate(15)->withQueryString();

        // ✅ Produk Z-A untuk filter dropdown
        $products = Product::where('is_active', true)->orderBy('name', 'desc')->get();

        return view('admin.stock-requests.index', compact('requests', 'products'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'request_type' => 'required|in:restock,new_product',
            'product_id' => 'nullable|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'product_name' => 'required_if:request_type,new_product|string|max:200',
            'mockup_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // Max 5MB
            'quantity_requested' => 'required|integer|min:1',
            'sample_notes' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        // Handle mockup file upload
        $mockupPath = null;
        if ($request->hasFile('mockup_file')) {
            $mockupPath = $request->file('mockup_file')->store('mockups', 'public');
        }

        StockRequest::create([
            'request_type' => $validated['request_type'],
            'product_id' => $validated['product_id'] ?? null,
            'variant_id' => $validated['variant_id'] ?? null,
            'product_name' => $validated['product_name'] ?? null,
            'mockup_file' => $mockupPath,
            'sample_notes' => $validated['sample_notes'] ?? null,
            'requested_by' => auth()->id(),
            'quantity_requested' => $validated['quantity_requested'],
            'status' => 'pending',
            'production_status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('stock-requests.index')
            ->with('success', 'Permintaan stok berhasil diajukan! Menunggu persetujuan Admin.');
    }

    public function approve(StockRequest $stockRequest)
    {
        if (! in_array(auth()->user()->role->slug, ['super_admin', 'warehouse_manager'])) {
            abort(403, 'Unauthorized');
        }

        $stockRequest->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Permintaan disetujui! Proses dapat dimulai.');
    }

    public function approveSample(StockRequest $stockRequest, Request $request)
    {
        if (! in_array(auth()->user()->role->slug, ['super_admin', 'warehouse_manager'])) {
            abort(403, 'Unauthorized');
        }

        $stockRequest->update([
            'sample_approved' => true,
            'sample_approved_by' => auth()->id(),
            'sample_approved_at' => now(),
            'status' => 'sample_approved',
        ]);

        return back()->with('success', 'Sample disetujui! Produksi dapat dimulai.');
    }

    public function updateProductionStatus(StockRequest $stockRequest, Request $request)
    {
        if (! in_array(auth()->user()->role->slug, ['super_admin', 'warehouse_manager'])) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'production_status' => 'required|in:pending,sample_created,sample_approved,in_production,production_done',
        ]);

        $updateData = [
            'production_status' => $validated['production_status'],
        ];

        // Update main status based on production status
        if ($validated['production_status'] === 'sample_created') {
            $updateData['status'] = 'sample_created';
        } elseif ($validated['production_status'] === 'in_production') {
            $updateData['status'] = 'in_production';
        } elseif ($validated['production_status'] === 'production_done') {
            $updateData['status'] = 'production_done';
        }

        $stockRequest->update($updateData);

        return back()->with('success', 'Status produksi berhasil diupdate!');
    }

    public function updateStatus(StockRequest $stockRequest, Request $request)
    {
        if (! in_array(auth()->user()->role->slug, ['super_admin', 'warehouse_manager'])) {
            abort(403, 'Unauthorized');
        }

        $newStatus = $request->input('status');

        // Define allowed transitions
        $allowedTransitions = [
            'pending' => 'approved',
            'approved' => 'sublimation_done',
            'sublimation_done' => 'konveksi_done',
            'konveksi_done' => 'done',
        ];

        if (
            ! isset($allowedTransitions[$stockRequest->status]) ||
            $allowedTransitions[$stockRequest->status] !== $newStatus
        ) {
            return back()->withErrors(['error' => 'Transisi status tidak valid!']);
        }

        DB::beginTransaction();
        try {
            $updateData = ['status' => $newStatus];

            if ($newStatus === 'approved') {
                $updateData['approved_by'] = auth()->id();
                $updateData['approved_at'] = now();
            } elseif ($newStatus === 'sublimation_done') {
                $updateData['sublimation_done_at'] = now();
            } elseif ($newStatus === 'konveksi_done') {
                $updateData['konveksi_done_at'] = now();
            } elseif ($newStatus === 'done') {
                $updateData['completed_at'] = now();
                // Jika ini restock request, tambah stok
                if ($stockRequest->request_type === 'restock' && $stockRequest->variant) {
                    $stockRequest->variant->increment('stock', $stockRequest->quantity_requested);
                } elseif ($stockRequest->request_type === 'new_product') {
                    // Jika produk baru, buat product dan variant
                    $this->createNewProduct($stockRequest);
                }
            }

            $stockRequest->update($updateData);

            DB::commit();

            $statusNames = [
                'approved' => 'Disetujui',
                'sublimation_done' => 'Selesai Sublim',
                'konveksi_done' => 'Selesai Konveksi',
                'done' => 'Done di Gudang',
            ];

            return back()->with('success', "Status berhasil diupdate menjadi: {$statusNames[$newStatus]}");
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Gagal update status: '.safe_error_message($e)]);
        }
    }

    private function createNewProduct(StockRequest $request)
    {
        // Create new product from stock request
        $product = Product::create([
            'sku' => 'NEW-'.strtoupper(uniqid()), // Generate unique SKU
            'name' => $request->product_name,
            'slug' => Str::slug($request->product_name).'-'.Str::random(5),
            'base_price' => 0, // Need to be updated later
            'cost_price' => 0,
            'is_active' => true,
        ]);

        // Create variant if needed
        if ($request->quantity_requested > 0) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $product->sku.'-STD',
                'size' => 'STD',
                'color' => 'Standard',
                'stock' => $request->quantity_requested,
                'min_stock' => 5,
                'is_active' => true,
            ]);
        }
    }

    public function destroy(StockRequest $stockRequest)
    {
        if ($stockRequest->status !== 'pending') {
            return back()->withErrors(['error' => 'Hanya permintaan dengan status pending yang bisa dihapus!']);
        }

        $stockRequest->delete();

        return back()->with('success', 'Permintaan stok berhasil dihapus!');
    }
}
