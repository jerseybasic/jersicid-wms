<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    /** Role yang boleh membuat, mengubah, menyetujui, dan membatalkan PO */
    private const MANAGER_ROLES = ['OWNER', 'SUPERVISOR'];

    public function index(Request $request)
    {
        $query = PurchaseOrder::with('items');

        if ($request->filled('search')) {
            $query->where('po_number', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        $stats = [
            'total' => PurchaseOrder::count(),
            'pending' => PurchaseOrder::whereIn('status', ['DRAFT', 'PENDING'])->count(),
            'approved' => PurchaseOrder::where('status', 'APPROVED')->count(),
            'in_production' => PurchaseOrder::where('status', 'IN_PRODUCTION')->count(),
        ];

        $statusOptions = PurchaseOrder::STATUS_LABELS;
        unset($statusOptions['READY']);

        $suppliers = Supplier::where('status', 'AKTIF')->orderBy('name')->get();

        return view('warehouse.po.index', compact('purchaseOrders', 'stats', 'statusOptions', 'suppliers'));
    }

    public function create()
    {
        $this->authorizeManager();

        return view('warehouse.po.create', $this->formData(new PurchaseOrder()));
    }

    public function store(Request $request)
    {
        $this->authorizeManager();
        $validated = $this->validatePo($request, true);

        try {
            $po = DB::transaction(function () use ($validated) {
                $supplier = Supplier::findOrFail($validated['supplier_id']);

                $po = PurchaseOrder::create([
                    'po_number' => 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'supplier_type' => $validated['supplier_type'],
                    'expected_date' => $validated['expected_date'],
                    'status' => 'DRAFT',
                    'created_by' => Auth::id(),
                    'notes' => $validated['notes'] ?? null,
                ]);

                $this->syncItems($po, $validated['items']);
                $this->audit('CREATE', $po, ['po_number' => $po->po_number]);

                return $po;
            });

            return redirect()->route('po.show', $po->id)
                ->with('success', "PO {$po->po_number} berhasil dibuat sebagai Draft.");
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat PO: ' . safe_error_message($e))->withInput();
        }
    }

    public function show($id)
    {
        $po = PurchaseOrder::with(['items.masterSku', 'creator:id,name,role', 'approver:id,name'])->findOrFail($id);

        return view('warehouse.po.show', compact('po'));
    }

    public function edit($id)
    {
        $this->authorizeManager();
        $po = PurchaseOrder::with('items')->findOrFail($id);

        if ($po->status !== 'DRAFT') {
            return redirect()->route('po.show', $po->id)->with('error', 'Hanya PO berstatus Draft yang bisa diedit.');
        }

        return view('warehouse.po.create', $this->formData($po));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeManager();
        $po = PurchaseOrder::findOrFail($id);

        if ($po->status !== 'DRAFT') {
            return redirect()->route('po.show', $po->id)->with('error', 'Hanya PO berstatus Draft yang bisa diedit.');
        }

        $validated = $this->validatePo($request, false);

        try {
            DB::transaction(function () use ($validated, $po) {
                $supplier = Supplier::findOrFail($validated['supplier_id']);

                $po->update([
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'supplier_type' => $validated['supplier_type'],
                    'expected_date' => $validated['expected_date'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                $po->items()->delete();
                $this->syncItems($po, $validated['items']);
                $this->audit('UPDATE', $po, ['po_number' => $po->po_number]);
            });

            return redirect()->route('po.show', $po->id)->with('success', 'Purchase Order berhasil diupdate!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal update PO: ' . safe_error_message($e))->withInput();
        }
    }

    public function destroy($id)
    {
        if (Auth::user()->role !== 'OWNER') {
            abort(403, 'Hanya OWNER yang bisa menghapus PO.');
        }

        $po = PurchaseOrder::findOrFail($id);

        if ($po->status !== 'DRAFT') {
            return back()->with('error', 'Hanya PO berstatus Draft yang bisa dihapus.');
        }

        DB::transaction(function () use ($po) {
            $po->items()->delete();
            $po->delete();
            $this->audit('DELETE', $po, ['po_number' => $po->po_number]);
        });

        return redirect()->route('po.index')->with('success', "PO {$po->po_number} berhasil dihapus.");
    }

    /**
     * Ubah status PO: ajukan, setujui, mulai produksi, siap diambil, batalkan.
     * Status RECEIVED diisi otomatis oleh Inbound.
     */
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'status' => 'required|in:PENDING,APPROVED,IN_PRODUCTION,READY_FOR_PICKUP,CANCELLED',
            'production_notes' => 'nullable|string|max:1000',
            'defect_count' => 'nullable|integer|min:0',
            'defect_notes' => 'nullable|string|max:1000',
        ]);

        $newStatus = $validated['status'];

        // Ajukan, setujui, dan batalkan hanya untuk OWNER/SUPERVISOR.
        // ADMIN_GUDANG boleh memperbarui tahap produksi.
        if (in_array($newStatus, ['PENDING', 'APPROVED', 'CANCELLED']) && !in_array($user->role, self::MANAGER_ROLES)) {
            abort(403, 'Hanya Owner atau Supervisor yang bisa melakukan aksi ini.');
        }

        $po = PurchaseOrder::findOrFail($id);
        $oldStatus = $po->status;

        if (!$po->canTransitionTo($newStatus)) {
            return back()->with('error', "Status PO tidak bisa diubah dari {$po->status_label} ke " . PurchaseOrder::STATUS_LABELS[$newStatus] . '.');
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'APPROVED') {
            $updateData['approved_by'] = $user->id;
            $updateData['approved_at'] = now();
        }

        if ($newStatus === 'READY_FOR_PICKUP') {
            $updateData['ready_at'] = now();
        }

        foreach (['production_notes', 'defect_count', 'defect_notes'] as $field) {
            if (isset($validated[$field])) {
                $updateData[$field] = $validated[$field];
            }
        }

        DB::transaction(function () use ($po, $updateData, $oldStatus) {
            $po->update($updateData);
            $this->audit('UPDATE_PO_STATUS', $po, ['from' => $oldStatus, 'to' => $updateData['status']]);
        });

        return back()->with('success', "Status PO {$po->po_number} sekarang: {$po->status_label}.");
    }

    private function authorizeManager(): void
    {
        if (!in_array(Auth::user()->role, self::MANAGER_ROLES)) {
            abort(403, 'Hanya Owner atau Supervisor yang bisa membuat dan mengubah PO.');
        }
    }

    private function validatePo(Request $request, bool $isNew): array
    {
        return $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_type' => 'required|in:SUBLIMASI,KONVEKSI,UMUM',
            'expected_date' => $isNew ? 'required|date|after_or_equal:today' : 'required|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|exists:master_skus,sku',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|integer|min:0',
        ]);
    }

    private function syncItems(PurchaseOrder $po, array $items): void
    {
        foreach ($items as $item) {
            PurchaseOrderItem::create([
                'id' => (string) Str::uuid(),
                'purchase_order_id' => $po->id,
                'sku' => $item['sku'],
                'qty_ordered' => $item['qty'],
                'qty_received' => 0,
                'price' => $item['price'],
            ]);
        }
    }

    private function formData(PurchaseOrder $po): array
    {
        return [
            'po' => $po,
            'suppliers' => Supplier::where('status', 'AKTIF')->orderBy('name')->get(),
            'skus' => MasterSku::where('status', 'AKTIF')
                ->orderBy('product_name')
                ->orderBy('size')
                ->get(['sku', 'product_name', 'price', 'size']),
        ];
    }

    private function audit(string $action, PurchaseOrder $po, array $values): void
    {
        AuditTrail::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => 'PURCHASE_ORDER',
            'new_values' => json_encode($values + ['po_id' => $po->id]),
            'ip_address' => request()->ip(),
        ]);
    }
}
