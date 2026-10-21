<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderReturn extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const REASONS = [
        'TUKAR_UKURAN' => 'Tukar ukuran',
        'RUSAK' => 'Barang rusak / cacat',
        'SALAH_KIRIM' => 'Salah kirim',
        'TIDAK_SESUAI' => 'Tidak sesuai pesanan',
        'LAINNYA' => 'Lainnya',
    ];

    protected $fillable = ['id', 'return_number', 'order_id', 'reason', 'notes', 'received_by'];

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
        return $this->belongsTo(Order::class, 'order_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class, 'order_return_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
