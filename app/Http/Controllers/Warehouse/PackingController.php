<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;
use App\Models\PickingSession;
use App\Models\AuditTrail;
use App\Services\Packing;
use App\Services\Picking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackingController extends Controller
{
    /** Jenis masalah yang bisa dilaporkan saat packing */
    public const PROBLEMS = [
        'RUSAK' => 'Rusak / cacat',
        'SALAH' => 'Salah barang / ukuran',
        'KURANG' => 'Tidak ada di keranjang',
    ];

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

                return $session;
            });

            return redirect()->route('gudang.packing.show', $session->id)
                ->with('success', 'Mulai packing. Scan atau ketuk setiap barang yang dimasukkan ke paket.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat packing session: ' . safe_error_message($e));
        }
    }

    public function show($id)
    {
        $session = PackingSession::with(['order', 'items.masterSku', 'user:id,name', 'pickingSession.items'])
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
        // Jumlah manual melewati scan: hanya untuk role pengawas, dan alasannya dicatat
        abort_unless(in_array(auth()->user()->role, Picking::MANUAL_ROLES, true), 403, 'Input jumlah manual hanya untuk Owner, Supervisor, dan Admin Gudang.');

        $validated = $request->validate([
            'qty_packed' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:200'],
        ], ['reason.required' => 'Tulis alasan input manual (tercatat di riwayat).']);

        try {
            DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $session = PackingSession::with('order')->lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);
                $before = $item->qty_packed;

                $this->applyPack($session, $item, (int) $validated['qty_packed']);

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'MANUAL_QTY',
                    'module' => 'PACKING',
                    'old_values' => json_encode(['qty_packed' => $before]),
                    'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku, 'qty_packed' => (int) $validated['qty_packed'], 'reason' => $validated['reason']]),
                    'ip_address' => $request->ip(),
                ]);
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
                    throw new \RuntimeException($session->status === 'CANCELLED' ? Picking::CANCELLED_MESSAGE : 'Mulai sesi packing dulu sebelum scan.');
                }

                $item = $session->items()
                    ->where('sku', $code)
                    ->whereColumn('qty_packed', '<', 'qty_ordered')
                    ->lockForUpdate()
                    ->first();

                if (!$item) {
                    if (!$session->items()->where('sku', $code)->exists() && ($order = Order::findByCode($code))) {
                        throw new \RuntimeException($order->id === $session->order_id
                            ? "Ini label order {$order->order_number} yang sedang dikemas. Sekarang scan barangnya."
                            : "Ini label order lain ({$order->order_number}). Selesaikan packing {$session->order?->order_number} dulu.");
                    }
                    throw new \RuntimeException($session->items()->where('sku', $code)->exists()
                        ? "SKU {$code} sudah lengkap dikemas. Jangan dimasukkan lagi."
                        : "{$code} bukan bagian dari order ini. Jangan dikemas.");
                }

                return $this->packOne($session, $item);
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
            throw new \RuntimeException($session->status === 'CANCELLED' ? Picking::CANCELLED_MESSAGE : 'Mulai sesi packing dulu sebelum mengemas barang.');
        }
        // Setelah lapor masalah, order kembali ke picking sampai barang penggantinya diambil
        if ($qtyPacked > $item->qty_packed && $session->order?->status === 'PICKING') {
            throw new \RuntimeException('Tunggu barang pengganti diambil dulu, lalu lanjutkan packing.');
        }

        $qtyPacked = min($qtyPacked, $item->qty_ordered);

        $item->update([
            'qty_packed' => $qtyPacked,
            'status' => $qtyPacked >= $item->qty_ordered ? 'COMPLETED' : ($qtyPacked > 0 ? 'PARTIAL' : 'PENDING'),
        ]);

        $item->orderItem?->update(['qty_packed' => $qtyPacked]);

        $allPacked = $session->items()->whereColumn('qty_packed', '<', 'qty_ordered')->doesntExist();

        if ($allPacked) {
            $session->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // Semua barang sudah dikemas: order siap diserahkan ke kurir
            $session->order->update([
                'status' => 'READY_TO_SHIP',
                'packed_by' => auth()->id(),
                'packed_at' => now(),
                'courier' => $session->order->courier ?? $session->courier,
            ]);
        }
    }

    /**
     * Ketuk barang di daftar: +1 sama dengan scan barangnya (untuk barang yang belum ada
     * barcode-nya), -1 mengoreksi barang yang salah dimasukkan.
     */
    public function tap(Request $request, $sessionId, $itemId)
    {
        $validated = $request->validate(['delta' => ['required', 'integer', 'in:1,-1']]);

        try {
            $result = DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $session = PackingSession::lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);

                if ($session->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException($session->status === 'CANCELLED' ? Picking::CANCELLED_MESSAGE : 'Packing ini sudah selesai.');
                }

                if ((int) $validated['delta'] === -1) {
                    if ($item->qty_packed < 1) {
                        throw new \RuntimeException("{$item->sku} belum ada yang dikemas.");
                    }
                    $this->applyPack($session, $item, $item->qty_packed - 1);
                    $item->refresh();

                    AuditTrail::create([
                        'user_id' => auth()->id(),
                        'action' => 'PACK_UNDO',
                        'module' => 'PACKING',
                        'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku]),
                        'ip_address' => $request->ip(),
                    ]);

                    return [
                        'ok' => true,
                        'type' => 'undo',
                        'message' => "Keluarkan 1 pcs {$item->sku} dari paket: {$item->qty_packed}/{$item->qty_ordered}",
                        'items' => [['id' => $item->id, 'qty' => $item->qty_packed, 'status' => $item->status]],
                    ];
                }

                if ($item->qty_packed >= $item->qty_ordered) {
                    throw new \RuntimeException("{$item->sku} sudah lengkap dikemas.");
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'MANUAL_PACK',
                    'module' => 'PACKING',
                    'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku]),
                    'ip_address' => $request->ip(),
                ]);

                return $this->packOne($session, $item);
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /** Kemas 1 pcs item lalu jawaban untuk kotak scan */
    private function packOne(PackingSession $session, PackingSessionItem $item): array
    {
        $this->applyPack($session, $item, $item->qty_packed + 1);

        $item->refresh();
        $session->refresh();

        return [
            'ok' => true,
            'type' => 'sku',
            'message' => "{$item->sku}: {$item->qty_packed}/{$item->qty_ordered}",
            'items' => [['id' => $item->id, 'qty' => $item->qty_packed, 'status' => $item->status]],
            'completed' => $session->status === 'COMPLETED',
        ];
    }

    /**
     * "Ada masalah" pada satu barang saat packing: rusak, salah barang/ukuran, atau kurang di keranjang.
     * 1 pcs dikeluarkan, order kembali ke picking untuk mengambil penggantinya, dan packing
     * dilanjutkan setelah pengganti diambil.
     */
    public function problem(Request $request, $sessionId, $itemId)
    {
        $validated = $request->validate(['reason' => ['required', 'in:' . implode(',', array_keys(self::PROBLEMS))]]);

        try {
            [$session, $pickItem] = DB::transaction(function () use ($sessionId, $itemId, $validated, $request) {
                $session = PackingSession::with('order')->lockForUpdate()->findOrFail($sessionId);
                $item = $session->items()->lockForUpdate()->findOrFail($itemId);

                if ($session->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException($session->status === 'CANCELLED' ? Picking::CANCELLED_MESSAGE : 'Packing ini sudah selesai.');
                }
                if ($session->order?->status !== 'PACKING') {
                    throw new \RuntimeException('Order ini sedang menunggu barang pengganti diambil.');
                }

                $picking = PickingSession::with('order', 'batch')->lockForUpdate()->findOrFail($session->picking_session_id);
                $pickItem = $picking->items()->where('order_item_id', $item->order_item_id)->lockForUpdate()->firstOrFail();

                Picking::replaceOne($picking, $pickItem, $validated['reason'], auth()->id(), $request->ip());

                // Barang yang dikeluarkan tidak lagi dihitung sebagai sudah dikemas
                $pickItem->refresh();
                if ($item->qty_packed > $pickItem->qty_picked) {
                    $item->update(['qty_packed' => $pickItem->qty_picked, 'status' => $pickItem->qty_picked > 0 ? 'PARTIAL' : 'PENDING']);
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'PACK_PROBLEM',
                    'module' => 'PACKING',
                    'new_values' => json_encode(['session_number' => $session->session_number, 'sku' => $item->sku, 'reason' => $validated['reason']]),
                    'ip_address' => $request->ip(),
                ]);

                return [$session, $pickItem];
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $aside = ['RUSAK' => 'Sisihkan barang yang rusak. ', 'SALAH' => 'Kembalikan barang yang salah ke raknya. ', 'KURANG' => ''][$validated['reason']];

        return redirect()->route('gudang.packing.show', $session->id)
            ->with('success', "{$aside}Ambil 1 pcs {$pickItem->sku} pengganti di rak {$pickItem->bin_code}, lalu lanjutkan packing.");
    }

    /**
     * Isi nomor resi dari layar packing (scan label BigSeller atau ketik).
     * Resi kosong boleh diisi semua staf; mengganti resi yang sudah ada hanya untuk pengawas.
     */
    public function resi(Request $request, $id)
    {
        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
        ], ['tracking_number.required' => 'Scan atau ketik nomor resinya.']);

        $resi = strtoupper(preg_replace('/\s+/', '', $validated['tracking_number']));

        try {
            $order = DB::transaction(function () use ($id, $resi, $request) {
                $session = PackingSession::lockForUpdate()->findOrFail($id);
                $order = Order::lockForUpdate()->findOrFail($session->order_id);

                if (in_array($order->status, ['SHIPPED', 'COMPLETED', 'CANCELLED'], true)) {
                    throw new \RuntimeException("Order {$order->order_number} sudah {$order->status_label}, resinya tidak bisa diubah di sini.");
                }
                if (filled($order->tracking_number) && !in_array(auth()->user()->role, Picking::MANUAL_ROLES, true)) {
                    throw new \RuntimeException('Resi sudah terisi. Minta Admin Gudang atau Supervisor kalau resinya perlu diganti.');
                }
                // Resi yang sama di order lain = label tertukar
                $other = Order::where('id', '!=', $order->id)
                    ->where('status', '!=', 'CANCELLED')
                    ->whereRaw('UPPER(tracking_number) = ?', [$resi])
                    ->first();
                if ($other) {
                    throw new \RuntimeException("Resi {$resi} sudah dipakai order {$other->order_number}. Cek lagi labelnya, mungkin tertukar.");
                }
                if (Order::whereRaw('UPPER(order_number) = ?', [$resi])->exists()) {
                    throw new \RuntimeException("{$resi} adalah nomor order, bukan resi. Scan barcode resi di label kirim.");
                }

                $before = $order->tracking_number;
                $order->update(['tracking_number' => $resi]);
                $session->update(['tracking_number' => $resi]);

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => $before ? 'CHANGE_RESI' : 'SET_RESI',
                    'module' => 'PACKING',
                    'old_values' => $before ? json_encode(['tracking_number' => $before]) : null,
                    'new_values' => json_encode(['order_number' => $order->order_number, 'tracking_number' => $resi]),
                    'ip_address' => $request->ip(),
                ]);

                return $order;
            });

            return back()->with('success', "Resi {$resi} tersimpan untuk order {$order->order_number}.");
        } catch (\Exception $e) {
            return back()->with('error', safe_error_message($e));
        }
    }

    public function destroy($id)
    {
        $session = PackingSession::findOrFail($id);
        $user = auth()->user();

        if (in_array($session->status, ['COMPLETED', 'CANCELLED'], true)) {
            return back()->with('error', 'Tidak dapat menghapus session yang sudah selesai atau dibatalkan.');
        }

        DB::transaction(function () use ($session, $user) {
            $session->order?->items()->update(['qty_packed' => 0]);
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
