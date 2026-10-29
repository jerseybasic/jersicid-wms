<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\SystemSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\LowStock;
use App\Services\PoNotifier;
use App\Services\WhatsApp;
use App\Services\WaitingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    /** Role yang boleh membuat, mengubah, menyetujui, dan membatalkan PO */
    private const MANAGER_ROLES = ['OWNER', 'SUPERVISOR'];

    /** Batas jumlah SKU yang diisi otomatis dari daftar stok menipis */
    private const LOW_STOCK_PREFILL_LIMIT = 50;

    public function index(Request $request)
    {
        $query = PurchaseOrder::with('items');

        if ($request->filled('search')) {
            $query->where('po_number', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        $stats = [
            'total' => PurchaseOrder::count(),
            'pending' => PurchaseOrder::whereIn('status', ['DRAFT', 'PENDING'])->count(),
            'approved' => PurchaseOrder::where('status', 'APPROVED')->count(),
            'in_production' => PurchaseOrder::where('status', 'IN_PRODUCTION')->count(),
        ];

        $statusOptions = PurchaseOrder::STATUS_LABELS;
        unset($statusOptions['READY']);

        $suppliers = Supplier::where('status', 'AKTIF')->orderBy('name')->get();

        return view('warehouse.po.index', compact('purchaseOrders', 'stats', 'statusOptions', 'suppliers'));
    }

    public function create(Request $request)
    {
        $this->authorizeManager();

        $data = $this->formData(new PurchaseOrder());

        // "Buat PO dari stok menipis": item terisi otomatis dengan saran jumlah pesan ulang
        if ($request->query('dari') === 'stok-menipis') {
            $lowStock = LowStock::query()
                ->orderBy('current_stock')
                ->limit(self::LOW_STOCK_PREFILL_LIMIT)
                ->get();

            $data['prefillRows'] = $lowStock->map(fn ($sku) => [
                'sku' => $sku->sku,
                'qty' => LowStock::suggestedQty((int) $sku->current_stock, (int) $sku->limit_qty),
                'price' => (int) ($sku->cost_price ?: $sku->price),
                'note' => "Stok sekarang {$sku->current_stock} pcs, batas {$sku->limit_qty} pcs",
            ])->all();
            $data['prefillLabel'] = 'SKU stok menipis sudah dimasukkan. Jumlah pesan disarankan sampai dua kali batas stok; ubah atau hapus baris yang tidak perlu.';
            $data['prefillEmpty'] = 'Tidak ada SKU yang stoknya menipis saat ini.';
            $data['prefillNotes'] = 'Isi ulang stok menipis';
        }

        // "Buat PO untuk kekurangan": barang yang kurang untuk order yang menunggu stok
        if ($request->query('dari') === 'kebutuhan-order') {
            $needs = WaitingStock::needs()->where('short', '>', 0)->values();
            $prices = MasterSku::whereIn('sku', $needs->pluck('sku'))->get(['sku', 'price', 'cost_price'])->keyBy('sku');

            $data['prefillRows'] = $needs->map(fn ($need) => [
                'sku' => $need['sku'],
                'qty' => $need['short'],
                'price' => (int) ($prices[$need['sku']]->cost_price ?? 0 ?: $prices[$need['sku']]->price ?? 0),
                'note' => "Dibutuhkan {$need['orders']} order: {$need['need']} pcs, stok bebas {$need['available']}" . ($need['incoming'] ? ", sudah di PO lain {$need['incoming']}" : ''),
            ])->all();
            $data['prefillLabel'] = 'SKU yang kurang untuk order Menunggu stok sudah dimasukkan, sejumlah kekurangannya. Order masuk antrean picking otomatis saat barangnya diterima.';
            $data['prefillEmpty'] = 'Tidak ada kekurangan: stok dan PO yang sudah ada cukup untuk semua order yang menunggu stok.';
            $data['prefillNotes'] = 'Kekurangan untuk order yang menunggu stok';
        }

        return view('warehouse.po.create', $data);
    }

    public function store(Request $request)
    {
        $this->authorizeManager();
        $validated = $this->validatePo($request, true);

        try {
            $po = DB::transaction(function () use ($validated) {
                $supplier = Supplier::findOrFail($validated['supplier_id']);

                $po = PurchaseOrder::create([
                    'po_number' => 'PO-' . date('Ymd') . '-' . strtoupper(Str::random(4)),
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'supplier_type' => $validated['supplier_type'],
                    'expected_date' => $validated['expected_date'],
                    'status' => 'DRAFT',
                    'created_by' => Auth::id(),
                    'notes' => $validated['notes'] ?? null,
                ]);

                $this->syncItems($po, $validated['items']);
                $this->audit('CREATE', $po, ['po_number' => $po->po_number]);

                return $po;
            });

            return redirect()->route('po.show', $po->id)
                ->with('success', "PO {$po->po_number} berhasil dibuat sebagai Draft.");
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal membuat PO: ' . safe_error_message($e))->withInput();
        }
    }

    public function show($id)
    {
        $po = PurchaseOrder::with(['items.masterSku', 'creator:id,name,role', 'approver:id,name'])->findOrFail($id);

        return view('warehouse.po.show', compact('po'));
    }

    /** Halaman cetak A4 untuk dikirim ke supplier (cetak atau simpan PDF dari browser) */
    public function print($id)
    {
        $po = PurchaseOrder::with(['items.masterSku', 'supplier', 'creator:id,name', 'approver:id,name'])->findOrFail($id);

        return view('warehouse.po.print', [
            'po' => $po,
            'company' => [
                'name' => SystemSetting::get('company_name', config('app.name')),
                'phone' => SystemSetting::get('company_phone', ''),
                'address' => SystemSetting::get('company_address', ''),
            ],
            'waText' => self::vendorText($po),
            'waPhone' => $po->supplier?->phone ? WhatsApp::normalize($po->supplier->phone) : null,
        ]);
    }

    /** Ringkasan PO untuk dikirim ke supplier lewat WhatsApp milik pengguna sendiri */
    private static function vendorText(PurchaseOrder $po): string
    {
        $lines = ["PO {$po->po_number} - " . SystemSetting::get('company_name', config('app.name'))];
        if ($po->expected_date) {
            $lines[] = 'Target selesai: ' . $po->expected_date->format('d/m/Y');
        }
        $lines[] = '';
        foreach ($po->items as $item) {
            $lines[] = "- {$item->sku}" . ($item->masterSku ? " ({$item->masterSku->product_name} {$item->masterSku->size})" : '') . ": {$item->qty_ordered} pcs";
        }
        $lines[] = '';
        $lines[] = 'Total: ' . (int) $po->items->sum('qty_ordered') . ' pcs';
        if ($po->notes) {
            $lines[] = 'Catatan: ' . $po->notes;
        }

        return implode("\n", $lines);
    }

    public function edit($id)
    {
        $this->authorizeManager();
        $po = PurchaseOrder::with('items')->findOrFail($id);

        if (!$po->isEditable()) {
            return redirect()->route('po.show', $po->id)->with('error', 'PO yang sudah ' . strtolower($po->status_label) . ' tidak bisa diedit.');
        }

        return view('warehouse.po.create', $this->formData($po));
    }

    /**
     * Simpan perubahan PO. PO yang sudah diajukan/disetujui/diproduksi tetap boleh diubah supaya miskom
     * dengan supplier bisa dibetulkan, tapi alasan wajib diisi dan jumlah barang yang sudah diterima
     * di gudang tidak boleh dikurangi atau dihapus.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeManager();
        $po = PurchaseOrder::with('items')->findOrFail($id);

        if (!$po->isEditable()) {
            return redirect()->route('po.show', $po->id)->with('error', 'PO yang sudah ' . strtolower($po->status_label) . ' tidak bisa diedit.');
        }

        $validated = $this->validatePo($request, false, $po->status !== 'DRAFT');
        $incoming = collect($validated['items'])->keyBy('sku');

        $problems = [];
        foreach ($po->items->where('qty_received', '>', 0) as $item) {
            if (!$incoming->has($item->sku)) {
                $problems[] = "{$item->sku} sudah diterima {$item->qty_received} pcs, jadi tidak bisa dihapus dari PO.";
            } elseif ((int) $incoming[$item->sku]['qty'] < $item->qty_received) {
                $problems[] = "Qty {$item->sku} tidak boleh kurang dari yang sudah diterima ({$item->qty_received} pcs).";
            }
        }
        if ($problems) {
            return back()->withErrors(['items' => $problems])->withInput();
        }

        try {
            DB::transaction(function () use ($validated, $incoming, $po) {
                $supplier = Supplier::findOrFail($validated['supplier_id']);
                $before = $po->items->map(fn ($i) => "{$i->sku} x{$i->qty_ordered} @{$i->price}")->all();

                $notes = $validated['notes'] ?? null;
                if (!empty($validated['change_reason'])) {
                    $change = 'Diubah oleh ' . Auth::user()->name . ' (' . now()->format('d/m/Y H:i') . '): ' . $validated['change_reason'];
                    $notes = trim($change . "\n" . $notes);
                }

                $po->update([
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'supplier_type' => $validated['supplier_type'],
                    'expected_date' => $validated['expected_date'],
                    'notes' => $notes,
                ]);

                // Item lama diperbarui (qty diterima tetap), yang dihapus dibuang, yang baru ditambahkan
                foreach ($po->items as $item) {
                    if ($incoming->has($item->sku)) {
                        $item->update(['qty_ordered' => $incoming[$item->sku]['qty'], 'price' => $incoming[$item->sku]['price']]);
                    } else {
                        $item->delete();
                    }
                }
                $this->syncItems($po, $incoming->except($po->items->pluck('sku')->all())->values()->all());

                // Qty dikurangi sampai sama dengan yang sudah diterima: PO otomatis selesai
                $po->load('items');
                $finished = $po->status !== 'DRAFT'
                    && $po->items->sum('qty_received') > 0
                    && $po->items->every(fn ($i) => $i->qty_received >= $i->qty_ordered);
                if ($finished) {
                    $po->update(['status' => 'RECEIVED']);
                }

                $this->audit('UPDATE', $po, [
                    'po_number' => $po->po_number,
                    'status' => $po->status,
                    'items_before' => $before,
                    'items_after' => $po->items->map(fn ($i) => "{$i->sku} x{$i->qty_ordered} @{$i->price}")->all(),
                ] + (!empty($validated['change_reason']) ? ['reason' => $validated['change_reason']] : []));
            });

            $message = $po->status === 'RECEIVED'
                ? 'Purchase Order berhasil diupdate. Semua item sudah diterima, jadi PO ditandai Sudah Diterima.'
                : 'Purchase Order berhasil diupdate!';

            return redirect()->route('po.show', $po->id)->with('success', $message);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal update PO: ' . safe_error_message($e))->withInput();
        }
    }

    public function destroy($id)
    {
        if (Auth::user()->role !== 'OWNER') {
            abort(403, 'Hanya OWNER yang bisa menghapus PO.');
        }

        $po = PurchaseOrder::findOrFail($id);

        if ($po->status !== 'DRAFT') {
            return back()->with('error', 'Hanya PO berstatus Draft yang bisa dihapus.');
        }

        DB::transaction(function () use ($po) {
            $po->items()->delete();
            $po->delete();
            $this->audit('DELETE', $po, ['po_number' => $po->po_number]);
        });

        return redirect()->route('po.index')->with('success', "PO {$po->po_number} berhasil dihapus.");
    }

    /**
     * Ubah status PO: ajukan, setujui, tolak (kembali ke Draft), mulai produksi, siap diambil, batalkan.
     * Status RECEIVED diisi otomatis oleh Inbound.
     */
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'status' => 'required|in:DRAFT,PENDING,APPROVED,IN_PRODUCTION,READY_FOR_PICKUP,CANCELLED',
            'reason' => 'required_if:status,DRAFT|nullable|string|max:500', // wajib saat ditolak, opsional saat dibatalkan
            'production_notes' => 'nullable|string|max:1000',
            'defect_count' => 'nullable|integer|min:0',
            'defect_notes' => 'nullable|string|max:1000',
        ], [
            'reason.required_if' => 'Alasan penolakan wajib diisi.',
        ]);

        $newStatus = $validated['status'];

        // Ajukan, setujui, tolak, dan batalkan hanya untuk OWNER/SUPERVISOR.
        // ADMIN_GUDANG boleh memperbarui tahap produksi.
        if (in_array($newStatus, ['DRAFT', 'PENDING', 'APPROVED', 'CANCELLED']) && !in_array($user->role, self::MANAGER_ROLES)) {
            abort(403, 'Hanya Owner atau Supervisor yang bisa melakukan aksi ini.');
        }

        $po = PurchaseOrder::findOrFail($id);
        $oldStatus = $po->status;

        if (!$po->canTransitionTo($newStatus)) {
            return back()->with('error', "Status PO tidak bisa diubah dari {$po->status_label} ke " . PurchaseOrder::STATUS_LABELS[$newStatus] . '.');
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'APPROVED') {
            $updateData['approved_by'] = $user->id;
            $updateData['approved_at'] = now();
        }

        if ($newStatus === 'READY_FOR_PICKUP') {
            $updateData['ready_at'] = now();
        }

        // Ditolak: alasan dicatat di atas catatan PO supaya pembuat PO langsung melihatnya
        if ($newStatus === 'DRAFT') {
            $rejection = 'Ditolak oleh ' . $user->name . ' (' . now()->format('d/m/Y H:i') . '): ' . $validated['reason'];
            $updateData['notes'] = trim($rejection . "\n" . $po->notes);
        }

        if ($newStatus === 'CANCELLED' && !empty($validated['reason'])) {
            $cancellation = 'Dibatalkan oleh ' . $user->name . ' (' . now()->format('d/m/Y H:i') . '): ' . $validated['reason'];
            $updateData['notes'] = trim($cancellation . "\n" . $po->notes);
        }

        foreach (['production_notes', 'defect_count', 'defect_notes'] as $field) {
            if (isset($validated[$field])) {
                $updateData[$field] = $validated[$field];
            }
        }

        DB::transaction(function () use ($po, $updateData, $oldStatus, $validated) {
            $po->update($updateData);
            $this->audit('UPDATE_PO_STATUS', $po, ['from' => $oldStatus, 'to' => $updateData['status']]
                + (isset($validated['reason']) ? ['reason' => $validated['reason']] : []));
        });

        PoNotifier::statusChanged($po, $user->name, $validated['reason'] ?? null);

        if ($newStatus === 'DRAFT') {
            return back()->with('success', "PO {$po->po_number} ditolak dan dikembalikan ke Draft untuk diperbaiki.");
        }

        return back()->with('success', "Status PO {$po->po_number} sekarang: {$po->status_label}.");
    }

    /**
     * Koreksi status yang salah karena miskom: Owner/Supervisor boleh memindahkan PO ke status mana saja
     * (termasuk mengaktifkan lagi PO yang terlanjur dibatalkan), dengan alasan yang dicatat di catatan PO
     * dan audit trail. Sudah Diterima tetap hanya diisi oleh Inbound.
     */
    public function correctStatus(Request $request, $id)
    {
        $this->authorizeManager();
        $user = Auth::user();

        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', PurchaseOrder::CORRECTABLE_STATUSES),
            'reason' => 'required|string|max:500',
        ], [
            'status.required' => 'Pilih status yang benar.',
            'reason.required' => 'Alasan koreksi status wajib diisi.',
        ]);

        $po = PurchaseOrder::findOrFail($id);
        $oldStatus = $po->status;
        $newStatus = $validated['status'];

        if ($oldStatus === 'RECEIVED') {
            return back()->with('error', 'PO yang sudah diterima lengkap tidak bisa dikoreksi statusnya. Edit qty-nya lewat penerimaan barang.');
        }
        if (!$po->canCorrectTo($newStatus)) {
            return back()->with('error', "PO {$po->po_number} sudah berstatus " . PurchaseOrder::STATUS_LABELS[$newStatus] . '.');
        }

        $updateData = ['status' => $newStatus];

        // Data persetujuan dan siap diambil disesuaikan dengan status yang baru
        if (in_array($newStatus, ['DRAFT', 'PENDING'], true)) {
            $updateData += ['approved_by' => null, 'approved_at' => null];
        } elseif (!$po->approved_at) {
            $updateData += ['approved_by' => $user->id, 'approved_at' => now()];
        }
        $updateData['ready_at'] = $newStatus === 'READY_FOR_PICKUP' ? ($po->ready_at ?? now()) : null;

        $correction = 'Status dikoreksi oleh ' . $user->name . ' (' . now()->format('d/m/Y H:i') . ') dari '
            . (PurchaseOrder::STATUS_LABELS[$oldStatus] ?? $oldStatus) . ' ke ' . PurchaseOrder::STATUS_LABELS[$newStatus] . ': ' . $validated['reason'];
        $updateData['notes'] = trim($correction . "\n" . $po->notes);

        DB::transaction(function () use ($po, $updateData, $oldStatus, $newStatus, $validated) {
            $po->update($updateData);
            $this->audit('CORRECT_PO_STATUS', $po, ['from' => $oldStatus, 'to' => $newStatus, 'reason' => $validated['reason']]);
        });

        return back()->with('success', "Status PO {$po->po_number} dikoreksi menjadi {$po->status_label}.");
    }

    private function authorizeManager(): void
    {
        if (!in_array(Auth::user()->role, self::MANAGER_ROLES)) {
            abort(403, 'Hanya Owner atau Supervisor yang bisa membuat dan mengubah PO.');
        }
    }

    private function validatePo(Request $request, bool $isNew, bool $needsReason = false): array
    {
        return $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_type' => 'required|in:SUBLIMASI,KONVEKSI,UMUM',
            'expected_date' => $isNew ? 'required|date|after_or_equal:today' : 'required|date',
            'notes' => 'nullable|string|max:2000',
            'change_reason' => $needsReason ? 'required|string|max:500' : 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            // SKU yang sama tidak boleh dua baris: Inbound mencatat penerimaan per SKU
            'items.*.sku' => 'required|distinct|exists:master_skus,sku',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|integer|min:0',
        ], [
            'change_reason.required' => 'PO ini sudah diajukan, jadi alasan perubahan wajib diisi.',
            'items.required' => 'Tambahkan minimal satu item.',
            'items.*.sku.distinct' => 'SKU :input ada lebih dari satu baris. Gabungkan jumlahnya di satu baris.',
            'items.*.sku.exists' => 'SKU :input tidak ada di Master SKU.',
        ]);
    }

    private function syncItems(PurchaseOrder $po, array $items): void
    {
        foreach ($items as $item) {
            PurchaseOrderItem::create([
                'id' => (string) Str::uuid(),
                'purchase_order_id' => $po->id,
                'sku' => $item['sku'],
                'qty_ordered' => $item['qty'],
                'qty_received' => 0,
                'price' => $item['price'],
            ]);
        }
    }

    private function formData(PurchaseOrder $po): array
    {
        return [
            'po' => $po,
            'suppliers' => Supplier::where('status', 'AKTIF')->orderBy('name')->get(),
            'skus' => MasterSku::where('status', 'AKTIF')
                ->orderBy('product_name')
                ->orderBy('size')
                ->get(['sku', 'product_name', 'price', 'cost_price', 'size']),
        ];
    }

    private function audit(string $action, PurchaseOrder $po, array $values): void
    {
        AuditTrail::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'module' => 'PURCHASE_ORDER',
            'new_values' => json_encode($values + ['po_id' => $po->id]),
            'ip_address' => request()->ip(),
        ]);
    }
}
