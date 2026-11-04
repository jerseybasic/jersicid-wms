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
     */
    public static function update(string $receiptNo, string $newReceiptNo, ?string $notes, array $qtys, string $reason, string $userId, ?string $ip): void
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

        self::checkDecrease($lines, $changes);
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
            ] + ($po ? ['po_number' => $po->po_number, 'po_status' => $poStatus] : [])),
            'ip_address' => $ip,
        ]);
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
