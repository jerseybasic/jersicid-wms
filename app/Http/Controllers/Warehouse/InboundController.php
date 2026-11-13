<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\MasterSku;
use App\Models\RackRowRule;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\AuditTrail;
use App\Models\Warehouse;
use App\Models\WarehouseRack;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Services\InboundCorrection;
use App\Services\PoNotifier;
use App\Services\SkuCorrection;
use App\Services\WaitingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InboundController extends Controller
{
    /** Status daftar Inbound: dihitung dari PO terkait */
    public const RECEIPT_STATUS_LABELS = [
        'BELUM_KOMPLIT' => 'Belum Komplit',
        'KOMPLIT' => 'Komplit',
    ];

    /**
     * Riwayat penerimaan: satu baris per nomor receipt (surat jalan), walaupun isinya banyak SKU.
     * Penerimaan dari PO yang sisanya masih ditunggu berstatus Belum Komplit.
     */
    public function index(Request $request)
    {
        $query = StockMovement::where('type', 'INBOUND')
            ->whereNotNull('reference_no')
            ->selectRaw('reference_no, MIN(id) as first_id, MAX(created_at) as last_at, SUM(qty) as total_qty, COUNT(DISTINCT sku) as sku_count')
            // Penerimaan tanpa PO: jumlah dipesan dan sisa yang belum datang dari kolom qty_expected
            ->selectRaw('SUM(COALESCE(qty_expected, qty)) as ordered_qty, SUM(CASE WHEN qty_expected > qty THEN qty_expected - qty ELSE 0 END) as pending_qty, COUNT(qty_expected) as expected_lines')
            ->groupBy('reference_no');

        if ($request->filled('search')) {
            $search = $request->search;
            $poReceipts = PurchaseOrderReceipt::select('receipt_number')
                ->whereIn('purchase_order_id', PurchaseOrder::select('id')
                    ->where('po_number', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%"));
            $query->where(function ($q) use ($search, $poReceipts) {
                $q->where('reference_no', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereIn('reference_no', $poReceipts);
            });
        }

        // Belum Komplit = penerimaan dari PO yang sisa barangnya masih ditunggu,
        // atau penerimaan tanpa PO yang qty datangnya masih di bawah qty dipesan
        $poReceiptNumbers = PurchaseOrderReceipt::select('receipt_number');
        $openPoReceipts = PurchaseOrderReceipt::select('receipt_number')
            ->whereIn('purchase_order_id', PurchaseOrder::select('id')->whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES));
        $openManualReceipts = StockMovement::select('reference_no')
            ->where('type', 'INBOUND')
            ->whereColumn('qty_expected', '>', 'qty')
            ->whereNotIn('reference_no', $poReceiptNumbers);
        if ($request->status === 'BELUM_KOMPLIT') {
            $query->where(fn ($q) => $q->whereIn('reference_no', $openPoReceipts)->orWhereIn('reference_no', $openManualReceipts));
        } elseif ($request->status === 'KOMPLIT') {
            $query->whereNotIn('reference_no', $openPoReceipts)->whereNotIn('reference_no', $openManualReceipts);
        }

        $inbounds = $query->orderByDesc('last_at')->paginate(20)->withQueryString();
        $refs = $inbounds->pluck('reference_no');

        // Data pendukung untuk satu halaman sekaligus (tanpa query per baris)
        $firstMovements = StockMovement::with('user:id,name')
            ->whereIn('id', $inbounds->pluck('first_id'))
            ->get()
            ->keyBy('id');
        $pos = PurchaseOrderReceipt::whereIn('receipt_number', $refs)
            ->with('purchaseOrder.items')
            ->get()
            ->groupBy('receipt_number')
            ->map(fn ($rows) => $rows->first()->purchaseOrder);
        $suppliers = $this->suppliersFor($refs->all());

        $inbounds->getCollection()->transform(function ($row) use ($firstMovements, $pos, $suppliers) {
            $first = $firstMovements[$row->first_id] ?? null;
            $po = $pos[$row->reference_no] ?? null;
            $row->first = $first;
            $row->po = $po;
            $row->supplier = $po?->supplier_name ?? $suppliers[$row->reference_no] ?? null;
            $row->receipt_status = ($po ? $po->isReceivable() && $po->qty_remaining_total > 0 : $row->pending_qty > 0) ? 'BELUM_KOMPLIT' : 'KOMPLIT';

            return $row;
        });

        $statusOptions = self::RECEIPT_STATUS_LABELS;

        return view('warehouse.inbound.index', compact('inbounds', 'statusOptions'));
    }

    /**
     * Nama supplier per nomor receipt, diambil dari audit trail penerimaan (penerimaan tanpa PO tidak menyimpan supplier di tempat lain).
     *
     * @return array<string, string>
     */
    private function suppliersFor(array $refs): array
    {
        if (!$refs) {
            return [];
        }

        return AuditTrail::whereIn('action', ['INBOUND', 'UPDATE_INBOUND'])
            ->orderBy('id')
            ->where(function ($q) use ($refs) {
                foreach ($refs as $ref) {
                    // Cocok kasar dulu; nomor yang tepat dicocokkan setelah JSON dibaca
                    $q->orWhere('new_values', 'like', '%' . $ref . '%');
                }
            })
            ->get(['new_values'])
            ->map(fn ($audit) => json_decode($audit->new_values, true))
            ->filter(fn ($values) => isset($values['receipt_number'], $values['supplier']) && in_array($values['receipt_number'], $refs, true))
            ->mapWithKeys(fn ($values) => [$values['receipt_number'] => $values['supplier']])
            ->all();
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

        // Paling lemah: baris rak yang aturannya cocok dengan kata kunci produk + size SKU
        $rules = RackRowRule::join('warehouse_racks', 'warehouse_racks.id', '=', 'rack_row_rules.warehouse_rack_id')
            ->orderByDesc('warehouse_racks.code')
            ->orderByDesc('rack_row_rules.row_index')
            ->get(['rack_row_rules.*', 'warehouse_racks.code as rack_code']);
        if ($rules->isNotEmpty()) {
            MasterSku::where('status', 'AKTIF')
                ->get(['sku', 'article', 'product_name', 'version', 'sub_version', 'size'])
                ->each(function ($sku) use ($rules, &$bins) {
                    // diurutkan turun, jadi rak dan baris paling awal yang menang
                    foreach ($rules as $rule) {
                        if ($rule->matches($sku)) {
                            $bins[$sku->sku] = RackRowRule::binFor($rule->rack_code, $rule->row_index);
                        }
                    }
                });
        }

        DB::table('rack_products')
            ->join('warehouse_racks', 'warehouse_racks.id', '=', 'rack_products.warehouse_rack_id')
            ->select('rack_products.sku', 'rack_products.bin_location', 'warehouse_racks.code')
            ->get()
            ->each(function ($row) use (&$bins) {
                $bins[$row->sku] = $row->bin_location && WarehouseRack::codeFromBin($row->bin_location) === $row->code
                    ? strtoupper($row->bin_location)
                    : $row->code . '-01-01';
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
            // Pengecekan terakhir: ringkasan SKU, qty, dan lokasi rak sudah dicocokkan dengan barang fisik
            'final_check' => ['accepted'],
        ], [
            'receipt_number.unique' => 'No. Surat Jalan sudah pernah digunakan.',
            'items.min' => 'Minimal 1 item harus ditambahkan.',
            'final_check.accepted' => 'Cek ulang ringkasan penerimaan (SKU, qty, dan lokasi rak), lalu centang konfirmasi sebelum menyimpan.',
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
                        // Tanpa PO: simpan jumlah yang dipesan supaya sisa yang belum datang terlihat.
                        // Angka di bawah qty diterima diabaikan (biasanya nilai bawaan form yang tidak diisi).
                        'qty_expected' => !$po && isset($item['qty_expected']) && (int) $item['qty_expected'] >= $qtyReceived ? (int) $item['qty_expected'] : null,
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

        $canCorrect = in_array(auth()->user()->role, self::CORRECTION_ROLES, true);
        $skuCorrections = $this->skuCorrectionHistory($inbound->reference_no, $po);

        return view('warehouse.inbound.show', compact('inbound', 'items', 'po', 'canCorrect', 'skuCorrections'));
    }

    /**
     * Riwayat koreksi SKU untuk penerimaan ini (langsung, atau lewat Koreksi SKU di PO-nya).
     *
     * @return \Illuminate\Support\Collection<int, AuditTrail>
     */
    private function skuCorrectionHistory(string $receiptNo, ?PurchaseOrder $po)
    {
        return AuditTrail::with('user:id,name')
            ->where(function ($q) use ($receiptNo, $po) {
                $q->where(fn ($q) => $q->where('action', 'CORRECT_INBOUND_SKU')->where('new_values', 'like', '%' . $receiptNo . '%'));
                if ($po) {
                    $q->orWhere(fn ($q) => $q->where('action', 'CORRECT_PO_SKU')->where('new_values', 'like', '%' . $po->id . '%'));
                }
            })
            ->latest('id')
            ->get()
            ->each(function ($audit) {
                // Kolom JSON lama tersimpan sebagai teks JSON di dalam JSON
                $values = $audit->new_values;
                $audit->values = is_array($values) ? $values : (json_decode((string) $values, true) ?: []);
            })
            ->filter(fn ($audit) => $audit->action === 'CORRECT_PO_SKU' || ($audit->values['receipt_number'] ?? null) === $receiptNo)
            ->values();
    }

    /** Role yang boleh mengedit dan menghapus penerimaan (mengubah stok) */
    private const CORRECTION_ROLES = ['OWNER', 'SUPERVISOR'];

    private function authorizeCorrection(): void
    {
        if (!in_array(auth()->user()->role, self::CORRECTION_ROLES, true)) {
            abort(403, 'Hanya Owner atau Supervisor yang bisa mengedit atau menghapus penerimaan barang.');
        }
    }

    public function edit($id)
    {
        $this->authorizeCorrection();
        $inbound = StockMovement::where('type', 'INBOUND')->findOrFail($id);
        $items = StockMovement::where('type', 'INBOUND')
            ->where('reference_no', $inbound->reference_no)
            ->with('masterSku:sku,product_name,size')
            ->orderBy('id')
            ->get();
        $po = PurchaseOrderReceipt::whereIn('stock_movement_id', $items->pluck('id'))->with('purchaseOrder.items')->first()?->purchaseOrder;

        // Penerimaan lama tanpa PO: tawarkan PO yang memuat SKU-nya untuk dihubungkan
        $linkablePos = $po ? collect() : PurchaseOrder::whereIn('status', [...PurchaseOrder::RECEIVABLE_STATUSES, 'RECEIVED'])
            ->whereHas('items', fn ($q) => $q->whereIn('sku', $items->pluck('sku')->unique()))
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return view('warehouse.inbound.edit', compact('inbound', 'items', 'po', 'linkablePos'));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeCorrection();
        $inbound = StockMovement::where('type', 'INBOUND')->findOrFail($id);

        $validated = $request->validate([
            'receipt_number' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:500'],
            'qty' => ['required', 'array'],
            'qty.*' => ['required', 'integer', 'min:0'],
            'po_qty' => ['nullable', 'array'],
            'po_qty.*' => ['required', 'integer', 'min:1'],
            'po_id' => ['nullable', 'exists:purchase_orders,id'],
            'expected' => ['nullable', 'array'],
            'expected.*' => ['nullable', 'integer', 'min:1'],
        ], [
            'reason.required' => 'Alasan perubahan wajib diisi.',
        ]);

        $receiptNo = trim($validated['receipt_number']);
        DB::transaction(fn () => InboundCorrection::update(
            $inbound->reference_no,
            $receiptNo,
            $validated['notes'] ?? null,
            collect($validated['qty'])->mapWithKeys(fn ($qty, $lineId) => [(int) $lineId => (int) $qty])->all(),
            $validated['reason'],
            auth()->id(),
            $request->ip(),
            collect($validated['po_qty'] ?? [])->map(fn ($qty) => (int) $qty)->all(),
            $validated['po_id'] ?? null,
            collect($validated['expected'] ?? [])->mapWithKeys(fn ($qty, $lineId) => [(int) $lineId => $qty === null ? null : (int) $qty])->all(),
        ));

        $released = WaitingStock::release(auth()->id(), $request->ip());
        $firstId = StockMovement::where('type', 'INBOUND')->where('reference_no', $receiptNo)->orderBy('id')->value('id');

        return redirect()->route('gudang.inbound.show', $firstId)
            ->with('success', "Penerimaan {$receiptNo} diperbarui. Stok dan PO sudah disesuaikan." . WaitingStock::releasedMessage($released));
    }

    /**
     * Koreksi SKU yang salah input, juga untuk penerimaan yang sudah Komplit. Stok dipindah dari SKU lama
     * ke SKU yang benar di bin yang sama, qty diterima PO ikut dipindah ke item yang benar.
     */
    public function skuCorrectionForm($id)
    {
        $this->authorizeCorrection();
        $inbound = StockMovement::where('type', 'INBOUND')->findOrFail($id);
        $items = StockMovement::where('type', 'INBOUND')
            ->where('reference_no', $inbound->reference_no)
            ->with('masterSku:sku,product_name,size')
            ->orderBy('id')
            ->get();
        $po = PurchaseOrderReceipt::whereIn('stock_movement_id', $items->pluck('id'))->with('purchaseOrder.items.masterSku:sku,product_name,size')->first()?->purchaseOrder;

        // Penerimaan dari PO hanya bisa diganti ke SKU yang ada di PO itu
        $skus = $po
            ? $po->items->map(fn ($item) => ['sku' => $item->sku, 'product_name' => $item->masterSku?->product_name, 'size' => $item->masterSku?->size, 'remaining' => $item->qty_remaining])->values()
            : MasterSku::where('status', 'AKTIF')->orderBy('sku')->get(['sku', 'product_name', 'size']);

        return view('warehouse.inbound.correct-sku', compact('inbound', 'items', 'po', 'skus'));
    }

    public function correctSku(Request $request, $id)
    {
        $this->authorizeCorrection();
        $inbound = StockMovement::where('type', 'INBOUND')->findOrFail($id);

        $validated = $request->validate([
            'sku' => ['required', 'array'],
            'sku.*' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
            'final_check' => ['accepted'],
        ], [
            'reason.required' => 'Alasan koreksi SKU wajib diisi.',
            'final_check.accepted' => 'Cek ulang ringkasan perubahan SKU, lalu centang konfirmasi sebelum menyimpan.',
        ]);

        $summary = DB::transaction(fn () => SkuCorrection::inbound(
            $inbound->reference_no,
            collect($validated['sku'])->mapWithKeys(fn ($sku, $lineId) => [(int) $lineId => (string) $sku])->all(),
            $validated['reason'],
            auth()->id(),
            $request->ip(),
        ));

        $released = WaitingStock::release(auth()->id(), $request->ip());

        return redirect()->route('gudang.inbound.show', $inbound->id)
            ->with('success', 'SKU penerimaan ' . $inbound->reference_no . ' dikoreksi: ' . implode('; ', $summary) . '. Stok di rak dan PO sudah disesuaikan. Cetak ulang label SKU untuk barang ini.' . WaitingStock::releasedMessage($released));
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeCorrection();
        $inbound = StockMovement::where('type', 'INBOUND')->findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Alasan menghapus wajib diisi.',
        ]);

        $po = DB::transaction(fn () => InboundCorrection::delete($inbound->reference_no, $validated['reason'], auth()->id(), $request->ip()));

        $message = "Penerimaan {$inbound->reference_no} dihapus. Stoknya sudah dikurangi lagi"
            . ($po ? " dan qty diterima di PO {$po->po_number} dikembalikan (status sekarang {$po->status_label})." : '.');

        return redirect()->route('gudang.inbound')->with('success', $message);
    }
}
