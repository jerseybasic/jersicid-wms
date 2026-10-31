<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kode SKU yang ditemui (mis. saat import pesanan BigSeller) tetapi belum terdaftar di Master SKU.
 * Tidak perlu ditandai selesai: begitu kodenya ada di Master SKU atau Pemetaan SKU, tidak tampil lagi.
 */
class UnregisteredSku extends Model
{
    public const SOURCE_LABELS = [
        'IMPORT_BIGSELLER' => 'Import pesanan BigSeller',
    ];

    protected $fillable = ['code', 'product_name', 'source', 'source_ref', 'orders_count', 'times_seen', 'last_seen_at', 'ignored_at', 'ignored_by'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'ignored_at' => 'datetime',
    ];

    /** Catat (atau perbarui) kode yang belum terdaftar */
    public static function record(string $code, ?string $productName, string $source, ?string $sourceRef = null, int $ordersCount = 0): void
    {
        $code = SkuAlias::normalize($code);
        if ($code === '' || mb_strlen($code) > 100) {
            return;
        }

        $row = self::firstOrNew(['code' => $code]);
        $row->fill([
            'product_name' => $productName ? mb_substr($productName, 0, 255) : $row->product_name,
            'source' => $source,
            'source_ref' => $sourceRef ? mb_substr($sourceRef, 0, 255) : null,
            'orders_count' => $ordersCount,
            'times_seen' => $row->exists ? $row->times_seen + 1 : 1,
            'last_seen_at' => now(),
            // Muncul lagi setelah diabaikan: tampilkan lagi
            'ignored_at' => null,
            'ignored_by' => null,
        ])->save();
    }

    /** Yang masih menghambat: belum ada di Master SKU, belum dipetakan, dan tidak diabaikan */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('ignored_at')
            ->whereNotIn('code', MasterSku::select('sku'))
            ->whereNotIn('code', SkuAlias::select('alias'));
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }

    /**
     * Isian awal form SKU baru dari kode yang belum terdaftar, mis. GOTHIC-BLK-M → ukuran M, artikel GOTHIC-BLK.
     */
    public static function guessFields(string $code, ?string $productName = null): array
    {
        $code = SkuAlias::normalize($code);
        $parts = preg_split('/[-_\s]+/', $code) ?: [];
        $last = end($parts) ?: '';
        $size = preg_match('/^(XXS|XS|S|M|L|XL|XXL|XXXL|[2-6]XL|\d{1,2})$/', $last) ? $last : null;
        $article = $size && count($parts) > 1 ? implode('-', array_slice($parts, 0, -1)) : $code;

        return array_filter([
            'sku' => $code,
            'article' => $article,
            'product_name' => $productName,
            'size' => $size,
        ]);
    }
}
