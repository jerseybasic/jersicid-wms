<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\CourierDetector;
use App\Helpers\ProductSearcher;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'all');
        $search = $request->get('search');

        $query = Order::with(['items.variant.product', 'pickedBy', 'packedBy', 'qcVerifiedBy'])
            ->latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%")
                    ->orWhere('tracking_number', 'LIKE', "%{$search}%")
                    ->orWhere('customer_name', 'LIKE', "%{$search}%");
            });
        }

        $orders = $query->paginate(20)->withQueryString();

        $stats = [
            'pending' => Order::where('status', 'pending')->count(),
            'picking' => Order::where('status', 'picking')->count(),
            'ready_packing' => Order::where('status', 'ready_packing')->count(),
            'ready_pickup' => Order::where('status', 'ready_pickup')->count(),
            'shipped_today' => Order::where('status', 'shipped')->whereDate('shipped_at', today())->count(),
        ];

        return view('admin.orders.index', compact('orders', 'stats', 'status'));
    }

    public function create()
    {
        $products = Product::with(['variants' => function ($q) {
            $q->where('is_active', true)
                ->where('stock', '>', 0);
        }])
            ->where('is_active', true)
            ->orderBy('name', 'desc')  // ✅ Z-A
            ->get();

        return view('admin.orders.create', compact('products'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_number' => 'required|string|max:50|unique:orders,order_number',
            'tracking_number' => 'nullable|string|max:100',
            'marketplace' => 'required|in:shopee,tiktok,tokopedia,lazada,walk-in,other',
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:20',
            'customer_address' => 'required|string',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'shipping_cost' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ], [
            'order_number.unique' => 'No pesanan sudah ada di sistem',
        ]);

        $courierInfo = ['courier_name' => null, 'courier_service' => null, 'detected' => false];
        if (! empty($validated['tracking_number'])) {
            $courierInfo = CourierDetector::detect(
                $validated['order_number'],
                $validated['tracking_number']
            );
        }

        DB::beginTransaction();
        try {
            $order = Order::create([
                'order_number' => $validated['order_number'],
                'tracking_number' => $validated['tracking_number'] ?? null,
                'courier_name' => $courierInfo['courier_name'],
                'courier_service' => $courierInfo['courier_service'],
                'marketplace' => $validated['marketplace'],
                'status' => 'pending',
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_address' => $validated['customer_address'],
                'city' => $validated['city'] ?? null,
                'province' => $validated['province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'shipping_cost' => $validated['shipping_cost'] ?? 0,
                'discount' => $validated['discount'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            $subtotal = 0;
            foreach ($validated['items'] as $itemData) {
                $variant = ProductVariant::with('product')->findOrFail($itemData['variant_id']);
                $itemSubtotal = $itemData['unit_price'] * $itemData['quantity'];
                $subtotal += $itemSubtotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_info' => $variant->size.' / '.$variant->color,
                    'sku' => $variant->sku,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'subtotal' => $itemSubtotal,
                    'location_code' => $variant->location_code,
                ]);
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal + ($validated['shipping_cost'] ?? 0) - ($validated['discount'] ?? 0),
            ]);

            DB::commit();

            NotificationService::activity(
                'Order Dibuat',
                auth()->user()->name.' membuat order #'.$order->order_number,
                auth()->user(),
                ['order_id' => $order->id],
                $order
            );

            ActivityLogger::order('create', auth()->user()->name.' membuat order #'.$order->order_number, $order, [
                'customer' => $order->customer_name,
                'total' => $order->total,
            ]);

            return redirect()->route('orders.show', $order);
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Gagal membuat order: '.safe_error_message($e)])->withInput();
        }
    }

    public function show(Order $order)
    {
        $order->load(['items.variant.product', 'pickedBy', 'packedBy', 'qcVerifiedBy']);

        return view('admin.orders.show', compact('order'));
    }

    public function printReceipt(Order $order)
    {
        $order->load(['items.variant.product']);

        return view('admin.orders.receipt', compact('order'));
    }

    public function searchProduct(Request $request)
    {
        $query = $request->get('q', '');
        $results = ProductSearcher::search($query, 10);

        return response()->json($results->map(function ($variant) {
            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $variant->product->name,
                'variant_info' => $variant->size.' / '.$variant->color,
                'stock' => $variant->stock,
                'price' => $variant->price_override ?? $variant->product->base_price,
            ];
        }));
    }

    public function edit(Order $order)
    {
        if (in_array($order->status, ['shipped', 'delivered', 'cancelled'])) {
            return back()->withErrors(['error' => 'Order yang sudah dikirim/dibatalkan tidak bisa diedit!']);
        }

        $order->load('items.variant.product');

        return view('admin.orders.edit', compact('order'));
    }

    public function update(Request $request, Order $order)
    {
        if (in_array($order->status, ['shipped', 'delivered', 'cancelled'])) {
            return back()->withErrors(['error' => 'Order tidak bisa diedit!']);
        }

        // Validasi dan update logic
        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:20',
            'customer_address' => 'required|string',
            // ... tambahkan field lainnya
        ]);

        $order->update($validated);

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order berhasil diupdate!');
    }

    public function detectCourier(Request $request)
    {
        $validated = $request->validate([
            'order_number' => 'required|string',
            'tracking_number' => 'required|string',
        ]);

        $result = CourierDetector::detect(
            $validated['order_number'],
            $validated['tracking_number']
        );

        return response()->json($result);
    }

    // --- PERBAIKAN DI SINI (Null Safety) ---
    public function startPicking(Order $order)
    {
        if ($order->status !== 'pending') {
            return back()->withErrors(['error' => 'Order tidak bisa dipicking. Status: '.$order->status]);
        }

        $user = Auth::user();

        $order->update([
            'status' => 'picking',
            'picked_by' => $user ? $user->id : null,
            'picked_at' => now(),
        ]);

        return redirect()->route('orders.picking', $order->id)
            ->with('success', 'Picking dimulai untuk order #'.$order->order_number);
    }

    public function picking(Order $order)
    {
        if (! in_array($order->status, ['pending', 'picking'])) {
            return redirect()->route('orders.index')
                ->withErrors(['error' => 'Order tidak dalam status picking']);
        }

        $order->load('items.variant.product');

        return view('admin.orders.picking', compact('order'));
    }

    public function updatePickingItem(Request $request, Order $order, OrderItem $item)
    {
        $validated = $request->validate([
            'picked_quantity' => 'required|integer|min:0|max:'.$item->quantity,
            'scanned_sku' => 'nullable|string',
        ]);

        if ($request->filled('scanned_sku') && $request->scanned_sku !== $item->sku) {
            return response()->json([
                'success' => false,
                'message' => "SKU tidak cocok! Expected: {$item->sku}, Got: {$request->scanned_sku}",
            ], 400);
        }

        $item->update([
            'picked_quantity' => $validated['picked_quantity'],
            'picking_status' => $validated['picked_quantity'] >= $item->quantity ? 'picked' : 'partial',
        ]);

        $allPicked = $order->items->every(function ($i) {
            return $i->picked_quantity >= $i->quantity;
        });

        if ($allPicked) {
            $order->update(['status' => 'ready_packing']);
        }

        return response()->json(['success' => true, 'message' => 'Item berhasil di-pick']);
    }

    public function completePicking(Order $order)
    {
        $allPicked = $order->items->every(function ($item) {
            return $item->picked_quantity >= $item->quantity;
        });

        if (! $allPicked) {
            return back()->withErrors(['error' => 'Semua item harus di-pick terlebih dahulu']);
        }

        ActivityLogger::order('picking', auth()->user()->name.' menyelesaikan picking untuk order #'.$order->order_number, $order);

        return redirect()->route('orders.index')->with('success', 'Picking selesai!');
    }

    // --- PERBAIKAN DI SINI (Null Safety) ---
    public function startPacking(Order $order)
    {
        if ($order->status !== 'ready_packing') {
            return back()->withErrors(['error' => 'Order belum siap packing']);
        }

        $user = Auth::user();

        $order->update([
            'status' => 'packing',
            'packed_by' => $user ? $user->id : null,
            'packed_at' => now(),
        ]);

        return redirect()->route('orders.packing', $order->id);
    }

    public function packing(Order $order)
    {
        $order->load(['items.variant.product']);

        return view('admin.orders.packing', compact('order'));
    }

    public function completePacking(Request $request, Order $order)
    {
        // Ubah status ke qc_review (Menunggu QC Admin Gudang)
        $order->update([
            'status' => 'qc_review',
            'notes' => $request->notes ?? $order->notes,
        ]);

        return redirect()->route('orders.index')
            ->with('success', "Order #{$order->order_number} selesai packing. Menunggu QC Admin Gudang.");
    }

    // --- PERBAIKAN DI SINI (Role Check & Null Safety) ---
    public function qcVerify(Request $request, Order $order)
    {
        $user = Auth::user();
        if (! $user || ! in_array($user->role->slug ?? '', ['super_admin', 'warehouse_manager'])) {
            abort(403, 'Hanya Admin Gudang yang bisa melakukan QC');
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string',
        ]);

        if ($validated['action'] === 'approve') {
            $order->update([
                'status' => 'ready_pickup', // Berubah jadi Siap Pickup
                'qc_verified_by' => $user->id,
                'qc_verified_at' => now(),
                'notes' => $validated['notes'] ?? $order->notes,
            ]);

            return redirect()->route('orders.index')->with('success', "Order #{$order->order_number} LOLOS QC - Siap Pickup Kurir!");
        } else {
            // Jika reject, kembalikan ke packing
            $order->update([
                'status' => 'packing',
                'notes' => ($order->notes ? $order->notes."\n\n" : '').'QC REJECT: '.($validated['notes'] ?? 'Barang tidak sesuai'),
            ]);

            return redirect()->route('orders.index')->with('warning', "Order #{$order->order_number} GAGAL QC - Dikembalikan ke Packing.");
        }
    }

    public function markShipped(Order $order)
    {
        $order->update([
            'status' => 'shipped',
            'shipped_at' => now(),
        ]);

        return back()->with('success', "Order #{$order->order_number} ditandai sudah dikirim");
    }

    public function dailyReport(Request $request)
    {
        $date = $request->get('date', today()->format('Y-m-d'));

        $orders = Order::with(['items.variant.product'])
            ->whereDate('created_at', $date)
            ->get();

        $byCourier = $orders->groupBy('courier_name')->map(function ($group) {
            return [
                'count' => $group->count(),
                'items' => $group->sum(fn ($o) => $o->total_items),
                'revenue' => $group->sum('total'),
            ];
        });

        $byStatus = $orders->groupBy('status')->map->count();
        $returns = Order::where('status', 'returned')->whereDate('updated_at', $date)->count();

        return view('admin.orders.daily-report', compact('orders', 'byCourier', 'byStatus', 'returns', 'date'));
    }

    public function showStockMovement(Product $product)
    {
        // Ambil semua SKU variant
        $variants = $product->variants()->with(['sku'])->get();

        // Data untuk chart
        $skuLabels = $variants->map(fn ($v) => $v->sku ? $v->sku->code : 'N/A');
        $initialStock = $variants->map(fn ($v) => $v->sku ? ($v->sku->initial_stock ?? 0) : 0);
        $stockIn = $variants->map(fn ($v) => $v->sku ? $v->sku->stock_in : 0);
        $stockOut = $variants->map(fn ($v) => $v->sku ? $v->sku->stock_out : 0);
        $currentStock = $variants->map(fn ($v) => $v->sku ? $v->sku->stock : 0);

        return view('admin.products.stock-movement', compact(
            'product',
            'variants',
            'skuLabels',
            'initialStock',
            'stockIn',
            'stockOut',
            'currentStock'
        ));
    }

    public function cancel(Request $request, Order $order)
    {
        // Cek apakah order bisa dibatalkan
        if (in_array($order->status, ['shipped', 'delivered', 'cancelled'])) {
            return back()->withErrors(['error' => 'Order tidak bisa dibatalkan karena status: '.$order->status]);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            // Restore stock untuk setiap item yang sudah di-pick
            foreach ($order->items as $item) {
                if ($item->picked_quantity > 0) {
                    $variant = ProductVariant::find($item->variant_id);
                    if ($variant) {
                        // Kembalikan stok ke inventory
                        $variant->increment('stock', $item->picked_quantity);
                    }
                }
            }

            // Update status order menjadi cancelled
            $order->update([
                'status' => 'cancelled',
                'notes' => ($order->notes ?? '')."\n\n=== ORDER DIBATALKAN ===\n".
                    'Alasan: '.$validated['reason'].
                    "\nOleh: ".auth()->user()->name.
                    "\nTanggal: ".now()->format('d M Y H:i:s'),
            ]);

            DB::commit();

            ActivityLogger::order('cancel', auth()->user()->name.' membatalkan order #'.$order->order_number, $order, [
                'reason' => $validated['reason'],
                'customer' => $order->customer_name,
            ]);

            return redirect()->route('orders.index')->with('success', 'Order dibatalkan!');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Gagal membatalkan order: '.safe_error_message($e)]);
        }
    }

    public function dailyReport()
    {
        $orders = Order::with(['items.variant.product', 'user', 'payments'])
            ->whereDate('created_at', today())
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.orders.daily-report', compact('orders'));
    }
}
