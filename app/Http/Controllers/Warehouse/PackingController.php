<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;
use App\Models\PickingSession;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackingController extends Controller
{
    public function index(Request $request)
    {
        $query = PackingSession::with(['order', 'user:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('session_number', 'like', "%{$request->search}%");
        }

        $sessions = $query->paginate(20);

        $stats = [
            'pending' => PackingSession::where('status', 'PENDING')->count(),
            'in_progress' => PackingSession::where('status', 'IN_PROGRESS')->count(),
            'completed' => PackingSession::where('status', 'COMPLETED')->count(),
        ];

        return view('warehouse.packing.index', compact('sessions', 'stats'));
    }

    public function create()
    {
        // Ambil order yang sudah selesai picking dan belum ada packing session
        $availableOrders = Order::where('status', 'PICKING_COMPLETED')
            ->whereDoesntHave('packingSession')
            ->with(['items', 'pickingSession'])
            ->orderBy('created_at', 'asc')
            ->get();

        return view('warehouse.packing.create', compact('availableOrders'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'courier' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            DB::transaction(function () use ($validated, $request) {
                $user = auth()->user();
                $order = Order::with('pickingSession')->findOrFail($validated['order_id']);

                $session = PackingSession::create([
                    'order_id' => $validated['order_id'],
                    'picking_session_id' => $order->pickingSession?->id,
                    'user_id' => $user->id,
                    'status' => 'PENDING',
                    'courier' => $validated['courier'],
                    'notes' => $validated['notes'],
                ]);

                // Ambil items dari picking session
                if ($order->pickingSession) {
                    foreach ($order->pickingSession->items as $pickItem) {
                        PackingSessionItem::create([
                            'packing_session_id' => $session->id,
                            'order_item_id' => $pickItem->order_item_id,
                            'sku' => $pickItem->sku,
                            'qty_ordered' => $pickItem->qty_ordered,
                            'qty_packed' => 0,
                            'status' => 'PENDING',
                        ]);
                    }
                }

                $order->update(['status' => 'PACKING']);

                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'CREATE',
                    'module' => 'PACKING',
                    'new_values' => json_encode([
                        'session_number' => $session->session_number,
                        'order_id' => $order->id,
                    ]),
                    'ip_address' => $request->ip(),
                ]);
            });

            return redirect()->route('gudang.packing')
                ->with('success', 'Packing session berhasil dibuat!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat packing session: ' . safe_error_message($e));
        }
    }

    public function show($id)
    {
        $session = PackingSession::with(['order', 'items.masterSku', 'user:id,name', 'pickingSession'])
            ->findOrFail($id);

        return view('warehouse.packing.show', compact('session'));
    }

    public function start($id)
    {
        $session = PackingSession::findOrFail($id);
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
            'module' => 'PACKING',
            'new_values' => json_encode(['session_number' => $session->session_number]),
            'ip_address' => request()->ip(),
        ]);

        return back()->with('success', 'Packing session dimulai!');
    }

    public function packItem(Request $request, $sessionId, $itemId)
    {
        $validated = $request->validate([
            'qty_packed' => ['required', 'integer', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $item = PackingSessionItem::findOrFail($itemId);
                $user = auth()->user();

                $item->update([
                    'qty_packed' => $validated['qty_packed'],
                    'status' => $validated['qty_packed'] >= $item->qty_ordered ? 'COMPLETED' : 'PARTIAL',
                ]);

                $session = PackingSession::findOrFail($sessionId);
                $allPacked = $session->items()->where('qty_packed', '<', DB::raw('qty_ordered'))->count() === 0;

                if ($allPacked) {
                    $session->update([
                        'status' => 'COMPLETED',
                        'completed_at' => now(),
                    ]);

                    $session->order->update(['status' => 'PACKING_COMPLETED']);
                }
            });

            return back()->with('success', 'Item berhasil dipacking!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal packing item: ' . safe_error_message($e));
        }
    }

    public function complete(Request $request, $id)
    {
        $validated = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'package_weight_gram' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($id, $validated, $request) {
                $session = PackingSession::findOrFail($id);
                $user = auth()->user();

                $session->update([
                    'tracking_number' => $validated['tracking_number'],
                    'package_weight_gram' => $validated['package_weight_gram'],
                    'status' => 'COMPLETED',
                    'completed_at' => now(),
                ]);

                $session->order->update([
                    'status' => 'READY_TO_SHIP',
                    'tracking_number' => $validated['tracking_number'],
                    'packed_at' => now(),
                ]);

                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'COMPLETE',
                    'module' => 'PACKING',
                    'new_values' => json_encode([
                        'session_number' => $session->session_number,
                        'tracking_number' => $validated['tracking_number'],
                    ]),
                    'ip_address' => $request->ip(),
                ]);
            });

            return back()->with('success', 'Packing selesai! Order siap dikirim.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyelesaikan packing: ' . safe_error_message($e));
        }
    }

    public function destroy($id)
    {
        $session = PackingSession::findOrFail($id);
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
                'module' => 'PACKING',
                'new_values' => json_encode(['session_number' => $session->session_number]),
                'ip_address' => request()->ip(),
            ]);
        });

        return back()->with('success', 'Packing session dihapus!');
    }
}
