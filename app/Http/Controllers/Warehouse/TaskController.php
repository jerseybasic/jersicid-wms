<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingSession;

/**
 * Daftar tugas staf gudang dalam satu layar yang nyaman dipakai di HP:
 * pekerjaan yang sedang berjalan, order yang menunggu picking/packing,
 * dan paket yang siap dicetak label kirimnya.
 */
class TaskController extends Controller
{
    public function index()
    {
        $activePicking = PickingSession::with(['order:id,order_number,customer_name', 'items:id,picking_session_id,bin_code,qty_ordered,qty_picked'])
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->orderBy('created_at')
            ->get();

        $activePacking = PackingSession::with(['order:id,order_number,customer_name', 'items:id,packing_session_id,qty_ordered,qty_packed'])
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->orderBy('created_at')
            ->get();

        $toPick = Order::where('status', 'CONFIRMED')
            ->whereDoesntHave('pickingSession')
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('created_at')
            ->get();

        $toPack = Order::where('status', 'PACKING')
            ->whereDoesntHave('packingSession')
            ->withSum('items', 'qty_ordered')
            ->withCount('items')
            ->orderBy('picked_at')
            ->get();

        $toShip = Order::where('status', 'READY_TO_SHIP')
            ->withSum('items', 'qty_ordered')
            ->orderBy('packed_at')
            ->get();

        return view('warehouse.tasks.index', compact('activePicking', 'activePacking', 'toPick', 'toPack', 'toShip'));
    }
}
