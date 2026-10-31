<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickingBatch;
use App\Models\RestockItem;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Services\ShipDeadline;
use App\Services\OrderCancellation;
use App\Services\OrderItemChange;
use App\Services\OrderReprocess;
use App\Services\Picking;
use App\Services\StockAvailability;
use App\Services\WaitingStock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboundController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with('items.masterSku', 'pickingSession:id,order_id,batch_id', 'packingSession:id,order_id,packing_batch_id')->orderBy('created_at', 'desc');

        // Filter Status; "Menunggu stok" adalah order Pending yang ditunda karena stoknya kurang
        if ($request->status === 'WAITING_STOCK') {
            $query->waitingStock();
        } elseif ($request->status === 'PENDING') {
            $query->where('status', 'PENDING')->whereNull('waiting_stock_at');
        } elseif ($request->filled('status')) {
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

        // Kekurangan barang untuk order yang menunggu stok, untuk tombol "Buat PO"
        $needs = $request->status === 'WAITING_STOCK' ? WaitingStock::needs() : collect();

        // Stats
        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', 'PENDING')->whereNull('waiting_stock_at')->count(),
            'waiting' => Order::waitingStock()->count(),
            'picking' => Order::whereIn('status', ['CONFIRMED', 'PICKING'])->count(),
            'packing' => Order::whereIn('status', ['PACKING', 'READY_TO_SHIP'])->count(),
            'ready' => Order::where('status', 'READY_TO_SHIP')->count(),
        ];

        // Order Pending yang stoknya cukup: bisa langsung dicentang lalu masuk picking gabungan
        $stockReady = StockAvailability::readyMap($orders->getCollection());

        return view('warehouse.outbound.index', compact('orders', 'stats', 'needs', 'stockReady'));
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
            'changeReturns' => RestockItem::pending()->where('order_id', $order->id)->orderBy('created_at')->get(),
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
            ->where('module', 'OUTBOUND')->whereIn('action', ['UPDATE_STATUS', 'UPDATE', 'WAITING_STOCK', 'REACTIVATE', 'CARRY_OVER', 'CHANGE_ITEMS'])
            ->where('new_values', 'like', '%' . $order->order_number . '%')
            ->orderBy('created_at')
            ->get()
            ->each(function ($trail) use ($order, $labels, $steps) {
                $values = is_string($trail->new_values) ? json_decode($trail->new_values, true) : $trail->new_values;
                if (($values['order_number'] ?? null) !== $order->order_number) {
                    return;
                }
                if ($trail->action === 'REACTIVATE') {
                    $steps->push(['at' => $trail->created_at, 'title' => 'Diaktifkan lagi', 'detail' => 'Kembali Pending setelah dibatalkan', 'user' => $trail->user?->name, 'color' => 'cyan']);

                    return;
                }
                if ($trail->action === 'CARRY_OVER') {
                    $steps->push(['at' => $trail->created_at, 'title' => 'Kembali Pending', 'detail' => $values['reason'] ?? 'Belum diproses saat tutup kiriman', 'user' => $trail->user?->name, 'color' => 'amber']);

                    return;
                }
                if ($trail->action === 'CHANGE_ITEMS') {
                    $old = is_string($trail->old_values) ? json_decode($trail->old_values, true) : $trail->old_values;
                    $returned = collect($values['to_return'] ?? [])->map(fn ($row) => "{$row['sku']} ×{$row['qty']}")->implode(', ');
                    $steps->push([
                        'at' => $trail->created_at,
                        'title' => 'Item diganti',
                        'detail' => OrderItemChange::diffText($old['items'] ?? [], $values['items'] ?? [])
                            . (!empty($values['source']) ? " · {$values['source']}" : '')
                            . ($returned ? " · kembalikan ke rak: {$returned}" : ''),
                        'user' => $trail->user?->name,
                        'color' => 'amber',
                    ]);

                    return;
                }
                if ($trail->action === 'WAITING_STOCK') {
                    $steps->push(['at' => $trail->created_at, 'title' => 'Menunggu stok', 'detail' => $values['reason'] ?? null, 'user' => $trail->user?->name, 'color' => 'orange']);

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
                    'detail' => match (true) {
                        !empty($values['tracking_number']) => 'Resi ' . $values['tracking_number'] . (!empty($values['courier']) ? " · {$values['courier']}" : ''),
                        !empty($values['auto']) => 'Otomatis: ' . $values['auto'],
                        !empty($values['reason']) => $values['reason'],
                        default => null,
                    },
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
        $rank = array_flip(['Order masuk', 'Menunggu stok', 'Dikonfirmasi', 'Dibatalkan', 'Mulai diambil (picking)', 'Selesai diambil', 'Mulai dikemas (packing)',
            'Selesai dikemas', 'Discan untuk serah terima kurir', 'Dikirim', 'Selesai', 'Retur diterima', 'Data order diubah', 'Item diganti']);

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

        $itemsEditable = $order->itemsEditable(auth()->user());
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
            'items.prohibited' => 'Item order yang sudah mulai di-picking hanya bisa diganti Owner, Supervisor, atau Admin Gudang.',
        ]);

        $fields = ['customer_name', 'customer_phone', 'shipping_address', 'courier', 'tracking_number', 'notes'];
        $data = collect($fields)->mapWithKeys(fn ($field) => [$field => $validated[$field] ?? null])->all();
        $data['ship_by'] = !empty($validated['ship_by']) ? Carbon::parse($validated['ship_by']) : $order->ship_by;

        // Item digabung per SKU; penggantian item (dan pengembalian barang ke rak jika sudah diambil) diurus OrderItemChange
        $newItems = $itemsEditable
            ? collect($validated['items'])->groupBy(fn ($item) => strtoupper($item['sku']))->map(fn ($rows) => (int) $rows->sum('qty'))->all()
            : null;

        $changes = [];
        foreach ($data as $field => $value) {
            $old = $order->{$field};
            $same = $field === 'ship_by' ? $old?->equalTo($value) ?? $value === null : (string) $old === (string) $value;
            if (!$same) {
                $changes[$field] = [$field === 'ship_by' ? $old?->format('Y-m-d H:i') : $old, $field === 'ship_by' ? $value?->format('Y-m-d H:i') : $value];
            }
        }
        $itemsChanged = $newItems !== null && !OrderItemChange::same(OrderItemChange::current($order), $newItems);

        if (!$changes && !$itemsChanged) {
            return redirect()->route('gudang.outbound.show', $order->id)->with('success', 'Tidak ada perubahan.');
        }

        try {
            $summary = DB::transaction(function () use ($order, $data, $itemsChanged, $newItems, $changes, $request) {
                $order = Order::lockForUpdate()->findOrFail($order->id);
                if ($changes) {
                    $order->update($data);

                    AuditTrail::create([
                        'user_id' => auth()->id(),
                        'action' => 'UPDATE',
                        'module' => 'OUTBOUND',
                        'old_values' => json_encode(array_map(fn ($c) => $c[0], $changes)),
                        'new_values' => json_encode(['order_number' => $order->order_number] + array_map(fn ($c) => $c[1], $changes)),
                        'ip_address' => $request->ip(),
                    ]);
                }

                return $itemsChanged
                    ? OrderItemChange::apply($order, $newItems, [], auth()->id(), $request->ip(), 'diubah manual')
                    : null;
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($summary) {
            return redirect()->route('gudang.outbound.show', $order->id)
                ->with('success', "Item order {$order->order_number} berhasil diganti. " . OrderItemChange::describe($order, $summary));
        }

        return redirect()->route('gudang.outbound.show', $order->id)->with('success', "Order {$order->order_number} berhasil diubah.");
    }

    /**
     * Aksi manual di daftar order: konfirmasi, batalkan, selesai.
     * Status PICKING, PACKING, dan READY_TO_SHIP diatur otomatis oleh sesi picking & packing,
     * SHIPPED oleh serah terima kurir.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:CONFIRMED,CANCELLED,SHIPPED,COMPLETED'],
        ]);

        $order = Order::findOrFail($id);

        // Satu jalan kirim: paket Dikirim hanya lewat serah terima, supaya tercatat di manifest kurir
        if ($validated['status'] === 'SHIPPED') {
            return back()->with('error', "Order {$order->order_number} dikirim lewat Serah Terima Kurir: scan resinya saat kurir datang.");
        }

        $error = $this->changeStatus($order, $validated['status'], $request);

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
            'status' => ['required', 'in:CONFIRMED,CANCELLED,COMPLETED,REACTIVATE'],
        ], [
            'ids.required' => 'Pilih minimal satu order.',
        ]);
        abort_if($validated['status'] === 'REACTIVATE' && !in_array(auth()->user()->role, OrderCancellation::ROLES, true), 403);

        // Yang paling mendesak diproses dulu, supaya stok terbatas jatuh ke order dengan batas kirim terdekat
        $orders = Order::whereIn('id', $validated['ids'])->orderBy('ship_by')->orderBy('created_at')->get();

        $done = 0;
        $skipped = [];
        foreach ($orders as $order) {
            $error = $validated['status'] === 'REACTIVATE'
                ? $this->reactivateOne($order->id, $request)
                : $this->changeStatus($order, $validated['status'], $request);
            if ($error) {
                $skipped[] = $error;
            } else {
                $done++;
            }
        }

        $verbs = ['CONFIRMED' => 'dikonfirmasi', 'CANCELLED' => 'dibatalkan', 'COMPLETED' => 'diselesaikan', 'REACTIVATE' => 'diaktifkan lagi (Pending)'];
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

    /**
     * Aksi massal "Picking gabungan": order Pending yang dicentang dikonfirmasi dulu (stoknya dicek seperti
     * biasa), lalu semua order yang siap picking langsung digabung jadi satu picking gabungan.
     * Satu order saja langsung jadi picking biasa. Order yang stoknya kurang ditunda (Menunggu stok).
     */
    public function bulkPick(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
        ], ['ids.required' => 'Pilih minimal satu order.']);

        $orders = Order::whereIn('id', $validated['ids'])->orderBy('ship_by')->orderBy('created_at')->get();

        // Yang paling mendesak dikonfirmasi dulu, supaya stok terbatas jatuh ke batas kirim terdekat
        $skipped = [];
        foreach ($orders->where('status', 'PENDING') as $order) {
            if ($error = $this->changeStatus($order, 'CONFIRMED', $request)) {
                $skipped[] = $error;
            }
        }
        foreach ($orders->whereNotIn('status', ['PENDING', 'CONFIRMED']) as $order) {
            $skipped[] = "Order {$order->order_number} sudah {$order->status_label}.";
        }

        [$started, $left] = DB::transaction(function () use ($validated, $request) {
            $ready = PickingBatchController::waitingOrders()->whereIn('id', $validated['ids'])->with('items')->lockForUpdate()->get();
            $left = max(0, $ready->count() - PickingBatch::MAX_ORDERS);
            $ready = $ready->take(PickingBatch::MAX_ORDERS)->values();

            if ($ready->count() === 1) {
                $session = Picking::createForOrder($ready->first(), auth()->id(), ['status' => 'IN_PROGRESS', 'started_at' => now()]);
                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'CREATE',
                    'module' => 'PICKING',
                    'new_values' => json_encode(['session_number' => $session->session_number, 'order_id' => $session->order_id, 'via' => 'Outbound']),
                    'ip_address' => $request->ip(),
                ]);

                return [$session, $left];
            }

            return [$ready->isEmpty() ? null : PickingBatchController::createBatch($ready, auth()->id(), $request->ip()), $left];
        });

        $skippedText = $skipped
            ? count($skipped) . ' order dilewati: ' . implode(' ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? ' (dan ' . (count($skipped) - 10) . ' lainnya)' : '')
            : null;

        if (!$started) {
            return back()->with('error', $skippedText ?? 'Tidak ada order yang bisa dipicking.');
        }

        $leftText = $left ? " {$left} order lainnya tetap di antrean picking (maksimal " . PickingBatch::MAX_ORDERS . ' sekali jalan).' : '';
        $redirect = $started instanceof PickingBatch
            ? redirect()->route('gudang.picking-batch.show', $started)
                ->with('success', 'Picking gabungan ' . $started->sessions()->count() . ' order dibuat. Siapkan keranjang bernomor 1 sampai ' . $started->sessions()->count() . ', lalu mulai scan.' . $leftText)
            : redirect()->route('gudang.picking.show', $started->id)
                ->with('success', 'Hanya 1 order yang siap, langsung picking biasa. Mulai scan barang.' . $leftText);

        return $skippedText ? $redirect->with('error', $skippedText) : $redirect;
    }

    /**
     * Batalkan order yang sudah mulai diproses (picking, packing, atau siap kirim), misalnya karena
     * dibatalkan pembeli di marketplace. Barang yang sudah diambil masuk daftar "Kembalikan ke rak".
     */
    public function cancel(Request $request, $id)
    {
        abort_unless(in_array(auth()->user()->role, OrderCancellation::ROLES, true), 403, 'Hanya Owner, Supervisor, atau Admin Gudang yang bisa membatalkan order yang sedang diproses.');
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $order = DB::transaction(function () use ($id, $validated, $request) {
                $order = Order::lockForUpdate()->findOrFail($id);
                OrderCancellation::cancel($order, $validated['reason'] ?? null, auth()->id(), $request->ip());

                return $order;
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $toReturn = (int) $order->items()->sum('qty_picked');

        return redirect()->route('gudang.outbound.show', $order->id)->with('success', $toReturn
            ? "Order {$order->order_number} dibatalkan. Kembalikan {$toReturn} pcs barangnya ke rak lewat menu Kembalikan ke Rak."
            : "Order {$order->order_number} dibatalkan.");
    }

    /** Aktifkan lagi order yang dibatalkan: kembali Pending seperti order baru */
    public function reactivate(Request $request, $id)
    {
        abort_unless(in_array(auth()->user()->role, OrderCancellation::ROLES, true), 403, 'Hanya Owner, Supervisor, atau Admin Gudang yang bisa mengaktifkan lagi order yang dibatalkan.');

        try {
            $order = DB::transaction(function () use ($id, $request) {
                $order = Order::lockForUpdate()->findOrFail($id);
                OrderCancellation::reactivate($order, auth()->id(), $request->ip());

                return $order;
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('gudang.outbound.show', $order->id)
            ->with('success', "Order {$order->order_number} aktif lagi sebagai Pending. Tekan Konfirmasi untuk memasukkannya ke antrean picking.");
    }

    /**
     * Proses ulang order Dikirim/Selesai dengan resi yang sama: kembali ke Siap Kirim jika paketnya
     * belum pernah diserahkan ke kurir, atau ke antrean picking jika barangnya sudah diretur ke rak.
     */
    public function reprocess(Request $request, $id)
    {
        abort_unless(in_array(auth()->user()->role, OrderReprocess::ROLES, true), 403, 'Hanya Owner, Supervisor, atau Admin Gudang yang bisa memproses ulang order.');

        try {
            [$order, $status] = DB::transaction(function () use ($id, $request) {
                $order = Order::lockForUpdate()->findOrFail($id);

                return [$order, OrderReprocess::reprocess($order, auth()->id(), $request->ip())];
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = match ($status) {
            'READY_TO_SHIP' => "Order {$order->order_number} kembali ke Siap Kirim. Scan resinya di Serah Terima Kurir saat kurir datang.",
            'CONFIRMED' => "Order {$order->order_number} kembali ke antrean picking dengan resi yang sama.",
            default => "Order {$order->order_number} diproses ulang, tapi stoknya kurang sehingga menunggu stok.",
        };

        return redirect()->route('gudang.outbound.show', $order->id)->with('success', $message);
    }

    /** Aktifkan lagi satu order (aksi massal). Mengembalikan pesan error, atau null jika berhasil. */
    private function reactivateOne(string $id, Request $request): ?string
    {
        try {
            DB::transaction(function () use ($id, $request) {
                OrderCancellation::reactivate(Order::lockForUpdate()->findOrFail($id), auth()->id(), $request->ip());
            });
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Ubah status satu order sesuai alur yang diizinkan. Mengembalikan pesan error, atau null jika berhasil. */
    private function changeStatus(Order $order, string $newStatus, Request $request): ?string
    {
        $allowedTransitions = [
            'PENDING' => ['CONFIRMED', 'CANCELLED'],
            'CONFIRMED' => ['CANCELLED'],
            'SHIPPED' => ['COMPLETED'],
        ];

        if (!in_array($newStatus, $allowedTransitions[$order->status] ?? [])) {
            return "Order {$order->order_number} tidak bisa diubah dari {$order->status_label} ke " . Order::STATUS_LABELS[$newStatus] . '.';
        }

        // Konfirmasi hanya jika stok cukup, supaya picker tidak berangkat mengambil barang yang tidak ada
        // Stok kurang: order ditunda sebagai "Menunggu stok" dan dikonfirmasi otomatis saat barangnya masuk
        if ($newStatus === 'CONFIRMED' && $shortages = StockAvailability::shortages($order)) {
            WaitingStock::mark($order, auth()->id(), $request->ip(), StockAvailability::describe($shortages));

            return "Order {$order->order_number} menunggu stok (" . StockAvailability::describe($shortages) . '). Order otomatis masuk antrean picking saat barangnya diterima.';
        }

        $updateData = ['status' => $newStatus];
        if ($order->waiting_stock_at) {
            $order->waiting_stock_at = null;
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
