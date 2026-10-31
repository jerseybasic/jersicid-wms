<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\Order;
use App\Models\PackingBatch;
use App\Models\PackingSession;
use App\Models\PackingSessionItem;
use App\Models\PickingBatch;
use App\Services\Packing;
use App\Services\Picking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Packing gabungan: keranjang-keranjang hasil picking dikemas di satu layar.
 * Scan label/resi keranjang untuk memilihnya, atau langsung scan barang: sistem memberi tahu
 * barang itu masuk paket keranjang nomor berapa. Tiap order tetap punya sesi packing sendiri,
 * jadi paket yang lengkap langsung Siap Kirim walau keranjang lain belum selesai.
 */
class PackingBatchController extends Controller
{
    /** Kemas order-order yang dipilih (dari daftar Outbound atau Tugas) */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:500'],
            'order_ids.*' => ['required', 'string', 'distinct'],
        ], ['order_ids.required' => 'Pilih minimal 1 order yang sudah selesai picking.']);

        return $this->start(fn () => Order::whereIn('id', $validated['order_ids']), $request);
    }

    /** Kemas sekaligus semua keranjang yang menunggu packing dari satu picking gabungan */
    public function fromPicking(Request $request, PickingBatch $pickingBatch)
    {
        return $this->start(fn () => Order::whereHas('pickingSession', fn ($q) => $q->where('batch_id', $pickingBatch->id)), $request);
    }

    /** Kemas sekaligus: keranjang dari picking gabungan yang paling mendesak (lihat Packing::autoOrders) */
    public function auto(Request $request)
    {
        $ids = Packing::autoOrders()->pluck('id');

        return $this->start(fn () => Order::whereIn('id', $ids), $request);
    }

    /**
     * Buat packing gabungan dari order yang siap dikemas di antara $query. Order yang belum selesai picking
     * atau sudah punya sesi packing dilewati. Satu order saja langsung jadi packing biasa.
     */
    private function start(\Closure $query, Request $request)
    {
        $result = DB::transaction(function () use ($query, $request) {
            $candidates = $query()->orderBy('ship_by')->orderBy('picked_at')->orderBy('created_at')->lockForUpdate()->get();
            $ready = $candidates->filter(fn ($order) => $order->status === 'PACKING' && !$order->packingSession()->exists())->values();
            $skipped = $candidates->count() - $ready->count();
            $left = max(0, $ready->count() - PackingBatch::MAX_ORDERS);
            $ready = $ready->take(PackingBatch::MAX_ORDERS);

            if ($ready->isEmpty()) {
                return [null, $skipped, $left];
            }
            if ($ready->count() === 1) {
                $session = Packing::createForOrder($ready->first(), auth()->id(), ['courier' => $ready->first()->courier]);
                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'CREATE',
                    'module' => 'PACKING',
                    'new_values' => json_encode(['session_number' => $session->session_number, 'order_id' => $session->order_id]),
                    'ip_address' => $request->ip(),
                ]);

                return [$session, $skipped, $left];
            }

            return [Packing::createBatch($ready, auth()->id(), $request->ip()), $skipped, $left];
        });

        [$started, $skipped, $left] = $result;
        if (!$started) {
            return back()->with('error', 'Tidak ada order yang siap dikemas. Packing hanya untuk order yang picking-nya sudah selesai (status Siap/Sedang Packing).');
        }

        $notes = collect([
            $skipped ? "{$skipped} order dilewati karena belum selesai picking atau sudah dikemas." : null,
            $left ? "{$left} order lainnya menunggu packing gabungan berikutnya (maksimal " . PackingBatch::MAX_ORDERS . ' sekali jalan).' : null,
        ])->filter()->implode(' ');

        if ($started instanceof PackingSession) {
            return redirect()->route('gudang.packing.show', $started->id)
                ->with('success', trim('Hanya 1 order yang siap dikemas, langsung packing biasa. ' . $notes));
        }

        return redirect()->route('gudang.packing-batch.show', $started)
            ->with('success', trim("Packing gabungan {$started->sessions()->count()} keranjang dibuat. Scan label keranjang atau langsung scan barangnya. " . $notes));
    }

    public function show(PackingBatch $batch)
    {
        $batch->load([
            'user:id,name',
            'sessions.order:id,order_number,customer_name,courier,tracking_number,status,ship_by,created_at,notes',
            'sessions.items.masterSku:sku,product_name,size',
        ]);
        $active = $batch->sessions->firstWhere('id', $batch->active_session_id);
        $labelIds = $batch->sessions->where('status', 'COMPLETED')->pluck('order_id');

        return view('warehouse.packing.batch-show', compact('batch', 'active', 'labelIds'));
    }

    /**
     * Scan di layar packing gabungan. Label/resi order memilih keranjangnya; kode SKU mengemas 1 pcs
     * ke keranjang yang sedang dipilih, atau (bila belum ada) ke keranjang terkecil yang masih butuh SKU itu.
     */
    public function scan(Request $request, PackingBatch $batch)
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $code = strtoupper(trim($validated['code']));

        try {
            $result = DB::transaction(function () use ($batch, $code) {
                $batch = PackingBatch::lockForUpdate()->findOrFail($batch->id);
                if ($batch->status !== 'IN_PROGRESS') {
                    throw new \RuntimeException('Packing gabungan ini sudah selesai.');
                }

                if (!MasterSku::where('sku', $code)->exists()) {
                    return $this->choose($batch, $code);
                }

                $session = $this->sessionFor($batch, $code);
                $session = PackingSession::with('order')->lockForUpdate()->findOrFail($session->id);
                $item = $session->items()->where('sku', $code)->whereColumn('qty_packed', '<', 'qty_ordered')->lockForUpdate()->firstOrFail();

                Packing::apply($session, $item, $item->qty_packed + 1, auth()->id());
                $item->refresh();
                $session->refresh();
                $batch->refresh();

                $order = $session->order;
                $result = [
                    'ok' => true,
                    'type' => 'sku',
                    'message' => "{$code} → paket keranjang {$session->basket_no} ({$order?->order_number}): {$item->qty_packed}/{$item->qty_ordered}",
                    'items' => [
                        ['id' => $item->id, 'qty' => $item->qty_packed],
                        ['id' => 'basket-' . $session->basket_no, 'qty' => (int) $session->items()->sum('qty_packed')],
                        ['id' => 'total', 'qty' => (int) PackingSessionItem::whereIn('packing_session_id', $batch->sessions()->where('status', '!=', 'CANCELLED')->select('id'))->sum('qty_packed')],
                        ['id' => 'active', 'qty' => "Keranjang {$session->basket_no} · {$order?->order_number}"],
                    ],
                ];

                if ($session->status !== 'COMPLETED') {
                    $batch->update(['active_session_id' => $session->id]);

                    return $result;
                }

                // Paket lengkap: tempel labelnya, lalu lanjut ke keranjang berikutnya
                $batch->update(['active_session_id' => null]);
                $done = "Keranjang {$session->basket_no} selesai dikemas. Tempel label " . ($order?->tracking_number ? "resi {$order->tracking_number}" : "order {$order?->order_number} (resi belum ada)") . ', taruh di rak Siap Kirim.';
                session()->flash('success', $done . ($batch->status === 'COMPLETED' ? ' Semua keranjang sudah dikemas.' : ''));

                return array_merge($result, ['message' => $done, 'redirect' => route('gudang.packing-batch.show', $batch)]);
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /** Label order atau resi di keranjang: keranjang itu yang dikemas berikutnya */
    private function choose(PackingBatch $batch, string $code): array
    {
        $order = Order::findByCode($code);
        if (!$order) {
            throw new \RuntimeException("Kode {$code} tidak dikenal sebagai barang maupun label order/resi.");
        }
        $session = $batch->sessions()->where('order_id', $order->id)->first();
        if (!$session) {
            throw new \RuntimeException("Order {$order->order_number} bukan bagian dari packing gabungan ini. Pisahkan keranjangnya.");
        }
        if ($session->status === 'CANCELLED') {
            throw new \RuntimeException(Picking::CANCELLED_MESSAGE);
        }
        if ($session->status === 'COMPLETED') {
            throw new \RuntimeException("Keranjang {$session->basket_no} ({$order->order_number}) sudah selesai dikemas.");
        }

        $batch->update(['active_session_id' => $session->id]);

        return [
            'ok' => true,
            'type' => 'basket',
            'message' => "Keranjang {$session->basket_no} · {$order->order_number} dipilih. Scan barangnya satu per satu.",
            'items' => [['id' => 'active', 'qty' => "Keranjang {$session->basket_no} · {$order->order_number}"]],
        ];
    }

    /**
     * Paket untuk SKU yang di-scan: keranjang yang sedang dikemas bila belum lengkap,
     * atau keranjang dengan nomor terkecil yang masih butuh SKU itu.
     */
    private function sessionFor(PackingBatch $batch, string $sku): PackingSession
    {
        $open = $batch->sessions()->with('order:id,order_number,status')->where('status', 'IN_PROGRESS')->get();
        $needs = fn (PackingSession $session) => $session->items()->where('sku', $sku)->whereColumn('qty_packed', '<', 'qty_ordered')->exists();
        $unfinished = fn (PackingSession $session) => $session->items()->whereColumn('qty_packed', '<', 'qty_ordered')->exists();

        $active = $open->firstWhere('id', $batch->active_session_id);
        if ($active && $unfinished($active)) {
            if ($needs($active)) {
                return $active;
            }
            $other = $open->first(fn ($session) => $session->id !== $active->id && $needs($session));
            throw new \RuntimeException($active->items()->where('sku', $sku)->exists()
                ? "{$sku} sudah lengkap di paket keranjang {$active->basket_no}. Jangan dimasukkan lagi."
                : "{$sku} bukan isi keranjang {$active->basket_no} ({$active->order?->order_number})."
                    . ($other ? " Barang ini untuk keranjang {$other->basket_no}: selesaikan keranjang {$active->basket_no} dulu, atau scan label keranjang {$other->basket_no}." : ' Jangan dikemas.'));
        }

        $session = $open->first(fn ($session) => $session->order?->status === 'PACKING' && $needs($session))
            ?? $open->first($needs);
        if ($session) {
            return $session;
        }

        $inBatch = PackingSessionItem::whereIn('packing_session_id', $batch->sessions()->select('id'))->where('sku', $sku)->exists();
        throw new \RuntimeException($inBatch
            ? "{$sku} sudah lengkap dikemas untuk semua keranjang. Jangan dimasukkan lagi."
            : "{$sku} tidak dipesan di packing gabungan ini. Jangan dikemas.");
    }
}
