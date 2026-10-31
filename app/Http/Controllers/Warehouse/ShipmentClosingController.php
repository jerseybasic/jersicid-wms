<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\ShipmentClosing;
use App\Services\Picking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tutup kiriman hari ini: ringkasan paket yang sudah diserahkan ke kurir hari ini,
 * paket yang tertinggal untuk besok, lalu catatan bahwa kiriman hari ini sudah ditutup.
 * Order yang belum mulai diproses (belum ada barang yang diambil) dikembalikan ke Pending.
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

        [$closing, $pending] = DB::transaction(function () use ($summary, $validated, $request) {
            $pending = $this->returnToPending($request);

            $closing = ShipmentClosing::whereDate('closing_date', today())->first()
                ?? new ShipmentClosing(['closing_date' => today()]);
            $closing->fill([
                'user_id' => auth()->id(),
                'closed_at' => now(),
                'shipped_count' => $summary['shipped']->sum(),
                'left_count' => $summary['left']->sum(),
                // Tutup ulang di hari yang sama menambah, bukan menimpa, jumlah yang sudah dikembalikan
                'pending_count' => ($closing->pending_count ?? 0) + $pending,
                'couriers' => $summary['shipped']->all(),
                'notes' => $validated['notes'] ?? null,
            ])->save();

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => $closing->wasRecentlyCreated ? 'CREATE' : 'UPDATE',
                'module' => 'TUTUP_KIRIMAN',
                'new_values' => json_encode($closing->only(['closing_date', 'shipped_count', 'left_count', 'couriers']) + ['returned_to_pending' => $pending]),
                'ip_address' => $request->ip(),
            ]);

            return [$closing, $pending];
        });

        $message = "Kiriman hari ini ditutup: {$closing->shipped_count} paket diserahkan ke kurir.";
        if ($pending) {
            $message .= " {$pending} order yang belum diproses dikembalikan ke Pending untuk besok.";
        }

        return redirect()->route('gudang.closing')->with('success', $message);
    }

    /**
     * Order yang belum diproses sampai akhir hari: Siap Picking yang belum diambil, atau picking yang
     * sudah dibuat tapi belum ada satu barang pun yang diambil. Barangnya masih di rak, jadi aman dikembalikan.
     */
    public static function unprocessedOrders()
    {
        return Order::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', 'CONFIRMED')->whereDoesntHave('pickingSession'))
            ->orWhere(fn ($q) => $q->where('status', 'PICKING')
                ->whereHas('pickingSession', fn ($s) => $s->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                    ->whereDoesntHave('items', fn ($i) => $i->where('qty_picked', '>', 0)))))
            ->orderBy('ship_by')
            ->orderBy('created_at');
    }

    /** Kembalikan order yang belum diproses ke Pending. Mengembalikan jumlah order. Dipanggil di dalam transaksi. */
    private function returnToPending(Request $request): int
    {
        $orders = self::unprocessedOrders()->with('pickingSession')->lockForUpdate()->get();

        foreach ($orders as $order) {
            $oldStatus = $order->status;
            // Picking yang belum mengambil apa pun dihapus; tidak ada stok yang perlu dikembalikan
            if ($order->pickingSession) {
                Picking::cancel($order->pickingSession, auth()->id(), $request->ip());
            }
            $order->refresh()->update(['status' => 'PENDING', 'picked_by' => null]);

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'CARRY_OVER',
                'module' => 'OUTBOUND',
                'old_values' => json_encode(['status' => $oldStatus]),
                'new_values' => json_encode(['status' => 'PENDING', 'order_number' => $order->order_number, 'reason' => 'Belum diproses saat tutup kiriman ' . today()->format('d/m/Y')]),
                'ip_address' => $request->ip(),
            ]);
        }

        return $orders->count();
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

        // Akan dikembalikan ke Pending saat ditutup, dan yang masih di tengah picking/packing (tidak diubah)
        $unprocessed = self::unprocessedOrders()->get(['id', 'order_number', 'customer_name', 'status', 'ship_by', 'created_at']);
        $inProcess = Order::whereIn('status', ['PICKING', 'PACKING'])->whereNotIn('id', $unprocessed->pluck('id'))->count();

        return compact('shipped', 'left', 'openHandovers', 'unprocessed', 'inProcess');
    }
}
