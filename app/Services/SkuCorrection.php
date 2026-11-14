<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\StockActive;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi SKU yang salah input pada PO dan penerimaan barang (Inbound), termasuk yang sudah selesai.
 *
 * Barangnya sendiri tidak berubah tempat: stok SKU lama di bin itu dikurangi dan stok SKU yang benar
 * di bin yang sama ditambah sebanyak qty yang diterima. Baris penerimaan (stock_movements) dan riwayat
 * kedatangan PO ikut diganti SKU-nya, jadi laporan dan kartu stok terbaca seperti kalau SKU-nya diinput
 * benar sejak awal. SKU lama dan baru, qty, bin, dan alasannya dicatat di Audit Trail dan di catatan baris.
 *
 * Ditolak jika stok SKU lama sudah dipindah, diambil, atau dijatah untuk order yang dikonfirmasi,
 * karena barangnya tidak lagi utuh di bin itu. Koreksi seperti itu lewat stock opname.
 * Method yang mengubah data harus dipanggil di dalam transaksi DB.
 */
class SkuCorrection
{
    /**
     * Ganti SKU baris-baris satu penerimaan barang.
     *
     * @param  array<int, string>  $newSkus  id baris => SKU yang benar (sama dengan SKU sekarang = tidak diubah)
     * @return array<int, string> ringkasan perubahan, mis. "JSC-A-L → JSC-A-XL (3 pcs di A-01-01)"
     */
    public static function inbound(string $receiptNo, array $newSkus, string $reason, string $userId, ?string $ip): array
    {
        $lines = InboundCorrection::lines($receiptNo);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['receipt' => "Penerimaan {$receiptNo} tidak ditemukan."]);
        }

        $changes = self::changesFor($lines, $newSkus);
        $receipts = PurchaseOrderReceipt::whereIn('stock_movement_id', $lines->pluck('id'))->get()->keyBy('stock_movement_id');
        $poId = $receipts->first()?->purchase_order_id;
        $po = $poId ? PurchaseOrder::with('items')->lockForUpdate()->find($poId) : null;

        // Penerimaan dari PO: SKU yang benar harus ada di PO itu dan masih punya sisa
        if ($po) {
            $problems = [];
            $netBySku = [];
            foreach ($changes as $lineId => $newSku) {
                $line = $lines->firstWhere('id', $lineId);
                if (!isset($receipts[$lineId])) {
                    continue;
                }
                if (!$po->items->contains('sku', $newSku)) {
                    $problems[] = "{$newSku} tidak ada di PO {$po->po_number}. Kalau SKU di PO-nya juga salah, koreksi lewat Koreksi SKU di halaman PO; penerimaan ini ikut terkoreksi.";
                    continue;
                }
                $netBySku[$line->sku] = ($netBySku[$line->sku] ?? 0) - $line->qty;
                $netBySku[$newSku] = ($netBySku[$newSku] ?? 0) + $line->qty;
            }
            foreach ($netBySku as $sku => $added) {
                $item = $po->items->firstWhere('sku', $sku);
                if ($added > 0 && $item && $added > $item->qty_remaining) {
                    $problems[] = "{$sku} di PO {$po->po_number} tinggal sisa {$item->qty_remaining} pcs, padahal penerimaan ini menambah {$added} pcs. Naikkan qty PO-nya dulu lewat Edit PO, atau pakai Koreksi SKU di halaman PO.";
                }
            }
            if ($problems) {
                throw ValidationException::withMessages(['sku' => array_values(array_unique($problems))]);
            }
        }

        $before = self::snapshot($lines);
        $summary = self::relabel($lines, $changes, $reason);

        if ($po) {
            foreach ($changes as $lineId => $newSku) {
                $receipt = $receipts[$lineId] ?? null;
                if (!$receipt) {
                    continue;
                }
                $line = $lines->firstWhere('id', $lineId);
                $from = $po->items->firstWhere('id', $receipt->purchase_order_item_id);
                $to = $po->items->firstWhere('sku', $newSku);
                $from?->decrement('qty_received', $line->qty);
                $to->increment('qty_received', $line->qty);
                $receipt->update(['sku' => $newSku, 'purchase_order_item_id' => $to->id]);
            }
            $poStatus = InboundCorrection::refreshPoStatus($po);
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'CORRECT_INBOUND_SKU',
            'module' => 'WAREHOUSE',
            'old_values' => json_encode(['receipt_number' => $receiptNo, 'items' => $before]),
            'new_values' => json_encode([
                'receipt_number' => $receiptNo,
                'items' => self::snapshot(InboundCorrection::lines($receiptNo)),
                'sku_changes' => $summary,
                'reason' => $reason,
                'supplier' => InboundCorrection::supplier($receiptNo),
            ] + ($po ? ['po_number' => $po->po_number, 'po_status' => $poStatus] : [])),
            'ip_address' => $ip,
        ]);

        return $summary;
    }

    /**
     * Ganti SKU item PO. Barang yang sudah diterima untuk item itu (di semua penerimaan) ikut dipindah
     * ke SKU yang benar. Jika SKU yang benar sudah ada di PO, kedua baris digabung.
     *
     * @param  array<string, string>  $newSkus  id item PO => SKU yang benar
     * @return array<int, string> ringkasan perubahan
     */
    public static function purchaseOrder(PurchaseOrder $po, array $newSkus, string $reason, string $userId, string $userName, ?string $ip): array
    {
        $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($po->id);
        $changes = collect($newSkus)
            ->map(fn ($sku) => strtoupper(trim((string) $sku)))
            ->filter(fn ($sku, $itemId) => $sku !== '' && ($item = $po->items->firstWhere('id', $itemId)) && $item->sku !== $sku);

        if ($changes->isEmpty()) {
            throw ValidationException::withMessages(['sku' => 'Tidak ada SKU yang diubah. Pilih SKU yang benar di kolom SKU benar.']);
        }
        self::checkSkusExist($changes->values()->all());

        $problems = [];
        if ($changes->values()->duplicates()->isNotEmpty()) {
            $problems[] = 'Dua baris tidak boleh diganti ke SKU yang sama sekaligus. Koreksi satu per satu.';
        }
        $changedSkus = $po->items->whereIn('id', $changes->keys())->pluck('sku');
        foreach ($changes as $newSku) {
            if ($changedSkus->contains($newSku)) {
                $problems[] = "{$newSku} juga sedang diganti di baris lain. Untuk menukar dua SKU, koreksi satu per satu.";
            }
        }

        // Riwayat kedatangan tiap item yang diganti. Penerimaan lama yang belum terhubung ke PO tidak
        // bisa ikut dikoreksi stoknya, jadi minta dihubungkan dulu.
        $receipts = PurchaseOrderReceipt::whereIn('purchase_order_item_id', $changes->keys())->get();
        foreach ($changes as $itemId => $newSku) {
            $item = $po->items->firstWhere('id', $itemId);
            $tracked = (int) $receipts->where('purchase_order_item_id', $itemId)->sum('qty');
            if ($item->qty_received > $tracked) {
                $problems[] = "{$item->sku}: " . ($item->qty_received - $tracked) . " pcs diterima lewat penerimaan lama yang belum terhubung ke PO ini. Buka penerimaannya, Edit, pilih PO Terkait {$po->po_number}, lalu ulangi koreksi SKU.";
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['sku' => array_values(array_unique($problems))]);
        }

        $lines = StockMovement::where('type', 'INBOUND')
            ->whereIn('id', $receipts->pluck('stock_movement_id')->filter())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $lineChanges = $receipts->filter(fn ($r) => $r->stock_movement_id && $lines->contains('id', $r->stock_movement_id))
            ->mapWithKeys(fn ($r) => [$r->stock_movement_id => $changes[$r->purchase_order_item_id]])
            ->all();

        $before = $po->items->map(fn ($i) => "{$i->sku} x{$i->qty_ordered} (diterima {$i->qty_received})")->values()->all();
        $stockSummary = $lineChanges ? self::relabel($lines, $lineChanges, $reason, "PO {$po->po_number}") : [];

        $summary = [];
        foreach ($changes as $itemId => $newSku) {
            $item = $po->items->firstWhere('id', $itemId);
            $oldSku = $item->sku;
            $target = $po->items->first(fn ($i) => $i->sku === $newSku && $i->id !== $item->id);

            if ($target) {
                // SKU yang benar sudah ada di PO: gabungkan qty pesanan dan qty diterimanya
                $target->update([
                    'qty_ordered' => $target->qty_ordered + $item->qty_ordered,
                    'qty_received' => $target->qty_received + $item->qty_received,
                ]);
                PurchaseOrderReceipt::where('purchase_order_item_id', $item->id)->update(['sku' => $newSku, 'purchase_order_item_id' => $target->id]);
                $item->delete();
                $summary[] = "{$oldSku} → {$newSku} ({$item->qty_ordered} pcs dipesan, {$item->qty_received} diterima; digabung ke baris {$newSku})";
            } else {
                $item->update(['sku' => $newSku]);
                PurchaseOrderReceipt::where('purchase_order_item_id', $item->id)->update(['sku' => $newSku]);
                $summary[] = "{$oldSku} → {$newSku} ({$item->qty_ordered} pcs dipesan, {$item->qty_received} diterima)";
            }
        }

        $po->load('items');
        $poStatus = in_array($po->status, [...PurchaseOrder::RECEIVABLE_STATUSES, 'RECEIVED'], true) && $po->qty_received_total > 0
            ? InboundCorrection::refreshPoStatus($po)
            : $po->status;

        $po->update(['notes' => trim('SKU dikoreksi oleh ' . $userName . ' (' . now()->format('d/m/Y H:i') . '): '
            . implode(', ', $summary) . '. Alasan: ' . $reason . "\n" . $po->notes)]);

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'CORRECT_PO_SKU',
            'module' => 'PURCHASE_ORDER',
            'old_values' => json_encode(['po_number' => $po->po_number, 'items' => $before]),
            'new_values' => json_encode([
                'po_id' => $po->id,
                'po_number' => $po->po_number,
                'status' => $poStatus,
                'items' => $po->items->map(fn ($i) => "{$i->sku} x{$i->qty_ordered} (diterima {$i->qty_received})")->values()->all(),
                'sku_changes' => $summary,
                'stock_changes' => $stockSummary,
                'reason' => $reason,
            ]),
            'ip_address' => $ip,
        ]);

        return $summary;
    }

    /**
     * Baris yang SKU-nya benar-benar berubah.
     *
     * @return array<int, string> id baris => SKU baru
     */
    private static function changesFor(Collection $lines, array $newSkus): array
    {
        $changes = [];
        foreach ($lines as $line) {
            $sku = strtoupper(trim((string) ($newSkus[$line->id] ?? '')));
            if ($sku !== '' && $sku !== $line->sku) {
                $changes[$line->id] = $sku;
            }
        }

        if (!$changes) {
            throw ValidationException::withMessages(['sku' => 'Tidak ada SKU yang diubah. Pilih SKU yang benar di kolom SKU benar.']);
        }
        self::checkSkusExist(array_values($changes));

        return $changes;
    }

    private static function checkSkusExist(array $skus): void
    {
        $skus = array_values(array_unique($skus));
        $known = MasterSku::whereIn('sku', $skus)->pluck('sku')->all();
        $missing = array_diff($skus, $known);
        if ($missing) {
            throw ValidationException::withMessages(['sku' => 'SKU ' . implode(', ', $missing) . ' tidak ada di Master SKU.']);
        }
    }

    /**
     * Pindahkan stok dari SKU lama ke SKU baru di bin yang sama dan ganti SKU di baris penerimaannya.
     * Selisih dihitung bersih per SKU dan bin, jadi menukar dua SKU di bin yang sama tetap aman.
     *
     * @param  array<int, string>  $changes  id baris => SKU baru
     * @return array<int, string> ringkasan per baris
     */
    private static function relabel(Collection $lines, array $changes, string $reason, ?string $context = null): array
    {
        $net = [];
        foreach ($changes as $lineId => $newSku) {
            $line = $lines->firstWhere('id', $lineId);
            $net[$line->sku . '|' . $line->bin_code] = ($net[$line->sku . '|' . $line->bin_code] ?? 0) - $line->qty;
            $net[$newSku . '|' . $line->bin_code] = ($net[$newSku . '|' . $line->bin_code] ?? 0) + $line->qty;
        }

        // Stok SKU lama harus masih utuh di bin itu dan belum dijatah untuk order
        $problems = [];
        $bySku = [];
        foreach ($net as $key => $delta) {
            if ($delta >= 0) {
                continue;
            }
            [$sku, $bin] = explode('|', $key, 2);
            $bySku[$sku] = ($bySku[$sku] ?? 0) - $delta;
            $stock = StockActive::where('sku', $sku)->where('bin_code', $bin)->lockForUpdate()->first();
            $free = max(0, (int) ($stock?->qty ?? 0) - (int) ($stock?->reserved_qty ?? 0));
            if ($free < -$delta) {
                $problems[] = "{$sku} di {$bin} tinggal {$free} pcs yang bebas, padahal harus dipindah " . (-$delta) . " pcs ke SKU yang benar. Barangnya sudah dipindah atau diambil untuk order; koreksi lewat stock opname.";
            }
        }
        foreach (StockAvailability::shortagesFor($bySku) as $short) {
            $problems[] = "{$short['sku']}: hanya {$short['available']} pcs yang belum dijatah untuk order, padahal harus dipindah {$short['need']} pcs ke SKU yang benar. Batalkan atau selesaikan ordernya dulu, atau koreksi lewat stock opname.";
        }
        if ($problems) {
            throw ValidationException::withMessages(['sku' => array_values(array_unique($problems))]);
        }

        foreach ($net as $key => $delta) {
            if ($delta !== 0) {
                [$sku, $bin] = explode('|', $key, 2);
                InboundCorrection::moveStock($sku, $bin, $delta);
            }
        }

        $summary = [];
        foreach ($changes as $lineId => $newSku) {
            $line = $lines->firstWhere('id', $lineId);
            $note = "SKU dikoreksi dari {$line->sku}" . ($context ? " ({$context})" : '') . ": {$reason}";
            $summary[] = "{$line->sku} → {$newSku} ({$line->qty} pcs di {$line->bin_code})";
            $line->update([
                'sku' => $newSku,
                'notes' => mb_substr(trim(($line->notes ? $line->notes . ' | ' : '') . $note), 0, 1000),
            ]);
        }

        return $summary;
    }

    private static function snapshot(Collection $lines): array
    {
        return $lines->map(fn ($line) => "{$line->sku} x{$line->qty} di {$line->bin_code}")->values()->all();
    }
}
