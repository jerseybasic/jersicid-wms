<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::with('creator:id,name');

        // Filter search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('supplier_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        // Filter type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $suppliers = $query->orderBy('created_at', 'desc')->paginate(15);

        return view('admin.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('admin.suppliers.create');
    }

    public function edit($id)
    {
        $supplier = Supplier::findOrFail($id);
        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_code' => ['nullable', 'string', 'max:20', 'unique:suppliers,supplier_code'],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'npwp' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'in:SUBLIMASI,KONVEKSI,UMUM'],
            'status' => ['required', 'in:AKTIF,NONAKTIF'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['created_by'] = auth()->id();

        $supplier = Supplier::create($validated);
        AuditTrail::record('CREATE', 'SUPPLIER', ['supplier' => $supplier->name, 'type' => $supplier->type, 'status' => $supplier->status]);

        // Return JSON untuk AJAX
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Supplier berhasil ditambahkan!']);
        }

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier berhasil ditambahkan!');
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'supplier_code' => ['nullable', 'string', 'max:20', 'unique:suppliers,supplier_code,' . $supplier->id],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'npwp' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'in:SUBLIMASI,KONVEKSI,UMUM'],
            'status' => ['required', 'in:AKTIF,NONAKTIF'],
            'notes' => ['nullable', 'string'],
        ]);

        $before = $supplier->only(array_keys($validated));
        $supplier->update($validated);
        [$old, $new] = AuditTrail::changes($before, $supplier->only(array_keys($validated)));
        if ($new) {
            AuditTrail::record('UPDATE', 'SUPPLIER', ['supplier' => $supplier->name] + $new, $old);
        }

        // Return JSON untuk AJAX
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Supplier berhasil diperbarui!']);
        }

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier berhasil diperbarui!');
    }

    public function destroy($id)
    {
        $supplier = Supplier::findOrFail($id);

        // Cek apakah supplier digunakan di PO
        $hasOrders = \App\Models\PurchaseOrder::where('supplier_name', $supplier->name)->exists();
        if ($hasOrders) {
            return back()->with('error', 'Tidak dapat menghapus supplier yang masih memiliki Purchase Order!');
        }

        $supplier->delete();
        AuditTrail::record('DELETE', 'SUPPLIER', ['supplier' => $supplier->name, 'supplier_code' => $supplier->supplier_code]);

        return redirect()->route('suppliers.index')
            ->with('success', 'Supplier berhasil dihapus!');
    }

    public function toggleStatus($id)
    {
        $supplier = Supplier::findOrFail($id);
        $oldStatus = $supplier->status;
        $supplier->update([
            'status' => $supplier->status === 'AKTIF' ? 'NONAKTIF' : 'AKTIF'
        ]);
        AuditTrail::record('TOGGLE_STATUS', 'SUPPLIER', ['supplier' => $supplier->name, 'status' => $supplier->status], ['status' => $oldStatus]);

        return back()->with('success', 'Status supplier berhasil diubah!');
    }
}
