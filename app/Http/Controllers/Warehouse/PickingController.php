<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\MasterSku;
use App\Models\WarehouseRack;
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
            'start_now' => ['nullable', 'boolean'],
        ]);

        try {
            $session = DB::transaction(function () use ($validated, $request) {
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

                // Dari layar Tugas: sesi langsung dimulai dan staf dibawa ke layar scan
                if (!empty($validated['start_now'])) {
                    $session->update(['status' => 'IN_PROGRESS', 'started_at' => now()]);
                }

                return $session;
            });

            if (!empty($validated['start_now'])) {
                return redirect()->route('gudang.picking.show', $session->id)
                    ->with('success', 'Tugas diambil. Mulai scan barang.');
            }

            return redirect()->route('gudang.picking')
                ->with('success', 'Picking session berhasil dibuat!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat picking session: ' . safe_error_message($e));
        }
    }

    public function show($id)
    {
        $session = PickingSession::with(['order', 'items' => fn ($q) => $q->orderBy('bin_code')->orderBy('sku'), 'items.masterSku', 'user:id,name'])
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

                $this->applyPick($session, $item, (int) $validated['qty_picked'], trim($validated['bin_code']), $request);
            });

            return back()->with('success', 'Item berhasil dipicking!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal picking item: ' . safe_error_message($e));
        }
    }

    /**
     * Scan di layar picking. Kode lokasi (mis. A-01-01) memilih bin,
     * kode SKU menambah 1 qty diambil untuk item yang cocok.
     */
    public function scan(Request $request, $id)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'bin_code' => ['nullable', 'string', 'max:50'],
        ]);

        $code = strtoupper(trim($validated['code']));

        try {
            $result = DB::transaction(function () use ($id, $code, $validated, $request) {
                $session = PickingSession::lockForUpdate()->findOrFail($id);

                if ($session->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException('Mulai sesi picking dulu sebelum scan.');
                }

                if (!MasterSku::where('sku', $code)->exists()) {
                    if (WarehouseRack::isKnownBin($code)) {
                        return ['ok' => true, 'type' => 'bin', 'bin_code' => $code, 'message' => "Lokasi {$code} dipilih. Sekarang scan barangnya."];
                    }
                    throw new \RuntimeException("Kode {$code} tidak dikenal sebagai SKU maupun lokasi rak.");
                }

                $item = $session->items()
                    ->where('sku', $code)
                    ->whereColumn('qty_picked', '<', 'qty_ordered')
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    throw new \RuntimeException($session->items()->where('sku', $code)->exists()
                        ? "SKU {$code} sudah lengkap diambil."
                        : "SKU {$code} bukan bagian dari order ini. Jangan diambil.");
                }

                $binCode = strtoupper(trim($validated['bin_code'] ?? '')) ?: $item->bin_code;
                $this->applyPick($session, $item, $item->qty_picked + 1, $binCode, $request);

                $item->refresh();
                $session->refresh();

                return [
                    'ok' => true,
                    'type' => 'sku',
                    'message' => "{$code} dari {$binCode}: {$item->qty_picked}/{$item->qty_ordered}",
                    'items' => [['id' => $item->id, 'qty' => $item->qty_picked, 'status' => $item->status]],
                    'completed' => $session->status === 'COMPLETED',
                ];
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * Set total qty diambil untuk satu item. Stok hanya dikurangi sebesar
     * tambahannya. Harus dipanggil di dalam transaksi dengan sesi dan item terkunci.
     */
    private function applyPick(PickingSession $session, PickingSessionItem $item, int $qtyPicked, string $binCode, Request $request): void
    {
        $user = auth()->user();

        if ($session->status !== 'IN_PROGRESS') {
            throw new \RuntimeException('Mulai sesi picking dulu sebelum mengambil barang.');
        }

        $newQty = min($qtyPicked, $item->qty_ordered);
        $delta = $newQty - $item->qty_picked;

        if ($delta < 0) {
            throw new \RuntimeException('Jumlah yang sudah diambil tidak bisa dikurangi. Hapus sesi picking untuk mengulang.');
        }

        // Satu item diambil dari satu bin, supaya stok bisa dikembalikan ke bin yang benar
        if ($item->qty_picked > 0 && $binCode !== $item->bin_code) {
            throw new \RuntimeException("{$item->sku} sudah mulai diambil dari bin {$item->bin_code}. Lanjutkan dari bin yang sama.");
        }

        if ($delta > 0) {
            $stock = StockActive::where('sku', $item->sku)
                ->where('bin_code', $binCode)
                ->lockForUpdate()
                ->first();

            if (!$stock || $stock->qty < $delta) {
                throw new \RuntimeException("Stok {$item->sku} di bin {$binCode} tidak cukup (tersedia " . ($stock?->qty ?? 0) . ').');
            }

            $oldQty = $stock->qty;
            $stock->update([
                'qty' => $oldQty - $delta,
                'last_movement_at' => now(),
            ]);

            StockMovement::create([
                'user_id' => $user->id,
                'sku' => $item->sku,
                'bin_code' => $binCode,
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
            'bin_code' => $binCode,
            'status' => $newQty >= $item->qty_ordered ? 'COMPLETED' : 'PARTIAL',
        ]);

        $item->orderItem?->update([
            'qty_picked' => $newQty,
            'bin_code' => $binCode,
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
