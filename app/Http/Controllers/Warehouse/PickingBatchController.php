<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\Order;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Models\PickingSessionItem;
use App\Models\WarehouseRack;
use App\Services\Picking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Picking gabungan: ambil barang untuk beberapa order dalam satu jalan.
 * Daftar ambil digabung per SKU dan diurutkan per bin; setiap scan memberi tahu
 * barang itu masuk keranjang nomor berapa. Tiap order tetap punya sesi picking
 * sendiri, jadi order yang lengkap langsung lanjut ke packing.
 */
class PickingBatchController extends Controller
{
    public function create()
    {
        $orders = self::waitingOrders()
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->get();

        return view('warehouse.picking.batch-create', ['orders' => $orders, 'max' => PickingBatch::MAX_ORDERS]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:2', 'max:' . PickingBatch::MAX_ORDERS],
            'order_ids.*' => ['required', 'string', 'distinct'],
        ], [
            'order_ids.required' => 'Pilih minimal 2 order.',
            'order_ids.min' => 'Pilih minimal 2 order. Untuk satu order, pakai picking biasa.',
            'order_ids.max' => 'Maksimal ' . PickingBatch::MAX_ORDERS . ' order sekali jalan.',
        ]);

        try {
            $batch = DB::transaction(function () use ($validated, $request) {
                $orders = Order::with('items')->whereIn('id', $validated['order_ids'])
                    ->orderBy('created_at')->lockForUpdate()->get();
                if ($orders->count() !== count($validated['order_ids'])) {
                    throw new \RuntimeException('Sebagian order tidak ditemukan. Muat ulang halaman.');
                }

                return self::createBatch($orders, auth()->id(), $request->ip());
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Gagal membuat picking gabungan: ' . $e->getMessage());
        }

        return $this->started($batch);
    }

    /**
     * Gabung otomatis: ambil order yang menunggu picking dengan batas kirim paling dekat,
     * tanpa perlu mencentang satu per satu. Satu order saja langsung jadi picking biasa.
     */
    public function auto(Request $request)
    {
        $validated = $request->validate(['jumlah' => ['nullable', 'integer', 'min:2', 'max:' . PickingBatch::MAX_ORDERS]]);

        $started = DB::transaction(function () use ($validated, $request) {
            $orders = self::waitingOrders()->with('items')->limit($validated['jumlah'] ?? PickingBatch::AUTO_ORDERS)->lockForUpdate()->get();

            if ($orders->count() === 1) {
                return Picking::createForOrder($orders->first(), auth()->id(), ['status' => 'IN_PROGRESS', 'started_at' => now()]);
            }

            return $orders->isEmpty() ? null : self::createBatch($orders, auth()->id(), $request->ip());
        });

        if (!$started) {
            return back()->with('error', 'Tidak ada order yang menunggu picking.');
        }
        if ($started instanceof PickingSession) {
            return redirect()->route('gudang.picking.show', $started->id)->with('success', 'Hanya ada 1 order, langsung picking biasa. Mulai scan barang.');
        }

        return $this->started($started);
    }

    /** Order yang menunggu picking, batas kirim paling dekat dulu */
    public static function waitingOrders()
    {
        return Order::where('status', 'CONFIRMED')
            ->whereDoesntHave('pickingSession')
            ->orderBy('ship_by')
            ->orderBy('created_at');
    }

    /**
     * Buat picking gabungan yang langsung berjalan untuk order-order ini (sudah dikunci).
     * Nomor keranjang mengikuti urutan order. Harus dipanggil di dalam transaksi.
     */
    public static function createBatch($orders, string $userId, ?string $ip): PickingBatch
    {
        // Langsung dimulai: yang membuat picking gabungan adalah yang mengambil barangnya
        $batch = PickingBatch::create(['user_id' => $userId, 'status' => 'IN_PROGRESS', 'started_at' => now()]);
        foreach ($orders->values() as $i => $order) {
            Picking::createForOrder($order, $userId, [
                'batch_id' => $batch->id,
                'basket_no' => $i + 1,
                'status' => 'IN_PROGRESS',
                'started_at' => now(),
            ]);
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'CREATE',
            'module' => 'PICKING',
            'new_values' => json_encode(['batch_number' => $batch->batch_number, 'orders' => $orders->pluck('order_number')]),
            'ip_address' => $ip,
        ]);

        return $batch;
    }

    private function started(PickingBatch $batch)
    {
        return redirect()->route('gudang.picking-batch.show', $batch)
            ->with('success', 'Picking gabungan dibuat. Siapkan keranjang bernomor 1 sampai ' . $batch->sessions()->count() . ', lalu mulai scan.');
    }

    public function show(PickingBatch $batch)
    {
        $batch->load(['user:id,name', 'sessions.order:id,order_number,customer_name,courier', 'sessions.items.masterSku:sku,product_name,size']);

        // Daftar ambil: satu baris per SKU, diurutkan per bin supaya rutenya tidak bolak-balik
        $pickList = $batch->sessions->where('status', '!=', 'CANCELLED')->flatMap(fn ($session) => $session->items->map(fn ($item) => [$session, $item]))
            ->groupBy(fn ($pair) => $pair[1]->sku)
            ->map(function ($pairs, $sku) {
                $items = $pairs->pluck(1);

                return [
                    'sku' => $sku,
                    'name' => trim(($items->first()->masterSku?->product_name ?? '') . ' ' . ($items->first()->masterSku?->size ?? '')),
                    'product' => $items->first()->masterSku?->product_name,
                    'size' => $items->first()->masterSku?->size,
                    'bins' => $items->pluck('bin_code')->unique()->sort()->values(),
                    'qty_ordered' => $items->sum('qty_ordered'),
                    'qty_picked' => $items->sum('qty_picked'),
                    'baskets' => $pairs->map(fn ($pair) => ['no' => $pair[0]->basket_no, 'qty' => $pair[1]->qty_ordered])->sortBy('no')->values(),
                ];
            })
            ->sortBy(fn ($row) => [$row['qty_picked'] >= $row['qty_ordered'] ? 1 : 0, $row['bins']->first(), $row['sku']])
            ->values();

        return view('warehouse.picking.batch-show', compact('batch', 'pickList'));
    }

    /**
     * Scan di layar picking gabungan. Kode lokasi memilih bin; kode SKU mengambil 1 pcs
     * untuk keranjang dengan nomor terkecil yang masih butuh SKU itu.
     */
    public function scan(Request $request, PickingBatch $batch)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'bin_code' => ['nullable', 'string', 'max:50'],
        ]);
        $code = strtoupper(trim($validated['code']));
        $bin = strtoupper(trim($validated['bin_code'] ?? ''));

        try {
            $result = DB::transaction(function () use ($batch, $code, $bin, $request) {
                $batch = PickingBatch::lockForUpdate()->findOrFail($batch->id);
                if ($batch->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException('Picking gabungan ini sudah selesai.');
                }

                if (!MasterSku::where('sku', $code)->exists()) {
                    if (WarehouseRack::isKnownBin($code)) {
                        return ['ok' => true, 'type' => 'bin', 'bin_code' => $code, 'message' => "Lokasi {$code} dipilih. Sekarang scan barangnya."];
                    }
                    throw new \RuntimeException("Kode {$code} tidak dikenal sebagai SKU maupun lokasi rak.");
                }

                $candidates = PickingSessionItem::query()
                    ->join('picking_sessions as ps', 'ps.id', '=', 'picking_session_items.picking_session_id')
                    ->where('ps.batch_id', $batch->id)
                    ->where('ps.status', 'IN_PROGRESS')
                    ->where('picking_session_items.sku', $code)
                    ->whereColumn('picking_session_items.qty_picked', '<', 'picking_session_items.qty_ordered')
                    ->orderBy('ps.basket_no')
                    ->select('picking_session_items.*', 'ps.basket_no')
                    ->get();

                if ($candidates->isEmpty()) {
                    $inBatch = PickingSessionItem::whereIn('picking_session_id', PickingSession::where('batch_id', $batch->id)->select('id'))->where('sku', $code)->exists();
                    throw new \RuntimeException($inBatch ? "SKU {$code} sudah lengkap untuk semua keranjang." : "SKU {$code} tidak dipesan di picking ini. Jangan diambil.");
                }

                // Lanjutkan item yang sudah mulai diambil dari bin ini dulu, lalu keranjang berikutnya.
                // Item yang sudah mulai dari bin lain dilewati karena satu item harus dari satu bin.
                $fits = fn ($item) => $bin === '' || (int) $item->qty_picked === 0 || $item->bin_code === $bin;
                $chosen = $candidates->first(fn ($item) => (int) $item->qty_picked > 0 && $fits($item))
                    ?? $candidates->first($fits)
                    ?? $candidates->first();

                $session = PickingSession::with('order:id,order_number')->lockForUpdate()->findOrFail($chosen->picking_session_id);
                $item = PickingSessionItem::lockForUpdate()->findOrFail($chosen->id);
                [$binCode, $warning] = Picking::resolveBin($item, $bin ?: null);

                Picking::apply($session, $item, $item->qty_picked + 1, $binCode, auth()->id(), $request->ip());

                $item->refresh();
                $session->refresh();
                $batch->refresh();
                $basket = $session->basket_no;

                $message = "{$code} dari {$binCode} → keranjang {$basket} ({$session->order?->order_number}): {$item->qty_picked}/{$item->qty_ordered}";
                if ($session->status === 'COMPLETED') {
                    $message .= ". Keranjang {$basket} lengkap, siap dikemas.";
                }

                return [
                    'ok' => true,
                    'type' => 'sku',
                    'basket' => $basket,
                    'warning' => $warning,
                    'active_bin' => $binCode,
                    'message' => $message . ($warning ? ". {$warning}" : ''),
                    'items' => $this->counters($batch, $session, $code),
                    'completed' => $batch->status === 'COMPLETED',
                ];
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * Ketuk "−1" di daftar ambil: 1 pcs SKU ini yang terakhir masuk keranjang dikembalikan ke raknya.
     * (+1 dari daftar memakai endpoint scan dengan kode SKU.)
     */
    public function undo(Request $request, PickingBatch $batch)
    {
        $validated = $request->validate(['sku' => ['required', 'string', 'max:100']]);
        $sku = strtoupper(trim($validated['sku']));

        try {
            $result = DB::transaction(function () use ($batch, $sku, $request) {
                $batch = PickingBatch::lockForUpdate()->findOrFail($batch->id);

                // Keranjang dengan nomor terbesar dulu, kebalikan dari urutan pengisian saat scan
                $chosen = PickingSessionItem::query()
                    ->join('picking_sessions as ps', 'ps.id', '=', 'picking_session_items.picking_session_id')
                    ->where('ps.batch_id', $batch->id)
                    ->where('ps.status', '!=', 'CANCELLED')
                    ->where('picking_session_items.sku', $sku)
                    ->where('picking_session_items.qty_picked', '>', 0)
                    ->orderByDesc('ps.basket_no')
                    ->select('picking_session_items.*')
                    ->first();
                if (!$chosen) {
                    throw new \RuntimeException("{$sku} belum ada yang diambil.");
                }

                $session = PickingSession::with('order:id,order_number,status')->lockForUpdate()->findOrFail($chosen->picking_session_id);
                $item = PickingSessionItem::lockForUpdate()->findOrFail($chosen->id);
                Picking::unpick($session, $item, auth()->id(), $request->ip());

                $session->refresh();

                return [
                    'ok' => true,
                    'type' => 'undo',
                    'basket' => $session->basket_no,
                    'message' => "Keluarkan 1 pcs {$sku} dari keranjang {$session->basket_no}, kembalikan ke {$item->bin_code}.",
                    'items' => $this->counters($batch, $session, $sku),
                ];
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * "Barang tidak ada" di daftar ambil: SKU ini tidak ditemukan di raknya. Keranjang yang masih
     * butuh SKU ini diarahkan ke rak lain yang ada stoknya, atau order-nya ditunda (Menunggu stok)
     * sementara keranjang lain tetap lanjut.
     */
    public function missing(Request $request, PickingBatch $batch)
    {
        $validated = $request->validate(['sku' => ['required', 'string', 'max:100']]);
        $sku = strtoupper(trim($validated['sku']));

        try {
            $messages = DB::transaction(function () use ($batch, $sku, $request) {
                PickingBatch::lockForUpdate()->findOrFail($batch->id);
                $items = PickingSessionItem::query()
                    ->join('picking_sessions as ps', 'ps.id', '=', 'picking_session_items.picking_session_id')
                    ->where('ps.batch_id', $batch->id)
                    ->where('ps.status', 'IN_PROGRESS')
                    ->where('picking_session_items.sku', $sku)
                    ->whereColumn('picking_session_items.qty_picked', '<', 'picking_session_items.qty_ordered')
                    ->orderBy('ps.basket_no')
                    ->pluck('picking_session_items.id');
                if ($items->isEmpty()) {
                    throw new \RuntimeException("{$sku} sudah lengkap untuk semua keranjang.");
                }

                return $items->map(function ($id) use ($request) {
                    $item = PickingSessionItem::lockForUpdate()->findOrFail($id);
                    $session = PickingSession::with('order')->lockForUpdate()->findOrFail($item->picking_session_id);

                    return Picking::reportMissing($session, $item, auth()->id(), $request->ip())[0];
                })->unique()->implode(' ');
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return PickingBatch::find($batch->id)
            ? redirect()->route('gudang.picking-batch.show', $batch)->with('success', $messages)
            : redirect()->route('gudang.tugas')->with('success', $messages);
    }

    /** Angka di layar yang berubah setelah scan: per SKU, per keranjang, dan total */
    private function counters(PickingBatch $batch, PickingSession $session, string $sku): array
    {
        $batchItems = PickingSessionItem::whereIn('picking_session_id', PickingSession::where('batch_id', $batch->id)->where('status', '!=', 'CANCELLED')->select('id'));

        return [
            ['id' => 'sku-' . $sku, 'qty' => (int) (clone $batchItems)->where('sku', $sku)->sum('qty_picked')],
            ['id' => 'basket-' . $session->basket_no, 'qty' => (int) $session->items()->sum('qty_picked')],
            ['id' => 'total', 'qty' => (int) $batchItems->sum('qty_picked')],
        ];
    }

    /**
     * Batalkan pesanan yang belum lengkap: stoknya dikembalikan dan order kembali ke antrean picking.
     * Keranjang yang sudah lengkap tetap lanjut ke packing.
     */
    public function destroy(Request $request, PickingBatch $batch)
    {
        $cancelled = DB::transaction(function () use ($batch, $request) {
            $sessions = $batch->sessions()->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->lockForUpdate()->get();
            foreach ($sessions as $session) {
                Picking::cancel($session, auth()->id(), $request->ip());
            }

            return $sessions->count();
        });

        if (!$cancelled) {
            return back()->with('error', 'Semua keranjang sudah lengkap, tidak ada yang dibatalkan.');
        }

        return redirect()->route('gudang.picking')
            ->with('success', "{$cancelled} order dikembalikan ke antrean picking dan stoknya dikembalikan ke rak.");
    }
}
