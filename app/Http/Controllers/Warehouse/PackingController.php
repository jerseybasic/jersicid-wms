<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;
use App\Models\PickingSession;
use App\Models\AuditTrail;
use App\Services\Packing;
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
        // Order yang picking-nya sudah selesai (status PACKING) dan belum punya sesi packing
        $availableOrders = Order::where('status', 'PACKING')
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
            'start_now' => ['nullable', 'boolean'],
        ]);

        try {
            $session = DB::transaction(function () use ($validated, $request) {
                $user = auth()->user();
                $order = Order::with('pickingSession.items')->lockForUpdate()->findOrFail($validated['order_id']);
                $session = Packing::createForOrder($order, $user->id, [
                    'courier' => $validated['courier'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);

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

                // Dari layar Tugas: sesi langsung dimulai dan staf dibawa ke layar scan
                if (!empty($validated['start_now'])) {
                    $session->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
                }

                return $session;
            });

            if (!empty($validated['start_now'])) {
                return redirect()->route('gudang.packing.show', $session->id)
                    ->with('success', 'Tugas diambil. Mulai scan barang.');
            }

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
            DB::transaction(function () use ($sessionId, $itemId, $validated) {
                $session = PackingSession::lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);

                $this->applyPack($session, $item, (int) $validated['qty_packed']);
            });

            return back()->with('success', 'Item berhasil dipacking!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal packing item: ' . safe_error_message($e));
        }
    }

    /**
     * Scan di layar packing: setiap scan SKU menambah 1 qty dikemas.
     * SKU yang bukan bagian order ditolak supaya barang salah tidak ikut terkirim.
     */
    public function scan(Request $request, $id)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
        ]);

        $code = strtoupper(trim($validated['code']));

        try {
            $result = DB::transaction(function () use ($id, $code) {
                $session = PackingSession::lockForUpdate()->findOrFail($id);

                if ($session->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException('Mulai sesi packing dulu sebelum scan.');
                }

                $item = $session->items()
                    ->where('sku', $code)
                    ->whereColumn('qty_packed', '<', 'qty_ordered')
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    throw new \RuntimeException($session->items()->where('sku', $code)->exists()
                        ? "SKU {$code} sudah lengkap dikemas. Jangan dimasukkan lagi."
                        : "{$code} bukan bagian dari order ini. Jangan dikemas.");
                }

                $this->applyPack($session, $item, $item->qty_packed + 1);

                $item->refresh();
                $session->refresh();

                return [
                    'ok' => true,
                    'type' => 'sku',
                    'message' => "{$code}: {$item->qty_packed}/{$item->qty_ordered}",
                    'items' => [['id' => $item->id, 'qty' => $item->qty_packed, 'status' => $item->status]],
                    'completed' => $session->status === 'COMPLETED',
                ];
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * Set total qty dikemas untuk satu item. Dipanggil di dalam transaksi.
     */
    private function applyPack(PackingSession $session, PackingSessionItem $item, int $qtyPacked): void
    {
        if ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException('Mulai sesi packing dulu sebelum mengemas barang.');
        }

        $qtyPacked = min($qtyPacked, $item->qty_ordered);

        $item->update([
            'qty_packed' => $qtyPacked,
            'status' => $qtyPacked >= $item->qty_ordered ? 'COMPLETED' : 'PARTIAL',
        ]);

        $item->orderItem?->update(['qty_packed' => $qtyPacked]);

        $allPacked = $session->items()->whereColumn('qty_packed', '<', 'qty_ordered')->doesntExist();

        if ($allPacked) {
            $session->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // Semua barang sudah dikemas: order siap dikirim (resi diisi di Outbound)
            $session->order->update([
                'status' => 'READY_TO_SHIP',
                'packed_by' => auth()->id(),
                'packed_at' => now(),
                'courier' => $session->order->courier ?? $session->courier,
            ]);
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
