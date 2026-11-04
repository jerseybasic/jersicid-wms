<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackingBatch;
use App\Models\PackingSession;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Services\OrderCancellation;
use App\Services\Packing;
use App\Services\OrderItemChange;
use App\Services\ShipDeadline;

/**
 * Daftar tugas staf gudang dalam satu layar yang nyaman dipakai di HP:
 * pekerjaan yang sedang berjalan, order yang menunggu picking/packing,
 * dan paket yang siap dicetak label kirimnya.
 */
class TaskController extends Controller
{
    /**
     * Banyaknya kartu per daftar (yang paling mendesak dulu). Angka di judul tetap jumlah sebenarnya;
     * sisanya dibuka lewat Outbound. Tanpa batas, ratusan kartu dirender setiap kali halaman dibuka.
     */
    public const LIST_LIMIT = 30;

    public function index()
    {
        $activePicking = PickingSession::with(['user:id,name', 'order:id,order_number,customer_name', 'items:id,picking_session_id,bin_code,qty_ordered,qty_picked'])
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->whereNull('batch_id')
            ->orderBy('created_at')
            ->get();

        $activeBatches = PickingBatch::with(['user:id,name', 'sessions.items:id,picking_session_id,qty_ordered,qty_picked'])
            ->where('status', 'IN_PROGRESS')
            ->orderBy('created_at')
            ->get();

        $activePacking = PackingSession::with(['user:id,name', 'order:id,order_number,customer_name', 'items:id,packing_session_id,qty_ordered,qty_packed'])
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->whereNull('packing_batch_id')
            ->orderBy('created_at')
            ->get();

        $activePackingBatches = PackingBatch::with(['user:id,name', 'sessions:id,packing_batch_id,status', 'sessions.items:id,packing_session_id,qty_ordered,qty_packed'])
            ->where('status', 'IN_PROGRESS')
            ->orderBy('created_at')
            ->get();

        $pickQuery = Order::where('status', 'CONFIRMED')->whereDoesntHave('pickingSession');
        $packQuery = Order::where('status', 'PACKING')->whereDoesntHave('packingSession');
        $shipQuery = Order::where('status', 'READY_TO_SHIP');
        $totals = [
            'pick' => (clone $pickQuery)->count(),
            'pack' => (clone $packQuery)->count(),
            'ship' => (clone $shipQuery)->count(),
        ];

        $toPick = $pickQuery
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('ship_by')
            ->orderBy('created_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        $toPack = $packQuery
            ->with('pickingSession:id,order_id,batch_id,basket_no', 'pickingSession.batch:id,batch_number')
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('ship_by')
            ->orderBy('picked_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        // Tombol "Kemas sekaligus": keranjang dari picking gabungan yang sama dikemas di satu layar
        $packTogether = $totals['pack'] >= 2 ? Packing::autoOrders()->count() : 0;

        $toShip = $shipQuery
            ->withSum('items', 'qty_ordered')
            ->orderBy('ship_by')
            ->orderBy('packed_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        $deadlines = self::deadlineCounts();

        $toRefill = ReplenishmentController::pendingCount();
        $toRestock = OrderItemChange::pendingRestockQty();
        $waitingStock = Order::waitingStock()->count();

        // Tiap staf punya akun sendiri: pekerjaan orang lain hanya ditampilkan sebagai info
        $mine = fn ($work) => (string) $work->user_id === (string) auth()->id();
        $othersWork = $activeBatches->reject($mine)->map(fn ($batch) => ['type' => 'Picking gabungan', 'title' => $batch->sessions->count() . ' order', 'user' => $batch->user?->name, 'url' => route('gudang.picking-batch.show', $batch)])
            ->concat($activePicking->reject($mine)->map(fn ($session) => ['type' => 'Picking', 'title' => $session->order?->order_number, 'user' => $session->user?->name, 'url' => route('gudang.picking.show', $session->id)]))
            ->concat($activePacking->reject($mine)->map(fn ($session) => ['type' => 'Packing', 'title' => $session->order?->order_number, 'user' => $session->user?->name, 'url' => route('gudang.packing.show', $session->id)]))
            ->concat($activePackingBatches->reject($mine)->map(fn ($batch) => ['type' => 'Packing gabungan', 'title' => $batch->sessions->count() . ' keranjang', 'user' => $batch->user?->name, 'url' => route('gudang.packing-batch.show', $batch)]))
            ->values();
        $activeBatches = $activeBatches->filter($mine)->values();
        $activePicking = $activePicking->filter($mine)->values();
        $activePacking = $activePacking->filter($mine)->values();
        $activePackingBatches = $activePackingBatches->filter($mine)->values();

        return view('warehouse.tasks.index', compact('activePicking', 'activeBatches', 'activePacking', 'activePackingBatches', 'packTogether', 'othersWork', 'toPick', 'toPack', 'toShip', 'totals', 'toRefill', 'toRestock', 'waitingStock', 'deadlines'));
    }

    /**
     * Order yang belum dikirim per batas kirim (terlambat / harus kirim hari ini), dihitung di database.
     * Hanya order lama tanpa kolom ship_by yang dihitung satu per satu.
     */
    private static function deadlineCounts(): array
    {
        $open = Order::whereIn('status', ShipDeadline::OPEN_STATUSES);
        $counts = [
            ShipDeadline::LATE => (clone $open)->where('ship_by', '<', now())->count(),
            ShipDeadline::TODAY => (clone $open)->whereBetween('ship_by', [now(), now()->endOfDay()])->count(),
        ];
        foreach ((clone $open)->whereNull('ship_by')->get(['id', 'ship_by', 'created_at']) as $order) {
            $status = $order->shipStatus();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }
}
