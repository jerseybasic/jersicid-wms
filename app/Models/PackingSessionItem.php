<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PackingSessionItem extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'packing_session_id',
        'order_item_id',
        'sku',
        'qty_ordered',
        'qty_packed',
        'status',
        'notes',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($item) {
            if (empty($item->id)) {
                $item->id = (string) Str::uuid();
            }
        });
    }

    public function session()
    {
        return $this->belongsTo(PackingSession::class, 'packing_session_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }

    public function getStatusLabelAttribute(): string
    {
        return ['PENDING' => 'Belum', 'PARTIAL' => 'Sebagian', 'COMPLETED' => 'Lengkap'][$this->status] ?? $this->status;
    }
}
