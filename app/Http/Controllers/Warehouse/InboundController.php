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
use App\Models\PurchaseOrderReceipt;
use App\Services\PoNotifier;
use App\Services\WaitingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $purchaseOrders = PurchaseOrder::whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES)
            ->with('items.masterSku:sku,product_name,size,price')
            ->orderBy('po_number')
            ->get();

        // 5. Saran bin per SKU: bin yang stoknya paling banyak, atau rak tempat SKU ditetapkan
        $suggestedBins = $this->suggestedBins();

        $poData = $purchaseOrders->mapWithKeys(fn ($po) => [$po->id => [
            'supplier_name' => $po->supplier_name,
            'po_number' => $po->po_number,
            'status_label' => $po->status_label,
            'ordered' => $po->qty_ordered_total,
            'received' => $po->qty_received_total,
            'items' => $po->items
                ->filter(fn ($item) => $item->qty_received < $item->qty_ordered)
                ->map(fn ($item) => [
                    'sku' => $item->sku,
                    'product_name' => $item->masterSku?->product_name,
                    'size' => $item->masterSku?->size,
                    'price' => $item->masterSku?->price,
                    'ordered' => $item->qty_ordered,
                    'received' => $item->qty_received,
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
            $notifyPo = null;
            $firstMovementId = DB::transaction(function () use ($validated, $request, &$notifyPo) {
                $user = auth()->user();
                $receiptNo = $validated['receipt_number'];
                $firstMovementId = null;

                // Barang dari PO: hanya SKU di PO itu, dan tidak boleh melebihi sisa yang belum datang
                $po = !empty($validated['po_id'])
                    ? PurchaseOrder::with('items')->lockForUpdate()->find($validated['po_id'])
                    : null;
                $poItems = $po ? $this->checkAgainstPo($po, $validated['items']) : collect();
                $poBefore = $po ? $po->items->mapWithKeys(fn ($i) => [$i->sku => $i->qty_received])->all() : [];

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

                    // Barang dari PO: tambah qty diterima dan catat sebagai satu kedatangan
                    if ($po) {
                        $poItem = $poItems[$sku];
                        $poItem->increment('qty_received', $qtyReceived);

                        PurchaseOrderReceipt::create([
                            'purchase_order_id' => $po->id,
                            'purchase_order_item_id' => $poItem->id,
                            'sku' => $sku,
                            'qty' => $qtyReceived,
                            'qty_rejected' => $qtyRejected,
                            'receipt_number' => $receiptNo,
                            'received_date' => $validated['received_date'],
                            'bin_code' => $binCode,
                            'stock_movement_id' => $movement->id,
                            'received_by' => $user->id,
                            'notes' => $validated['notes'] ?? null,
                        ]);
                    }
                }

                if ($po) {
                    $oldStatus = $po->status;
                    $newStatus = $po->receivingStatus();
                    if ($newStatus !== $oldStatus) {
                        $po->update(['status' => $newStatus]);
                    }
                    // Kabari setiap kedatangan selama masih sebagian, dan sekali saat lengkap
                    if ($newStatus === 'PARTIALLY_RECEIVED' || $newStatus !== $oldStatus) {
                        $notifyPo = $po;
                    }

                    AuditTrail::create([
                        'user_id' => $user->id,
                        'action' => 'RECEIVE_PO',
                        'module' => 'PURCHASE_ORDER',
                        'old_values' => json_encode(['status' => $oldStatus, 'qty_received' => $poBefore]),
                        'new_values' => json_encode([
                            'po_id' => $po->id,
                            'po_number' => $po->po_number,
                            'receipt_number' => $receiptNo,
                            'received_date' => $validated['received_date'],
                            'status' => $newStatus,
                            'qty_received' => $po->items->mapWithKeys(fn ($i) => [$i->sku => $i->qty_received])->all(),
                            'qty_remaining' => $po->items->mapWithKeys(fn ($i) => [$i->sku => $i->qty_remaining])->all(),
                        ]),
                        'ip_address' => $request->ip(),
                    ]);
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
                    ] + ($po ? ['po_number' => $po->po_number] : [])),
                    'ip_address' => $request->ip(),
                ]);

                return $firstMovementId;
            });

            if ($notifyPo) {
                PoNotifier::statusChanged($notifyPo, auth()->user()->name);
            }

            // Order yang menunggu stok langsung kembali ke antrean jika barangnya sekarang cukup
            $released = WaitingStock::release(auth()->id(), $request->ip());

            // Langsung ke detail penerimaan supaya label SKU bisa dicetak
            $poMessage = '';
            if ($notifyPo?->status === 'PARTIALLY_RECEIVED') {
                $poMessage = " PO {$notifyPo->po_number} diterima sebagian: {$notifyPo->qty_received_total} dari {$notifyPo->qty_ordered_total} pcs, sisa {$notifyPo->qty_remaining_total} pcs menunggu kedatangan berikutnya.";
            } elseif ($notifyPo?->status === 'RECEIVED') {
                $poMessage = " PO {$notifyPo->po_number} sudah diterima lengkap.";
            }

            return redirect()->route('gudang.inbound.show', $firstMovementId)
                ->with('success', 'Barang berhasil diterima! Stok telah diperbarui.' . $poMessage . ' Cetak label SKU di bawah ini.' . WaitingStock::releasedMessage($released));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Gagal memproses inbound: ' . safe_error_message($e));
        }
    }

    /**
     * Pastikan barang yang diterima cocok dengan PO: PO masih menunggu barang, SKU-nya ada di PO, dan
     * jumlahnya (digabung jika satu SKU dibagi ke beberapa bin) tidak melebihi sisa yang belum datang.
     *
     * @return \Illuminate\Support\Collection<string, \App\Models\PurchaseOrderItem> sku => item PO
     */
    private function checkAgainstPo(PurchaseOrder $po, array $items)
    {
        if (!$po->isReceivable()) {
            throw ValidationException::withMessages([
                'po_id' => "PO {$po->po_number} berstatus {$po->status_label}, jadi barangnya tidak bisa diterima.",
            ]);
        }

        $poItems = $po->items->keyBy('sku');
        $problems = [];

        foreach (collect($items)->groupBy('sku') as $sku => $rows) {
            $qty = (int) $rows->sum('qty_received');
            $poItem = $poItems[$sku] ?? null;

            if (!$poItem) {
                $problems[] = "{$sku} tidak ada di PO {$po->po_number}. Terima tanpa PO, atau minta Owner/Supervisor menambahkannya ke PO dulu.";
            } elseif ($qty > $poItem->qty_remaining) {
                $problems[] = $poItem->qty_remaining === 0
                    ? "{$sku} di PO {$po->po_number} sudah diterima lengkap ({$poItem->qty_ordered} pcs)."
                    : "{$sku} diterima {$qty} pcs, melebihi sisa PO {$poItem->qty_remaining} pcs (dipesan {$poItem->qty_ordered}, sudah diterima {$poItem->qty_received}). Jika supplier memang mengirim lebih, minta Owner/Supervisor menambah qty di PO dulu.";
            }
        }

        if ($problems) {
            throw ValidationException::withMessages(['items' => $problems]);
        }

        return $poItems;
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

        // Penerimaan dari PO: tampilkan PO-nya dan sisa yang masih ditunggu
        $poReceipts = PurchaseOrderReceipt::whereIn('stock_movement_id', $items->pluck('id'))
            ->with('purchaseOrder.items')
            ->get();
        $po = $poReceipts->first()?->purchaseOrder;

        return view('warehouse.inbound.show', compact('inbound', 'items', 'po'));
    }
}
