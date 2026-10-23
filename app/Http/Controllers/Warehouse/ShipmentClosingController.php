<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\ShipmentClosing;
use Illuminate\Http\Request;

/**
 * Tutup kiriman hari ini: ringkasan paket yang sudah diserahkan ke kurir hari ini,
 * paket yang tertinggal untuk besok, lalu catatan bahwa kiriman hari ini sudah ditutup.
 */
class ShipmentClosingController extends Controller
{
    public function index()
    {
        $summary = $this->summary();
        $closing = ShipmentClosing::with('user:id,name')->whereDate('closing_date', today())->first();
        $history = ShipmentClosing::with('user:id,name')->latest('closing_date')->limit(14)->get();

        return view('warehouse.closing.index', $summary + compact('closing', 'history'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $summary = $this->summary();

        if ($summary['openHandovers']->isNotEmpty()) {
            return back()->with('error', 'Masih ada serah terima yang belum diserahkan: '
                . $summary['openHandovers']->pluck('courier')->implode(', ') . '. Selesaikan atau kosongkan dulu.');
        }

        $closing = ShipmentClosing::whereDate('closing_date', today())->first()
            ?? new ShipmentClosing(['closing_date' => today()]);
        $closing->fill([
            'user_id' => auth()->id(),
            'closed_at' => now(),
            'shipped_count' => $summary['shipped']->sum(),
            'left_count' => $summary['left']->sum(),
            'couriers' => $summary['shipped']->all(),
            'notes' => $validated['notes'] ?? null,
        ])->save();

        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => $closing->wasRecentlyCreated ? 'CREATE' : 'UPDATE',
            'module' => 'TUTUP_KIRIMAN',
            'new_values' => json_encode($closing->only(['closing_date', 'shipped_count', 'left_count', 'couriers'])),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('gudang.closing')
            ->with('success', "Kiriman hari ini ditutup: {$closing->shipped_count} paket diserahkan ke kurir.");
    }

    /**
     * Paket diserahkan hari ini per kurir, paket Siap Kirim yang tertinggal per kurir,
     * dan serah terima terbuka yang sudah berisi paket (harus diselesaikan dulu).
     */
    private function summary(): array
    {
        $shipped = CourierHandover::where('status', 'COMPLETED')
            ->whereBetween('completed_at', [today(), today()->endOfDay()])
            ->withCount('items')
            ->get(['id', 'courier'])
            ->groupBy('courier')
            ->map(fn ($handovers) => (int) $handovers->sum('items_count'))
            ->filter()
            ->sortKeys();

        $left = Order::where('status', 'READY_TO_SHIP')
            ->get(['id', 'courier'])
            ->countBy(fn ($order) => CourierHandover::brand($order->courier))
            ->sortKeys();

        $openHandovers = CourierHandover::where('status', 'OPEN')->has('items')->withCount('items')->get();

        return compact('shipped', 'left', 'openHandovers');
    }
}
