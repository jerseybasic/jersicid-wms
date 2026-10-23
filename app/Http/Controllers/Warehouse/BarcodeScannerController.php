<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\Order;
use App\Models\StockActive;
use App\Models\WarehouseRack;
use Illuminate\Http\Request;

/**
 * Cek barang & lokasi: scan SKU untuk melihat stok per bin, atau scan bin/rak
 * untuk melihat isinya. Halaman ini hanya membaca data dan tidak mengubah stok;
 * stok berubah lewat layar Inbound, Picking, dan Stock Opname.
 */
class BarcodeScannerController extends Controller
{
    public function index()
    {
        return view('warehouse.scanner.index');
    }

    public function lookup(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
        ]);

        $code = strtoupper(trim($validated['code']));

        $sku = MasterSku::where('sku', $code)->first();
        if ($sku) {
            $bins = StockActive::where('sku', $sku->sku)
                ->where('qty', '>', 0)
                ->orderByDesc('qty')
                ->get(['bin_code', 'qty']);

            return response()->json([
                'ok' => true,
                'type' => 'sku',
                'message' => "{$sku->sku}: total {$bins->sum('qty')} pcs di {$bins->count()} bin",
                'sku' => [
                    'sku' => $sku->sku,
                    'product_name' => $sku->product_name,
                    'size' => $sku->size,
                    'status' => $sku->status,
                    'min_stock' => $sku->min_stock,
                    'total' => (int) $bins->sum('qty'),
                ],
                'rows' => $bins->map(fn ($bin) => ['label' => $bin->bin_code, 'qty' => $bin->qty])->values(),
            ]);
        }

        // No. order atau no. resi (QR di label kirim): buka detail order
        if ($order = Order::findByCode($code)) {
            return response()->json([
                'ok' => true,
                'type' => 'order',
                'message' => "Order {$order->order_number}: {$order->status_label}",
                'redirect' => route('gudang.outbound.show', $order->id),
            ]);
        }

        // Kode bin persis, atau kode rak (semua bin di rak itu)
        $rackCode = WarehouseRack::codeFromBin($code);
        $isRack = WarehouseRack::where('code', $code)->exists();

        $stocks = StockActive::with('masterSku:sku,product_name,size')
            ->where('qty', '>', 0)
            ->get(['sku', 'bin_code', 'qty'])
            ->filter(fn ($stock) => $isRack
                ? WarehouseRack::codeFromBin($stock->bin_code) === $rackCode
                : strtoupper($stock->bin_code) === $code)
            ->sortBy(['bin_code', 'sku'])
            ->values();

        if ($stocks->isEmpty() && !WarehouseRack::isKnownBin($code)) {
            return response()->json([
                'ok' => false,
                'message' => "Kode {$code} tidak dikenal sebagai SKU, lokasi rak, maupun nomor order/resi.",
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'type' => 'bin',
            'message' => ($isRack ? "Rak {$code}" : "Bin {$code}") . ": {$stocks->count()} SKU, {$stocks->sum('qty')} pcs",
            'rows' => $stocks->map(fn ($stock) => [
                'label' => $stock->sku . ($isRack ? " ({$stock->bin_code})" : ''),
                'detail' => trim(($stock->masterSku?->product_name ?? '') . ' ' . ($stock->masterSku?->size ?? '')),
                'qty' => $stock->qty,
            ]),
        ]);
    }
}
