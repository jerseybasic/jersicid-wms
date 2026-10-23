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
        $orders = Order::where('status', 'CONFIRMED')
            ->whereDoesntHave('pickingSession')
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('created_at')
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
                $user = auth()->user();
                $orders = Order::with('items')->whereIn('id', $validated['order_ids'])
                    ->orderBy('created_at')->lockForUpdate()->get();
                if ($orders->count() !== count($validated['order_ids'])) {
                    throw new \RuntimeException('Sebagian order tidak ditemukan. Muat ulang halaman.');
                }

                // Langsung dimulai: yang membuat picking gabungan adalah yang mengambil barangnya
                $batch = PickingBatch::create(['user_id' => $user->id, 'status' => 'IN_PROGRESS', 'started_at' => now()]);
                foreach ($orders->values() as $i => $order) {
                    Picking::createForOrder($order, $user->id, [
                        'batch_id' => $batch->id,
                        'basket_no' => $i + 1,
                        'status' => 'IN_PROGRESS',
                        'started_at' => now(),
                    ]);
                }

                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'CREATE',
                    'module' => 'PICKING',
                    'new_values' => json_encode(['batch_number' => $batch->batch_number, 'orders' => $orders->pluck('order_number')]),
                    'ip_address' => $request->ip(),
                ]);

                return $batch;
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Gagal membuat picking gabungan: ' . $e->getMessage());
        }

        return redirect()->route('gudang.picking-batch.show', $batch)
            ->with('success', 'Picking gabungan dibuat. Siapkan keranjang bernomor 1 sampai ' . $batch->sessions()->count() . ', lalu mulai scan.');
    }

    public function show(PickingBatch $batch)
    {
        $batch->load(['user:id,name', 'sessions.order:id,order_number,customer_name,courier', 'sessions.items.masterSku:sku,product_name,size']);

        // Daftar ambil: satu baris per SKU, diurutkan per bin supaya rutenya tidak bolak-balik
        $pickList = $batch->sessions->flatMap(fn ($session) => $session->items->map(fn ($item) => [$session, $item]))
            ->groupBy(fn ($pair) => $pair[1]->sku)
            ->map(function ($pairs, $sku) {
                $items = $pairs->pluck(1);

                return [
                    'sku' => $sku,
                    'name' => trim(($items->first()->masterSku?->product_name ?? '') . ' ' . ($items->first()->masterSku?->size ?? '')),
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
                $binCode = $bin ?: $item->bin_code;

                Picking::apply($session, $item, $item->qty_picked + 1, $binCode, auth()->id(), $request->ip());

                $item->refresh();
                $session->refresh();
                $batch->refresh();
                $basket = $session->basket_no;

                $batchItems = PickingSessionItem::whereIn('picking_session_id', PickingSession::where('batch_id', $batch->id)->select('id'));
                $skuPicked = (int) (clone $batchItems)->where('sku', $code)->sum('qty_picked');
                $totalPicked = (int) $batchItems->sum('qty_picked');
                $basketPicked = (int) $session->items()->sum('qty_picked');

                $message = "{$code} dari {$binCode} → keranjang {$basket} ({$session->order?->order_number}): {$item->qty_picked}/{$item->qty_ordered}";
                if ($session->status === 'COMPLETED') {
                    $message .= ". Keranjang {$basket} lengkap, siap dikemas.";
                }

                return [
                    'ok' => true,
                    'type' => 'sku',
                    'basket' => $basket,
                    'message' => $message,
                    'items' => [
                        ['id' => 'sku-' . $code, 'qty' => $skuPicked],
                        ['id' => 'basket-' . $basket, 'qty' => $basketPicked],
                        ['id' => 'total', 'qty' => $totalPicked],
                    ],
                    'completed' => $batch->status === 'COMPLETED',
                ];
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /**
     * Batalkan pesanan yang belum lengkap: stoknya dikembalikan dan order kembali ke antrean picking.
     * Keranjang yang sudah lengkap tetap lanjut ke packing.
     */
    public function destroy(Request $request, PickingBatch $batch)
    {
        $cancelled = DB::transaction(function () use ($batch, $request) {
            $sessions = $batch->sessions()->where('status', '!=', 'COMPLETED')->lockForUpdate()->get();
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
