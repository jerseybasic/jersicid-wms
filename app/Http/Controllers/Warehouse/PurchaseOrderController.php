<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\MasterSku;
use App\Models\Supplier; // ✅ TAMBAHKAN BARIS INI
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\POStatusUpdated;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\AuditTrail; // ✅ Pastikan ini juga ada untuk method updateStatus
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = PurchaseOrder::with(['supplier', 'user', 'items.masterSku']);

        // Filter berdasarkan Role
        if (in_array($user->role, ['SUBLIM', 'KONVEKSI'])) {
            $query->where('workspace', $user->workspace_default ?? 'PO')
                ->whereIn('status', ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION']);
        }

        // Filter Search
        if ($request->filled('search')) {
            $query->where('po_number', 'like', '%' . $request->search . '%');
        }

        // Filter Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter Supplier
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(15);

        // ==================== STATS ====================
        $baseQuery = PurchaseOrder::query();

        // Jika role SUBLIM/KONVEKSI, filter stats juga
        if (in_array($user->role, ['SUBLIM', 'KONVEKSI'])) {
            $baseQuery->where('workspace', $user->workspace_default ?? 'PO');
        }

        $stats = [
            'total' => $baseQuery->count(),
            'pending' => $baseQuery->clone()->whereIn('status', ['DRAFT', 'PENDING'])->count(),
            'approved' => $baseQuery->clone()->where('status', 'APPROVED')->count(),
            'in_production' => $baseQuery->clone()->where('status', 'IN_PRODUCTION')->count(),
        ];

        // ==================== STATUS OPTIONS ====================
        $statusOptions = ['DRAFT', 'PENDING', 'APPROVED', 'SENT', 'RECEIVED', 'IN_PRODUCTION', 'CANCELLED'];

        // ==================== SUPPLIERS ====================
        $suppliers = Supplier::where('status', 'AKTIF')->orderBy('name')->get();

        return view('warehouse.po.index', compact(
            'purchaseOrders',
            'stats',
            'statusOptions',
            'suppliers'
        ));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Ambil semua supplier aktif
        $suppliers = Supplier::where('status', 'AKTIF')->orderBy('name')->get();

        // Ambil semua SKU aktif untuk dropdown item
        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->orderBy('size')
            ->get(['sku', 'product_name', 'price', 'size']);

        // Default supplier type berdasarkan role user
        $user = auth()->user();
        $defaultSupplierType = match ($user->role) {
            'SUBLIM' => 'SUBLIMASI',
            'KONVEKSI' => 'KONVEKSI',
            default => 'UMUM',
        };

        return view('warehouse.po.create', compact('suppliers', 'skus', 'defaultSupplierType'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'article' => 'required|string|max:50',
            'expected_date' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|exists:master_skus,sku',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4));

                $po = PurchaseOrder::create([
                    'id' => Str::uuid(),
                    'po_number' => $poNumber,
                    'supplier_id' => $validated['supplier_id'],
                    'user_id' => Auth::id(),
                    'article' => $validated['article'],
                    'expected_date' => $validated['expected_date'],
                    'status' => 'DRAFT',
                    'notes' => $validated['notes'] ?? null,
                    'total_amount' => 0,
                ]);

                $totalAmount = 0;

                foreach ($validated['items'] as $item) {
                    $sku = MasterSku::where('sku', $item['sku'])->first();
                    $subtotal = $item['qty'] * $item['unit_price'];
                    $totalAmount += $subtotal;

                    PurchaseOrderItem::create([
                        'id' => Str::uuid(),
                        'purchase_order_id' => $po->id,
                        'sku' => $item['sku'],
                        'product_name' => $sku->product_name,
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $subtotal,
                    ]);
                }

                $po->update(['total_amount' => $totalAmount]);
            });

            return redirect()->route('po.index')
                ->with('success', 'Purchase Order berhasil dibuat!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat PO: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $po = PurchaseOrder::with(['vendor', 'items'])->findOrFail($id);
        return view('warehouse.po.show', compact('po'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PurchaseOrder $po)
    {
        if (!in_array($po->status, ['DRAFT'])) {
            return back()->with('error', 'Hanya PO dengan status DRAFT yang bisa diedit.');
        }

        $po->load(['items']);
        $suppliers = Supplier::where('status', 'AKTIF')->orderBy('name')->get();
        $masterSkus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['id', 'sku', 'product_name', 'article', 'price']);

        return view('warehouse.po.edit', compact('po', 'suppliers', 'masterSkus'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PurchaseOrder $po)
    {
        if (!in_array($po->status, ['DRAFT'])) {
            return back()->with('error', 'Hanya PO dengan status DRAFT yang bisa diupdate.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'article' => 'required|string|max:50',
            'expected_date' => 'required|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|exists:master_skus,sku',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($validated, $po) {
                $po->update([
                    'supplier_id' => $validated['supplier_id'],
                    'article' => $validated['article'],
                    'expected_date' => $validated['expected_date'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                $po->items()->delete();

                $totalAmount = 0;
                foreach ($validated['items'] as $item) {
                    $sku = MasterSku::where('sku', $item['sku'])->first();
                    $subtotal = $item['qty'] * $item['unit_price'];
                    $totalAmount += $subtotal;

                    PurchaseOrderItem::create([
                        'id' => Str::uuid(),
                        'purchase_order_id' => $po->id,
                        'sku' => $item['sku'],
                        'product_name' => $sku->product_name,
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $subtotal,
                    ]);
                }

                $po->update(['total_amount' => $totalAmount]);
            });

            return redirect()->route('po.show', $po)
                ->with('success', 'Purchase Order berhasil diupdate!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal update PO: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Approve Purchase Order
     */
    public function approve(PurchaseOrder $po)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['OWNER', 'SUPERVISOR'])) {
            abort(403, 'Akses ditolak.');
        }

        if ($po->status !== 'DRAFT') {
            return back()->with('error', 'Hanya PO DRAFT yang bisa di-approve.');
        }

        DB::transaction(function () use ($po) {
            $po->update([
                'status' => 'APPROVED',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
        });

        return back()->with('success', "PO {$po->po_number} berhasil di-approve!");
    }

    /**
     * Mark PO as Sent
     */
    public function markSent(PurchaseOrder $po)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['OWNER', 'SUPERVISOR'])) {
            abort(403, 'Akses ditolak.');
        }

        if ($po->status !== 'APPROVED') {
            return back()->with('error', 'Hanya PO APPROVED yang bisa ditandai SENT.');
        }

        DB::transaction(function () use ($po) {
            $po->update([
                'status' => 'SENT',
                'sent_at' => now(),
            ]);
        });

        return back()->with('success', "PO {$po->po_number} ditandai terkirim ke supplier!");
    }

    /**
     * Receive PO
     */
    public function receive(Request $request, PurchaseOrder $po)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['OWNER', 'SUPERVISOR', 'STAFF_GUDANG'])) {
            abort(403, 'Akses ditolak.');
        }

        if ($po->status !== 'SENT') {
            return back()->with('error', 'Hanya PO SENT yang bisa diterima.');
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.sku' => 'required|exists:master_skus,sku',
            'items.*.received_qty' => 'required|integer|min:0',
            'items.*.bin_code' => 'required|string',
        ]);

        try {
            DB::transaction(function () use ($validated, $po, $user) {
                foreach ($validated['items'] as $item) {
                    if ($item['received_qty'] > 0) {
                        StockActive::updateOrCreate(
                            [
                                'sku' => $item['sku'],
                                'bin_code' => $item['bin_code'],
                            ],
                            [
                                'qty' => DB::raw('qty + ' . $item['received_qty']),
                                'last_movement_at' => now(),
                                'received_at' => now(),
                            ]
                        );

                        StockMovement::create([
                            'id' => Str::uuid(),
                            'type' => 'INBOUND',
                            'sku' => $item['sku'],
                            'qty' => $item['received_qty'],
                            'reference_type' => 'PO',
                            'reference_id' => $po->id,
                            'bin_code' => $item['bin_code'],
                            'user_id' => $user->id,
                            'notes' => "Penerimaan PO {$po->po_number}",
                        ]);
                    }
                }

                $po->update([
                    'status' => 'RECEIVED',
                    'received_at' => now(),
                    'received_by' => $user->id,
                ]);
            });

            return redirect()->route('po.show', $po)
                ->with('success', "PO {$po->po_number} berhasil diterima!");
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menerima PO: ' . $e->getMessage());
        }
    }

    /**
     * Cancel Purchase Order
     */
    public function cancel(PurchaseOrder $po)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['OWNER', 'SUPERVISOR'])) {
            abort(403, 'Akses ditolak.');
        }

        if (in_array($po->status, ['RECEIVED', 'CANCELLED'])) {
            return back()->with('error', 'PO tidak bisa dibatalkan.');
        }

        DB::transaction(function () use ($po) {
            $po->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
            ]);
        });

        return back()->with('success', "PO {$po->po_number} dibatalkan.");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PurchaseOrder $po)
    {
        $user = Auth::user();
        if ($user->role !== 'OWNER') {
            abort(403, 'Hanya OWNER yang bisa menghapus PO.');
        }

        if ($po->status !== 'DRAFT') {
            return back()->with('error', 'Hanya PO DRAFT yang bisa dihapus.');
        }

        DB::transaction(function () use ($po) {
            $po->items()->delete();
            $po->delete();
        });

        return redirect()->route('po.index')
            ->with('success', "PO {$po->po_number} berhasil dihapus.");
    }

    /**
     * ✅ NEW: Create PO dari Low Stock (via AJAX)
     */
    public function createFromLowStock(Request $request)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.sku' => 'required|exists:master_skus,sku',
            'products.*.product_name' => 'required|string',
            'products.*.qty_needed' => 'required|integer|min:1',
        ]);

        try {
            DB::transaction(function () use ($validated) {
                // Generate PO number
                $poNumber = 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4));

                // Create PO
                $po = PurchaseOrder::create([
                    'id' => (string) Str::uuid(),
                    'po_number' => $poNumber,
                    'supplier_id' => null, // Akan diisi manual nanti
                    'user_id' => Auth::id(),
                    'expected_date' => now()->addDays(14),
                    'status' => 'DRAFT',
                    'workspace' => 'GUDANG',
                    'notes' => 'Auto-generated dari Low Stock Alert',
                    'total_amount' => 0,
                ]);

                // Add items
                $totalAmount = 0;
                foreach ($validated['products'] as $product) {
                    $masterSku = MasterSku::where('sku', $product['sku'])->first();
                    $price = $masterSku->price ?? 0;
                    $qty = $product['qty_needed'];
                    $subtotal = $qty * $price;
                    $totalAmount += $subtotal;

                    $po->items()->create([
                        'id' => (string) Str::uuid(),
                        'sku' => $product['sku'],
                        'qty' => $qty,
                        'price' => $price,
                        'subtotal' => $subtotal,
                    ]);
                }

                $po->update(['total_amount' => $totalAmount]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Draft PO berhasil dibuat! Silakan lengkapi data supplier.',
                'redirect_url' => route('po.index'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat PO: ' . $e->getMessage(),
            ], 500);
        }
    }

        /**
     * Update Status PO (Khusus Admin Gudang / Supervisor / Owner)
     */
    public function updateStatus(Request $request, $id)
    {
        $user = auth()->user();
        
        // Validasi Role
        if (!in_array($user->role, ['ADMIN_GUDANG', 'SUPERVISOR', 'OWNER'])) {
            abort(403, 'Akses ditolak. Hanya Admin Gudang/Supervisor/Owner yang bisa update status.');
        }

        $validated = $request->validate([
            'status' => 'required|in:IN_PRODUCTION,READY_FOR_PICKUP,RECEIVED,CANCELLED',
            'defect_count' => 'nullable|integer|min:0',
            'defect_notes' => 'nullable|string|max:1000',
        ]);

        $po = PurchaseOrder::with('vendor')->findOrFail($id);
        $oldStatus = $po->status;
        $newStatus = $validated['status'];

        // Cek apakah transisi status diizinkan
        if (!$po->canTransitionTo($newStatus)) {
            return back()->withErrors([
                'status' => "Transisi dari '{$oldStatus}' ke '{$newStatus}' tidak diizinkan."
            ]);
        }

        try {
            DB::transaction(function () use ($po, $validated, $newStatus) {
                $updateData = ['status' => $newStatus];
                
                // Jika status READY_FOR_PICKUP, catat waktunya
                if ($newStatus === 'READY_FOR_PICKUP') {
                    $updateData['ready_at'] = now();
                }
                
                // Update defect jika ada
                if (isset($validated['defect_count'])) {
                    $updateData['defect_count'] = $validated['defect_count'];
                    $updateData['defect_notes'] = $validated['defect_notes'] ?? null;
                }

                $po->update($updateData);

                // Audit trail
                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'UPDATE_PO_STATUS',
                    'module' => 'PURCHASE_ORDER',
                    'old_values' => json_encode(['status' => $oldStatus]),
                    'new_values' => json_encode(array_merge(['status' => $newStatus], $updateData)),
                    'ip_address' => request()->ip(),
                ]);
            });

            // ✅ KIRIM NOTIFIKASI
            $this->sendStatusNotification($po, $oldStatus, $newStatus, $user);

            $statusLabels = [
                'IN_PRODUCTION' => 'Sedang Diproduksi',
                'READY_FOR_PICKUP' => 'Siap Diambil',
                'RECEIVED' => 'Sudah Diterima',
                'CANCELLED' => 'Dibatalkan',
            ];

            return back()->with('success', 
                "Status PO berhasil diupdate menjadi '{$statusLabels[$newStatus]}'. " .
                "Notifikasi terkirim ke Owner & Supervisor."
            );

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Gagal update status: ' . $e->getMessage()]);
        }
    }
} // ✅ TAMBAHKAN KURUNG KURAWAL INI DI BARIS PALING BAWAH