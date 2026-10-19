<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferController extends Controller
{
    public function index()
    {
        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size']);

        $activeStocks = StockActive::where('qty', '>', 0)
            ->select('sku', 'bin_code', 'qty')
            ->get()
            ->groupBy('sku')
            ->map(function ($items) {
                return $items->map(function ($item) {
                    return [
                        'bin_code' => $item->bin_code,
                        'qty' => (int) $item->qty,
                    ];
                })->values()->toArray();
            })
            ->toArray();

        $stocks = $activeStocks;

        $transfers = StockMovement::where('type', 'TRANSFER')
            ->with(['user:id,name', 'masterSku:sku,product_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('warehouse.transfer.index', compact('skus', 'activeStocks', 'stocks', 'transfers'));
    }

    public function store(Request $request)
    {
        $binFromInput = $request->input('bin_from') ?? $request->input('source_bin');
        $binToInput = $request->input('bin_to') ?? $request->input('dest_bin');

        $request->merge([
            'bin_from' => $binFromInput,
            'bin_to' => $binToInput,
            'source_bin' => $binFromInput,
            'dest_bin' => $binToInput,
        ]);

        $validated = $request->validate([
            'sku' => ['required', 'exists:master_skus,sku'],
            'bin_from' => ['required', 'string', 'max:50'],
            'bin_to' => ['required', 'string', 'max:50', 'different:bin_from'],
            'qty' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'bin_to.different' => 'Bin tujuan tidak boleh sama dengan bin asal.',
        ]);

        try {
            DB::transaction(function () use ($validated, $request) {
                $user = auth()->user();
                $sku = $validated['sku'];
                $binFrom = $validated['bin_from'];
                $binTo = $validated['bin_to'];
                $qty = (int) $validated['qty'];

                // Cek stok di bin asal
                $stockFrom = StockActive::where('sku', $sku)
                    ->where('bin_code', $binFrom)
                    ->first();

                if (!$stockFrom || $stockFrom->qty < $qty) {
                    throw new \Exception(
                        "Stok tidak mencukupi di bin {$binFrom}. Tersedia: " . ($stockFrom->qty ?? 0)
                    );
                }

                // Kurangi stok bin asal
                $oldQtyFrom = $stockFrom->qty;
                $newQtyFrom = $oldQtyFrom - $qty;
                $stockFrom->update([
                    'qty' => $newQtyFrom,
                    'last_movement_at' => now(),
                ]);
                if ($newQtyFrom <= 0) {
                    $stockFrom->delete();
                }

                // Tambah stok bin tujuan
                $stockTo = StockActive::where('sku', $sku)
                    ->where('bin_code', $binTo)
                    ->first();

                $oldQtyTo = $stockTo ? $stockTo->qty : 0;

                if ($stockTo) {
                    $stockTo->update([
                        'qty' => $oldQtyTo + $qty,
                        'last_movement_at' => now(),
                    ]);
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

                $refNo = 'TRF-' . date('YmdHis') . '-' . strtoupper(Str::random(4));

                // Catat movement
                StockMovement::create([
                    'user_id' => $user->id,
                    'sku' => $sku,
                    'bin_code' => $binFrom,
                    'type' => 'TRANSFER',
                    'qty' => $qty,
                    'qty_before' => $oldQtyFrom,
                    'qty_after' => $newQtyFrom,
                    'reference_no' => $refNo,
                    'notes' => "Transfer dari {$binFrom} ke {$binTo}. " . ($validated['notes'] ?? ''),
                    'ip_address' => $request->ip(),
                ]);

                // Audit trail
                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'TRANSFER',
                    'module' => 'WAREHOUSE',
                    'new_values' => json_encode([
                        'sku' => $sku,
                        'from' => $binFrom,
                        'to' => $binTo,
                        'qty' => $qty,
                        'reference_no' => $refNo,
                    ]),
                    'ip_address' => $request->ip(),
                ]);
            });

            return back()->with('success', "Berhasil memindahkan {$validated['qty']} unit dari {$validated['bin_from']} ke {$validated['bin_to']}!");
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal transfer: ' . safe_error_message($e));
        }
    }
}
