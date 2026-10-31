<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Barang dari order yang itemnya diganti setelah mulai diambil. qty = sisa yang belum ditaruh
 * kembali ke rak (lihat OrderItemChange::putBack).
 */
class RestockItem extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'order_id', 'sku', 'bin_code', 'qty', 'qty_initial', 'reason', 'created_by'];

    protected $casts = [
        'qty' => 'integer',
        'qty_initial' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function scopePending($query)
    {
        return $query->where('qty', '>', 0);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }
}
