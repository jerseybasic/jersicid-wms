<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\WarehouseRack;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Isi ulang rak: SKU yang ditetapkan ke rak (Denah Gudang) dengan stok di raknya
 * di bawah batas kapasitas, sementara stok cadangannya ada di bin lain.
 * Setiap saran membuka Transfer Stok yang sudah terisi.
 */
class ReplenishmentController extends Controller
{
    public const LEVELS = [25, 50, 75];

    public function index(Request $request)
    {
        $level = in_array((int) $request->query('batas'), self::LEVELS, true) ? (int) $request->query('batas') : 50;

        $rows = self::rows();
        $refill = $rows->filter(fn ($row) => $row->percent <= $level && $row->source !== null)
            ->sortBy([['percent', 'asc'], ['rack', 'asc']])->values();
        // Habis di semua lokasi: satu baris per SKU walau ditetapkan ke beberapa rak
        $empty = $rows->filter(fn ($row) => $row->total === 0)->unique('sku')->values();
        $over = $rows->filter(fn ($row) => $row->in_rack > $row->capacity)->values();

        return view('warehouse.replenishment.index', compact('refill', 'empty', 'over', 'level'));
    }

    /** Jumlah SKU yang perlu diisi ulang pada batas 50%, untuk layar Tugas */
    public static function pendingCount(): int
    {
        return self::rows()->filter(fn ($row) => $row->percent <= 50 && $row->source !== null)->count();
    }

    /**
     * Satu baris per penetapan SKU ke rak: stok di rak itu, kapasitas, dan bin cadangan terbanyak di luar rak.
     */
    private static function rows(): Collection
    {
        $assignments = DB::table('rack_products')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'rack_products.warehouse_rack_id')
            ->select('rack_products.sku', 'rack_products.max_capacity', 'rack_products.bin_location', 'warehouse_racks.code as rack')
            ->get();
        if ($assignments->isEmpty()) {
            return collect();
        }

        $assignedRacks = $assignments->groupBy('sku')->map(fn ($rows) => $rows->pluck('rack')->all());
        $stocks = StockActive::whereIn('sku', $assignedRacks->keys())->where('qty', '>', 0)
            ->get(['sku', 'bin_code', 'qty'])
            ->groupBy('sku');
        $skus = MasterSku::whereIn('sku', $assignedRacks->keys())->get(['sku', 'product_name', 'size'])->keyBy('sku');

        return $assignments->map(function ($assignment) use ($stocks, $assignedRacks, $skus) {
            $bins = ($stocks[$assignment->sku] ?? collect())->map(fn ($stock) => (object) [
                'bin' => strtoupper($stock->bin_code),
                'qty' => (int) $stock->qty,
                'rack' => WarehouseRack::codeFromBin($stock->bin_code),
            ]);
            $inRack = $bins->where('rack', $assignment->rack);
            // Cadangan: bin di luar semua rak tempat SKU ini ditetapkan
            $source = $bins->reject(fn ($bin) => in_array($bin->rack, $assignedRacks[$assignment->sku], true))
                ->sortByDesc('qty')->first();

            $capacity = max(1, (int) $assignment->max_capacity);
            $qtyInRack = (int) $inRack->sum('qty');
            $location = strtoupper((string) $assignment->bin_location);
            $target = WarehouseRack::codeFromBin($location) === $assignment->rack
                ? $location
                : ($inRack->sortByDesc('qty')->first()?->bin ?? $assignment->rack . '-01-01');

            return (object) [
                'sku' => $assignment->sku,
                'name' => trim(($skus[$assignment->sku]->product_name ?? '') . ' ' . ($skus[$assignment->sku]->size ?? '')),
                'rack' => $assignment->rack,
                'target' => $target,
                'capacity' => $capacity,
                'in_rack' => $qtyInRack,
                'total' => (int) $bins->sum('qty'),
                'percent' => (int) floor($qtyInRack * 100 / $capacity),
                'source' => $source,
                'suggest' => $source ? min(max(0, $capacity - $qtyInRack), $source->qty) : 0,
            ];
        })->values();
    }
}
