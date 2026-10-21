<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * Alur status order:
     * PENDING → CONFIRMED → PICKING → PACKING → READY_TO_SHIP → SHIPPED → COMPLETED
     * PENDING/CONFIRMED → CANCELLED
     */
    public const STATUS_LABELS = [
        'PENDING' => 'Menunggu Konfirmasi',
        'CONFIRMED' => 'Siap Picking',
        'PICKING' => 'Sedang Picking',
        'PACKING' => 'Siap/Sedang Packing',
        'READY_TO_SHIP' => 'Siap Kirim',
        'SHIPPED' => 'Dikirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];

    protected $fillable = [
        'id',
        'order_number',
        'customer_name',
        'customer_phone',
        'shipping_address',
        'courier',
        'tracking_number',
        'status',
        'created_by',
        'picked_by',
        'packed_by',
        'picked_at',
        'packed_at',
        'shipped_at',
        'notes',
    ];

    protected $casts = [
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pickedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }

    public function packedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    public function pickingSession()
    {
        return $this->hasOne(PickingSession::class, 'order_id');
    }

    public function packingSession()
    {
        return $this->hasOne(PackingSession::class, 'order_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
