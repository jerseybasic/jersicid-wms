<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use App\Models\Warehouse;
use App\Models\WarehouseRack;
use App\Models\PurchaseOrder;
use App\Services\PoNotifier;
use App\Services\WaitingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InboundController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::where('type', 'INBOUND')
            ->with(['user:id,name', 'masterSku:sku,product_name']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $inbounds = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('warehouse.inbound.index', compact('inbounds'));
    }

    public function create()
    {
        // 1. Ambil SKU aktif
        $skus = MasterSku::where('status', 'AKTIF')
            ->orderBy('product_name')
            ->get(['sku', 'product_name', 'size', 'price']);

        // 2. Ambil Warehouse aktif
        $warehouses = Warehouse::where('status', 'AKTIF')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // 3. ✅ FIX: warehouse_racks TIDAK punya kolom 'status'
        //    Ambil semua rack tanpa filter status, urutkan berdasarkan code
        $racks = WarehouseRack::orderBy('code')
            ->get(['id', 'code', 'color', 'rows_count']);

        // 4. Purchase Order yang siap diterima, beserta sisa qty per item
        $purchaseOrders = PurchaseOrder::whereIn('status', ['APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'READY'])
            ->with('items.masterSku:sku,product_name,size,price')
            ->orderBy('po_number')
            ->get();

        // 5. Saran bin per SKU: bin yang stoknya paling banyak, atau rak tempat SKU ditetapkan
        $suggestedBins = $this->suggestedBins();

        $poData = $purchaseOrders->mapWithKeys(fn ($po) => [$po->id => [
            'supplier_name' => $po->supplier_name,
            'items' => $po->items
                ->filter(fn ($item) => $item->qty_received < $item->qty_ordered)
                ->map(fn ($item) => [
                    'sku' => $item->sku,
                    'product_name' => $item->masterSku?->product_name,
                    'size' => $item->masterSku?->size,
                    'price' => $item->masterSku?->price,
                    'remaining' => $item->qty_ordered - $item->qty_received,
                    'bin_code' => $suggestedBins[$item->sku] ?? '',
                ])
                ->values(),
        ]]);

        return view('warehouse.inbound.create', compact('skus', 'warehouses', 'racks', 'purchaseOrders', 'poData', 'suggestedBins'));
    }

    /**
     * @return array<string, string> sku => kode bin
     */
    private function suggestedBins(): array
    {
        $bins = [];

        DB::table('rack_products')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'rack_products.warehouse_rack_id')
            ->select('rack_products.sku', 'warehouse_racks.code')
            ->get()
            ->each(function ($row) use (&$bins) {
                $bins[$row->sku] = $row->code . '-01-01';
            });

        StockActive::where('qty', '>', 0)
            ->orderBy('qty')
            ->get(['sku', 'bin_code'])
            ->each(function ($stock) use (&$bins) {
                // diurutkan naik, jadi bin dengan stok terbanyak menimpa yang lain
                $bins[$stock->sku] = $stock->bin_code;
            });

        return $bins;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receipt_number' => ['required', 'string', 'max:100', 'unique:stock_movements,reference_no'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'received_date' => ['required', 'date'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'po_id' => ['nullable', 'exists:purchase_orders,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'exists:master_skus,sku'],
            'items.*.bin_code' => ['required', 'string', 'max:50'],
            'items.*.qty_received' => ['required', 'integer', 'min:1'],
            'items.*.qty_expected' => ['nullable', 'integer', 'min:0'],
            'items.*.qty_rejected' => ['nullable', 'integer', 'min:0'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
        ], [
            'receipt_number.unique' => 'No. Surat Jalan sudah pernah digunakan.',
            'items.min' => 'Minimal 1 item harus ditambahkan.',
        ]);

        try {
            $receivedPo = null;
            $firstMovementId = DB::transaction(function () use ($validated, $request, &$receivedPo) {
                $user = auth()->user();
                $receiptNo = $validated['receipt_number'];
                $firstMovementId = null;

                foreach ($validated['items'] as $item) {
                    $sku = $item['sku'];
                    $binCode = strtoupper(trim($item['bin_code']));
                    $qtyReceived = $item['qty_received'];
                    $qtyRejected = $item['qty_rejected'] ?? 0;
                    $qtyExpected = $item['qty_expected'] ?? $qtyReceived;
                    $batchNo = $item['batch_no'] ?? null;

                    // Cek stock aktif di bin tersebut
                    $stockActive = StockActive::where('sku', $sku)
                        ->where('bin_code', $binCode)
                        ->lockForUpdate()
                        ->first();

                    $qtyBefore = $stockActive?->qty ?? 0;
                    $qtyAfter = $qtyBefore + $qtyReceived;

                    // Update atau create stock active
                    if ($stockActive) {
                        $stockActive->update([
                            'qty' => $qtyAfter,
                            'batch_no' => $batchNo ?? $stockActive->batch_no,
                            'last_movement_at' => now(),
                        ]);
                    } else {
                        StockActive::create([
                            'id' => (string) Str::uuid(),
                            'sku' => $sku,
                            'bin_code' => $binCode,
                            'qty' => $qtyReceived,
                            'reserved_qty' => 0,
                            'batch_no' => $batchNo,
                            'received_at' => now(),
                            'last_movement_at' => now(),
                        ]);
                    }

                    // Catat stock movement
                    $movement = StockMovement::create([
                        'user_id' => $user->id,
                        'sku' => $sku,
                        'bin_code' => $binCode,
                        'type' => 'INBOUND',
                        'qty' => $qtyReceived,
                        'qty_before' => $qtyBefore,
                        'qty_after' => $qtyAfter,
                        'reference_no' => $receiptNo,
                        'notes' => trim(($validated['notes'] ?? '') . ($qtyRejected > 0 ? " (reject {$qtyRejected} pcs)" : '')) ?: null,
                        'ip_address' => $request->ip(),
                    ]);
                    $firstMovementId ??= $movement->id;

                    // Jika ada PO terkait, update qty_received di PO items
                    if (!empty($validated['po_id'])) {
                        $po = PurchaseOrder::find($validated['po_id']);
                        if ($po) {
                            $poItem = $po->items()->where('sku', $sku)->first();
                            if ($poItem) {
                                $poItem->increment('qty_received', $qtyReceived);
                            }

                            // Cek apakah semua item PO sudah diterima
                            $allReceived = $po->items()->whereColumn('qty_received', '<', 'qty_ordered')->count() === 0;
                            if ($allReceived && $po->status !== 'RECEIVED') {
                                $po->update(['status' => 'RECEIVED']);
                                $receivedPo = $po;
                            }
                        }
                    }
                }

                // Audit trail
                AuditTrail::create([
                    'user_id' => $user->id,
                    'action' => 'INBOUND',
                    'module' => 'WAREHOUSE',
                    'new_values' => json_encode([
                        'receipt_number' => $receiptNo,
                        'supplier' => $validated['supplier_name'],
                        'total_items' => count($validated['items']),
                        'warehouse_id' => $validated['warehouse_id'],
                    ]),
                    'ip_address' => $request->ip(),
                ]);

                return $firstMovementId;
            });

            if ($receivedPo) {
                PoNotifier::statusChanged($receivedPo, auth()->user()->name);
            }

            // Order yang menunggu stok langsung kembali ke antrean jika barangnya sekarang cukup
            $released = WaitingStock::release(auth()->id(), $request->ip());

            // Langsung ke detail penerimaan supaya label SKU bisa dicetak
            return redirect()->route('gudang.inbound.show', $firstMovementId)
                ->with('success', 'Barang berhasil diterima! Stok telah diperbarui. Cetak label SKU di bawah ini.' . WaitingStock::releasedMessage($released));
        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Gagal memproses inbound: ' . safe_error_message($e));
        }
    }

    public function show($id)
    {
        $inbound = StockMovement::with(['user:id,name', 'masterSku:sku,product_name,size'])
            ->where('type', 'INBOUND')
            ->findOrFail($id);

        // Ambil semua movement dengan reference_no yang sama (1 receipt bisa banyak item)
        $items = StockMovement::where('reference_no', $inbound->reference_no)
            ->where('type', 'INBOUND')
            ->with(['masterSku:sku,product_name,size'])
            ->orderBy('created_at')
            ->get();

        return view('warehouse.inbound.show', compact('inbound', 'items'));
    }
}
