<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'order_id',
        'sku',
        'bin_code',
        'qty_ordered',
        'qty_picked',
        'qty_packed',
        'price',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_picked' => 'integer',
        'qty_packed' => 'integer',
        'price' => 'integer',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function masterSku(): BelongsTo
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }
}
