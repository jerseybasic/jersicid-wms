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

class StockTransferController extends Controller
{
    public function index()
    {
        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size']);

        // Format data stok aktif dikelompokkan berdasarkan SKU untuk Alpine.js
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

        // Alias $stocks untuk kompatibilitas
        $stocks = $activeStocks;

        $transfers = StockMovement::where('type', 'TRANSFER')
            ->with(['user:id,name', 'masterSku:sku,product_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('warehouse.transfer.index', compact('skus', 'activeStocks', 'stocks', 'transfers'));
    }

    public function store(Request $request)
    {
        // Support bin_from/bin_to (dari form Blade) maupun source_bin/dest_bin
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
                $sourceBin = $validated['bin_from'];
                $destBin = $validated['bin_to'];
                $qty = (int) $validated['qty'];

                // 1. Cek stok di bin asal
                $sourceStock = StockActive::where('sku', $sku)
                    ->where('bin_code', $sourceBin)
                    ->first();

                if (!$sourceStock || $sourceStock->qty < $qty) {
                    throw new \Exception("Stok di bin {$sourceBin} tidak mencukupi! Tersedia: " . ($sourceStock->qty ?? 0));
                }

                // 2. Cek stok di bin tujuan
                $destStock = StockActive::where('sku', $sku)
                    ->where('bin_code', $destBin)
                    ->first();

                $sourceQtyBefore = $sourceStock->qty;
                $sourceQtyAfter = $sourceQtyBefore - $qty;
                $destQtyBefore = $destStock ? $destStock->qty : 0;
                $destQtyAfter = $destQtyBefore + $qty;

                // 3. Kurangi stok bin asal
                $sourceStock->update([
                    'qty' => $sourceQtyAfter,
                    'last_movement_at' => now(),
                ]);

                if ($sourceQtyAfter <= 0) {
                    $sourceStock->delete();
                }

                // 4. Tambah/Create stok bin tujuan
                if ($destStock) {
                    $destStock->update([
                        'qty' => $destQtyAfter,
                        'last_movement_at' => now(),
                    ]);
                } else {
                    StockActive::create([
                        'id' => (string) Str::uuid(),
                        'sku' => $sku,
                        'bin_code' => $destBin,
                        'qty' => $qty,
                        'reserved_qty' => 0,
                        'received_at' => now(),
                        'last_movement_at' => now(),
                    ]);
                }

                $refNo = "TRF-" . date('YmdHis') . "-" . strtoupper(Str::random(4));

                // 5. Catat Stock Movement (TRANSFER)
                StockMovement::create([
                    'user_id' => $user->id,
                    'sku' => $sku,
                    'bin_code' => $sourceBin,
                    'type' => 'TRANSFER',
                    'qty' => $qty,
                    'qty_before' => $sourceQtyBefore,
                    'qty_after' => $sourceQtyAfter,
                    'reference_no' => $refNo,
                    'notes' => "Transfer dari {$sourceBin} ke {$destBin}. " . ($validated['notes'] ?? ''),
                    'ip_address' => $request->ip(),
                ]);

                // 6. Audit Trail
                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'TRANSFER',
                    'module' => 'WAREHOUSE',
                    'new_values' => json_encode([
                        'sku' => $sku,
                        'from_bin' => $sourceBin,
                        'to_bin' => $destBin,
                        'qty' => $qty,
                        'reference_no' => $refNo,
                    ]),
                    'ip_address' => $request->ip(),
                ]);
            });

            return back()->with('success', "Berhasil memindahkan {$validated['qty']} unit dari {$validated['bin_from']} ke {$validated['bin_to']}!");
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal melakukan transfer: ' . safe_error_message($e));
        }
    }
}
