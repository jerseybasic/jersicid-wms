<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\OrderItem;
use App\Models\RestockItem;
use App\Models\WarehouseRack;
use App\Services\OrderCancellation;
use App\Services\OrderItemChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kembalikan ke rak: barang dari order yang dibatalkan saat sedang diproses, atau barang lama dari
 * order yang itemnya diganti setelah diambil (masih di keranjang atau sudah dikemas). Stok baru
 * bertambah saat barangnya di-scan kembali ke rak.
 */
class RestockController extends Controller
{
    public function index()
    {
        $cancelled = OrderCancellation::pendingItems()
            ->with('order:id,order_number,customer_name,updated_at', 'masterSku:sku,product_name,size')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id, 'sku' => $item->sku, 'bin_code' => $item->bin_code, 'qty' => $item->qty_picked,
                'masterSku' => $item->masterSku, 'order' => $item->order, 'at' => $item->order->updated_at,
                'why' => 'dibatalkan', 'tap' => route('gudang.restock.tap', $item->id),
            ]);
        $changed = RestockItem::pending()
            ->with('order:id,order_number,customer_name', 'masterSku:sku,product_name,size')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id, 'sku' => $item->sku, 'bin_code' => $item->bin_code, 'qty' => $item->qty,
                'masterSku' => $item->masterSku, 'order' => $item->order, 'at' => $item->created_at,
                'why' => 'item diganti', 'tap' => route('gudang.restock.tap-change', $item->id),
            ]);

        $groups = $cancelled->concat($changed)
            ->sortBy(fn ($row) => [$row['at'], $row['sku']])
            ->groupBy(fn ($row) => $row['order']->id . ':' . $row['why']);

        return view('warehouse.restock.index', ['groups' => $groups]);
    }

    /**
     * Scan rak lalu scan barang: 1 pcs SKU itu dari order batal (atau order yang itemnya diganti)
     * dikembalikan ke rak yang di-scan. Tanpa rak, barang dikembalikan ke rak asalnya saat picking.
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

                if ($item = OrderCancellation::pendingItems()->where('sku', $code)->orderBy('updated_at')->lockForUpdate()->first()) {
                    return $this->putOne($item, $bin ?: null, $request);
                }
                if ($item = RestockItem::pending()->where('sku', $code)->orderBy('created_at')->lockForUpdate()->first()) {
                    return $this->putOneChanged($item, $bin ?: null, $request);
                }

                throw new \RuntimeException("Tidak ada {$code} dari order batal atau order yang diganti yang perlu dikembalikan.");
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

    /** Sama seperti tap, untuk barang lama dari order yang itemnya diganti */
    public function tapChange(Request $request, RestockItem $restockItem)
    {
        $validated = $request->validate(['bin_code' => ['nullable', 'string', 'max:50']]);

        try {
            $result = DB::transaction(function () use ($restockItem, $validated, $request) {
                $item = RestockItem::pending()->lockForUpdate()->find($restockItem->id);
                if (!$item) {
                    throw new \RuntimeException('Barang ini sudah semua dikembalikan.');
                }

                return $this->putOneChanged($item, $validated['bin_code'] ?? null, $request);
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

        return $this->result($item->id, $item->sku, $item->qty_picked, $bin, $item->order?->order_number);
    }

    private function putOneChanged(RestockItem $item, ?string $bin, Request $request): array
    {
        $bin = $bin ?: $item->bin_code;
        if (!$bin) {
            throw new \RuntimeException("Rak asal {$item->sku} tidak diketahui. Scan raknya dulu.");
        }

        OrderItemChange::putBack($item, $bin, 1, auth()->id(), $request->ip());
        $item->refresh();

        return $this->result($item->id, $item->sku, $item->qty, $bin, $item->order?->order_number);
    }

    private function result(string $id, string $sku, int $left, string $bin, ?string $orderNumber): array
    {
        $bin = strtoupper(trim($bin));

        return [
            'ok' => true,
            'type' => 'sku',
            'active_bin' => $bin,
            'message' => "{$sku} masuk ke {$bin}" . ($left ? ", sisa {$left} pcs" : ', selesai') . " ({$orderNumber}).",
            'items' => [['id' => $id, 'qty' => $left]],
            'completed' => !OrderCancellation::pendingItems()->exists() && !RestockItem::pending()->exists(),
        ];
    }
}
