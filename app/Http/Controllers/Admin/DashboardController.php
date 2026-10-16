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
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function owner()
    {
        // ==================== STATS CARDS ====================

        // Total Stok (unit)
        $totalStock = StockActive::sum('qty');

        // Nilai Stok (Rp) - SUM(qty * price)
        $stockValue = DB::table('stock_actives')
            ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
            ->where('master_skus.status', 'AKTIF')
            ->sum(DB::raw('stock_actives.qty * master_skus.price'));

        // Low Stock Count
        $lowStockCount = MasterSku::where('status', 'AKTIF')
            ->whereRaw('min_stock > 0')
            ->whereRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) <= min_stock')
            ->count();

        // Inbound & Outbound Hari Ini
        $todayInbound = StockMovement::where('type', 'INBOUND')
            ->whereDate('created_at', today())
            ->sum('qty');

        $todayOutbound = StockMovement::where('type', 'OUTBOUND')
            ->whereDate('created_at', today())
            ->sum('qty');

        // In Production (PO dengan status IN_PRODUCTION)
        $inProduction = PurchaseOrder::where('status', 'IN_PRODUCTION')->count();

        // Supplier Aktif
        $activeSuppliers = Supplier::where('status', 'AKTIF')->count();

        // Order Aktif (PO dengan status APPROVED/SENT)
        $activeOrders = PurchaseOrder::whereIn('status', ['APPROVED', 'SENT'])->count();

        // ==================== CHART DATA (7 Hari Terakhir) ====================

        $last7Days = collect();
        $inboundData = [];
        $outboundData = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $label = now()->subDays($i)->format('d M');

            $inbound = StockMovement::where('type', 'INBOUND')
                ->whereDate('created_at', $date)
                ->sum('qty');

            $outbound = StockMovement::where('type', 'OUTBOUND')
                ->whereDate('created_at', $date)
                ->sum('qty');

            $last7Days->push($label);
            $inboundData[] = $inbound;
            $outboundData[] = $outbound;
        }

        // ==================== DISTRIBUSI STOK PER KATEGORI ====================

        $categoryDistribution = MasterSku::select('master_skus.size_category', DB::raw('COALESCE(SUM(stock_actives.qty), 0) as total_qty'))
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->where('master_skus.status', 'AKTIF')
            ->groupBy('master_skus.size_category')
            ->orderByDesc('total_qty')
            ->get();

        // ==================== TOP 5 SKU STOK TERBANYAK ====================

        $topSkus = MasterSku::select('master_skus.*', DB::raw('COALESCE(SUM(stock_actives.qty), 0) as current_stock'))
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->where('master_skus.status', 'AKTIF')
            ->groupBy('master_skus.id')
            ->orderByDesc('current_stock')
            ->limit(5)
            ->get();

        // ==================== PERINGATAN LOW STOCK ====================

        $lowStockItems = MasterSku::select('master_skus.*', DB::raw('COALESCE(SUM(stock_actives.qty), 0) as current_stock'))
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->where('master_skus.status', 'AKTIF')
            ->whereRaw('master_skus.min_stock > 0')
            ->groupBy('master_skus.id')
            ->havingRaw('COALESCE(SUM(stock_actives.qty), 0) <= master_skus.min_stock')
            ->orderBy('current_stock')
            ->limit(5)
            ->get();

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
        $user = auth()->user();
        
        // 1. Total stok unit
        $totalStock = StockActive::sum('qty');
        
        // 2. Nilai stok (price × qty)
        $stockValue = DB::table('stock_actives')
            ->join('master_skus', 'stock_actives.sku', '=', 'master_skus.sku')
            ->selectRaw('SUM(master_skus.price * stock_actives.qty) as total_value')
            ->value('total_value') ?? 0;
        
        // 3. Low stock alert count
        $lowStock = MasterSku::where('status', 'AKTIF')
            ->whereRaw('min_stock > 0')
            ->whereRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) <= min_stock')
            ->count();
        
        // 4. Inbound hari ini
        $inboundToday = $this->getInboundToday();
        
        // 5. Outbound hari ini
        $outboundToday = $this->getOutboundToday();
        
        // 6-9. Session stats
        $pickingPending = DB::table('picking_sessions')->where('status', 'PENDING')->count();
        $packingPending = DB::table('packing_sessions')->where('status', 'PENDING')->count();
        $inProgress = DB::table('picking_sessions')->where('status', 'IN_PROGRESS')->count() 
                    + DB::table('packing_sessions')->where('status', 'IN_PROGRESS')->count();
        $completedToday = DB::table('picking_sessions')->whereDate('completed_at', today())->count()
                        + DB::table('packing_sessions')->whereDate('completed_at', today())->count();
        
        // 10. PO siap diterima
        $poReady = DB::table('purchase_orders')->where('status', 'READY_FOR_PICKUP')->count();
        
        // 11. ✅ TAMBAH: Order perlu picking
        $orderNeedPicking = DB::table('orders')->whereIn('status', ['CONFIRMED', 'PENDING'])->count();
        
        // 12. ✅ TAMBAH: Order perlu packing (status sudah picked, belum packed)
        $orderNeedPacking = DB::table('orders')->whereIn('status', ['PICKED', 'PICKING'])->count();
        
        // 13. Low stock products (top 5) - untuk panel
        $lowStockProducts = MasterSku::select('master_skus.*')
            ->selectRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) as current_stock')
            ->where('master_skus.status', 'AKTIF')
            ->whereRaw('min_stock > 0')
            ->whereRaw('COALESCE((SELECT SUM(qty) FROM stock_actives WHERE stock_actives.sku = master_skus.sku), 0) <= min_stock')
            ->orderBy('current_stock')
            ->limit(5)
            ->get();
        
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
     * ✅ FIX: Hitung inbound hari ini - tanpa Schema facade
     */
    private function getInboundToday(): int
    {
        try {
            // Coba kolom qty_received (paling umum di inbound_receipt_items)
            $result = DB::table('inbound_receipts')
                ->join('inbound_receipt_items', 'inbound_receipts.id', '=', 'inbound_receipt_items.inbound_receipt_id')
                ->whereDate('inbound_receipts.created_at', today())
                ->sum('inbound_receipt_items.qty_received');
            
            if ($result !== null) {
                return (int) $result;
            }
        } catch (\Exception $e) {
            // Kolom qty_received tidak ada, coba kolom lain
        }

        try {
            // Fallback: coba received_qty
            $result = DB::table('inbound_receipts')
                ->join('inbound_receipt_items', 'inbound_receipts.id', '=', 'inbound_receipt_items.inbound_receipt_id')
                ->whereDate('inbound_receipts.created_at', today())
                ->sum('inbound_receipt_items.received_qty');
            
            if ($result !== null) {
                return (int) $result;
            }
        } catch (\Exception $e) {
            // Kolom received_qty tidak ada, coba kolom lain
        }

        try {
            // Fallback terakhir: coba qty
            $result = DB::table('inbound_receipts')
                ->join('inbound_receipt_items', 'inbound_receipts.id', '=', 'inbound_receipt_items.inbound_receipt_id')
                ->whereDate('inbound_receipts.created_at', today())
                ->sum('inbound_receipt_items.qty');
            
            return (int) ($result ?? 0);
        } catch (\Exception $e) {
            \Log::warning('Failed to get inbound today: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * ✅ FIX: Hitung outbound hari ini - tanpa Schema facade
     */
    private function getOutboundToday(): int
    {
        try {
            // Coba total_qty (paling umum di orders)
            $result = DB::table('orders')
                ->whereDate('created_at', today())
                ->sum('total_qty');
            
            if ($result !== null) {
                return (int) $result;
            }
        } catch (\Exception $e) {
            // Kolom total_qty tidak ada, coba kolom lain
        }

        try {
            // Fallback: coba total_quantity
            $result = DB::table('orders')
                ->whereDate('created_at', today())
                ->sum('total_quantity');
            
            if ($result !== null) {
                return (int) $result;
            }
        } catch (\Exception $e) {
            // Kolom total_quantity tidak ada, coba kolom lain
        }

        try {
            // Fallback terakhir: hitung jumlah order saja
            $result = DB::table('orders')
                ->whereDate('created_at', today())
                ->count();
            
            return (int) $result;
        } catch (\Exception $e) {
            \Log::warning('Failed to get outbound today: ' . $e->getMessage());
            return 0;
        }
    }
}
