<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aturan satu baris rak: artikel dan/atau size yang boleh diisi di baris itu.
 * Baris N di rak A memakai bin A-0N-01 (format bin RAK-BARIS-POSISI).
 */
class RackRowRule extends Model
{
    protected $fillable = ['warehouse_rack_id', 'row_index', 'article', 'size'];

    protected $casts = ['row_index' => 'integer'];

    public function rack()
    {
        return $this->belongsTo(WarehouseRack::class, 'warehouse_rack_id');
    }

    public static function binFor(string $rackCode, int $row): string
    {
        return strtoupper($rackCode) . '-' . str_pad((string) $row, 2, '0', STR_PAD_LEFT) . '-01';
    }

    /**
     * Cocok bila artikel sama dengan kolom artikel SKU atau ada di nama produknya,
     * dan size sama persis. Kolom aturan yang kosong tidak membatasi.
     */
    public function matches(object $sku): bool
    {
        return static::matchesValues($this->article, $this->size, $sku);
    }

    public static function matchesValues(?string $article, ?string $size, object $sku): bool
    {
        $article = static::norm($article);
        $size = static::norm($size);
        if ($article === '' && $size === '') {
            return false;
        }

        if ($size !== '' && static::norm($sku->size ?? '') !== $size) {
            return false;
        }

        if ($article !== '') {
            $skuArticle = static::norm($sku->article ?? '');
            $name = static::norm($sku->product_name ?? '');
            if ($skuArticle !== $article && !str_contains($name, $article)) {
                return false;
            }
        }

        return true;
    }

    public static function norm(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
    }
}
