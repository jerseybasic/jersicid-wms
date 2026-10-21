<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrderReturnItem extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'order_return_id', 'order_item_id', 'sku', 'qty_good', 'qty_damaged', 'bin_code'];

    protected $casts = [
        'qty_good' => 'integer',
        'qty_damaged' => 'integer',
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

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    public function masterSku(): BelongsTo
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }
}
