<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickingListController extends Controller
{
    public function index()
    {
        $pickingLists = PickingList::with(['assignedTo', 'items.variant.product'])
            ->latest()
            ->paginate(15);

        return view('admin.picking-lists.index', compact('pickingLists'));
    }

    public function create()
    {
        $warehouses = Warehouse::where('is_active', true)->get();
        // Ambil semua varian yang stoknya > 0
        $variants = ProductVariant::with('product')
            ->where('stock', '>', 0)
            ->where('is_active', true)
            ->get();

        return view('admin.picking-lists.create', compact('warehouses', 'variants'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'required|in:low,normal,high,urgent',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $pickingList = PickingList::create([
                'reference_type' => 'manual',
                'reference_id' => 0,
                'warehouse_id' => $validated['warehouse_id'],
                'assigned_to' => $validated['assigned_to'] ?? null,
                'priority' => $validated['priority'],
                'status' => 'pending',
            ]);

            foreach ($validated['items'] as $item) {
                $variant = ProductVariant::find($item['variant_id']);
                PickingListItem::create([
                    'picking_list_id' => $pickingList->id,
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'location_code' => $variant->location_code,
                    'status' => 'pending',
                ]);
            }

            DB::commit();
            return redirect()->route('picking-lists.index')->with('success', 'Picking List berhasil dibuat!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => safe_error_message($e)]);
        }
    }

    // Halaman khusus untuk Mobile Picker (Staff Gudang)
    public function mobileView(PickingList $pickingList)
    {
        $pickingList->load(['items.variant.product', 'warehouse']);
        return view('admin.picking-lists.mobile', compact('pickingList'));
    }

    // API untuk update status picking via mobile
    public function updatePickingItem(Request $request, PickingListItem $item)
    {
        $validated = $request->validate([
            'status' => 'required|in:picked,verified',
            'scanned_sku' => 'nullable|string', // Untuk validasi scan QR
        ]);

        // Jika ada scan, validasi SKU
        if ($request->scanned_sku) {
            if ($request->scanned_sku !== $item->variant->sku) {
                return response()->json(['success' => false, 'message' => 'SKU tidak cocok! Scan ulang.'], 400);
            }
        }

        $item->update([
            'status' => $validated['status'],
            'picked_quantity' => $item->quantity, // Asumsi picked full
            'picked_at' => now(),
        ]);

        // Cek apakah semua item sudah picked
        $pickingList = $item->pickingList;
        $allPicked = $pickingList->items->where('status', '!=', 'picked')->count() === 0;

        if ($allPicked) {
            $pickingList->update(['status' => 'completed', 'completed_at' => now()]);
        } else {
            $pickingList->update(['status' => 'in_progress']);
        }

        return response()->json(['success' => true, 'message' => 'Item berhasil di-pick!']);
    }
}
