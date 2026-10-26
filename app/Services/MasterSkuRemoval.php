<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\OrderItem;
use App\Models\OrderReturnItem;
use App\Models\PurchaseOrderItem;
use App\Models\SkuAlias;
use App\Models\StockActive;
use App\Models\StockMovement;
use App\Models\StockOpnameItem;
use Illuminate\Support\Collection;

/**
 * Hapus SKU yang salah masuk (misalnya dari import yang keliru) dan batalkan satu import Master SKU.
 * SKU hanya bisa dihapus selama belum dipakai di transaksi apa pun; stoknya dinolkan dan tercatat
 * di Pergerakan Stok, lalu SKU dihapus permanen supaya kodenya bisa dipakai lagi.
 * Method yang mengubah data harus dipanggil di dalam transaksi DB.
 */
class MasterSkuRemoval
{
    /** Pergerakan stok yang tidak dianggap pemakaian: hanya penyesuaian dari import atau penghapusan */
    private const OWN_MOVEMENTS = ['IMPORT_SKU', 'IMPORT_SKU_UNDO', 'DELETE_SKU'];

    /**
     * Alasan SKU tidak bisa dihapus, per SKU. SKU yang tidak ada di hasil berarti aman dihapus.
     *
     * @param  iterable<string>  $skus
     * @return array<string, string>
     */
    public static function inUse(iterable $skus): array
    {
        $skus = collect($skus)->unique()->values();
        if ($skus->isEmpty()) {
            return [];
        }

        $checks = [
            'sudah dipakai di order' => fn ($chunk) => OrderItem::whereIn('sku', $chunk),
            'ada di Purchase Order' => fn ($chunk) => PurchaseOrderItem::whereIn('sku', $chunk),
            'ada di retur' => fn ($chunk) => OrderReturnItem::whereIn('sku', $chunk),
            'ada di stock opname' => fn ($chunk) => StockOpnameItem::whereIn('sku', $chunk),
            'sudah ada pergerakan stok (inbound, transfer, picking, atau penyesuaian)' => fn ($chunk) => StockMovement::whereIn('sku', $chunk)
                ->where(fn ($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', self::OWN_MOVEMENTS)),
        ];

        $reasons = [];
        foreach ($skus->chunk(500) as $chunk) {
            foreach ($checks as $reason => $query) {
                foreach ($query($chunk->all())->distinct()->pluck('sku') as $sku) {
                    $reasons[$sku] ??= $reason;
                }
            }
        }

        return $reasons;
    }

    /**
     * Hapus SKU yang belum dipakai. SKU yang sudah dipakai dilewati beserta alasannya.
     *
     * @param  iterable<string>  $skus
     * @return array{removed: array<string>, kept: array<string, string>}
     */
    public static function remove(iterable $skus, ?string $userId, ?string $ip, string $reference, string $note): array
    {
        $skus = collect($skus)->map(fn ($sku) => strtoupper(trim($sku)))->filter()->unique()->values();
        $kept = self::inUse($skus);
        $removed = [];

        foreach ($skus as $sku) {
            if (isset($kept[$sku])) {
                continue;
            }
            $model = MasterSku::withTrashed()->where('sku', $sku)->lockForUpdate()->first();
            if (!$model) {
                continue;
            }

            foreach (StockActive::where('sku', $sku)->lockForUpdate()->get() as $bin) {
                if ((int) $bin->qty !== 0) {
                    StockMovement::create([
                        'user_id' => $userId,
                        'sku' => $sku,
                        'bin_code' => $bin->bin_code,
                        'type' => 'ADJUSTMENT',
                        'qty' => abs((int) $bin->qty),
                        'qty_before' => (int) $bin->qty,
                        'qty_after' => 0,
                        'reference_no' => $reference,
                        'reference_type' => 'DELETE_SKU',
                        'notes' => $note,
                        'ip_address' => $ip,
                    ]);
                }
                $bin->delete();
            }
            SkuAlias::where('sku', $sku)->delete();

            // Permanen: lokasi rak ikut terhapus, dan kode SKU bisa dipakai lagi untuk SKU baru
            $model->forceDelete();
            $removed[] = $sku;
        }

        return ['removed' => $removed, 'kept' => array_intersect_key($kept, array_flip($skus->all()))];
    }

    /** Import Master SKU terakhir beserta tanda sudah dibatalkan atau belum */
    public static function recentImports(int $limit = 5): Collection
    {
        $undone = self::undoneReferences();

        return AuditTrail::with('user')
            ->where('module', 'MASTER_SKU')->where('action', 'IMPORT')
            ->latest('id')->limit($limit)->get()
            ->each(fn (AuditTrail $import) => $import->setAttribute('undone', in_array($import->new_values['reference'] ?? null, $undone, true)));
    }

    /** @return array<string> referensi import yang sudah dibatalkan */
    public static function undoneReferences(): array
    {
        return AuditTrail::where('module', 'MASTER_SKU')->where('action', 'UNDO_IMPORT')->get()
            ->map(fn (AuditTrail $undo) => $undo->new_values['reference'] ?? null)
            ->filter()->values()->all();
    }

    /**
     * Rencana pembatalan import, untuk ditampilkan sebelum dijalankan:
     * - remove: SKU baru dari import ini yang akan dihapus (sku => stok sekarang);
     * - kept: SKU baru yang sudah dipakai sehingga tidak bisa dihapus (sku => alasan);
     * - stock: selisih stok dari import ini yang dibalik, per lokasi (termasuk SKU baru yang tidak bisa dihapus);
     * - fields: data produk yang dikembalikan (sku => [field => [sekarang, sebelum import]]).
     */
    public static function plan(AuditTrail $import): array
    {
        $values = $import->new_values ?? [];
        $reference = $values['reference'] ?? null;
        $newSkus = collect($values['new'] ?? []);

        $kept = self::inUse($newSkus);
        $remove = $newSkus->reject(fn ($sku) => isset($kept[$sku]))->values();
        $stockNow = StockActive::whereIn('sku', $remove)->groupBy('sku')->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku');

        // Selisih stok yang dibuat import ini per lokasi; dibalik dengan selisih yang sama
        // supaya pergerakan sesudah import (misalnya picking) tetap terhitung
        $stock = StockMovement::where('reference_no', $reference)->where('reference_type', 'IMPORT_SKU')
            ->whereNotIn('sku', $remove)
            ->get()
            ->groupBy(fn ($m) => $m->sku . '|' . $m->bin_code)
            ->map(function ($moves) {
                $first = $moves->first();
                $diff = (int) $moves->sum(fn ($m) => $m->qty_after - $m->qty_before);
                $now = (int) StockActive::where('sku', $first->sku)->where('bin_code', $first->bin_code)->value('qty');
                $to = $now - $diff;

                return [
                    'sku' => $first->sku,
                    'bin' => $first->bin_code,
                    'diff' => -$diff,
                    'now' => $now,
                    'to' => max(0, $to),
                    'short' => $to < 0 ? -$to : 0,
                ];
            })
            ->filter(fn ($row) => $row['diff'] !== 0)
            ->sortBy(['sku', 'bin'])
            ->values();

        $fields = [];
        $changed = $values['changed'] ?? [];
        $current = MasterSku::whereIn('sku', array_keys($changed))->get()->keyBy('sku');
        foreach ($import->old_values ?? [] as $sku => $old) {
            $model = $current->get($sku);
            if (!$model) {
                continue;
            }
            foreach ($old as $field => $before) {
                // Hanya field yang belum diubah lagi sejak import
                if (array_key_exists($field, $changed[$sku] ?? []) && (string) $model->{$field} === (string) $changed[$sku][$field] && (string) $before !== (string) $model->{$field}) {
                    $fields[$sku][$field] = [$model->{$field}, $before];
                }
            }
        }

        return [
            'reference' => $reference,
            'file' => $values['file'] ?? null,
            'remove' => $remove->mapWithKeys(fn ($sku) => [$sku => (int) ($stockNow[$sku] ?? 0)])->all(),
            'kept' => $kept,
            'stock' => $stock->all(),
            'fields' => $fields,
        ];
    }

    /** Ada yang bisa dibatalkan dari rencana ini? */
    public static function hasWork(array $plan): bool
    {
        return $plan['remove'] || $plan['stock'] || $plan['fields'];
    }

    /**
     * Batalkan import: hapus SKU baru yang belum dipakai, kembalikan selisih stoknya, dan kembalikan
     * data produk yang diubah. Setiap import hanya bisa dibatalkan sekali.
     */
    public static function undoImport(AuditTrail $import, ?string $userId, ?string $ip): array
    {
        if ($import->module !== 'MASTER_SKU' || $import->action !== 'IMPORT') {
            throw new \RuntimeException('Ini bukan riwayat import Master SKU.');
        }
        $plan = self::plan($import);
        if (in_array($plan['reference'], self::undoneReferences(), true)) {
            throw new \RuntimeException("Import {$plan['reference']} sudah dibatalkan sebelumnya.");
        }

        $note = "Batal import Master SKU {$plan['reference']}" . ($plan['file'] ? " ({$plan['file']})" : '');
        $result = self::remove(array_keys($plan['remove']), $userId, $ip, $plan['reference'], $note);
        $kept = $plan['kept'] + $result['kept'];

        $stockDone = [];
        foreach ($plan['stock'] as $row) {
            $bin = StockActive::where('sku', $row['sku'])->where('bin_code', $row['bin'])->lockForUpdate()->first()
                ?? StockActive::create(['sku' => $row['sku'], 'bin_code' => $row['bin'], 'qty' => 0, 'reserved_qty' => 0]);
            $before = (int) $bin->qty;
            $after = max(0, $before + $row['diff']);
            if ($after === $before) {
                continue;
            }
            $bin->update(['qty' => $after, 'last_movement_at' => now()]);
            StockMovement::create([
                'user_id' => $userId,
                'sku' => $row['sku'],
                'bin_code' => $row['bin'],
                'type' => 'ADJUSTMENT',
                'qty' => abs($after - $before),
                'qty_before' => $before,
                'qty_after' => $after,
                'reference_no' => $plan['reference'],
                'reference_type' => 'IMPORT_SKU_UNDO',
                'notes' => $note,
                'ip_address' => $ip,
            ]);
            $stockDone[] = ['sku' => $row['sku'], 'bin' => $row['bin'], 'before' => $before, 'after' => $after];
        }

        foreach ($plan['fields'] as $sku => $fields) {
            MasterSku::where('sku', $sku)->first()?->update(array_map(fn ($pair) => $pair[1], $fields));
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'UNDO_IMPORT',
            'module' => 'MASTER_SKU',
            'new_values' => [
                'reference' => $plan['reference'],
                'file' => $plan['file'],
                'removed' => $result['removed'],
                'kept' => $kept,
                'stock' => $stockDone,
                'fields' => array_map(fn ($fields) => array_map(fn ($pair) => $pair[1], $fields), $plan['fields']),
            ],
            'ip_address' => $ip,
        ]);

        return [
            'removed' => count($result['removed']),
            'kept' => $kept,
            'stock' => count(collect($stockDone)->unique('sku')),
            'fields' => count($plan['fields']),
            'reference' => $plan['reference'],
        ];
    }
}
