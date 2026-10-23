<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\MasterSku;
use App\Models\StockActive;
use App\Models\StockMovement;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Import ulang Master SKU dari Excel/CSV dengan pratinjau.
 * File dibandingkan dulu dengan data saat ini (SKU baru, stok naik, stok turun, data produk berubah),
 * baru disimpan setelah dikonfirmasi. Selisih stok dicatat sebagai pergerakan stok ADJUSTMENT.
 */
class MasterSkuSync
{
    /** Lokasi stok untuk SKU yang belum punya lokasi; nanti ditata ke rak dari Denah Gudang */
    public const DEFAULT_BIN = 'DEFAULT-01';

    public const SIZE_CATEGORIES = ['DEWASA', 'KIDS', 'TEENS'];

    /**
     * Nama kolom yang dikenali per field, setelah dinormalisasi (huruf kecil, selain huruf/angka jadi "_").
     * Mendukung file ekspor Master SKU (SKU, PRODUCT_NAME, CURRENT_STOCK, ...) dan judul berbahasa Indonesia
     * (Artikel, Nama Produk, Total Stok Saat Ini, ...).
     */
    private const COLUMNS = [
        'sku' => ['sku', 'kode_sku'],
        'article' => ['article', 'artikel'],
        'product_name' => ['product_name', 'nama_produk'],
        'version' => ['version', 'versi'],
        'sub_version' => ['sub_version', 'sub_versi'],
        'size_category' => ['size_category', 'kategori_size', 'kategori_ukuran'],
        'size' => ['size', 'ukuran'],
        'size_token' => ['size_token'],
        'price' => ['price', 'harga', 'harga_rp', 'harga_jual'],
        'cost_price' => ['cost_price', 'harga_modal', 'harga_modal_rp', 'hpp'],
        'standard_weight_gram' => ['standard_weight_gram', 'berat', 'berat_gram'],
        'min_stock' => ['min_stock', 'min_stok', 'stok_minimum', 'minimal_stok'],
        'status' => ['status'],
        'stock' => ['current_stock', 'total_stok_saat_ini', 'stok_saat_ini', 'total_stok', 'stok', 'qty'],
    ];

    private const TEXT_FIELDS = ['article', 'product_name', 'version', 'size'];
    private const OPTIONAL_TEXT_FIELDS = ['sub_version', 'size_token'];

    /** Label field untuk ditampilkan di pratinjau */
    public const LABELS = [
        'article' => 'Artikel',
        'product_name' => 'Nama produk',
        'version' => 'Versi',
        'sub_version' => 'Sub versi',
        'size_category' => 'Kategori size',
        'size' => 'Size',
        'size_token' => 'Size token',
        'price' => 'Harga',
        'cost_price' => 'Harga modal',
        'standard_weight_gram' => 'Berat',
        'min_stock' => 'Min. stok',
        'status' => 'Status',
    ];

    /**
     * Baca file menjadi daftar baris [row, sku, field => nilai|null]. Nilai null berarti kolomnya tidak ada
     * atau sel kosong, jadi data yang tersimpan tidak diubah.
     * Sel berisi rumus (misalnya dari Google Sheets) dibaca dari hasil terakhirnya.
     *
     * @return array{rows: array, sheet: string, columns: array}
     * @throws \InvalidArgumentException jika tidak ada sheet dengan kolom SKU
     */
    public function parse(string $path): array
    {
        $book = IOFactory::load($path);

        foreach ($book->getWorksheetIterator() as $sheet) {
            $columns = $this->columns($sheet);
            if (isset($columns['sku']) && count($columns) > 1) {
                return ['rows' => $this->rows($sheet, $columns), 'sheet' => $sheet->getTitle(), 'columns' => array_keys($columns)];
            }
        }

        throw new \InvalidArgumentException('Kolom SKU tidak ditemukan. Baris pertama file harus berisi judul kolom, misalnya SKU, Nama Produk, dan Total Stok Saat Ini (atau CURRENT_STOCK).');
    }

    /**
     * Bandingkan isi file dengan data saat ini. Setiap baris mendapat jenis:
     * new (SKU baru), up (stok bertambah), down (stok berkurang), same (stok tetap), skip (tidak bisa diproses).
     */
    public function compare(array $parsed): array
    {
        $rows = $parsed['rows'];
        $skus = array_values(array_unique(array_column($rows, 'sku')));

        $existing = collect();
        $stock = collect();
        foreach (array_chunk($skus, 500) as $chunk) {
            $existing = $existing->merge(MasterSku::withTrashed()->whereIn('sku', $chunk)->get()->keyBy('sku'));
            $stock = $stock->merge(StockActive::whereIn('sku', $chunk)->groupBy('sku')->selectRaw('sku, SUM(qty) as qty')->pluck('qty', 'sku'));
        }

        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $sku = $row['sku'];
            $item = [
                'row' => $row['row'],
                'sku' => $sku,
                'name' => $row['product_name'] ?? $existing->get($sku)?->product_name,
                'size' => $row['size'] ?? $existing->get($sku)?->size,
                'kind' => 'same',
                'before' => (int) ($stock[$sku] ?? 0),
                'after' => $row['stock'] ?? null,
                'create' => null,
                'changes' => [],
                'problems' => [],
                'warnings' => [],
            ];

            if (isset($seen[$sku])) {
                $item['kind'] = 'skip';
                $item['problems'][] = "SKU dobel di file (sudah ada di baris {$seen[$sku]}), baris ini dilewati.";
                $result[] = $item;
                continue;
            }
            $seen[$sku] = $row['row'];

            $model = $existing->get($sku);
            if ($model && !$model->trashed()) {
                [$item['changes'], $item['warnings']] = $this->changes($model, $row);
            } else {
                [$item['create'], $item['problems'], $item['warnings']] = $this->newSku($row);
                if ($model) {
                    $item['warnings'][] = 'SKU ini pernah dihapus, akan diaktifkan lagi.';
                }
            }

            if ($item['problems']) {
                $item['kind'] = 'skip';
            } elseif ($item['create']) {
                $item['kind'] = 'new';
            } elseif ($item['after'] !== null && $item['after'] > $item['before']) {
                $item['kind'] = 'up';
            } elseif ($item['after'] !== null && $item['after'] < $item['before']) {
                $item['kind'] = 'down';
            }

            $result[] = $item;
        }

        $inFile = array_flip($skus);
        $notInFile = MasterSku::where('status', 'AKTIF')->pluck('sku')->reject(fn ($sku) => isset($inFile[$sku]))->count();

        return [
            'rows' => $result,
            'sheet' => $parsed['sheet'],
            'hasStock' => in_array('stock', $parsed['columns'], true),
            'notInFile' => $notInFile,
        ];
    }

    /** Ringkasan jumlah per jenis untuk kartu di pratinjau */
    public static function summary(array $rows): array
    {
        $rows = collect($rows);
        $valid = $rows->where('kind', '!=', 'skip');

        return [
            'total' => $rows->count(),
            'new' => $rows->where('kind', 'new')->count(),
            'new_qty' => (int) $rows->where('kind', 'new')->sum('after'),
            'up' => $rows->where('kind', 'up')->count(),
            'up_qty' => (int) $rows->where('kind', 'up')->sum(fn ($r) => $r['after'] - $r['before']),
            'down' => $rows->where('kind', 'down')->count(),
            'down_qty' => (int) $rows->where('kind', 'down')->sum(fn ($r) => $r['before'] - $r['after']),
            'same' => $rows->where('kind', 'same')->filter(fn ($r) => !$r['changes'])->count(),
            'skip' => $rows->where('kind', 'skip')->count(),
            'changed' => $valid->filter(fn ($r) => $r['changes'])->count(),
        ];
    }

    /** Ada yang akan disimpan? */
    public static function hasWork(array $rows): bool
    {
        $s = self::summary($rows);

        return ($s['new'] + $s['up'] + $s['down'] + $s['changed']) > 0;
    }

    /**
     * Simpan hasil pratinjau. Stok dihitung ulang dari data saat menyimpan, jadi kalau ada pergerakan
     * di antara pratinjau dan simpan, stok akhirnya tetap sama dengan angka di file.
     *
     * @return array ringkasan yang disimpan: new, up, down, changed, reference
     */
    public function apply(array $rows, ?string $userId, ?string $ip, string $fileName): array
    {
        $reference = 'IMPSKU-' . now()->format('YmdHis');
        $done = ['new' => [], 'up' => [], 'down' => [], 'changed' => []];
        $oldValues = [];

        foreach ($rows as $row) {
            if ($row['kind'] === 'skip') {
                continue;
            }
            $sku = $row['sku'];

            if ($row['create']) {
                $model = MasterSku::withTrashed()->where('sku', $sku)->first();
                if ($model) {
                    $model->restore();
                    $model->update($row['create']);
                } else {
                    MasterSku::create($row['create'] + ['sku' => $sku]);
                }
                $done['new'][] = $sku;
            } elseif ($row['changes']) {
                $model = MasterSku::where('sku', $sku)->first();
                if ($model) {
                    $oldValues[$sku] = array_map(fn ($c) => $c['old'], $row['changes']);
                    $model->update(array_map(fn ($c) => $c['new'], $row['changes']));
                    $done['changed'][$sku] = array_map(fn ($c) => $c['new'], $row['changes']);
                }
            }

            if ($row['after'] !== null) {
                $change = $this->setStock($sku, $row['after'], $reference, $userId, $ip, $fileName);
                if ($change && $change['after'] > $change['before'] && !$row['create']) {
                    $done['up'][$sku] = $change;
                } elseif ($change && $change['after'] < $change['before']) {
                    $done['down'][$sku] = $change;
                }
            }
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => 'IMPORT',
            'module' => 'MASTER_SKU',
            'old_values' => $oldValues ?: null,
            'new_values' => [
                'file' => $fileName,
                'reference' => $reference,
                'new' => $done['new'],
                'stock_up' => $done['up'],
                'stock_down' => $done['down'],
                'changed' => $done['changed'],
            ],
            'ip_address' => $ip,
        ]);

        return [
            'new' => count($done['new']),
            'up' => count($done['up']),
            'down' => count($done['down']),
            'changed' => count($done['changed']),
            'reference' => $reference,
        ];
    }

    /**
     * Samakan total stok SKU dengan angka di file. Tambahan masuk ke lokasi dengan stok terbanyak
     * (atau DEFAULT-01 jika SKU belum punya lokasi); pengurangan diambil dulu dari lokasi yang belum
     * ditata di rak, lalu dari lokasi dengan stok terbanyak. Setiap lokasi yang berubah dicatat sebagai ADJUSTMENT.
     */
    private function setStock(string $sku, int $target, string $reference, ?string $userId, ?string $ip, string $fileName): ?array
    {
        $bins = StockActive::where('sku', $sku)->lockForUpdate()->get();
        $before = (int) $bins->sum('qty');
        $diff = $target - $before;
        if ($diff === 0) {
            return null;
        }

        $note = "Import ulang Master SKU ({$fileName}): total {$before} → {$target}";
        $log = function (StockActive $bin, int $old) use ($sku, $reference, $userId, $ip, $note) {
            StockMovement::create([
                'user_id' => $userId,
                'sku' => $sku,
                'bin_code' => $bin->bin_code,
                'type' => 'ADJUSTMENT',
                'qty' => abs($bin->qty - $old),
                'qty_before' => $old,
                'qty_after' => $bin->qty,
                'reference_no' => $reference,
                'reference_type' => 'IMPORT_SKU',
                'notes' => $note,
                'ip_address' => $ip,
            ]);
        };

        if ($diff > 0) {
            $bin = $bins->sortByDesc('qty')->first() ?? StockActive::create([
                'sku' => $sku,
                'bin_code' => self::DEFAULT_BIN,
                'qty' => 0,
                'reserved_qty' => 0,
                'received_at' => now(),
            ]);
            $old = (int) $bin->qty;
            $bin->update(['qty' => $old + $diff, 'last_movement_at' => now()]);
            $log($bin, $old);
        } else {
            $left = -$diff;
            $placed = fn (StockActive $bin) => $bin->bin_code === self::DEFAULT_BIN || $bin->bin_code === 'GUDANG-UTAMA' ? 0 : 1;
            $ordered = $bins->where('qty', '>', 0)->sortBy([fn ($a, $b) => $placed($a) <=> $placed($b), fn ($a, $b) => $b->qty <=> $a->qty]);
            foreach ($ordered as $bin) {
                if ($left <= 0) {
                    break;
                }
                $old = (int) $bin->qty;
                $take = min($old, $left);
                $bin->update(['qty' => $old - $take, 'last_movement_at' => now()]);
                $log($bin, $old);
                $left -= $take;
            }
        }

        return ['before' => $before, 'after' => (int) StockActive::where('sku', $sku)->sum('qty')];
    }

    /** Perubahan data produk untuk SKU yang sudah ada: [[field => [old, new]], warnings] */
    private function changes(MasterSku $sku, array $row): array
    {
        $changes = [];
        $warnings = [];

        foreach (array_keys(self::LABELS) as $field) {
            $value = $row[$field] ?? null;
            if ($value === null) {
                continue;
            }

            if ($field === 'size_category' && !in_array($value, self::SIZE_CATEGORIES, true)) {
                $warnings[] = "Kategori size \"{$value}\" tidak dikenal, kategori lama ({$sku->size_category}) dipakai.";
                continue;
            }
            if ($field === 'status' && !in_array($value, ['AKTIF', 'NONAKTIF'], true)) {
                $warnings[] = "Status \"{$value}\" tidak dikenal, status lama ({$sku->status}) dipakai.";
                continue;
            }
            // Harga dan berat 0 di file dianggap belum diisi, supaya tidak menghapus angka yang sudah tersimpan
            if (in_array($field, ['price', 'cost_price', 'standard_weight_gram'], true) && $value === 0) {
                continue;
            }
            if (in_array($field, self::OPTIONAL_TEXT_FIELDS, true) && $value === '-') {
                $value = null;
            }

            $old = $sku->{$field};
            if ((string) $old !== (string) $value) {
                $changes[$field] = ['old' => $old, 'new' => $value];
            }
        }

        return [$changes, $warnings];
    }

    /** Data SKU baru dari satu baris: [data|null, problems, warnings] */
    private function newSku(array $row): array
    {
        $problems = [];
        if (($row['product_name'] ?? null) === null) {
            $problems[] = 'SKU baru tanpa nama produk.';
        }
        $warnings = [];
        $category = $row['size_category'] ?? null;
        if ($category === null || $category === '-') {
            // Seperti import sebelumnya: kategori kosong dianggap DEWASA, bisa diubah nanti di Master SKU
            $category = 'DEWASA';
            $warnings[] = 'Kategori size kosong, diisi DEWASA.';
        } elseif (!in_array($category, self::SIZE_CATEGORIES, true)) {
            $problems[] = "Kategori size \"{$category}\" tidak dikenal (harus DEWASA/KIDS/TEENS).";
        }
        if ($problems) {
            return [null, $problems, []];
        }

        $optional = fn ($field) => ($row[$field] ?? '-') === '-' ? null : $row[$field];

        return [[
            'article' => $row['article'] ?? '',
            'product_name' => $row['product_name'],
            'version' => $row['version'] ?? '1.0',
            'sub_version' => $optional('sub_version'),
            'size_category' => $category,
            'size' => $row['size'] ?? '',
            'size_token' => $optional('size_token'),
            'price' => $row['price'] ?? 0,
            'cost_price' => $row['cost_price'] ?? 0,
            'standard_weight_gram' => ($row['standard_weight_gram'] ?? null) ?: 250,
            'min_stock' => $row['min_stock'] ?? 0,
            'status' => in_array($row['status'] ?? null, ['AKTIF', 'NONAKTIF'], true) ? $row['status'] : 'AKTIF',
        ], [], $warnings];
    }

    /** Posisi kolom per field dari baris judul */
    private function columns(Worksheet $sheet): array
    {
        $found = [];
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($col = 1; $col <= $lastColumn; $col++) {
            $name = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $this->value($sheet, $col, 1))), '_');
            foreach (self::COLUMNS as $field => $aliases) {
                if (!isset($found[$field]) && in_array($name, $aliases, true)) {
                    $found[$field] = $col;
                }
            }
        }

        return $found;
    }

    private function rows(Worksheet $sheet, array $columns): array
    {
        $rows = [];
        $lastRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $lastRow; $r++) {
            $sku = strtoupper(trim((string) $this->value($sheet, $columns['sku'], $r)));
            if ($sku === '') {
                continue;
            }

            $row = ['row' => $r, 'sku' => $sku];
            foreach ($columns as $field => $col) {
                if ($field !== 'sku') {
                    $row[$field] = $this->clean($field, $this->value($sheet, $col, $r));
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Nilai sel; untuk rumus dipakai hasil hitungan terakhir yang tersimpan di file */
    private function value(Worksheet $sheet, int $col, int $row): mixed
    {
        $cell = $sheet->getCell([$col, $row]);
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        return $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText ? $value->getPlainText() : $value;
    }

    private function clean(string $field, mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (in_array($field, ['price', 'cost_price', 'standard_weight_gram', 'min_stock', 'stock'], true)) {
            if (is_numeric($value)) {
                return max(0, (int) round((float) $value));
            }
            $digits = preg_replace('/[^0-9]/', '', (string) $value);

            return $digits === '' ? null : (int) $digits;
        }

        // Angka seperti size 4 terbaca 4.0 dari Excel
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        $value = trim((string) $value);

        return in_array($field, ['size_category', 'status', 'size'], true) ? strtoupper($value) : $value;
    }
}
