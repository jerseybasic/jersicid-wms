<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackingSession;
use App\Models\PickingSession;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

        return view('reports.performance', [
            'start' => $start,
            'end' => $end,
            'picking' => $this->sessionStats(PickingSession::class, 'picking_session_items', 'picking_session_id', 'qty_picked', $start, $end),
            'packing' => $this->sessionStats(PackingSession::class, 'packing_session_items', 'packing_session_id', 'qty_packed', $start, $end),
        ]);
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

        return view('reports.opname-accuracy', compact('opnames', 'summary', 'problemSkus', 'start', 'end'));
    }

    /**
     * Nilai stok (jumlah × harga jual di master SKU) per kategori ukuran dan per produk.
     */
    public function stockValue()
    {
        $base = fn () => StockActive::query()
            ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
            ->where('stock_actives.qty', '>', 0);

        $categories = $base()
            ->groupBy('master_skus.size_category')
            ->selectRaw('master_skus.size_category as name, COUNT(DISTINCT stock_actives.sku) as skus, SUM(stock_actives.qty) as qty, SUM(stock_actives.qty * master_skus.price) as value')
            ->orderByDesc('value')
            ->get();

        $products = $base()
            ->groupBy('master_skus.product_name')
            ->selectRaw('master_skus.product_name as name, COUNT(DISTINCT stock_actives.sku) as skus, SUM(stock_actives.qty) as qty, SUM(stock_actives.qty * master_skus.price) as value')
            ->orderByDesc('value')
            ->limit(20)
            ->get();

        $total = ['skus' => (int) $categories->sum('skus'), 'qty' => (int) $categories->sum('qty'), 'value' => (int) $categories->sum('value')];

        return view('reports.stock-value', compact('categories', 'products', 'total'));
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
