<?php

namespace App\Http\Controllers;

use App\Models\MasterSku;
use App\Models\PurchaseOrder;
use App\Models\StockActive;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class NotificationController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Isi notifikasi hanya bergantung pada role, jadi di-cache per role
        // agar polling dari banyak tab/staf tidak membebani database.
        $payload = Cache::remember('notifications.role.' . $user->role, 60, fn () => $this->build($user));

        return response()->json($payload);
    }

    private function build($user): array
    {
        $notifications = [];

        // 1. Low Stock Alerts (Priority: HIGH)
        if (in_array($user->role, ['OWNER', 'SUPERVISOR', 'STAFF_GUDANG'])) {
            $lowStockItems = MasterSku::select('master_skus.sku', 'master_skus.product_name', 'master_skus.min_stock')
                ->join('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
                ->where('master_skus.status', 'AKTIF')
                ->whereRaw('stock_actives.qty <= master_skus.min_stock')
                ->selectRaw('SUM(stock_actives.qty) as current_qty')
                ->groupBy('master_skus.sku', 'master_skus.product_name', 'master_skus.min_stock')
                ->orderBy('current_qty')
                ->limit(10)
                ->get();

            foreach ($lowStockItems as $item) {
                $notifications[] = [
                    'id' => 'low_stock_' . $item->sku,
                    'type' => 'warning',
                    'priority' => 'high',
                    'title' => 'Stok Menipis',
                    'subtitle' => $item->sku,
                    'message' => $item->product_name,
                    'detail' => "Sisa: {$item->current_qty} | Min: {$item->min_stock}",
                    'time' => now()->diffForHumans(),
                    'timestamp' => now()->toIso8601String(),
                    'link' => route('master-skus.index'),
                    'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                    'color' => 'amber',
                ];
            }
        }

        // 2. Pending PO Approvals (Priority: MEDIUM)
        if (in_array($user->role, ['OWNER', 'SUPERVISOR'])) {
            $pendingPOs = PurchaseOrder::where('status', 'PENDING')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'po_number', 'supplier_name', 'created_at']);

            foreach ($pendingPOs as $po) {
                $notifications[] = [
                    'id' => 'po_' . $po->id,
                    'type' => 'info',
                    'priority' => 'medium',
                    'title' => 'PO Menunggu Approval',
                    'subtitle' => $po->po_number,
                    'message' => $po->supplier_name,
                    'detail' => 'Klik untuk review',
                    'time' => Carbon::parse($po->created_at)->diffForHumans(),
                    'timestamp' => Carbon::parse($po->created_at)->toIso8601String(),
                    'link' => route('po.show', $po->id),
                    'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                    'color' => 'cyan',
                ];
            }
        }

        // 3. Recent Stock Movements (Priority: LOW)
        $recentMovements = StockMovement::with(['masterSku:sku,product_name'])
            ->whereIn('type', ['INBOUND', 'OUTBOUND'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        foreach ($recentMovements as $movement) {
            $isInbound = $movement->type === 'INBOUND';
            $notifications[] = [
                'id' => 'movement_' . $movement->id,
                'type' => $isInbound ? 'success' : 'info',
                'priority' => 'low',
                'title' => $isInbound ? 'Barang Masuk' : 'Barang Keluar',
                'subtitle' => $movement->sku,
                'message' => $movement->masterSku?->product_name ?? 'Unknown',
                'detail' => ($isInbound ? '+' : '-') . number_format($movement->qty) . ' unit',
                'time' => Carbon::parse($movement->created_at)->diffForHumans(),
                'timestamp' => Carbon::parse($movement->created_at)->toIso8601String(),
                'link' => route('reports.movement'),
                'icon' => $isInbound ? 'M7 16V4m0 0L3 8m4-4l4 4' : 'M17 8V20m0 0l4-4m-4 4l-4-4',
                'color' => $isInbound ? 'emerald' : 'blue',
            ];
        }

        // Sort by timestamp (newest first)
        usort($notifications, function ($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return [
            'notifications' => $notifications,
            'unread_count' => count($notifications),
            'summary' => [
                'warnings' => count(array_filter($notifications, fn($n) => $n['type'] === 'warning')),
                'info' => count(array_filter($notifications, fn($n) => $n['type'] === 'info')),
                'success' => count(array_filter($notifications, fn($n) => $n['type'] === 'success')),
            ]
        ];
    }
}
