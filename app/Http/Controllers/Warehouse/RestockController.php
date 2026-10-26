<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\OrderItem;
use App\Models\WarehouseRack;
use App\Services\OrderCancellation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kembalikan ke rak: barang dari order yang dibatalkan saat sedang diproses (masih di keranjang
 * atau sudah dikemas). Stok baru bertambah saat barangnya di-scan kembali ke rak.
 */
class RestockController extends Controller
{
    public function index()
    {
        $items = OrderCancellation::pendingItems()
            ->with('order:id,order_number,customer_name,updated_at', 'masterSku:sku,product_name,size')
            ->get()
            ->sortBy(fn ($item) => [$item->order->updated_at, $item->sku])
            ->groupBy('order_id');

        return view('warehouse.restock.index', ['groups' => $items]);
    }

    /**
     * Scan rak lalu scan barang: 1 pcs SKU itu dari order batal mana pun dikembalikan ke rak yang di-scan.
     * Tanpa rak, barang dikembalikan ke rak asalnya saat picking.
     */
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'bin_code' => ['nullable', 'string', 'max:50'],
        ]);
        $code = strtoupper(trim($validated['code']));
        $bin = strtoupper(trim($validated['bin_code'] ?? ''));

        try {
            $result = DB::transaction(function () use ($code, $bin, $request) {
                if (!MasterSku::where('sku', $code)->exists()) {
                    if (WarehouseRack::isKnownBin($code)) {
                        return ['ok' => true, 'type' => 'bin', 'bin_code' => $code, 'message' => "Rak {$code} dipilih. Sekarang scan barang yang ditaruh di sini."];
                    }
                    throw new \RuntimeException("Kode {$code} tidak dikenal sebagai SKU maupun lokasi rak.");
                }

                $item = OrderCancellation::pendingItems()->where('sku', $code)->orderBy('updated_at')->lockForUpdate()->first();
                if (!$item) {
                    throw new \RuntimeException("Tidak ada {$code} dari order batal yang perlu dikembalikan.");
                }

                return $this->putOne($item, $bin ?: null, $request);
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    /** Ketuk "+1 Sudah dikembalikan" di daftar: ke rak yang terakhir di-scan, atau rak asalnya */
    public function tap(Request $request, OrderItem $item)
    {
        $validated = $request->validate(['bin_code' => ['nullable', 'string', 'max:50']]);

        try {
            $result = DB::transaction(function () use ($item, $validated, $request) {
                $item = OrderCancellation::pendingItems()->lockForUpdate()->find($item->id);
                if (!$item) {
                    throw new \RuntimeException('Barang ini sudah semua dikembalikan.');
                }

                return $this->putOne($item, $validated['bin_code'] ?? null, $request);
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'message' => safe_error_message($e)], 422);
        }
    }

    private function putOne(OrderItem $item, ?string $bin, Request $request): array
    {
        $bin = $bin ?: $item->bin_code;
        if (!$bin) {
            throw new \RuntimeException("Rak asal {$item->sku} tidak diketahui. Scan raknya dulu.");
        }

        OrderCancellation::putBack($item, $bin, 1, auth()->id(), $request->ip());
        $item->refresh();
        $bin = strtoupper(trim($bin));

        return [
            'ok' => true,
            'type' => 'sku',
            'active_bin' => $bin,
            'message' => "{$item->sku} masuk ke {$bin}" . ($item->qty_picked ? ", sisa {$item->qty_picked} pcs" : ', selesai') . " ({$item->order?->order_number}).",
            'items' => [['id' => $item->id, 'qty' => $item->qty_picked]],
            'completed' => !OrderCancellation::pendingItems()->exists(),
        ];
    }
}
