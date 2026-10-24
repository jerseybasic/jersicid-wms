<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingBatch;
use App\Models\PickingSession;
use App\Services\ShipDeadline;

/**
 * Daftar tugas staf gudang dalam satu layar yang nyaman dipakai di HP:
 * pekerjaan yang sedang berjalan, order yang menunggu picking/packing,
 * dan paket yang siap dicetak label kirimnya.
 */
class TaskController extends Controller
{
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
            ->orderBy('created_at')
            ->get();

        $toPick = Order::where('status', 'CONFIRMED')
            ->whereDoesntHave('pickingSession')
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('ship_by')
            ->orderBy('created_at')
            ->get();

        $toPack = Order::where('status', 'PACKING')
            ->whereDoesntHave('packingSession')
            ->with('pickingSession:id,order_id,batch_id,basket_no', 'pickingSession.batch:id,batch_number')
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('ship_by')
            ->orderBy('picked_at')
            ->get();

        $toShip = Order::where('status', 'READY_TO_SHIP')
            ->withSum('items', 'qty_ordered')
            ->orderBy('ship_by')
            ->orderBy('packed_at')
            ->get();

        // Order yang belum dikirim, dihitung per batas kirim (terlambat / harus kirim hari ini)
        $deadlines = Order::whereIn('status', ShipDeadline::OPEN_STATUSES)->get(['id', 'ship_by', 'created_at'])
            ->countBy(fn ($order) => $order->shipStatus());

        $toRefill = ReplenishmentController::pendingCount();

        // Tiap staf punya akun sendiri: pekerjaan orang lain hanya ditampilkan sebagai info
        $mine = fn ($work) => (string) $work->user_id === (string) auth()->id();
        $othersWork = $activeBatches->reject($mine)->map(fn ($batch) => ['type' => 'Picking gabungan', 'title' => $batch->sessions->count() . ' order', 'user' => $batch->user?->name, 'url' => route('gudang.picking-batch.show', $batch)])
            ->concat($activePicking->reject($mine)->map(fn ($session) => ['type' => 'Picking', 'title' => $session->order?->order_number, 'user' => $session->user?->name, 'url' => route('gudang.picking.show', $session->id)]))
            ->concat($activePacking->reject($mine)->map(fn ($session) => ['type' => 'Packing', 'title' => $session->order?->order_number, 'user' => $session->user?->name, 'url' => route('gudang.packing.show', $session->id)]))
            ->values();
        $activeBatches = $activeBatches->filter($mine)->values();
        $activePicking = $activePicking->filter($mine)->values();
        $activePacking = $activePacking->filter($mine)->values();

        return view('warehouse.tasks.index', compact('activePicking', 'activeBatches', 'activePacking', 'othersWork', 'toPick', 'toPack', 'toShip', 'toRefill', 'deadlines'));
    }
}
