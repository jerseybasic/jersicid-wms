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

                $order = Order::with('items')->lockForUpdate()->findOrFail($validated['order_id']);
                if ($order->status !== 'CONFIRMED' || $order->pickingSession()->exists()) {
                    throw new \RuntimeException('Order harus berstatus CONFIRMED dan belum punya sesi picking.');
                }

                // Buat picking session
                $session = PickingSession::create([
                    'order_id' => $validated['order_id'],
                    'user_id' => $user->id,
                    'status' => 'PENDING',
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($order->items as $item) {
                    // Sarankan bin dengan stok terbanyak untuk SKU ini
                    $stock = StockActive::where('sku', $item->sku)
                        ->where('qty', '>', 0)
                        ->orderByDesc('qty')
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
                $session = PickingSession::lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);
                $user = auth()->user();

                if ($session->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException('Mulai sesi picking dulu sebelum mengambil barang.');
                }

                // qty_picked adalah total yang sudah diambil untuk item ini,
                // jadi stok hanya dikurangi sebesar tambahannya.
                $newQty = min((int) $validated['qty_picked'], $item->qty_ordered);
                $delta = $newQty - $item->qty_picked;

                if ($delta < 0) {
                    throw new \RuntimeException('Jumlah yang sudah diambil tidak bisa dikurangi. Hapus sesi picking untuk mengulang.');
                }

                if ($delta > 0) {
                    $stock = StockActive::where('sku', $item->sku)
                        ->where('bin_code', $validated['bin_code'])
                        ->lockForUpdate()
                        ->first();

                    if (!$stock || $stock->qty < $delta) {
                        throw new \RuntimeException("Stok {$item->sku} di bin {$validated['bin_code']} tidak cukup (tersedia " . ($stock?->qty ?? 0) . ').');
                    }

                    $oldQty = $stock->qty;
                    $stock->update([
                        'qty' => $oldQty - $delta,
                        'last_movement_at' => now(),
                    ]);

                    StockMovement::create([
                        'user_id' => $user->id,
                        'sku' => $item->sku,
                        'bin_code' => $validated['bin_code'],
                        'type' => 'OUTBOUND',
                        'qty' => $delta,
                        'qty_before' => $oldQty,
                        'qty_after' => $oldQty - $delta,
                        'reference_no' => $session->session_number,
                        'reference_type' => 'PICKING',
                        'notes' => 'Picking ' . $session->session_number,
                        'ip_address' => $request->ip(),
                    ]);
                }

                $item->update([
                    'qty_picked' => $newQty,
                    'bin_code' => $validated['bin_code'],
                    'status' => $newQty >= $item->qty_ordered ? 'COMPLETED' : 'PARTIAL',
                ]);

                $item->orderItem?->update([
                    'qty_picked' => $newQty,
                    'bin_code' => $validated['bin_code'],
                ]);

                $allPicked = $session->items()->whereColumn('qty_picked', '<', 'qty_ordered')->doesntExist();

                if ($allPicked) {
                    $session->update([
                        'status' => 'COMPLETED',
                        'completed_at' => now(),
                    ]);

                    // Semua barang sudah diambil: order lanjut ke packing
                    $session->order->update([
                        'status' => 'PACKING',
                        'picked_by' => $user->id,
                        'picked_at' => now(),
                    ]);
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
            // Kembalikan stok yang sudah terambil, lalu order kembali ke antrean picking
            foreach ($session->items()->where('qty_picked', '>', 0)->get() as $item) {
                $stock = StockActive::firstOrCreate(
                    ['sku' => $item->sku, 'bin_code' => $item->bin_code],
                    ['qty' => 0, 'reserved_qty' => 0]
                );
                $oldQty = $stock->qty;
                $stock->update(['qty' => $oldQty + $item->qty_picked, 'last_movement_at' => now()]);

                StockMovement::create([
                    'user_id' => $user->id,
                    'sku' => $item->sku,
                    'bin_code' => $item->bin_code,
                    'type' => 'RETURN',
                    'qty' => $item->qty_picked,
                    'qty_before' => $oldQty,
                    'qty_after' => $oldQty + $item->qty_picked,
                    'reference_no' => $session->session_number,
                    'reference_type' => 'PICKING_CANCEL',
                    'notes' => 'Sesi picking dihapus, stok dikembalikan',
                    'ip_address' => request()->ip(),
                ]);

                $item->orderItem?->update(['qty_picked' => 0]);
            }

            $session->order?->update(['status' => 'CONFIRMED']);
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
