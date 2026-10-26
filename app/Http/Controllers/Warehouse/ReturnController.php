<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Services\OrderReprocess;
use App\Services\WaitingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Retur dari pelanggan untuk order yang sudah dikirim.
 * Saat paket retur diterima, staf mengisi jumlah yang masih bagus (masuk lagi ke rak)
 * dan yang rusak (hanya dicatat, tidak menambah stok).
 */
class ReturnController extends Controller
{
    /** Order yang boleh diretur: barangnya sudah keluar dari gudang */
    private const RETURNABLE_STATUSES = ['SHIPPED', 'COMPLETED'];

    /** Role yang boleh mengubah dan menghapus retur */
    private const MANAGER_ROLES = ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'];

    public function index(Request $request)
    {
        $returns = OrderReturn::with(['order:id,order_number,customer_name', 'receiver:id,name'])
            ->withSum('items', 'qty_good')
            ->withSum('items', 'qty_damaged')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('return_number', 'like', "%{$search}%")
                        ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('warehouse.returns.index', compact('returns'));
    }

    /**
     * Form terima retur. ?order=<no. order, no. resi (QR di label kirim), atau id> memilih order; tanpa itu tampil kotak cari.
     */
    public function create(Request $request)
    {
        $order = null;
        $lines = collect();
        $search = trim((string) $request->query('order', ''));

        if ($search !== '') {
            $order = Order::with('items.masterSku')
                ->where(fn ($q) => $q->where('order_number', strtoupper($search))->orWhere('tracking_number', $search)->orWhere('id', $search))
                ->first();

            if (!$order) {
                return redirect()->route('gudang.retur.create')
                    ->withInput(['order' => $search])
                    ->with('error', "Order {$search} tidak ditemukan.");
            }

            if (!in_array($order->status, self::RETURNABLE_STATUSES)) {
                return redirect()->route('gudang.retur.create')
                    ->withInput(['order' => $search])
                    ->with('error', "Order {$order->order_number} belum dikirim ({$order->status_label}), jadi belum bisa diretur.");
            }

            $lines = $this->returnableLines($order);
        }

        return view('warehouse.returns.create', [
            'order' => $order,
            'lines' => $lines,
            'reasons' => OrderReturn::REASONS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'reason' => ['required', 'in:' . implode(',', array_keys(OrderReturn::REASONS))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array'],
            'items.*.qty_good' => ['nullable', 'integer', 'min:0'],
            'items.*.qty_damaged' => ['nullable', 'integer', 'min:0'],
            'items.*.bin_code' => ['nullable', 'string', 'max:50'],
        ]);

        $order = Order::with('items')->findOrFail($validated['order_id']);
        abort_unless(in_array($order->status, self::RETURNABLE_STATUSES), 422, 'Order ini belum dikirim.');

        $lines = $this->returnableLines($order)->keyBy('order_item_id');
        $rows = [];
        $errors = [];

        foreach ($validated['items'] as $orderItemId => $input) {
            $line = $lines->get($orderItemId);
            if (!$line) {
                continue;
            }

            $good = (int) ($input['qty_good'] ?? 0);
            $damaged = (int) ($input['qty_damaged'] ?? 0);
            if ($good + $damaged === 0) {
                continue;
            }

            if ($good + $damaged > $line['returnable']) {
                $errors["items.{$orderItemId}.qty_good"] = "{$line['sku']}: maksimal {$line['returnable']} pcs yang bisa diretur.";
                continue;
            }

            $bin = strtoupper(trim((string) ($input['bin_code'] ?? '')));
            if ($good > 0 && $bin === '') {
                $errors["items.{$orderItemId}.bin_code"] = "{$line['sku']}: isi lokasi rak untuk barang yang masih bagus.";
                continue;
            }

            $rows[] = $line + ['good' => $good, 'damaged' => $damaged, 'bin' => $good > 0 ? $bin : null];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (!$rows) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah barang yang diretur, minimal 1 pcs.']);
        }

        $return = DB::transaction(function () use ($order, $validated, $rows, $request) {
            $return = OrderReturn::create([
                'return_number' => 'RTR-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                'order_id' => $order->id,
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'received_by' => auth()->id(),
            ]);

            foreach ($rows as $row) {
                OrderReturnItem::create([
                    'order_return_id' => $return->id,
                    'order_item_id' => $row['order_item_id'],
                    'sku' => $row['sku'],
                    'qty_good' => $row['good'],
                    'qty_damaged' => $row['damaged'],
                    'bin_code' => $row['bin'],
                ]);

                if ($row['good'] > 0) {
                    $this->putBackToRack($row['sku'], $row['bin'], $row['good'], $return, $order, $request);
                }
            }

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'CREATE',
                'module' => 'RETURN',
                'new_values' => json_encode([
                    'return_number' => $return->return_number,
                    'order_number' => $order->order_number,
                    'qty_good' => array_sum(array_column($rows, 'good')),
                    'qty_damaged' => array_sum(array_column($rows, 'damaged')),
                ]),
                'ip_address' => $request->ip(),
            ]);

            return $return;
        });

        $released = WaitingStock::release(auth()->id(), $request->ip());

        return redirect()->route('gudang.retur.show', $return->id)
            ->with('success', "Retur {$return->return_number} tersimpan. Barang bagus sudah masuk lagi ke stok." . WaitingStock::releasedMessage($released));
    }

    public function show($id)
    {
        $return = OrderReturn::with(['order', 'receiver:id,name', 'items.masterSku'])->findOrFail($id);

        return view('warehouse.returns.show', compact('return'));
    }

    /**
     * Item order beserta jumlah yang masih bisa diretur (dikirim dikurangi retur sebelumnya)
     * dan saran rak: rak asal saat picking, atau rak yang sekarang paling banyak menyimpan SKU itu.
     */
    private function returnableLines(Order $order)
    {
        $alreadyReturned = OrderReprocess::returnedQty($order);

        return $order->items->map(function ($item) use ($alreadyReturned) {
            $suggestedBin = $item->bin_code ?: StockActive::where('sku', $item->sku)->orderByDesc('qty')->value('bin_code');

            return [
                'order_item_id' => $item->id,
                'sku' => $item->sku,
                'product' => trim(($item->masterSku?->product_name ?? '') . ' ' . ($item->masterSku?->size ?? '')),
                'sent' => (int) $item->qty_ordered,
                'returned' => (int) ($alreadyReturned[$item->id] ?? 0),
                'returnable' => max(0, (int) $item->qty_ordered - (int) ($alreadyReturned[$item->id] ?? 0)),
                'suggested_bin' => $suggestedBin,
            ];
        })->values();
    }

    /** Ubah alasan dan catatan retur. Jumlah yang salah diperbaiki dengan menghapus lalu membuat retur baru. */
    public function update(Request $request, $id)
    {
        abort_unless(in_array(auth()->user()->role, self::MANAGER_ROLES, true), 403, 'Hanya Owner, Supervisor, atau Admin Gudang yang bisa mengubah retur.');
        $return = OrderReturn::findOrFail($id);
        $validated = $request->validate([
            'reason' => ['required', 'in:' . implode(',', array_keys(OrderReturn::REASONS))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $old = $return->only('reason', 'notes');
        $return->update($validated);

        AuditTrail::create([
            'user_id' => auth()->id(),
            'action' => 'UPDATE',
            'module' => 'RETURN',
            'old_values' => json_encode($old),
            'new_values' => json_encode(['return_number' => $return->return_number] + $validated),
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', "Retur {$return->return_number} diubah.");
    }

    /**
     * Hapus retur yang salah dibuat: barang bagus yang sudah masuk rak dikeluarkan lagi dari stok.
     * Hanya untuk order yang masih Dikirim/Selesai; retur yang sudah dipakai untuk memproses ulang
     * order tidak bisa dihapus karena barangnya sudah diambil lagi.
     */
    public function destroy(Request $request, $id)
    {
        abort_unless(in_array(auth()->user()->role, self::MANAGER_ROLES, true), 403, 'Hanya Owner, Supervisor, atau Admin Gudang yang bisa menghapus retur.');

        try {
            $return = DB::transaction(function () use ($id, $request) {
                $return = OrderReturn::with('items', 'order')->lockForUpdate()->findOrFail($id);
                $order = $return->order;
                if (!$order || !in_array($order->status, self::RETURNABLE_STATUSES, true)
                    || ($order->shipped_at && $return->created_at->lt($order->shipped_at))) {
                    throw new \RuntimeException("Retur {$return->return_number} tidak bisa dihapus karena order-nya sudah diproses ulang.");
                }

                foreach ($return->items->where('qty_good', '>', 0) as $item) {
                    $stock = StockActive::where('sku', $item->sku)->where('bin_code', $item->bin_code)->lockForUpdate()->first();
                    if (!$stock || $stock->qty < $item->qty_good) {
                        throw new \RuntimeException("Stok {$item->sku} di {$item->bin_code} tinggal " . (int) $stock?->qty . " pcs, kurang dari {$item->qty_good} pcs yang masuk lewat retur ini. Cek dulu barangnya.");
                    }
                    $before = $stock->qty;
                    $stock->update(['qty' => $before - $item->qty_good, 'last_movement_at' => now()]);

                    StockMovement::create([
                        'user_id' => auth()->id(),
                        'sku' => $item->sku,
                        'bin_code' => $item->bin_code,
                        'type' => 'ADJUSTMENT',
                        'qty' => $item->qty_good,
                        'qty_before' => $before,
                        'qty_after' => $before - $item->qty_good,
                        'reference_no' => $return->return_number,
                        'reference_type' => 'ORDER_RETURN_DELETE',
                        'notes' => "Retur {$return->return_number} dihapus, stok dikoreksi",
                        'ip_address' => $request->ip(),
                    ]);
                }

                $return->items()->delete();
                $return->delete();

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'DELETE',
                    'module' => 'RETURN',
                    'new_values' => json_encode(['return_number' => $return->return_number, 'order_number' => $order->order_number, 'qty_good' => $return->items->sum('qty_good')]),
                    'ip_address' => $request->ip(),
                ]);

                return $return;
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('gudang.outbound.show', $return->order_id)
            ->with('success', "Retur {$return->return_number} dihapus dan stoknya dikoreksi.");
    }

    private function putBackToRack(string $sku, string $bin, int $qty, OrderReturn $return, Order $order, Request $request): void
    {
        $stock = StockActive::firstOrCreate(
            ['sku' => $sku, 'bin_code' => $bin],
            ['qty' => 0, 'reserved_qty' => 0]
        );
        $before = $stock->qty;
        $stock->update(['qty' => $before + $qty, 'last_movement_at' => now()]);

        StockMovement::create([
            'user_id' => auth()->id(),
            'sku' => $sku,
            'bin_code' => $bin,
            'type' => 'RETURN',
            'qty' => $qty,
            'qty_before' => $before,
            'qty_after' => $before + $qty,
            'reference_no' => $return->return_number,
            'reference_type' => 'ORDER_RETURN',
            'notes' => "Retur order {$order->order_number}",
            'ip_address' => $request->ip(),
        ]);
    }
}
