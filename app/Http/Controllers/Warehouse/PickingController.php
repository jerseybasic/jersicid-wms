<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\MasterSku;
use App\Models\WarehouseRack;
use App\Models\AuditTrail;
use App\Services\Picking;
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
                $session = Picking::createForOrder($order, $user->id, ['notes' => $validated['notes'] ?? null]);

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
        // Jumlah manual melewati scan: hanya untuk role pengawas, dan alasannya dicatat
        abort_unless(in_array(auth()->user()->role, Picking::MANUAL_ROLES, true), 403, 'Input jumlah manual hanya untuk Owner, Supervisor, dan Admin Gudang.');

        $validated = $request->validate([
            'qty_picked' => ['required', 'integer', 'min:0'],
            'bin_code' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:200'],
        ], ['reason.required' => 'Tulis alasan input manual (tercatat di riwayat).']);

        try {
            DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $session = PickingSession::lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);
                $before = $item->qty_picked;

                Picking::apply($session, $item, (int) $validated['qty_picked'], trim($validated['bin_code']), auth()->id(), $request->ip());

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'MANUAL_QTY',
                    'module' => 'PICKING',
                    'old_values' => json_encode(['qty_picked' => $before]),
                    'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku, 'qty_picked' => (int) $validated['qty_picked'], 'bin' => trim($validated['bin_code']), 'reason' => $validated['reason']]),
                    'ip_address' => $request->ip(),
                ]);
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

                return $this->pickOne($session, $item, $validated['bin_code'] ?? null, $request);
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * Ketuk barang di daftar: +1 sama dengan scan barangnya (untuk barang yang belum ada
     * barcode-nya), -1 mengembalikan 1 pcs yang salah diambil ke raknya.
     */
    public function tap(Request $request, $sessionId, $itemId)
    {
        $validated = $request->validate([
            'delta' => ['required', 'integer', 'in:1,-1'],
            'bin_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $result = DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $session = PickingSession::lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);

                if ((int) $validated['delta'] === -1) {
                    Picking::unpick($session, $item, auth()->id(), $request->ip());
                    $item->refresh();

                    return [
                        'ok' => true,
                        'type' => 'undo',
                        'message' => "1 pcs {$item->sku} dikembalikan ke {$item->bin_code}: {$item->qty_picked}/{$item->qty_ordered}",
                        'items' => [['id' => $item->id, 'qty' => $item->qty_picked, 'status' => $item->status]],
                    ];
                }

                if ($session->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException('Mulai sesi picking dulu.');
                }
                if ($item->qty_picked >= $item->qty_ordered) {
                    throw new \RuntimeException("{$item->sku} sudah lengkap diambil.");
                }

                return $this->pickOne($session, $item, $validated['bin_code'] ?? null, $request, 'diketuk');
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * "Barang tidak ada": picker tidak menemukan barang di rak sarannya. Picker diarahkan ke rak lain
     * yang masih ada stoknya, atau order ditunda sebagai "Menunggu stok".
     */
    public function missing(Request $request, $sessionId, $itemId)
    {
        try {
            [$message, $newBin, $session] = DB::transaction(function () use ($sessionId, $itemId, $request) {
                $session = PickingSession::with('order')->lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);

                return [...Picking::reportMissing($session, $item, auth()->id(), $request->ip()), $session];
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($newBin) {
            return back()->with('success', $message);
        }

        return $session->batch_id && PickingBatch::find($session->batch_id)
            ? redirect()->route('gudang.picking-batch.show', $session->batch_id)->with('success', $message)
            : redirect()->route('gudang.tugas')->with('success', $message);
    }

    /** Ambil 1 pcs item dari rak yang paling masuk akal, lalu jawaban untuk kotak scan */
    private function pickOne(PickingSession $session, PickingSessionItem $item, ?string $activeBin, Request $request, string $via = 'scan'): array
    {
        [$binCode, $warning] = Picking::resolveBin($item, $activeBin);
        Picking::apply($session, $item, $item->qty_picked + 1, $binCode, auth()->id(), $request->ip());

        if ($via !== 'scan') {
            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'MANUAL_PICK',
                'module' => 'PICKING',
                'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku, 'bin' => $binCode]),
                'ip_address' => $request->ip(),
            ]);
        }

        $item->refresh();
        $session->refresh();

        return [
            'ok' => true,
            'type' => 'sku',
            'warning' => $warning,
            'active_bin' => $binCode,
            'message' => trim("{$item->sku} dari {$binCode}: {$item->qty_picked}/{$item->qty_ordered}. " . ($warning ?? '')),
            'items' => [['id' => $item->id, 'qty' => $item->qty_picked, 'status' => $item->status]],
            'completed' => $session->status === 'COMPLETED',
        ];
    }

    public function destroy($id)
    {
        $session = PickingSession::findOrFail($id);
        $user = auth()->user();

        if (in_array($session->status, ['COMPLETED', 'CANCELLED'], true)) {
            return back()->with('error', 'Tidak dapat menghapus session yang sudah selesai atau dibatalkan.');
        }

        DB::transaction(fn () => Picking::cancel($session, $user->id, request()->ip()));

        return back()->with('success', 'Picking session dihapus!');
    }
}
