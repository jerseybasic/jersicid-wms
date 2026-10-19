<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PickingController extends Controller
{
    public function index(Request $request)
    {
        $query = PickingSession::with(['order', 'user:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('session_number', 'like', "%{$request->search}%");
        }

        $sessions = $query->paginate(20);

        // Stats
        $stats = [
            'pending' => PickingSession::where('status', 'PENDING')->count(),
            'in_progress' => PickingSession::where('status', 'IN_PROGRESS')->count(),
            'completed' => PickingSession::where('status', 'COMPLETED')->count(),
        ];

        return view('warehouse.picking.index', compact('sessions', 'stats'));
    }

    public function create()
    {
        // Ambil order yang statusnya CONFIRMED dan belum ada picking session
        $availableOrders = Order::where('status', 'CONFIRMED')
            ->whereDoesntHave('pickingSession')
            ->with('items')
            ->orderBy('created_at', 'asc')
            ->get();

        return view('warehouse.picking.create', compact('availableOrders'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($validated, $request) {
                $user = auth()->user();

                // Buat picking session
                $session = PickingSession::create([
                    'order_id' => $validated['order_id'],
                    'user_id' => $user->id,
                    'status' => 'PENDING',
                    'notes' => $validated['notes'],
                ]);

                // Ambil order items
                $order = Order::with('items')->findOrFail($validated['order_id']);

                foreach ($order->items as $item) {
                    // Cari stok di bin
                    $stock = StockActive::where('sku', $item->sku)
                        ->where('qty', '>', 0)
                        ->first();

                    PickingSessionItem::create([
                        'picking_session_id' => $session->id,
                        'order_item_id' => $item->id,
                        'sku' => $item->sku,
                        'bin_code' => $stock?->bin_code ?? 'UNKNOWN',
                        'qty_ordered' => $item->qty_ordered,
                        'qty_picked' => 0,
                        'status' => 'PENDING',
                    ]);
                }

                // Update order status
                $order->update(['status' => 'PICKING']);

                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'CREATE',
                    'module' => 'PICKING',
                    'new_values' => json_encode([
                        'session_number' => $session->session_number,
                        'order_id' => $order->id,
                    ]),
                    'ip_address' => $request->ip(),
                ]);
            });

            return redirect()->route('gudang.picking')
                ->with('success', 'Picking session berhasil dibuat!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat picking session: ' . safe_error_message($e));
        }
    }

    public function show($id)
    {
        $session = PickingSession::with(['order', 'items.masterSku', 'user:id,name'])
            ->findOrFail($id);

        return view('warehouse.picking.show', compact('session'));
    }

    public function start($id)
    {
        $session = PickingSession::findOrFail($id);
        $user = auth()->user();

        if ($session->status !== 'PENDING') {
            return back()->with('error', 'Hanya session PENDING yang bisa dimulai.');
        }

        $session->update([
            'status' => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        AuditTrail::create([
            'user_id' => $user->id,
            'action' => 'START',
            'module' => 'PICKING',
            'new_values' => json_encode(['session_number' => $session->session_number]),
            'ip_address' => request()->ip(),
        ]);

        return back()->with('success', 'Picking session dimulai!');
    }

    public function pickItem(Request $request, $sessionId, $itemId)
    {
        $validated = $request->validate([
            'qty_picked' => ['required', 'integer', 'min:0'],
            'bin_code' => ['required', 'string', 'max:50'],
        ]);

        try {
            DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $item = PickingSessionItem::findOrFail($itemId);
                $user = auth()->user();

                // Update picking item
                $item->update([
                    'qty_picked' => $validated['qty_picked'],
                    'bin_code' => $validated['bin_code'],
                    'status' => $validated['qty_picked'] >= $item->qty_ordered ? 'COMPLETED' : 'PARTIAL',
                ]);

                // Kurangi stok di stock_actives
                $stock = StockActive::where('sku', $item->sku)
                    ->where('bin_code', $validated['bin_code'])
                    ->first();

                if ($stock) {
                    $oldQty = $stock->qty;
                    $newQty = max(0, $oldQty - $validated['qty_picked']);

                    $stock->update([
                        'qty' => $newQty,
                        'last_movement_at' => now(),
                    ]);

                    // Catat movement
                    StockMovement::create([
                        'user_id' => $user->id,
                        'sku' => $item->sku,
                        'bin_code' => $validated['bin_code'],
                        'type' => 'OUTBOUND',
                        'qty' => $validated['qty_picked'],
                        'qty_before' => $oldQty,
                        'qty_after' => $newQty,
                        'reference_no' => 'PICK-' . $sessionId,
                        'notes' => 'Picking for session ' . $sessionId,
                        'ip_address' => $request->ip(),
                    ]);
                }

                // Cek apakah semua item sudah dipicking
                $session = PickingSession::findOrFail($sessionId);
                $allPicked = $session->items()->where('qty_picked', '<', DB::raw('qty_ordered'))->count() === 0;

                if ($allPicked) {
                    $session->update([
                        'status' => 'COMPLETED',
                        'completed_at' => now(),
                    ]);

                    // Update order status
                    $session->order->update(['status' => 'PICKING_COMPLETED']);
                }
            });

            return back()->with('success', 'Item berhasil dipicking!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal picking item: ' . safe_error_message($e));
        }
    }

    public function destroy($id)
    {
        $session = PickingSession::findOrFail($id);
        $user = auth()->user();

        if ($session->status === 'COMPLETED') {
            return back()->with('error', 'Tidak dapat menghapus session yang sudah selesai.');
        }

        DB::transaction(function () use ($session, $user) {
            $session->items()->delete();
            $session->delete();

            AuditTrail::create([
                'user_id' => $user->id,
                'action' => 'DELETE',
                'module' => 'PICKING',
                'new_values' => json_encode(['session_number' => $session->session_number]),
                'ip_address' => request()->ip(),
            ]);
        });

        return back()->with('success', 'Picking session dihapus!');
    }
}
