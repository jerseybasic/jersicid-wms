<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\InboundReceipt;
use App\Models\Order;
use App\Models\PickingSession;
use App\Models\PackingSession;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\AuditTrail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Angka agregat dashboard di-cache sebentar supaya buka/refresh dashboard
     * tidak selalu menjalankan puluhan query berat.
     */
    private const STATS_CACHE_SECONDS = 60;

    public function owner()
    {
        $stats = Cache::remember('dashboard.owner.stats', self::STATS_CACHE_SECONDS, function () {
            [$todayInbound, $todayOutbound] = $this->movementTotalsBetween(today(), today()->endOfDay());
            [$last7Days, $inboundData, $outboundData] = $this->movementChartLast7Days();

            return [
                // Total Stok (unit)
                'totalStock' => StockActive::sum('qty'),

                // Nilai Stok (Rp) - SUM(qty * price)
                'stockValue' => DB::table('stock_actives')
                    ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
                    ->where('master_skus.status', 'AKTIF')
                    ->sum(DB::raw('stock_actives.qty * master_skus.price')),

                'lowStockCount' => $this->lowStockCount(),

                // Inbound & Outbound Hari Ini
                'todayInbound' => $todayInbound,
                'todayOutbound' => $todayOutbound,

                // In Production (PO dengan status IN_PRODUCTION)
                'inProduction' => PurchaseOrder::where('status', 'IN_PRODUCTION')->count(),

                // Supplier Aktif
                'activeSuppliers' => Supplier::where('status', 'AKTIF')->count(),

                // Order Aktif (PO dengan status APPROVED/SENT)
                'activeOrders' => PurchaseOrder::whereIn('status', ['APPROVED', 'SENT'])->count(),

                // Chart 7 hari terakhir
                'last7Days' => $last7Days,
                'inboundData' => $inboundData,
                'outboundData' => $outboundData,

                // Distribusi stok per kategori
                'categoryDistribution' => MasterSku::select('master_skus.size_category', DB::raw('COALESCE(SUM(stock_actives.qty), 0) as total_qty'))
                    ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
                    ->where('master_skus.status', 'AKTIF')
                    ->groupBy('master_skus.size_category')
                    ->orderByDesc('total_qty')
                    ->get(),

                // Top 5 SKU stok terbanyak
                'topSkus' => $this->skusWithStock()
                    ->orderByDesc('current_stock')
                    ->limit(5)
                    ->get(),

                // Peringatan low stock
                'lowStockItems' => $this->lowStockQuery()
                    ->orderBy('current_stock')
                    ->limit(5)
                    ->get(),
            ];
        });

        extract($stats);

        // ==================== AKTIVITAS TERAKHIR ====================

        $recentActivities = AuditTrail::with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // ==================== PASS TO VIEW ====================

        return view('dashboard.owner', compact(
            'totalStock',
            'stockValue',
            'lowStockCount',
            'todayInbound',
            'todayOutbound',
            'inProduction',
            'activeSuppliers',
            'activeOrders',
            'last7Days',
            'inboundData',
            'outboundData',
            'categoryDistribution',
            'topSkus',
            'lowStockItems',
            'recentActivities'
        ));
    }

    public function gudang()
    {
        $stats = Cache::remember('dashboard.gudang.stats', self::STATS_CACHE_SECONDS, function () {
            [$inboundToday, $outboundToday] = $this->movementTotalsBetween(today(), today()->endOfDay());
            $todayRange = [today(), today()->endOfDay()];

            $pickingCounts = DB::table('picking_sessions')
                ->selectRaw('status, COUNT(*) as total')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->groupBy('status')
                ->pluck('total', 'status');
            $packingCounts = DB::table('packing_sessions')
                ->selectRaw('status, COUNT(*) as total')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->groupBy('status')
                ->pluck('total', 'status');

            return [
                // 1. Total stok unit
                'totalStock' => StockActive::sum('qty'),

                // 2. Nilai stok (price × qty)
                'stockValue' => DB::table('stock_actives')
                    ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
                    ->selectRaw('SUM(master_skus.price * stock_actives.qty) as total_value')
                    ->value('total_value') ?? 0,

                // 3. Low stock alert count
                'lowStock' => $this->lowStockCount(),

                // 4-5. Inbound & outbound hari ini (unit, dari stock_movements)
                'inboundToday' => $inboundToday,
                'outboundToday' => $outboundToday,

                // 6-9. Session stats
                'pickingPending' => (int) ($pickingCounts['PENDING'] ?? 0),
                'packingPending' => (int) ($packingCounts['PENDING'] ?? 0),
                'inProgress' => (int) ($pickingCounts['IN_PROGRESS'] ?? 0) + (int) ($packingCounts['IN_PROGRESS'] ?? 0),
                'completedToday' => DB::table('picking_sessions')->whereBetween('completed_at', $todayRange)->count()
                    + DB::table('packing_sessions')->whereBetween('completed_at', $todayRange)->count(),

                // 10. PO siap diterima
                'poReady' => DB::table('purchase_orders')->where('status', 'READY_FOR_PICKUP')->count(),

                // 11. Order perlu picking
                'orderNeedPicking' => DB::table('orders')->whereIn('status', ['CONFIRMED', 'PENDING'])->count(),

                // 12. Order perlu packing (status sudah picked, belum packed)
                'orderNeedPacking' => DB::table('orders')->whereIn('status', ['PICKED', 'PICKING'])->count(),

                // 13. Low stock products (top 5) - untuk panel
                'lowStockProducts' => $this->lowStockQuery()
                    ->orderBy('current_stock')
                    ->limit(5)
                    ->get(),
            ];
        });

        extract($stats);

        // 14. Pergerakan terakhir (pakai Eloquent agar relasi berfungsi)
        $recentMovements = StockMovement::with(['user:id,name', 'masterSku:sku,product_name'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        return view('dashboard.gudang', compact(
            'totalStock',
            'stockValue',
            'lowStock',
            'inboundToday',
            'outboundToday',
            'pickingPending',
            'packingPending',
            'inProgress',
            'completedToday',
            'poReady',           // ← untuk $poPending di view
            'orderNeedPicking',  // ← untuk $pendingPick di view
            'orderNeedPacking',  // ← untuk $pendingPack di view
            'lowStockProducts',  // ← untuk $lowStockItems di view
            'recentMovements'
        ));
    }

    /**
     * SKU aktif + total stoknya. Stok dijumlahkan sekali per SKU di subquery,
     * lalu di-join (lebih ringan daripada subquery per baris, dan aman untuk ONLY_FULL_GROUP_BY).
     */
    private function skusWithStock()
    {
        $stockPerSku = DB::table('stock_actives')
            ->select('sku', DB::raw('SUM(qty) as total_qty'))
            ->groupBy('sku');

        return MasterSku::select('master_skus.*', DB::raw('COALESCE(stock_sum.total_qty, 0) as current_stock'))
            ->leftJoinSub($stockPerSku, 'stock_sum', 'stock_sum.sku', '=', 'master_skus.sku')
            ->where('master_skus.status', 'AKTIF');
    }

    private function lowStockQuery()
    {
        return $this->skusWithStock()
            ->where('master_skus.min_stock', '>', 0)
            ->whereRaw('COALESCE(stock_sum.total_qty, 0) <= master_skus.min_stock');
    }

    /**
     * Jumlah SKU aktif yang stoknya di bawah/sama dengan min_stock.
     */
    private function lowStockCount(): int
    {
        return $this->lowStockQuery()->count();
    }

    /**
     * Total qty INBOUND & OUTBOUND dalam rentang waktu, satu query.
     * Memakai whereBetween (bukan whereDate) supaya index created_at terpakai.
     *
     * @return array{0:int,1:int}
     */
    private function movementTotalsBetween($start, $end): array
    {
        $totals = StockMovement::selectRaw('type, SUM(qty) as total')
            ->whereIn('type', ['INBOUND', 'OUTBOUND'])
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('type')
            ->pluck('total', 'type');

        return [(int) ($totals['INBOUND'] ?? 0), (int) ($totals['OUTBOUND'] ?? 0)];
    }

    /**
     * Data chart inbound/outbound 7 hari terakhir dalam satu query (sebelumnya 14 query).
     *
     * @return array{0:\Illuminate\Support\Collection,1:array,2:array}
     */
    private function movementChartLast7Days(): array
    {
        $start = today()->subDays(6);

        $rows = StockMovement::selectRaw('DATE(created_at) as day, type, SUM(qty) as total')
            ->whereIn('type', ['INBOUND', 'OUTBOUND'])
            ->whereBetween('created_at', [$start, today()->endOfDay()])
            ->groupBy('day', 'type')
            ->get()
            ->groupBy(fn ($row) => $row->day . '|' . $row->type);

        $labels = collect();
        $inbound = [];
        $outbound = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->format('Y-m-d');

            $labels->push($date->format('d M'));
            $inbound[] = (int) ($rows->get($key . '|INBOUND')?->first()->total ?? 0);
            $outbound[] = (int) ($rows->get($key . '|OUTBOUND')?->first()->total ?? 0);
        }

        return [$labels, $inbound, $outbound];
    }
}
