<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Services\ShipDeadline;
use App\Services\StockAvailability;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboundController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with('items.masterSku')->orderBy('created_at', 'desc');

        // Filter Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%");
            });
        }

        // Filter batas kirim: hanya order yang belum dikirim, diurutkan dari yang paling mendesak
        if (in_array($request->batas, ['terlambat', 'hari-ini'], true)) {
            $query->whereIn('status', ShipDeadline::OPEN_STATUSES)
                ->reorder('ship_by')->orderBy('created_at');
            $request->batas === 'terlambat'
                ? $query->where('ship_by', '<', now())
                : $query->whereBetween('ship_by', [now(), now()->endOfDay()]);
        }

        $perPage = in_array((int) $request->per_page, [15, 50, 100], true) ? (int) $request->per_page : 15;
        $orders = $query->paginate($perPage)->withQueryString();

        // Stats
        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', 'PENDING')->count(),
            'picking' => Order::whereIn('status', ['CONFIRMED', 'PICKING'])->count(),
            'packing' => Order::whereIn('status', ['PACKING', 'READY_TO_SHIP'])->count(),
            'ready' => Order::where('status', 'READY_TO_SHIP')->count(),
        ];

        return view('warehouse.outbound.index', compact('orders', 'stats'));
    }

    /**
     * Detail satu order: data pembeli, item, dan riwayat prosesnya dari masuk sampai dikirim/diretur.
     */
    public function show($id)
    {
        $order = Order::with([
            'items.masterSku:sku,product_name,size',
            'creator:id,name',
            'pickingSession.user:id,name', 'pickingSession.batch:id,batch_number', 'pickingSession.items:id,picking_session_id,order_item_id,bin_code',
            'packingSession.user:id,name',
            'handoverItem.handover.user:id,name',
            'returns.receiver:id,name',
        ])->where('id', $id)->orWhere('order_number', $id)->firstOrFail();

        // Bin asal tiap item, dari sesi picking
        $bins = $order->pickingSession?->items->groupBy('order_item_id')->map(fn ($rows) => $rows->pluck('bin_code')->unique()->implode(', ')) ?? collect();

        return view('warehouse.outbound.show', [
            'order' => $order,
            'bins' => $bins,
            'timeline' => $this->timeline($order),
        ]);
    }

    /** Langkah-langkah order, diurutkan dari yang paling awal */
    private function timeline(Order $order): \Illuminate\Support\Collection
    {
        $steps = collect([[
            'at' => $order->created_at,
            'title' => 'Order masuk',
            'detail' => str_starts_with((string) $order->notes, 'Import BigSeller') ? strtok($order->notes, "\n") : 'Dibuat manual',
            'user' => $order->creator?->name,
            'color' => 'slate',
        ]]);

        // Perubahan status manual (konfirmasi, batal, kirim, selesai) dari audit trail
        $labels = ['CONFIRMED' => ['Dikonfirmasi', 'blue'], 'CANCELLED' => ['Dibatalkan', 'red'], 'SHIPPED' => ['Dikirim', 'emerald'], 'COMPLETED' => ['Selesai', 'slate']];
        AuditTrail::with('user:id,name')
            ->where('module', 'OUTBOUND')->whereIn('action', ['UPDATE_STATUS', 'UPDATE'])
            ->where('new_values', 'like', '%' . $order->order_number . '%')
            ->orderBy('created_at')
            ->get()
            ->each(function ($trail) use ($order, $labels, $steps) {
                $values = is_string($trail->new_values) ? json_decode($trail->new_values, true) : $trail->new_values;
                if (($values['order_number'] ?? null) !== $order->order_number) {
                    return;
                }
                if ($trail->action === 'UPDATE') {
                    $names = ['customer_name' => 'nama', 'customer_phone' => 'telepon', 'shipping_address' => 'alamat', 'courier' => 'kurir',
                        'tracking_number' => 'resi', 'notes' => 'catatan', 'ship_by' => 'batas kirim', 'items' => 'item'];
                    $steps->push([
                        'at' => $trail->created_at,
                        'title' => 'Data order diubah',
                        'detail' => 'Diubah: ' . collect(array_keys($values))->map(fn ($key) => $names[$key] ?? null)->filter()->implode(', '),
                        'user' => $trail->user?->name,
                        'color' => 'amber',
                    ]);

                    return;
                }
                if (!isset($labels[$values['status'] ?? ''])) {
                    return;
                }
                [$title, $color] = $labels[$values['status']];
                $steps->push([
                    'at' => $trail->created_at,
                    'title' => $title,
                    'detail' => !empty($values['tracking_number']) ? 'Resi ' . $values['tracking_number'] . (!empty($values['courier']) ? " · {$values['courier']}" : '') : null,
                    'user' => $trail->user?->name,
                    'color' => $color,
                ]);
            });

        if ($picking = $order->pickingSession) {
            $where = $picking->batch ? "Picking gabungan {$picking->batch->batch_number}, keranjang {$picking->basket_no}" : $picking->session_number;
            $steps->push(['at' => $picking->started_at ?? $picking->created_at, 'title' => 'Mulai diambil (picking)', 'detail' => $where, 'user' => $picking->user?->name, 'color' => 'purple', 'url' => route('gudang.picking.show', $picking->id)]);
            if ($picking->completed_at) {
                $steps->push(['at' => $picking->completed_at, 'title' => 'Selesai diambil', 'detail' => null, 'user' => $picking->user?->name, 'color' => 'purple']);
            }
        }

        if ($packing = $order->packingSession) {
            $steps->push(['at' => $packing->started_at ?? $packing->created_at, 'title' => 'Mulai dikemas (packing)', 'detail' => $packing->session_number, 'user' => $packing->user?->name, 'color' => 'cyan', 'url' => route('gudang.packing.show', $packing->id)]);
            if ($packing->completed_at) {
                $steps->push(['at' => $packing->completed_at, 'title' => 'Selesai dikemas', 'detail' => $packing->package_weight_gram ? number_format($packing->package_weight_gram) . ' gram' : null, 'user' => $packing->user?->name, 'color' => 'cyan']);
            }
        }

        if ($handover = $order->handoverItem?->handover) {
            $steps->push(['at' => $order->handoverItem->created_at, 'title' => 'Discan untuk serah terima kurir', 'detail' => $handover->handover_number . ($handover->driver_name ? " · kurir {$handover->driver_name}" : ''), 'user' => $handover->user?->name, 'color' => 'emerald']);
        }

        // Order lama tanpa audit "Dikirim" tetap punya waktu kirim
        if ($order->shipped_at && !$steps->contains('title', 'Dikirim')) {
            $steps->push(['at' => $order->shipped_at, 'title' => 'Dikirim', 'detail' => $order->tracking_number ? "Resi {$order->tracking_number}" : null, 'user' => null, 'color' => 'emerald']);
        }

        foreach ($order->returns as $return) {
            $steps->push(['at' => $return->created_at, 'title' => 'Retur diterima', 'detail' => "{$return->return_number} · {$return->reason_label}", 'user' => $return->receiver?->name, 'color' => 'orange', 'url' => route('gudang.retur.show', $return->id)]);
        }

        // Urut waktu; langkah di detik yang sama mengikuti urutan alur order
        $rank = array_flip(['Order masuk', 'Dikonfirmasi', 'Dibatalkan', 'Mulai diambil (picking)', 'Selesai diambil', 'Mulai dikemas (packing)',
            'Selesai dikemas', 'Discan untuk serah terima kurir', 'Dikirim', 'Selesai', 'Retur diterima', 'Data order diubah']);

        return $steps->filter(fn ($step) => $step['at'])
            ->sortBy([fn ($a, $b) => [$a['at']->getTimestamp(), $rank[$a['title']] ?? 99] <=> [$b['at']->getTimestamp(), $rank[$b['title']] ?? 99]])
            ->values();
    }

    public function create()
    {
        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size']);

        return view('warehouse.outbound.create', compact('skus'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'shipping_address' => ['required', 'string'],
            'courier' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'exists:master_skus,sku'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                $order = Order::create([
                    'id' => (string) Str::uuid(),
                    'order_number' => 'ORD-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'customer_name' => $validated['customer_name'],
                    'customer_phone' => $validated['customer_phone'] ?? null,
                    'shipping_address' => $validated['shipping_address'],
                    'courier' => $validated['courier'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => 'PENDING',
                    'created_by' => auth()->id(),
                ]);

                foreach ($validated['items'] as $item) {
                    $skuData = MasterSku::where('sku', $item['sku'])->first();

                    OrderItem::create([
                        'id' => (string) Str::uuid(),
                        'order_id' => $order->id,
                        'sku' => $item['sku'],
                        'qty_ordered' => $item['qty'],
                        'qty_picked' => 0,
                        'qty_packed' => 0,
                        'price' => $skuData?->price ?? 0,
                    ]);
                }
            });

            return redirect()->route('gudang.outbound')->with('success', 'Order berhasil dibuat!');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal membuat order: ' . safe_error_message($e));
        }
    }

    public function edit($id)
    {
        $order = Order::with('items.masterSku:sku,product_name,size', 'pickingSession:id,order_id')->findOrFail($id);
        abort_unless($order->isEditable(), 403, 'Order yang sudah selesai atau dibatalkan tidak bisa diubah.');

        // SKU yang sudah ada di order tetap bisa dipilih walau sudah tidak aktif
        $skus = MasterSku::where(fn ($query) => $query->where('status', 'AKTIF')->orWhereIn('sku', $order->items->pluck('sku')))
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size']);

        return view('warehouse.outbound.create', compact('order', 'skus'));
    }

    /**
     * Ubah data order. Data pembeli, kurir, resi, catatan, dan batas kirim bisa diubah sampai order selesai;
     * item hanya bisa diubah sebelum picking dimulai.
     */
    public function update(Request $request, $id)
    {
        $order = Order::with('items', 'pickingSession:id,order_id')->findOrFail($id);
        abort_unless($order->isEditable(), 403, 'Order yang sudah selesai atau dibatalkan tidak bisa diubah.');

        $itemsEditable = $order->itemsEditable();
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'shipping_address' => ['required', 'string'],
            'courier' => ['nullable', 'string', 'max:50'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'ship_by' => ['nullable', 'date'],
            'items' => [$itemsEditable ? 'required' : 'prohibited', 'array', 'min:1'],
            'items.*.sku' => ['required', 'exists:master_skus,sku'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ], [
            'items.prohibited' => 'Item tidak bisa diubah karena order sudah mulai di-picking.',
        ]);

        $fields = ['customer_name', 'customer_phone', 'shipping_address', 'courier', 'tracking_number', 'notes'];
        $data = collect($fields)->mapWithKeys(fn ($field) => [$field => $validated[$field] ?? null])->all();
        $data['ship_by'] = !empty($validated['ship_by']) ? Carbon::parse($validated['ship_by']) : $order->ship_by;

        // Item digabung per SKU; harga lama dipakai untuk SKU yang sudah ada di order
        $newItems = $itemsEditable
            ? collect($validated['items'])->groupBy(fn ($item) => strtoupper($item['sku']))->map(fn ($rows) => (int) $rows->sum('qty'))
            : null;
        $oldItems = $order->items->groupBy(fn ($item) => strtoupper($item->sku))->map(fn ($rows) => (int) $rows->sum('qty_ordered'));

        $changes = [];
        foreach ($data as $field => $value) {
            $old = $order->{$field};
            $same = $field === 'ship_by' ? $old?->equalTo($value) ?? $value === null : (string) $old === (string) $value;
            if (!$same) {
                $changes[$field] = [$field === 'ship_by' ? $old?->format('Y-m-d H:i') : $old, $field === 'ship_by' ? $value?->format('Y-m-d H:i') : $value];
            }
        }
        $itemsChanged = $newItems !== null && $newItems->sortKeys()->all() !== $oldItems->sortKeys()->all();
        if ($itemsChanged) {
            $changes['items'] = [$oldItems->all(), $newItems->all()];
        }

        if (!$changes) {
            return redirect()->route('gudang.outbound.show', $order->id)->with('success', 'Tidak ada perubahan.');
        }

        try {
            DB::transaction(function () use ($order, $data, $itemsChanged, $newItems, $changes, $request) {
                $order->update($data);

                if ($itemsChanged) {
                    $prices = $order->items->mapWithKeys(fn ($item) => [strtoupper($item->sku) => $item->price]);
                    $catalog = MasterSku::whereIn('sku', $newItems->keys())->get(['sku', 'price'])->keyBy(fn ($sku) => strtoupper($sku->sku));
                    $order->items()->delete();
                    foreach ($newItems as $sku => $qty) {
                        OrderItem::create([
                            'order_id' => $order->id,
                            'sku' => $catalog[$sku]->sku ?? $sku,
                            'qty_ordered' => $qty,
                            'qty_picked' => 0,
                            'qty_packed' => 0,
                            'price' => $prices[$sku] ?? $catalog[$sku]->price ?? 0,
                        ]);
                    }

                    // Order yang sudah dikonfirmasi harus tetap bisa dipenuhi stoknya
                    if ($order->status === 'CONFIRMED' && $shortages = StockAvailability::shortages($order)) {
                        throw new \RuntimeException('Stok tidak cukup untuk item baru: ' . StockAvailability::describe($shortages) . '.');
                    }
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'UPDATE',
                    'module' => 'OUTBOUND',
                    'old_values' => json_encode(array_map(fn ($c) => $c[0], $changes)),
                    'new_values' => json_encode(['order_number' => $order->order_number] + array_map(fn ($c) => $c[1], $changes)),
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('gudang.outbound.show', $order->id)->with('success', "Order {$order->order_number} berhasil diubah.");
    }

    /**
     * Aksi manual di daftar order: konfirmasi, batalkan, kirim (input resi), selesai.
     * Status PICKING, PACKING, dan READY_TO_SHIP diatur otomatis oleh sesi picking & packing.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:CONFIRMED,CANCELLED,SHIPPED,COMPLETED'],
            'tracking_number' => ['nullable', 'required_if:status,SHIPPED', 'string', 'max:255'],
            'courier' => ['nullable', 'string', 'max:50'],
        ], [
            'tracking_number.required_if' => 'Nomor resi wajib diisi saat mengirim order.',
        ]);

        $order = Order::findOrFail($id);
        $error = $this->changeStatus($order, $validated['status'], $request, $validated);

        return $error
            ? back()->with('error', $error)
            : back()->with('success', "Order {$order->order_number} sekarang: {$order->status_label}.");
    }

    /**
     * Aksi massal dari daftar Outbound: konfirmasi, selesai, atau batalkan banyak order sekaligus.
     * Order yang tidak bisa diproses (status tidak cocok, stok kurang) dilewati dan disebutkan alasannya.
     */
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
            'status' => ['required', 'in:CONFIRMED,CANCELLED,COMPLETED'],
        ], [
            'ids.required' => 'Pilih minimal satu order.',
        ]);

        // Yang paling mendesak diproses dulu, supaya stok terbatas jatuh ke order dengan batas kirim terdekat
        $orders = Order::whereIn('id', $validated['ids'])->orderBy('ship_by')->orderBy('created_at')->get();

        $done = 0;
        $skipped = [];
        foreach ($orders as $order) {
            $error = $this->changeStatus($order, $validated['status'], $request);
            if ($error) {
                $skipped[] = $error;
            } else {
                $done++;
            }
        }

        $verbs = ['CONFIRMED' => 'dikonfirmasi', 'CANCELLED' => 'dibatalkan', 'COMPLETED' => 'diselesaikan'];
        $redirect = back();
        if ($done) {
            $redirect = $redirect->with('success', "{$done} order {$verbs[$validated['status']]}.");
        }
        if ($skipped) {
            $more = count($skipped) > 10 ? ' (dan ' . (count($skipped) - 10) . ' lainnya)' : '';
            $redirect = $redirect->with('error', count($skipped) . ' order dilewati: ' . implode(' ', array_slice($skipped, 0, 10)) . $more);
        }

        return $redirect;
    }

    /** Ubah status satu order sesuai alur yang diizinkan. Mengembalikan pesan error, atau null jika berhasil. */
    private function changeStatus(Order $order, string $newStatus, Request $request, array $input = []): ?string
    {
        $allowedTransitions = [
            'PENDING' => ['CONFIRMED', 'CANCELLED'],
            'CONFIRMED' => ['CANCELLED'],
            'READY_TO_SHIP' => ['SHIPPED'],
            'SHIPPED' => ['COMPLETED'],
        ];

        if (!in_array($newStatus, $allowedTransitions[$order->status] ?? [])) {
            return "Order {$order->order_number} tidak bisa diubah dari {$order->status_label} ke " . Order::STATUS_LABELS[$newStatus] . '.';
        }

        // Konfirmasi hanya jika stok cukup, supaya picker tidak berangkat mengambil barang yang tidak ada
        if ($newStatus === 'CONFIRMED' && $shortages = StockAvailability::shortages($order)) {
            return "Order {$order->order_number} belum bisa dikonfirmasi, stok kurang: " . StockAvailability::describe($shortages) . '.';
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'SHIPPED') {
            $updateData['tracking_number'] = $input['tracking_number'];
            $updateData['courier'] = $input['courier'] ?? $order->courier;
            $updateData['shipped_at'] = now();
        }

        DB::transaction(function () use ($order, $updateData, $request) {
            $oldStatus = $order->status;
            $order->update($updateData);

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'UPDATE_STATUS',
                'module' => 'OUTBOUND',
                'old_values' => json_encode(['status' => $oldStatus]),
                'new_values' => json_encode($updateData + ['order_number' => $order->order_number]),
                'ip_address' => $request->ip(),
            ]);
        });

        return null;
    }
}
