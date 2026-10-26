<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\CourierHandover;
use App\Models\CourierHandoverItem;
use App\Models\Order;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Services\ShipDeadline;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan lanjutan untuk owner/supervisor: kinerja picker & packer, barang lambat laku,
 * akurasi stock opname, dan nilai stok per kategori.
 */
class InsightReportController extends Controller
{
    /**
     * Kinerja per orang: jumlah order dan pcs yang selesai diambil/dikemas, serta rata-rata waktunya.
     */
    public function performance(Request $request)
    {
        [$start, $end] = $this->period($request);
        $picking = $this->sessionStats(PickingSession::class, 'picking_session_items', 'picking_session_id', 'qty_picked', $start, $end);
        $packing = $this->sessionStats(PackingSession::class, 'packing_session_items', 'packing_session_id', 'qty_packed', $start, $end);
        $notes = $this->staffNotes($start, $end);
        $daily = $this->dailyOutput($start, $end);

        if ($request->query('export') === 'excel') {
            $rows = [];
            foreach (['Picking' => $picking, 'Packing' => $packing] as $type => $stats) {
                foreach ($stats as $row) {
                    $rows[] = [$type, $row['name'], $row['orders'], $row['pcs'], $row['avg_minutes']];
                }
            }
            $rows[] = [];
            $rows[] = ['Catatan per staf', 'Nama', 'Paket diserahkan', 'Ketuk/input manual', 'Koreksi -1', 'Barang tidak ada', 'Masalah packing'];
            foreach ($notes as $row) {
                $rows[] = ['', $row['name'], $row['handed'], $row['manual'], $row['undo'], $row['missing'], $row['problem']];
            }
            $rows[] = [];
            $rows[] = ['Per hari', 'Tanggal', 'Order dipicking', 'Pcs dipicking', 'Order dikemas', 'Pcs dikemas', 'Paket diserahkan'];
            foreach ($daily as $row) {
                $rows[] = ['', $row['date']->format('Y-m-d'), $row['picked_orders'], $row['picked_pcs'], $row['packed_orders'], $row['packed_pcs'], $row['handed']];
            }

            return $this->excel('Kinerja_Picker_Packer', ['Jenis', 'Nama', 'Order', 'Pcs', 'Rata-rata menit/order'], $rows, $start, $end);
        }

        return view('reports.performance', compact('start', 'end', 'picking', 'packing', 'notes', 'daily'));
    }

    /**
     * SKU yang masih ada stoknya tapi tidak keluar (picking) selama N hari.
     * SKU yang belum pernah keluar dihitung sejak stoknya pertama kali tercatat.
     */
    public function slowMoving(Request $request)
    {
        $days = max(7, min(365, (int) $request->query('hari', 60)));
        $cutoff = now()->subDays($days);

        $stocks = StockActive::query()
            ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
            ->selectRaw('stock_actives.sku, master_skus.product_name, master_skus.size, master_skus.size_category, master_skus.price, SUM(stock_actives.qty) as qty, MIN(stock_actives.created_at) as first_stocked_at')
            ->groupBy('stock_actives.sku', 'master_skus.product_name', 'master_skus.size', 'master_skus.size_category', 'master_skus.price')
            ->havingRaw('SUM(stock_actives.qty) > 0')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('stock_actives.sku', 'like', "%{$request->search}%")
                ->orWhere('master_skus.product_name', 'like', "%{$request->search}%")))
            ->get();

        $lastOut = StockMovement::where('type', 'OUTBOUND')->whereIn('sku', $stocks->pluck('sku'))
            ->groupBy('sku')->selectRaw('sku, MAX(created_at) as last_at')->pluck('last_at', 'sku');
        $firstMove = StockMovement::whereIn('sku', $stocks->pluck('sku'))
            ->groupBy('sku')->selectRaw('sku, MIN(created_at) as first_at')->pluck('first_at', 'sku');

        $rows = $stocks->map(function ($row) use ($lastOut, $firstMove) {
            $last = $lastOut[$row->sku] ?? null;
            $since = $last ?? collect([$firstMove[$row->sku] ?? null, $row->first_stocked_at])->filter()->min();

            return [
                'sku' => $row->sku,
                'product' => trim("{$row->product_name} {$row->size}"),
                'category' => $row->size_category,
                'qty' => (int) $row->qty,
                'value' => (int) $row->qty * (int) $row->price,
                'last_out_at' => $last ? Carbon::parse($last) : null,
                'idle_days' => $since ? (int) Carbon::parse($since)->diffInDays(now()) : null,
            ];
        })
            ->filter(fn ($row) => $row['idle_days'] !== null && $row['idle_days'] >= $days)
            ->sortByDesc(fn ($row) => [$row['idle_days'], $row['value']])
            ->values();

        $summary = ['skus' => $rows->count(), 'qty' => $rows->sum('qty'), 'value' => $rows->sum('value')];

        if ($request->query('export') === 'excel') {
            return $this->excel("Barang_Lambat_{$days}hari", ['SKU', 'Produk', 'Kategori', 'Stok', 'Nilai (Rp)', 'Terakhir keluar', 'Diam (hari)'],
                $rows->map(fn ($r) => [$r['sku'], $r['product'], $r['category'], $r['qty'], $r['value'], $r['last_out_at']?->format('Y-m-d') ?? 'Belum pernah', $r['idle_days']])->all());
        }
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('reports.slow-moving', compact('items', 'summary', 'days', 'cutoff'));
    }

    /**
     * Akurasi tiap opname yang selesai: berapa persen lokasi yang hitungannya sama dengan sistem,
     * plus SKU yang paling sering selisih.
     */
    public function opnameAccuracy(Request $request)
    {
        [$start, $end] = $this->period($request, 90);

        $opnames = StockOpname::with('creator:id,name')
            ->where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$start, $end])
            ->withCount([
                'items as counted_count' => fn ($q) => $q->whereNotNull('physical_qty'),
                'items as match_count' => fn ($q) => $q->whereNotNull('physical_qty')->where('difference', 0),
            ])
            ->withSum(['items as plus_qty' => fn ($q) => $q->where('difference', '>', 0)], 'difference')
            ->withSum(['items as minus_qty' => fn ($q) => $q->where('difference', '<', 0)], 'difference')
            ->orderByDesc('completed_at')
            ->get()
            ->each(fn ($o) => $o->accuracy = $o->counted_count > 0 ? round($o->match_count / $o->counted_count * 100, 1) : null);

        $counted = $opnames->sum('counted_count');
        $summary = [
            'opnames' => $opnames->count(),
            'counted' => $counted,
            'accuracy' => $counted > 0 ? round($opnames->sum('match_count') / $counted * 100, 1) : null,
            'plus' => (int) $opnames->sum('plus_qty'),
            'minus' => (int) abs($opnames->sum('minus_qty')),
        ];

        $problemSkus = StockOpnameItem::query()
            ->whereIn('stock_opname_id', $opnames->pluck('id'))
            ->where('difference', '!=', 0)
            ->groupBy('sku')
            ->selectRaw('sku, COUNT(*) as times, SUM(difference) as net, SUM(ABS(difference)) as total_abs')
            ->orderByDesc('times')->orderByDesc('total_abs')
            ->limit(10)
            ->get();

        if ($request->query('export') === 'excel') {
            return $this->excel('Akurasi_Opname', ['No. Opname', 'Cakupan', 'Selesai', 'Lokasi dihitung', 'Cocok', 'Akurasi (%)', 'Kelebihan (pcs)', 'Kekurangan (pcs)'],
                $opnames->map(fn ($o) => [$o->opname_number, $o->scope_label, $o->completed_at?->format('Y-m-d H:i'), $o->counted_count, $o->match_count, $o->accuracy, (int) $o->plus_qty, (int) abs($o->minus_qty)])->all(),
                $start, $end);
        }

        return view('reports.opname-accuracy', compact('opnames', 'summary', 'problemSkus', 'start', 'end'));
    }

    /**
     * Nilai stok per kategori ukuran dan per produk: nilai jual (jumlah × harga jual),
     * nilai modal (jumlah × HPP), dan potensi laba dari SKU yang HPP-nya sudah diisi.
     */
    public function stockValue(Request $request)
    {
        $base = fn () => StockActive::query()
            ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
            ->where('stock_actives.qty', '>', 0);
        // Laba dihitung di PHP dari dua jumlah positif, karena kolom harga unsigned di MySQL
        $sums = 'COUNT(DISTINCT stock_actives.sku) as skus, SUM(stock_actives.qty) as qty,'
            . ' SUM(stock_actives.qty * master_skus.price) as value,'
            . ' SUM(stock_actives.qty * master_skus.cost_price) as cost_value,'
            . ' SUM(CASE WHEN master_skus.cost_price > 0 THEN stock_actives.qty * master_skus.price ELSE 0 END) as costed_value';
        $withProfit = fn ($rows) => $rows->each(function ($row) {
            $row->value = (int) $row->value;
            $row->cost_value = (int) $row->cost_value;
            $row->profit = (int) $row->costed_value - $row->cost_value;
        });

        $categories = $withProfit($base()
            ->groupBy('master_skus.size_category')
            ->selectRaw('master_skus.size_category as name, ' . $sums)
            ->orderByDesc('value')
            ->get());

        $products = $withProfit($base()
            ->groupBy('master_skus.product_name')
            ->selectRaw('master_skus.product_name as name, ' . $sums)
            ->orderByDesc('value')
            ->limit(20)
            ->get());

        $total = [
            'skus' => (int) $categories->sum('skus'),
            'qty' => (int) $categories->sum('qty'),
            'value' => (int) $categories->sum('value'),
            'cost_value' => (int) $categories->sum('cost_value'),
            'profit' => (int) $categories->sum('profit'),
            'without_cost' => (int) $base()->where('master_skus.cost_price', 0)->distinct()->count('stock_actives.sku'),
        ];

        if ($request->query('export') === 'excel') {
            $rows = [];
            foreach (['Kategori ukuran' => $categories, 'Produk (20 terbesar)' => $products] as $group => $list) {
                foreach ($list as $row) {
                    $rows[] = [$group, $row->name, (int) $row->skus, (int) $row->qty, $row->value, $row->cost_value, $row->profit];
                }
            }

            return $this->excel('Nilai_Stok', ['Kelompok', 'Nama', 'Jumlah SKU', 'Stok (pcs)', 'Nilai jual (Rp)', 'Nilai modal (Rp)', 'Potensi laba (Rp)'], $rows);
        }

        return view('reports.stock-value', compact('categories', 'products', 'total'));
    }

    /**
     * Ketepatan kirim: order yang dikirim dalam periode dibandingkan dengan batas kirimnya,
     * per hari dan per kurir, plus order yang sekarang sudah lewat batas tapi belum dikirim.
     */
    public function shipping(Request $request)
    {
        [$start, $end] = $this->period($request);

        $orders = Order::whereNotNull('shipped_at')
            ->whereBetween('shipped_at', [$start, $end])
            ->whereNotIn('status', ['CANCELLED'])
            ->get(['id', 'order_number', 'customer_name', 'courier', 'created_at', 'shipped_at', 'ship_by'])
            ->each(function ($order) {
                $order->deadline = ShipDeadline::of($order);
                $order->on_time = $order->shipped_at->lte($order->deadline);
                // Order yang dicatat setelah dikirim (input susulan) tidak ikut rata-rata
                $minutes = $order->created_at->diffInMinutes($order->shipped_at);
                $order->hours = $minutes >= 0 ? round($minutes / 60, 1) : null;
            });

        $stats = function ($group) {
            $onTime = $group->where('on_time', true)->count();

            return [
                'shipped' => $group->count(),
                'on_time' => $onTime,
                'late' => $group->count() - $onTime,
                'rate' => $group->count() ? round($onTime / $group->count() * 100, 1) : null,
                'avg_hours' => $group->count() ? round($group->avg('hours'), 1) : null,
            ];
        };

        $summary = $stats($orders) + [
            'open_late' => Order::whereIn('status', ShipDeadline::OPEN_STATUSES)->where('ship_by', '<', now())->count(),
        ];
        $daily = $orders->groupBy(fn ($o) => $o->shipped_at->toDateString())->sortKeysDesc()->map($stats);
        $couriers = $orders->groupBy(fn ($o) => $o->courier ?: 'Tanpa kurir')->map($stats)->sortByDesc('shipped');
        $late = $orders->where('on_time', false)
            ->sortByDesc(fn ($o) => $o->deadline->diffInMinutes($o->shipped_at))->values();

        if ($request->query('export') === 'excel') {
            return $this->excel('Ketepatan_Kirim', ['No. Order', 'Pembeli', 'Kurir', 'Order masuk', 'Batas kirim', 'Dikirim', 'Status'],
                $orders->sortBy('shipped_at')->map(fn ($o) => [
                    $o->order_number, $o->customer_name, $o->courier, $o->created_at->format('Y-m-d H:i'),
                    $o->deadline->format('Y-m-d H:i'), $o->shipped_at->format('Y-m-d H:i'), $o->on_time ? 'Tepat waktu' : 'Terlambat',
                ])->values()->all(), $start, $end);
        }

        return view('reports.shipping', compact('start', 'end', 'summary', 'daily', 'couriers', 'late'));
    }

    private function excel(string $name, array $headings, array $rows, ?Carbon $start = null, ?Carbon $end = null)
    {
        $period = $start ? '_' . $start->format('Ymd') . '-' . $end->format('Ymd') : '_' . now()->format('Ymd');

        return Excel::download(new ArrayExport($headings, $rows), "Laporan_{$name}{$period}.xlsx");
    }

    /** Periode dari ?start_date & ?end_date; bawaan N hari terakhir. */
    private function period(Request $request, int $defaultDays = 30): array
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->subDays($defaultDays - 1)->startOfDay();
        $end = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : now()->endOfDay();

        return [$start, $end];
    }

    /**
     * Catatan per staf dalam periode: paket yang diserahkan ke kurir, barang yang diketuk/diisi manual
     * (tanpa scan), koreksi -1 saat picking, laporan barang tidak ada di rak, dan masalah saat packing.
     */
    private function staffNotes(Carbon $start, Carbon $end)
    {
        $audits = fn (array $actions) => AuditTrail::whereIn('action', $actions)->whereBetween('created_at', [$start, $end])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) as total')->pluck('total', 'user_id');

        $counts = [
            'handed' => CourierHandoverItem::join('courier_handovers', 'courier_handovers.id', '=', 'courier_handover_items.courier_handover_id')
                ->where('courier_handovers.status', 'COMPLETED')
                ->whereBetween('courier_handovers.completed_at', [$start, $end])
                ->groupBy('courier_handovers.user_id')
                ->selectRaw('courier_handovers.user_id, COUNT(*) as total')
                ->pluck('total', 'user_id'),
            'manual' => $audits(['MANUAL_PICK', 'MANUAL_PACK', 'MANUAL_QTY']),
            'undo' => StockMovement::where('reference_type', 'PICKING_CANCEL')->where('notes', 'like', 'Koreksi picking%')
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('user_id')->selectRaw('user_id, COUNT(*) as total')->pluck('total', 'user_id'),
            'missing' => $audits(['REPORT_MISSING']),
            'problem' => $audits(['PACK_PROBLEM']),
        ];

        $userIds = collect($counts)->flatMap(fn ($values) => $values->keys())->filter()->unique();
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');

        return $userIds->map(fn ($id) => ['name' => $names[$id] ?? '-'] + collect($counts)->map(fn ($values) => (int) ($values[$id] ?? 0))->all())
            ->sortBy('name')->values();
    }

    /** Hasil kerja gudang per hari: order dan pcs yang selesai dipicking/dikemas, dan paket yang diserahkan */
    private function dailyOutput(Carbon $start, Carbon $end)
    {
        $perDay = function (string $model, string $itemTable, string $foreignKey, string $qtyColumn) use ($start, $end) {
            $sessions = $model::where('status', 'COMPLETED')->whereBetween('completed_at', [$start, $end])->get(['id', 'completed_at']);
            $pcs = DB::table($itemTable)->whereIn($foreignKey, $sessions->pluck('id'))
                ->groupBy($foreignKey)->selectRaw("{$foreignKey} as session_id, SUM({$qtyColumn}) as pcs")
                ->pluck('pcs', 'session_id');

            return $sessions->groupBy(fn ($s) => $s->completed_at->toDateString())
                ->map(fn ($rows) => ['orders' => $rows->count(), 'pcs' => (int) $rows->sum(fn ($s) => $pcs[$s->id] ?? 0)]);
        };

        $picked = $perDay(PickingSession::class, 'picking_session_items', 'picking_session_id', 'qty_picked');
        $packed = $perDay(PackingSession::class, 'packing_session_items', 'packing_session_id', 'qty_packed');
        $handed = CourierHandover::where('status', 'COMPLETED')->whereBetween('completed_at', [$start, $end])->withCount('items')->get(['id', 'completed_at'])
            ->groupBy(fn ($h) => $h->completed_at->toDateString())
            ->map(fn ($rows) => (int) $rows->sum('items_count'));

        return collect($picked->keys())->merge($packed->keys())->merge($handed->keys())->unique()->sortDesc()->values()
            ->map(fn ($date) => [
                'date' => Carbon::parse($date),
                'picked_orders' => $picked[$date]['orders'] ?? 0,
                'picked_pcs' => $picked[$date]['pcs'] ?? 0,
                'packed_orders' => $packed[$date]['orders'] ?? 0,
                'packed_pcs' => $packed[$date]['pcs'] ?? 0,
                'handed' => $handed[$date] ?? 0,
            ]);
    }

    /**
     * Sesi yang selesai dalam periode, dikelompokkan per orang yang mengerjakannya.
     */
    private function sessionStats(string $model, string $itemTable, string $foreignKey, string $qtyColumn, Carbon $start, Carbon $end)
    {
        $sessions = $model::with('user:id,name')
            ->where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$start, $end])
            ->get(['id', 'user_id', 'started_at', 'completed_at']);

        $pcs = DB::table($itemTable)->whereIn($foreignKey, $sessions->pluck('id'))
            ->groupBy($foreignKey)->selectRaw("{$foreignKey} as session_id, SUM({$qtyColumn}) as pcs")
            ->pluck('pcs', 'session_id');

        return $sessions->groupBy('user_id')->map(function ($rows) use ($pcs) {
            $minutes = $rows->filter(fn ($s) => $s->started_at && $s->completed_at)
                ->map(fn ($s) => $s->started_at->diffInSeconds($s->completed_at) / 60);

            return [
                'name' => $rows->first()->user?->name ?? '-',
                'orders' => $rows->count(),
                'pcs' => (int) $rows->sum(fn ($s) => $pcs[$s->id] ?? 0),
                'avg_minutes' => $minutes->isNotEmpty() ? round($minutes->avg(), 1) : null,
            ];
        })->sortByDesc('pcs')->values();
    }
}
