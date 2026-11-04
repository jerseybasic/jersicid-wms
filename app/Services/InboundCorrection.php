<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\StockActive;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi penerimaan barang (Inbound) yang salah input: ubah nomor receipt, catatan, dan qty per baris,
 * atau hapus penerimaannya. Stok di rak dan qty diterima di PO ikut disesuaikan, jadi hasilnya sama
 * seperti kalau penerimaannya diinput benar sejak awal. Isi sebelum dan sesudahnya disimpan di audit trail.
 *
 * Mengurangi atau menghapus ditolak jika barangnya sudah dipakai: dipindah, diambil untuk order,
 * atau sudah dijatah untuk order yang dikonfirmasi. Koreksi seperti itu lewat stock opname.
 * Method yang mengubah data harus dipanggil di dalam transaksi DB.
 */
class InboundCorrection
{
    /** Semua baris INBOUND dengan nomor receipt ini, dikunci untuk diubah */
    public static function lines(string $receiptNo): Collection
    {
        return StockMovement::where('type', 'INBOUND')
            ->where('reference_no', $receiptNo)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<int, int>  $qtys  id baris => qty baru (0 = baris dihapus)
     * @param  array<string, int>  $poQtys  sku => qty dipesan baru di PO (hanya untuk penerimaan dari PO)
     * @param  string|null  $linkPoId  hubungkan penerimaan lama yang belum terhubung ke PO ini
     */
    public static function update(string $receiptNo, string $newReceiptNo, ?string $notes, array $qtys, string $reason, string $userId, ?string $ip, array $poQtys = [], ?string $linkPoId = null): void
    {
        $lines = self::lines($receiptNo);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['receipt' => "Penerimaan {$receiptNo} tidak ditemukan."]);
        }

        if ($newReceiptNo !== $receiptNo && StockMovement::where('reference_no', $newReceiptNo)->exists()) {
            throw ValidationException::withMessages(['receipt_number' => "No. Surat Jalan {$newReceiptNo} sudah pernah digunakan."]);
        }

        $changes = $lines->mapWithKeys(fn ($line) => [$line->id => (int) ($qtys[$line->id] ?? $line->qty) - $line->qty])
            ->filter();
        if ($lines->every(fn ($line) => (int) ($qtys[$line->id] ?? $line->qty) === 0)) {
            throw ValidationException::withMessages(['items' => 'Semua qty nol. Untuk membatalkan seluruh penerimaan, pakai tombol Hapus penerimaan.']);
        }

        $receipts = PurchaseOrderReceipt::whereIn('stock_movement_id', $lines->pluck('id'))->get()->keyBy('stock_movement_id');
        $po = self::lockPo($receipts);
        $linked = false;
        if (!$po && $linkPoId) {
            $po = self::linkPo($linkPoId, $lines, $userId);
            $receipts = PurchaseOrderReceipt::whereIn('stock_movement_id', $lines->pluck('id'))->get()->keyBy('stock_movement_id');
            $linked = true;
        }

        self::checkDecrease($lines, $changes);
        $poChanges = $po ? self::applyPoQty($po, $lines, $changes, $poQtys) : [];
        self::checkPoIncrease($po, $lines, $receipts, $changes);

        $before = self::snapshot($lines, $receiptNo);
        $notesChanged = $notes !== $lines->first()->notes;

        foreach ($lines as $line) {
            $delta = $changes[$line->id] ?? 0;
            $receipt = $receipts[$line->id] ?? null;

            if ($delta !== 0) {
                self::moveStock($line->sku, $line->bin_code, $delta);
                $po?->items->firstWhere('id', $receipt?->purchase_order_item_id)?->increment('qty_received', $delta);
            }

            $newQty = $line->qty + $delta;
            if ($newQty === 0) {
                $receipt?->delete();
                $line->delete();
                continue;
            }

            $line->update([
                'reference_no' => $newReceiptNo,
                'qty' => $newQty,
                'qty_after' => $line->qty_before + $newQty,
                'notes' => $notesChanged ? $notes : $line->notes,
            ]);
            $receipt?->update(['receipt_number' => $newReceiptNo, 'qty' => $newQty] + ($notesChanged ? ['notes' => $notes] : []));
        }

        $poStatus = $po ? self::refreshPoStatus($po) : null;

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UPDATE_INBOUND',
            'module' => 'WAREHOUSE',
            'old_values' => json_encode($before),
            'new_values' => json_encode(self::snapshot(self::lines($newReceiptNo), $newReceiptNo) + [
                'reason' => $reason,
                'supplier' => self::supplier($receiptNo),
            ] + ($po ? ['po_number' => $po->po_number, 'po_status' => $poStatus] : [])
              + ($poChanges ? ['po_qty' => $poChanges] : [])
              + ($linked ? ['linked_po' => $po->po_number] : [])),
            'ip_address' => $ip,
        ]);

        if ($poChanges) {
            $po->update(['notes' => trim('Qty diubah lewat edit penerimaan ' . $newReceiptNo . ' (' . now()->format('d/m/Y H:i') . '): '
                . implode(', ', $poChanges) . '. Alasan: ' . $reason . "\n" . $po->notes)]);
        }
    }

    /**
     * Hubungkan penerimaan yang dicatat sebelum ada riwayat per kedatangan ke sebuah PO. Inbound lama
     * sudah menambah qty diterima PO jika PO-nya dipilih saat itu, tapi tidak menyimpan hubungannya.
     * Jadi qty diterima PO yang belum punya riwayat dianggap milik penerimaan ini dulu, dan hanya
     * kekurangannya yang ditambahkan ke PO.
     */
    private static function linkPo(string $poId, Collection $lines, string $userId): PurchaseOrder
    {
        $po = PurchaseOrder::with('items')->lockForUpdate()->find($poId);
        if (!$po || !in_array($po->status, [...PurchaseOrder::RECEIVABLE_STATUSES, 'RECEIVED'], true)) {
            throw ValidationException::withMessages(['po_id' => 'PO yang dipilih tidak bisa dihubungkan (belum disetujui atau sudah dibatalkan).']);
        }

        $missing = $lines->pluck('sku')->unique()->reject(fn ($sku) => $po->items->contains('sku', $sku));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['po_id' => 'SKU ' . $missing->implode(', ') . " tidak ada di PO {$po->po_number}. Pilih PO yang benar, atau tambahkan SKU-nya ke PO dulu."]);
        }

        $linkedQty = PurchaseOrderReceipt::whereIn('purchase_order_item_id', $po->items->pluck('id'))
            ->groupBy('purchase_order_item_id')
            ->selectRaw('purchase_order_item_id, SUM(qty) as qty')
            ->pluck('qty', 'purchase_order_item_id');
        $unlinked = $po->items->mapWithKeys(fn ($item) => [$item->sku => max(0, $item->qty_received - (int) ($linkedQty[$item->id] ?? 0))])->all();

        $add = [];
        foreach ($lines as $line) {
            $counted = min($unlinked[$line->sku], $line->qty);
            $unlinked[$line->sku] -= $counted;
            $add[$line->sku] = ($add[$line->sku] ?? 0) + $line->qty - $counted;
        }

        $problems = [];
        foreach ($add as $sku => $qty) {
            $item = $po->items->firstWhere('sku', $sku);
            if ($qty > $item->qty_remaining) {
                $problems[] = "{$sku}: penerimaan ini menambah {$qty} pcs ke PO, melebihi sisa PO {$item->qty_remaining} pcs. Naikkan qty PO-nya dulu lewat Edit PO.";
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['po_id' => $problems]);
        }

        foreach ($add as $sku => $qty) {
            if ($qty > 0) {
                $po->items->firstWhere('sku', $sku)->increment('qty_received', $qty);
            }
        }
        foreach ($lines as $line) {
            PurchaseOrderReceipt::create([
                'purchase_order_id' => $po->id,
                'purchase_order_item_id' => $po->items->firstWhere('sku', $line->sku)->id,
                'sku' => $line->sku,
                'qty' => $line->qty,
                'receipt_number' => $line->reference_no,
                'received_date' => $line->created_at->toDateString(),
                'bin_code' => $line->bin_code,
                'stock_movement_id' => $line->id,
                'received_by' => $line->user_id ?? $userId,
                'notes' => $line->notes,
            ]);
        }

        return $po;
    }

    /**
     * Ubah qty dipesan di PO untuk SKU di penerimaan ini. Tidak boleh di bawah total yang sudah diterima
     * setelah koreksi. Item PO di objek $po ikut diperbarui supaya pengecekan sisa memakai angka baru.
     *
     * @return array<int, string> ringkasan perubahan, mis. "JSC-A-L 5 → 4"
     */
    private static function applyPoQty(PurchaseOrder $po, Collection $lines, Collection $changes, array $poQtys): array
    {
        $summary = [];
        $problems = [];
        foreach ($lines->groupBy('sku') as $sku => $rows) {
            $item = $po->items->firstWhere('sku', $sku);
            if (!$item || !isset($poQtys[$sku]) || (int) $poQtys[$sku] === $item->qty_ordered) {
                continue;
            }
            $newOrdered = (int) $poQtys[$sku];
            $receivedAfter = $item->qty_received + $rows->sum(fn ($line) => $changes[$line->id] ?? 0);
            if ($newOrdered < max(1, $receivedAfter)) {
                $problems[] = "Qty PO {$sku} tidak boleh kurang dari yang sudah diterima ({$receivedAfter} pcs).";
                continue;
            }
            $summary[] = "{$sku} {$item->qty_ordered} → {$newOrdered}";
            $item->update(['qty_ordered' => $newOrdered]);
        }

        if ($problems) {
            throw ValidationException::withMessages(['po_qty' => $problems]);
        }

        return $summary;
    }

    /** Hapus seluruh penerimaan: stok dikurangi lagi dan qty diterima di PO dikembalikan */
    public static function delete(string $receiptNo, string $reason, string $userId, ?string $ip): ?PurchaseOrder
    {
        $lines = self::lines($receiptNo);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['receipt' => "Penerimaan {$receiptNo} tidak ditemukan."]);
        }

        $receipts = PurchaseOrderReceipt::whereIn('stock_movement_id', $lines->pluck('id'))->get()->keyBy('stock_movement_id');
        $po = self::lockPo($receipts);
        $changes = $lines->mapWithKeys(fn ($line) => [$line->id => -$line->qty]);

        self::checkDecrease($lines, $changes);

        $before = self::snapshot($lines, $receiptNo);
        foreach ($lines as $line) {
            self::moveStock($line->sku, $line->bin_code, -$line->qty);
            $receipt = $receipts[$line->id] ?? null;
            if ($receipt) {
                $po?->items->firstWhere('id', $receipt->purchase_order_item_id)?->decrement('qty_received', $line->qty);
                $receipt->delete();
            }
            $line->delete();
        }

        $poStatus = $po ? self::refreshPoStatus($po) : null;

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'DELETE_INBOUND',
            'module' => 'WAREHOUSE',
            'old_values' => json_encode($before + ['supplier' => self::supplier($receiptNo)]),
            'new_values' => json_encode(['receipt_number' => $receiptNo, 'reason' => $reason]
                + ($po ? ['po_number' => $po->po_number, 'po_status' => $poStatus] : [])),
            'ip_address' => $ip,
        ]);

        return $po;
    }

    private static function lockPo(Collection $receipts): ?PurchaseOrder
    {
        $poId = $receipts->first()?->purchase_order_id;

        return $poId ? PurchaseOrder::with('items')->lockForUpdate()->find($poId) : null;
    }

    /**
     * Barang yang mau dikurangi harus masih ada di bin yang sama dan belum dijatah untuk order.
     *
     * @param  Collection<int, int>  $changes  id baris => selisih qty
     */
    private static function checkDecrease(Collection $lines, Collection $changes): void
    {
        $decrease = $lines->filter(fn ($line) => ($changes[$line->id] ?? 0) < 0);
        if ($decrease->isEmpty()) {
            return;
        }

        $problems = [];
        foreach ($decrease->groupBy(fn ($line) => $line->sku . '|' . $line->bin_code) as $rows) {
            $first = $rows->first();
            $need = -$rows->sum(fn ($line) => $changes[$line->id]);
            $stock = StockActive::where('sku', $first->sku)->where('bin_code', $first->bin_code)->lockForUpdate()->first();
            $free = max(0, (int) ($stock?->qty ?? 0) - (int) ($stock?->reserved_qty ?? 0));
            if ($free < $need) {
                $problems[] = "{$first->sku} di {$first->bin_code} tinggal {$free} pcs yang bebas, padahal mau dikurangi {$need} pcs. Barangnya sudah dipindah atau diambil untuk order; koreksi lewat stock opname.";
            }
        }

        $bySku = $decrease->groupBy('sku')->map(fn ($rows) => -$rows->sum(fn ($line) => $changes[$line->id]))->all();
        foreach (StockAvailability::shortagesFor($bySku) as $short) {
            $problems[] = "{$short['sku']}: hanya {$short['available']} pcs yang belum dijatah untuk order, padahal mau dikurangi {$short['need']} pcs. Batalkan atau selesaikan ordernya dulu, atau koreksi lewat stock opname.";
        }

        if ($problems) {
            throw ValidationException::withMessages(['items' => array_values(array_unique($problems))]);
        }
    }

    /** Qty yang ditambah pada penerimaan dari PO tidak boleh melebihi sisa PO */
    private static function checkPoIncrease(?PurchaseOrder $po, Collection $lines, Collection $receipts, Collection $changes): void
    {
        if (!$po) {
            return;
        }

        $problems = [];
        foreach ($lines->groupBy('sku') as $sku => $rows) {
            $added = $rows->sum(fn ($line) => $changes[$line->id] ?? 0);
            $item = $po->items->firstWhere('sku', $sku);
            if ($added > 0 && $item && $added > $item->qty_remaining) {
                $problems[] = "{$sku} ditambah {$added} pcs, melebihi sisa PO {$item->qty_remaining} pcs.";
            }
        }

        if ($problems) {
            throw ValidationException::withMessages(['items' => $problems]);
        }
    }

    private static function moveStock(string $sku, string $binCode, int $delta): void
    {
        $stock = StockActive::where('sku', $sku)->where('bin_code', $binCode)->lockForUpdate()->first();
        if ($stock) {
            $stock->update(['qty' => max(0, $stock->qty + $delta), 'last_movement_at' => now()]);
        } elseif ($delta > 0) {
            StockActive::create([
                'sku' => $sku,
                'bin_code' => $binCode,
                'qty' => $delta,
                'reserved_qty' => 0,
                'received_at' => now(),
                'last_movement_at' => now(),
            ]);
        }
    }

    /**
     * Status PO setelah qty diterima berubah. PO yang dibatalkan tetap dibatalkan. PO yang tidak lagi
     * punya barang diterima kembali ke status sebelum barang pertama datang.
     */
    private static function refreshPoStatus(PurchaseOrder $po): string
    {
        $po->load('items');
        if (!in_array($po->status, [...PurchaseOrder::RECEIVABLE_STATUSES, 'RECEIVED'], true)) {
            return $po->status;
        }

        if ($po->qty_received_total === 0) {
            $status = self::statusBeforeReceiving($po);
        } elseif ($po->items->every(fn ($item) => $item->qty_received >= $item->qty_ordered)) {
            $status = 'RECEIVED';
        } else {
            $status = 'PARTIALLY_RECEIVED';
        }

        if ($status !== $po->status) {
            $po->update(['status' => $status]);
        }

        return $status;
    }

    private static function statusBeforeReceiving(PurchaseOrder $po): string
    {
        if (in_array($po->status, ['APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'READY'], true)) {
            return $po->status;
        }

        $first = AuditTrail::where('action', 'RECEIVE_PO')
            ->where('new_values', 'like', '%' . $po->id . '%')
            ->orderBy('id')
            ->value('old_values');
        $status = json_decode((string) $first, true)['status'] ?? null;

        return in_array($status, ['APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'READY'], true)
            ? $status
            : ($po->ready_at ? 'READY_FOR_PICKUP' : 'APPROVED');
    }

    private static function snapshot(Collection $lines, string $receiptNo): array
    {
        return [
            'receipt_number' => $receiptNo,
            'notes' => $lines->first()?->notes,
            'items' => $lines->map(fn ($line) => "{$line->sku} x{$line->qty} di {$line->bin_code}")->values()->all(),
        ];
    }

    /** Supplier yang dicatat saat penerimaan (atau koreksi terakhir) */
    public static function supplier(string $receiptNo): ?string
    {
        return AuditTrail::whereIn('action', ['INBOUND', 'UPDATE_INBOUND'])
            ->where('new_values', 'like', '%' . $receiptNo . '%')
            ->orderByDesc('id')
            ->get(['new_values'])
            ->map(fn ($audit) => json_decode($audit->new_values, true))
            ->first(fn ($values) => ($values['receipt_number'] ?? null) === $receiptNo && !empty($values['supplier']))['supplier'] ?? null;
    }
}
