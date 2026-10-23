<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\PickingSessionItem;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\WarehouseRack;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Peta rak gudang.
 *
 * Qty yang tampil selalu diambil dari stock_actives (stok asli per bin),
 * sehingga peta rak sama dengan hasil inbound, picking, dan stock opname.
 * Bin masuk ke sebuah rak bila kodenya diawali kode rak, misalnya A-01-01
 * (atau format lama RAK-A-01) masuk ke rak A.
 * Tabel rack_products hanya menyimpan SKU yang "ditetapkan" ke rak beserta
 * kapasitas maksimalnya, bukan qty.
 */
class WarehouseMappingController extends Controller
{
    /**
     * Denah gudang dengan jumlah SKU dan unit per rak
     */
    public function index()
    {
        $racks = WarehouseRack::orderBy('position_y')->orderBy('position_x')->get();

        $stockByRack = [];
        $stocks = DB::table('stock_actives')
            ->where('qty', '>', 0)
            ->select('sku', 'bin_code', 'qty')
            ->get();

        $rackCodes = $racks->pluck('code')->flip();
        $unplaced = ['skus' => [], 'qty' => 0];

        foreach ($stocks as $stock) {
            $code = WarehouseRack::codeFromBin($stock->bin_code);
            if ($code === null || !isset($rackCodes[$code])) {
                $unplaced['skus'][$stock->sku] = true;
                $unplaced['qty'] += $stock->qty;
                continue;
            }
            $stockByRack[$code]['skus'][$stock->sku] = true;
            $stockByRack[$code]['qty'] = ($stockByRack[$code]['qty'] ?? 0) + $stock->qty;
        }

        $assignedByRack = DB::table('rack_products')
            ->select('warehouse_rack_id', 'sku')
            ->get()
            ->groupBy('warehouse_rack_id');

        foreach ($racks as $rack) {
            $skus = array_keys($stockByRack[$rack->code]['skus'] ?? []);
            $assigned = ($assignedByRack[$rack->id] ?? collect())->pluck('sku')->all();

            $rack->products_count = count(array_unique(array_merge($skus, $assigned)));
            $rack->total_qty = $stockByRack[$rack->code]['qty'] ?? 0;
        }

        $unplaced['skus'] = count($unplaced['skus']);

        return view('warehouse.mapping.index', compact('racks', 'unplaced'));
    }

    /**
     * Detail rak: SKU yang ada stoknya di bin rak ini, ditambah SKU yang
     * ditetapkan ke rak ini walau stoknya sedang kosong.
     */
    public function show($rackCode)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        // Semua SKU aktif dengan total stok (untuk pencarian di keranjang)
        $allSkus = MasterSku::where('status', 'AKTIF')
            ->leftJoin('stock_actives', 'master_skus.sku', '=', 'stock_actives.sku')
            ->select(
                'master_skus.sku',
                'master_skus.product_name',
                'master_skus.article',
                'master_skus.size',
                'master_skus.size_category',
                'master_skus.version',
                'master_skus.sub_version',
                DB::raw('COALESCE(SUM(stock_actives.qty), 0) as current_stock')
            )
            ->groupBy(
                'master_skus.sku',
                'master_skus.product_name',
                'master_skus.article',
                'master_skus.size',
                'master_skus.size_category',
                'master_skus.version',
                'master_skus.sub_version'
            )
            ->orderBy('master_skus.product_name')
            ->get();

        $unplacedBySku = $this->unplacedStock()->map(fn ($bins) => (int) $bins->sum('qty'));
        $allSkus->each(fn ($sku) => $sku->unplaced_stock = $unplacedBySku->get($sku->sku, 0));

        $rackProducts = $this->rackProducts($rack);

        $groupedProducts = $rackProducts->groupBy(function ($product) {
            return $product->product_name . '|' . ($product->version ?? '');
        });

        $totalSku = $rackProducts->count();
        $totalQty = $rackProducts->sum('current_qty');

        return view('warehouse.mapping.show', compact(
            'rack',
            'allSkus',
            'rackProducts',
            'groupedProducts',
            'totalSku',
            'totalQty'
        ));
    }

    /**
     * Tetapkan SKU ke rak beserta kapasitas maksimalnya.
     * Qty tidak diisi di sini: stok masuk lewat Inbound atau Stock Opname.
     */
    public function assign(Request $request, $rackCode)
    {
        $validated = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.sku' => 'required|exists:master_skus,sku',
            'products.*.max_capacity' => 'required|integer|min:1',
            'move_unplaced' => 'nullable|boolean',
            'bin_code' => 'nullable|string|max:50',
        ]);

        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();
        $targetBin = strtoupper(trim($validated['bin_code'] ?? '')) ?: $rack->code . '-01-01';
        if (WarehouseRack::codeFromBin($targetBin) !== $rack->code) {
            return back()->withErrors(['bin_code' => "Lokasi {$targetBin} bukan bagian dari rak {$rack->code}. Contoh: {$rack->code}-01-01."]);
        }
        $moveUnplaced = $request->boolean('move_unplaced');
        $moved = 0;

        try {
            DB::transaction(function () use ($rack, $validated, $targetBin, $moveUnplaced, $request, &$moved) {
                foreach ($validated['products'] as $product) {
                    $exists = DB::table('rack_products')
                        ->where('warehouse_rack_id', $rack->id)
                        ->where('sku', $product['sku'])
                        ->exists();

                    if ($exists) {
                        DB::table('rack_products')
                            ->where('warehouse_rack_id', $rack->id)
                            ->where('sku', $product['sku'])
                            ->update([
                                'max_capacity' => $product['max_capacity'],
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('rack_products')->insert([
                            'warehouse_rack_id' => $rack->id,
                            'sku' => $product['sku'],
                            'current_qty' => 0,
                            'max_capacity' => $product['max_capacity'],
                            'bin_location' => $targetBin,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($moveUnplaced) {
                    $moved = $this->moveUnplacedStock(array_column($validated['products'], 'sku'), $targetBin, $request);
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'BULK_ASSIGN',
                    'module' => 'WAREHOUSE_MAPPING',
                    'new_values' => json_encode([
                        'rack' => $rack->code,
                        'skus' => array_column($validated['products'], 'sku'),
                        'moved_to' => $moved ? $targetBin : null,
                        'moved_qty' => $moved,
                    ]),
                    'ip_address' => request()->ip(),
                ]);
            });

            $message = count($validated['products']) . ' produk berhasil ditetapkan ke rak ' . $rackCode . '.';
            if ($moved) {
                $message .= " {$moved} pcs stok yang belum punya rak dipindah ke {$targetBin}.";
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menetapkan produk: ' . safe_error_message($e));
        }
    }

    /**
     * Lepas SKU dari rak. Stok di bin tidak berubah.
     */
    public function unassign($rackCode, $sku)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();

        try {
            DB::transaction(function () use ($rack, $sku) {
                DB::table('rack_products')
                    ->where('warehouse_rack_id', $rack->id)
                    ->where('sku', $sku)
                    ->delete();

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'UNASSIGN',
                    'module' => 'WAREHOUSE_MAPPING',
                    'new_values' => json_encode(['rack' => $rack->code, 'sku' => $sku]),
                    'ip_address' => request()->ip(),
                ]);
            });

            return back()->with('success', 'Produk dilepas dari rak ' . $rackCode . '. Stok di bin tidak berubah.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal melepas produk: ' . safe_error_message($e));
        }
    }

    /**
     * Edit beberapa SKU sekaligus di rak ini (dipilih atau semua).
     * Kapasitas kosong = tidak diubah. Lokasi bin baru memindahkan semua stok SKU itu
     * dari bin rak ini ke bin baru (tercatat TRANSFER); bin di rak lain ikut memindahkan penetapannya.
     */
    public function bulkUpdate(Request $request, $rackCode)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|exists:master_skus,sku',
            'items.*.max_capacity' => 'nullable|integer|min:1',
            'items.*.bin_code' => 'nullable|string|max:50',
        ]);

        $rackIds = WarehouseRack::pluck('id', 'code');
        $items = collect($validated['items'])->map(function ($item) {
            $item['bin_code'] = strtoupper(preg_replace('/\s+/', '', $item['bin_code'] ?? ''));

            return $item;
        });

        $badBin = $items->first(fn ($item) => $item['bin_code'] !== '' && !$rackIds->has(WarehouseRack::codeFromBin($item['bin_code'])));
        if ($badBin) {
            return back()->with('error', "Lokasi {$badBin['bin_code']} bukan bin rak yang ada di denah. Contoh: {$rack->code}-01-01.");
        }

        $changed = 0;
        $moved = 0;
        $skipped = [];

        try {
            DB::transaction(function () use ($rack, $items, $rackIds, $request, &$changed, &$moved, &$skipped) {
                $reference = 'MAP-' . now()->format('YmdHis');

                foreach ($items as $item) {
                    $sku = $item['sku'];
                    $assignment = DB::table('rack_products')->where('warehouse_rack_id', $rack->id)->where('sku', $sku)->first();
                    $sources = StockActive::where('sku', $sku)->where('qty', '>', 0)->lockForUpdate()->get()
                        ->filter(fn ($stock) => WarehouseRack::codeFromBin($stock->bin_code) === $rack->code);
                    $targetBin = $item['bin_code'];
                    $didChange = false;

                    // Pindah lokasi: semua bin lain di rak ini masuk ke bin baru
                    $toMove = $targetBin === '' ? collect() : $sources->filter(fn ($stock) => strtoupper($stock->bin_code) !== $targetBin);
                    if ($toMove->isNotEmpty()) {
                        $busy = PickingSessionItem::where('sku', $sku)
                            ->whereIn('bin_code', $toMove->pluck('bin_code'))
                            ->whereColumn('qty_picked', '<', 'qty_ordered')
                            ->whereHas('session', fn ($q) => $q->whereIn('status', ['PENDING', 'IN_PROGRESS']))
                            ->exists();
                        if ($busy) {
                            $skipped[] = $sku;
                            continue;
                        }

                        foreach ($toMove as $source) {
                            $qty = (int) $source->qty;
                            $target = StockActive::lockForUpdate()->firstOrCreate(
                                ['sku' => $sku, 'bin_code' => $targetBin],
                                ['qty' => 0, 'reserved_qty' => 0]
                            );
                            $targetBefore = (int) $target->qty;
                            $target->update(['qty' => $targetBefore + $qty, 'last_movement_at' => now()]);

                            StockMovement::create([
                                'user_id' => auth()->id(),
                                'sku' => $sku,
                                'bin_code' => $source->bin_code,
                                'type' => 'TRANSFER',
                                'qty' => $qty,
                                'qty_before' => $qty,
                                'qty_after' => 0,
                                'reference_no' => $reference,
                                'reference_type' => 'MAPPING',
                                'notes' => "Koreksi lokasi di denah: {$source->bin_code} ke {$targetBin} (sebelumnya {$targetBefore} pcs di sana)",
                                'ip_address' => $request->ip(),
                            ]);

                            $source->delete();
                            $moved += $qty;
                        }
                        $didChange = true;
                    }

                    // Penetapan SKU ke rak: ikut pindah bila bin baru ada di rak lain
                    $targetRackId = $targetBin !== '' ? $rackIds[WarehouseRack::codeFromBin($targetBin)] : $rack->id;
                    $capacity = $item['max_capacity'] ?? null;
                    if ($assignment || $capacity) {
                        $values = array_filter([
                            'max_capacity' => $capacity ?? $assignment?->max_capacity,
                            'bin_location' => $targetBin !== '' ? $targetBin : $assignment?->bin_location,
                        ], fn ($value) => $value !== null);

                        if ($assignment) {
                            DB::table('rack_products')->where('id', $assignment->id)->delete();
                        }
                        DB::table('rack_products')->updateOrInsert(
                            ['warehouse_rack_id' => $targetRackId, 'sku' => $sku],
                            $values + ['current_qty' => 0, 'created_at' => $assignment?->created_at ?? now(), 'updated_at' => now()]
                        );

                        $didChange = $didChange
                            || !$assignment
                            || $targetRackId !== $rack->id
                            || (int) $assignment->max_capacity !== (int) ($values['max_capacity'] ?? 0)
                            || ($targetBin !== '' && $assignment->bin_location !== $targetBin);
                    }

                    $changed += $didChange ? 1 : 0;
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'BULK_UPDATE',
                    'module' => 'WAREHOUSE_MAPPING',
                    'new_values' => json_encode([
                        'rack' => $rack->code,
                        'items' => $items->values()->all(),
                        'moved_qty' => $moved,
                        'skipped' => $skipped,
                    ]),
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyimpan perubahan: ' . safe_error_message($e));
        }

        $message = "{$changed} SKU diperbarui.";
        if ($moved) {
            $message .= " {$moved} pcs stok dipindah ke lokasi baru.";
        }
        $redirect = back()->with('success', $message);

        return $skipped
            ? $redirect->with('error', 'Lokasi tidak dipindah karena sedang dipakai picking: ' . implode(', ', $skipped) . '.')
            : $redirect;
    }

    /**
     * Lepas beberapa SKU dari rak sekaligus (dipilih atau semua). Stok di bin tidak berubah.
     */
    public function bulkDelete(Request $request, $rackCode)
    {
        $rack = WarehouseRack::where('code', $rackCode)->firstOrFail();
        $validated = $request->validate([
            'skus' => 'required|array|min:1',
            'skus.*' => 'required|string',
        ]);

        $removed = DB::transaction(function () use ($rack, $validated, $request) {
            $removed = DB::table('rack_products')
                ->where('warehouse_rack_id', $rack->id)
                ->whereIn('sku', $validated['skus'])
                ->delete();

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'BULK_UNASSIGN',
                'module' => 'WAREHOUSE_MAPPING',
                'new_values' => json_encode(['rack' => $rack->code, 'skus' => $validated['skus']]),
                'ip_address' => $request->ip(),
            ]);

            return $removed;
        });

        $stillStocked = $this->rackProducts($rack)->whereIn('sku', $validated['skus'])->pluck('sku');
        $message = "{$removed} SKU dilepas dari rak {$rack->code}.";
        if ($stillStocked->isNotEmpty()) {
            $message .= " {$stillStocked->count()} SKU masih tampil karena stoknya ada di bin rak ini; pindahkan lokasinya lewat Edit atau kurangi lewat Stock Opname.";
        }

        return back()->with('success', $message);
    }

    /**
     * Stok yang lokasinya bukan rak di denah (misalnya DEFAULT-01 dari import Master SKU
     * atau GUDANG-UTAMA dari form SKU), dikelompokkan per SKU.
     */
    private function unplacedStock(?array $skus = null): Collection
    {
        $rackCodes = WarehouseRack::pluck('code')->flip();

        return StockActive::where('qty', '>', 0)
            ->when($skus !== null, fn ($q) => $q->whereIn('sku', $skus))
            ->get()
            ->filter(fn ($stock) => !isset($rackCodes[WarehouseRack::codeFromBin($stock->bin_code) ?? '']))
            ->groupBy('sku');
    }

    /**
     * Pindahkan stok yang belum punya rak ke bin tujuan (tercatat sebagai TRANSFER). Mengembalikan jumlah pcs.
     */
    private function moveUnplacedStock(array $skus, string $targetBin, Request $request): int
    {
        $moved = 0;

        foreach ($this->unplacedStock($skus) as $sku => $sources) {
            foreach ($sources as $source) {
                $source = StockActive::lockForUpdate()->find($source->id);
                $qty = (int) $source->qty;
                if ($qty <= 0) {
                    continue;
                }

                $target = StockActive::lockForUpdate()->firstOrCreate(
                    ['sku' => $sku, 'bin_code' => $targetBin],
                    ['qty' => 0, 'reserved_qty' => 0]
                );
                $targetBefore = (int) $target->qty;
                $target->update(['qty' => $targetBefore + $qty, 'last_movement_at' => now()]);

                // Dicatat seperti Transfer Stok: satu baris di bin asal, catatan menyebut tujuannya
                StockMovement::create([
                    'user_id' => auth()->id(),
                    'sku' => $sku,
                    'bin_code' => $source->bin_code,
                    'type' => 'TRANSFER',
                    'qty' => $qty,
                    'qty_before' => $qty,
                    'qty_after' => 0,
                    'reference_no' => 'MAP-' . now()->format('YmdHis'),
                    'reference_type' => 'MAPPING',
                    'notes' => "Ditata ke rak: {$source->bin_code} ke {$targetBin} (sebelumnya {$targetBefore} pcs di sana)",
                    'ip_address' => $request->ip(),
                ]);

                $source->delete();
                $moved += $qty;
            }
        }

        return $moved;
    }

    /**
     * Baris produk di rak: qty asli dari stock_actives per bin rak ini,
     * digabung dengan SKU yang ditetapkan ke rak (kapasitas).
     */
    private function rackProducts(WarehouseRack $rack): Collection
    {
        $stocks = DB::table('stock_actives')
            ->where('qty', '>', 0)
            ->select('sku', 'bin_code', 'qty')
            ->get()
            ->filter(fn ($stock) => WarehouseRack::codeFromBin($stock->bin_code) === $rack->code)
            ->groupBy('sku');

        $assigned = DB::table('rack_products')
            ->where('warehouse_rack_id', $rack->id)
            ->pluck('max_capacity', 'sku');

        $skuCodes = $stocks->keys()->merge($assigned->keys())->unique()->values();

        if ($skuCodes->isEmpty()) {
            return collect();
        }

        return MasterSku::whereIn('sku', $skuCodes)
            ->orderBy('product_name')
            ->orderBy('size')
            ->get(['id', 'sku', 'product_name', 'article', 'version', 'sub_version', 'size', 'size_category', 'status'])
            ->map(function ($sku) use ($stocks, $assigned) {
                $bins = $stocks->get($sku->sku, collect());

                return (object) [
                    'sku_id' => $sku->id,
                    'sku' => $sku->sku,
                    'product_name' => $sku->product_name,
                    'article' => $sku->article,
                    'version' => $sku->version,
                    'sub_version' => $sku->sub_version,
                    'size' => $sku->size,
                    'size_category' => $sku->size_category,
                    'status' => $sku->status,
                    'current_qty' => (int) $bins->sum('qty'),
                    'max_capacity' => $assigned->get($sku->sku),
                    'bin_location' => $bins->pluck('bin_code')->unique()->sort()->implode(', '),
                    'is_assigned' => $assigned->has($sku->sku),
                ];
            })
            ->values();
    }
}
