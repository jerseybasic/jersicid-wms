<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MasterSku;
use App\Models\StockActive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboundController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with('items.masterSku')->orderBy('created_at', 'desc');

        // Filter Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(15);

        // Stats
        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', 'PENDING')->count(),
            'picking' => Order::whereIn('status', ['CONFIRMED', 'PICKING'])->count(),
            'packing' => Order::where('status', 'PACKING')->count(),
        ];

        return view('warehouse.outbound.index', compact('orders', 'stats'));
    }

    public function create()
    {
        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size']);

        return view('warehouse.outbound.create', compact('skus'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'shipping_address' => ['required', 'string'],
            'courier' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'exists:master_skus,sku'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $order = Order::create([
                    'id' => (string) Str::uuid(),
                    'order_number' => 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'customer_name' => $validated['customer_name'],
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'shipping_address' => $validated['shipping_address'],
                    'courier' => $validated['courier'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => 'PENDING',
                    'created_by' => auth()->id(),
                ]);

                foreach ($validated['items'] as $item) {
                    $skuData = MasterSku::where('sku', $item['sku'])->first();

                    OrderItem::create([
                        'id' => (string) Str::uuid(),
                        'order_id' => $order->id,
                        'sku' => $item['sku'],
                        'qty_ordered' => $item['qty'],
                        'qty_picked' => 0,
                        'qty_packed' => 0,
                        'price' => $skuData?->price ?? 0,
                    ]);
                }
            });

            return redirect()->route('outbound.index')->with('success', 'Order berhasil dibuat!');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal membuat order: ' . safe_error_message($e));
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        $newStatus = $request->status;

        $allowedTransitions = [
            'PENDING' => ['CONFIRMED', 'CANCELLED'],
            'CONFIRMED' => ['PICKING'],
            'PICKING' => ['PACKING'],
            'PACKING' => ['READY_TO_SHIP', 'SHIPPED'],
        ];

        if (!isset($allowedTransitions[$order->status]) || !in_array($newStatus, $allowedTransitions[$order->status])) {
            return back()->with('error', 'Transisi status tidak valid!');
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'PICKING') $updateData['picked_at'] = now();
        if ($newStatus === 'PACKING') $updateData['packed_at'] = now();
        if ($newStatus === 'SHIPPED') $updateData['shipped_at'] = now();

        $order->update($updateData);

        return back()->with('success', "Status order diubah menjadi {$newStatus}");
    }
}
