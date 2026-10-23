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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transfer stok antar bin dengan alur scan: scan bin asal, pilih/scan barang yang
 * dipindah (bisa banyak SKU sekaligus), lalu scan bin tujuan.
 */
class TransferController extends Controller
{
    public function index(Request $request)
    {
        $search = strtoupper(trim((string) $request->query('cari', '')));

        $transfers = StockMovement::where('type', 'TRANSFER')
            ->with(['user:id,name', 'masterSku:sku,product_name,size'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('sku', 'like', "%{$search}%")
                ->orWhere('bin_code', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhere('reference_no', 'like', "%{$search}%")))
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('warehouse.transfer.index', compact('transfers', 'search'));
    }

    /**
     * Isi kode yang di-scan: bin (isi stoknya) atau SKU (bin-bin tempat SKU itu berada).
     */
    public function lookup(Request $request)
    {
        $code = strtoupper(trim((string) $request->validate(['code' => ['required', 'string', 'max:100']])['code']));

        $sku = MasterSku::where('sku', $code)->first(['sku', 'product_name', 'size']);
        if ($sku) {
            $bins = StockActive::where('sku', $sku->sku)->where('qty', '>', 0)->orderByDesc('qty')->get(['bin_code', 'qty']);

            return response()->json([
                'ok' => true,
                'type' => 'sku',
                'message' => "{$sku->sku} ada di {$bins->count()} bin. Pilih bin asalnya.",
                'sku' => $sku,
                'bins' => $bins->map(fn ($bin) => ['bin_code' => $bin->bin_code, 'qty' => (int) $bin->qty])->values(),
            ]);
        }

        if (!$this->isValidBin($code)) {
            return response()->json([
                'ok' => false,
                'message' => "Kode {$code} tidak dikenal sebagai SKU maupun bin rak di denah.",
            ], 404);
        }

        $items = StockActive::with('masterSku:sku,product_name,size')
            ->where('bin_code', $code)
            ->where('qty', '>', 0)
            ->orderBy('sku')
            ->get()
            ->map(fn ($stock) => [
                'sku' => $stock->sku,
                'name' => trim(($stock->masterSku?->product_name ?? '') . ' ' . ($stock->masterSku?->size ?? '')),
                'qty' => (int) $stock->qty,
                'locked' => $this->neededByPicking($stock->sku, $code),
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'type' => 'bin',
            'bin_code' => $code,
            'message' => "Bin {$code}: {$items->count()} SKU, {$items->sum('qty')} pcs",
            'items' => $items,
        ]);
    }

    /**
     * Pindahkan satu atau beberapa SKU dari satu bin ke bin lain.
     * Format lama (sku + qty) tetap diterima.
     */
    public function store(Request $request)
    {
        if ($request->filled('sku') && !$request->has('items')) {
            $request->merge(['items' => [['sku' => $request->input('sku'), 'qty' => $request->input('qty')]]]);
        }
        $request->merge([
            'bin_from' => strtoupper(trim((string) ($request->input('bin_from') ?? $request->input('source_bin')))),
            'bin_to' => strtoupper(trim((string) ($request->input('bin_to') ?? $request->input('dest_bin')))),
        ]);

        $validated = $request->validate([
            'bin_from' => ['required', 'string', 'max:50'],
            'bin_to' => ['required', 'string', 'max:50', 'different:bin_from'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'exists:master_skus,sku'],
            'items.*.qty' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'bin_to.different' => 'Bin tujuan tidak boleh sama dengan bin asal.',
        ]);

        $binFrom = $validated['bin_from'];
        $binTo = $validated['bin_to'];
        if (!$this->isValidBin($binTo)) {
            return back()->withInput()->with('error', "Bin tujuan {$binTo} tidak ada di denah gudang. Contoh format: A-01-01.");
        }

        $items = collect($validated['items'])
            ->filter(fn ($item) => (int) ($item['qty'] ?? 0) > 0)
            ->groupBy('sku')
            ->map(fn ($rows) => (int) $rows->sum('qty'));
        if ($items->isEmpty()) {
            return back()->withInput()->with('error', 'Isi jumlah yang dipindah minimal 1 pcs.');
        }

        try {
            $refNo = DB::transaction(function () use ($items, $binFrom, $binTo, $validated, $request) {
                $refNo = 'TRF-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

                foreach ($items as $sku => $qty) {
                    $stockFrom = StockActive::where('sku', $sku)->where('bin_code', $binFrom)->lockForUpdate()->first();
                    $available = (int) ($stockFrom->qty ?? 0);
                    $needed = $this->neededByPicking($sku, $binFrom);

                    if ($available < $qty) {
                        throw new \RuntimeException("Stok {$sku} di bin {$binFrom} hanya {$available} pcs.");
                    }
                    if ($available - $qty < $needed) {
                        throw new \RuntimeException("{$needed} pcs {$sku} di bin {$binFrom} sedang ditunggu picking. Maksimal yang bisa dipindah: " . max(0, $available - $needed) . ' pcs.');
                    }

                    $stockFrom->update(['qty' => $available - $qty, 'last_movement_at' => now()]);
                    if ($available - $qty <= 0) {
                        $stockFrom->delete();
                    }

                    $stockTo = StockActive::where('sku', $sku)->where('bin_code', $binTo)->lockForUpdate()->first();
                    if ($stockTo) {
                        $stockTo->update(['qty' => $stockTo->qty + $qty, 'last_movement_at' => now()]);
                    } else {
                        StockActive::create([
                            'id' => (string) Str::uuid(),
                            'sku' => $sku,
                            'bin_code' => $binTo,
                            'qty' => $qty,
                            'reserved_qty' => 0,
                            'received_at' => now(),
                            'last_movement_at' => now(),
                        ]);
                    }

                    StockMovement::create([
                        'user_id' => auth()->id(),
                        'sku' => $sku,
                        'bin_code' => $binFrom,
                        'type' => 'TRANSFER',
                        'qty' => $qty,
                        'qty_before' => $available,
                        'qty_after' => $available - $qty,
                        'reference_no' => $refNo,
                        'notes' => trim("Transfer dari {$binFrom} ke {$binTo}. " . ($validated['notes'] ?? '')),
                        'ip_address' => $request->ip(),
                    ]);
                }

                AuditTrail::create([
                    'user_id' => auth()->id(),
                    'action' => 'TRANSFER',
                    'module' => 'WAREHOUSE',
                    'new_values' => json_encode([
                        'from' => $binFrom,
                        'to' => $binTo,
                        'items' => $items->all(),
                        'reference_no' => $refNo,
                    ]),
                    'ip_address' => $request->ip(),
                ]);

                return $refNo;
            });
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal transfer: ' . safe_error_message($e));
        }

        return redirect()->route('gudang.transfer')->with(
            'success',
            "Berhasil memindahkan {$items->sum()} pcs ({$items->count()} SKU) dari {$binFrom} ke {$binTo}. No. {$refNo}"
        );
    }

    /** Bin yang sudah pernah berisi stok, atau bin (bukan kode rak saja) dari rak yang ada di denah */
    private function isValidBin(string $code): bool
    {
        return StockActive::where('bin_code', $code)->exists()
            || ($code !== WarehouseRack::codeFromBin($code) && WarehouseRack::isKnownBin($code));
    }

    /** Jumlah pcs SKU di bin ini yang masih harus diambil sesi picking aktif */
    private function neededByPicking(string $sku, string $bin): int
    {
        return (int) PickingSessionItem::where('sku', $sku)
            ->where('bin_code', $bin)
            ->whereHas('session', fn ($q) => $q->whereIn('status', ['PENDING', 'IN_PROGRESS']))
            ->selectRaw('COALESCE(SUM(qty_ordered - qty_picked), 0) as need')
            ->value('need');
    }
}
