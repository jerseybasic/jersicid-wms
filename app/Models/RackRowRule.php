<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aturan satu baris rak: kata kunci produk (kolom article) dan/atau size yang boleh diisi di baris itu.
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
     * Cocok bila kata kunci produk (kolom article di tabel ini) ada di SKU, dan size sama persis.
     * Kata kunci dicari di artikel, nama produk, versi, sub versi, dan kode SKU. Semua kata harus
     * ada, urutannya bebas, tanda baca diabaikan: "jujutsu kaisen" cocok dengan "JUJUTSU-KAISEN GOJO".
     * Kolom aturan yang kosong tidak membatasi.
     */
    public function matches(object $sku): bool
    {
        return static::matchesValues($this->article, $this->size, $sku);
    }

    public static function matchesValues(?string $keyword, ?string $size, object $sku): bool
    {
        $words = static::words($keyword);
        $size = static::norm($size);
        if ($words === [] && $size === '') {
            return false;
        }

        if ($size !== '' && static::norm($sku->size ?? '') !== $size) {
            return false;
        }

        if ($words !== []) {
            $haystack = ' ' . implode(' ', static::words(implode(' ', [
                $sku->article ?? '', $sku->product_name ?? '', $sku->version ?? '', $sku->sub_version ?? '', $sku->sku ?? '',
            ]))) . ' ';
            foreach ($words as $word) {
                // awal kata: "OP" cocok dengan "OP" atau "OPM", tidak dengan "TOP"
                if (!str_contains($haystack, ' ' . $word)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Kata-kata huruf besar tanpa tanda baca: "One-Piece  luffy" menjadi ["ONE", "PIECE", "LUFFY"].
     */
    public static function words(?string $value): array
    {
        $clean = preg_replace('/[^A-Z0-9]+/', ' ', strtoupper((string) $value));

        return array_values(array_filter(explode(' ', $clean), fn ($word) => $word !== ''));
    }

    public static function norm(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim((string) $value)));
    }
}
