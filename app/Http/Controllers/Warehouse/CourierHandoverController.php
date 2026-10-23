<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\CourierHandoverItem;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Serah terima paket ke kurir: pilih kurir, scan resi setiap paket yang diserahkan,
 * lalu semua paket di daftar ditandai Dikirim dan manifestnya dicetak untuk ditandatangani.
 */
class CourierHandoverController extends Controller
{
    public function index()
    {
        $open = CourierHandover::with('user:id,name')->withCount('items')
            ->where('status', 'OPEN')->orderBy('created_at')->get();

        // Paket Siap Kirim per kurir, yang belum masuk serah terima mana pun
        $couriers = Order::where('status', 'READY_TO_SHIP')->whereDoesntHave('handoverItem')
            ->get(['id', 'courier'])
            ->countBy(fn ($order) => CourierHandover::brand($order->courier))
            ->sortKeys();

        $history = CourierHandover::with('user:id,name')->withCount('items')
            ->where('status', 'COMPLETED')->latest('completed_at')->limit(20)->get();

        return view('warehouse.handover.index', compact('open', 'couriers', 'history'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['courier' => ['required', 'string', 'max:50']]);
        $courier = $validated['courier'] === CourierHandover::NO_COURIER
            ? CourierHandover::NO_COURIER
            : CourierHandover::brand($validated['courier']);

        // Satu serah terima terbuka per kurir, supaya paket tidak tercecer di dua daftar
        $handover = CourierHandover::where('status', 'OPEN')->where('courier', $courier)->first()
            ?? CourierHandover::create(['courier' => $courier, 'user_id' => auth()->id(), 'status' => 'OPEN']);

        return redirect()->route('gudang.handover.show', $handover);
    }

    public function show(CourierHandover $handover)
    {
        if ($handover->status === 'COMPLETED') {
            return redirect()->route('gudang.handover.print', $handover);
        }

        $orders = CourierHandover::readyOrders($handover->courier, $handover->id);
        $scannedIds = $handover->items()->pluck('order_id')->flip();

        return view('warehouse.handover.show', compact('handover', 'orders', 'scannedIds'));
    }

    /**
     * Scan resi (atau nomor order) paket yang diserahkan ke kurir.
     */
    public function scan(Request $request, CourierHandover $handover)
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $code = strtoupper(trim($validated['code']));

        try {
            $result = DB::transaction(function () use ($handover, $code) {
                $handover = CourierHandover::lockForUpdate()->findOrFail($handover->id);
                if ($handover->status !== 'OPEN') {
                    throw new \RuntimeException('Serah terima ini sudah selesai.');
                }

                $order = Order::with('handoverItem.handover:id,handover_number,courier')
                    ->withSum('items', 'qty_ordered')
                    ->where(fn ($q) => $q->whereRaw('UPPER(tracking_number) = ?', [$code])->orWhereRaw('UPPER(order_number) = ?', [$code]))
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    throw new \RuntimeException("Resi atau nomor order {$code} tidak ditemukan.");
                }
                if ($order->status === 'SHIPPED' || $order->status === 'COMPLETED') {
                    throw new \RuntimeException("{$order->order_number} sudah dikirim sebelumnya" . ($order->shipped_at ? ' (' . $order->shipped_at->format('d/m H:i') . ')' : '') . '.');
                }
                if ($order->status !== 'READY_TO_SHIP') {
                    throw new \RuntimeException("{$order->order_number} belum siap kirim (status: {$order->status_label}). Jangan diserahkan.");
                }
                if (($brand = CourierHandover::brand($order->courier)) !== $handover->courier) {
                    throw new \RuntimeException("{$order->order_number} untuk kurir {$brand}, bukan {$handover->courier}. Pisahkan paket ini.");
                }
                if (blank($order->tracking_number)) {
                    throw new \RuntimeException("{$order->order_number} belum punya nomor resi. Isi resinya dulu di Outbound.");
                }
                if ($item = $order->handoverItem) {
                    throw new \RuntimeException($item->courier_handover_id === $handover->id
                        ? "{$order->order_number} sudah ada di daftar."
                        : "{$order->order_number} sudah masuk serah terima {$item->handover?->handover_number}.");
                }

                CourierHandoverItem::create(['courier_handover_id' => $handover->id, 'order_id' => $order->id]);
                $count = $handover->items()->count();

                return [
                    'ok' => true,
                    'type' => 'order',
                    'message' => "✓ {$order->order_number} · {$order->tracking_number} · " . (int) $order->items_sum_qty_ordered . " pcs. Total {$count} paket.",
                    'items' => [
                        ['id' => 'count', 'qty' => $count],
                        ['id' => 'row-' . $order->id, 'qty' => 'Sudah di-scan'],
                    ],
                ];
            });

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Keluarkan paket dari daftar (misalnya kurir menolak paketnya) */
    public function removeItem(CourierHandover $handover, Order $order)
    {
        abort_unless($handover->status === 'OPEN', 422, 'Serah terima ini sudah selesai.');
        $handover->items()->where('order_id', $order->id)->delete();

        return back()->with('success', "{$order->order_number} dikeluarkan dari daftar.");
    }

    /**
     * Serahkan: semua paket di daftar menjadi Dikirim, lalu buka manifest untuk dicetak.
     */
    public function finish(Request $request, CourierHandover $handover)
    {
        $validated = $request->validate(['driver_name' => ['nullable', 'string', 'max:100']]);

        try {
            $count = DB::transaction(function () use ($handover, $validated, $request) {
                $handover = CourierHandover::lockForUpdate()->findOrFail($handover->id);
                if ($handover->status !== 'OPEN') {
                    throw new \RuntimeException('Serah terima ini sudah selesai.');
                }

                $orders = Order::whereIn('id', $handover->items()->select('order_id'))->lockForUpdate()->get();
                if ($orders->isEmpty()) {
                    throw new \RuntimeException('Belum ada paket yang di-scan.');
                }
                if ($notReady = $orders->where('status', '!=', 'READY_TO_SHIP')->pluck('order_number')->implode(', ')) {
                    throw new \RuntimeException("Status order berubah: {$notReady}. Keluarkan dari daftar dulu.");
                }

                $now = now();
                foreach ($orders as $order) {
                    $order->update(['status' => 'SHIPPED', 'shipped_at' => $now]);
                }
                $handover->update([
                    'status' => 'COMPLETED',
                    'completed_at' => $now,
                    'driver_name' => $validated['driver_name'] ?? null,
                ]);

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'HANDOVER',
                    'module' => 'OUTBOUND',
                    'new_values' => json_encode([
                        'handover_number' => $handover->handover_number,
                        'courier' => $handover->courier,
                        'orders' => $orders->pluck('order_number'),
                    ]),
                    'ip_address' => $request->ip(),
                ]);

                return $orders->count();
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('gudang.handover.print', $handover)
            ->with('success', "{$count} paket diserahkan ke {$handover->courier} dan berstatus Dikirim.");
    }

    public function print(CourierHandover $handover)
    {
        $handover->load(['user:id,name', 'orders' => fn ($q) => $q->withSum('items', 'qty_ordered')->orderBy('order_number')]);

        return view('warehouse.handover.print', compact('handover'));
    }

    /** Batalkan serah terima yang belum diserahkan. Status paket tidak berubah. */
    public function destroy(CourierHandover $handover)
    {
        abort_unless($handover->status === 'OPEN', 422, 'Serah terima yang sudah selesai tidak bisa dibatalkan.');
        $handover->delete();

        return redirect()->route('gudang.handover')->with('success', "Serah terima {$handover->handover_number} dibatalkan. Paketnya tetap Siap Kirim.");
    }
}
