<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterSku extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sku',
        'article',
        'product_name',
        'version',
        'sub_version',
        'size_category',
        'size',
        'size_token',
        'price',
        'cost_price',
        'standard_weight_gram',
        'min_stock',
        'status'
    ];

    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
        'standard_weight_gram' => 'integer',
        'min_stock' => 'integer',
    ];

    /**
     * Harga modal dari satu baris file import (kolom COST_PRICE, HARGA_MODAL, atau HPP).
     * Null kalau kolomnya tidak ada atau kosong, supaya harga modal yang sudah tersimpan tidak terhapus.
     */
    public static function costFromRow(array $row): ?int
    {
        foreach (['cost_price', 'harga_modal', 'hpp'] as $key) {
            $value = $row[$key] ?? null;
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
            $digits = preg_replace('/[^0-9]/', '', (string) $value);

            return $digits === '' ? null : (int) $digits;
        }

        return null;
    }

    /**
     * Relasi ke StockActive
     */
    public function stockActives()
    {
        return $this->hasMany(StockActive::class, 'sku', 'sku');
    }

    /**
     * Relasi ke WarehouseRack melalui pivot table rack_products
     * ✅ FIX: Gunakan belongsToMany dengan benar
     */
    public function racks()
    {
        return $this->belongsToMany(WarehouseRack::class, 'rack_products', 'sku', 'warehouse_rack_id')
            ->withPivot('id', 'current_qty', 'max_capacity', 'bin_location')
            ->withTimestamps();
    }

    /**
     * ✅ FIX: Hitung total stok dari stock_actives (bukan dari pivot)
     */
    public function getTotalStockAttribute()
    {
        return $this->stockActives()->sum('qty');
    }

    /**
     * Cek apakah SKU low stock
     */
    public function getIsLowStockAttribute()
    {
        $currentStock = $this->total_stock;
        return $this->min_stock > 0 && $currentStock <= $this->min_stock;
    }

    /**
     * Scope untuk SKU aktif
     */
    public function scopeAktif($query)
    {
        return $query->where('status', 'AKTIF');
    }

    /**
     * Scope untuk low stock
     */
    public function scopeLowStock($query)
    {
        return $query->where('status', 'AKTIF')
            ->whereRaw('min_stock > 0')
            ->whereRaw('(SELECT COALESCE(SUM(qty), 0) FROM stock_actives WHERE stock_actives.sku = master_skus.sku) <= min_stock');
    }
}
