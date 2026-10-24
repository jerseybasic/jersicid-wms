<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\PickingSessionItem;
use App\Models\Order;
use App\Models\User;

class PickingSession extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'session_number',
        'order_id',
        'batch_id',
        'basket_no',
        'user_id',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($session) {
            if (empty($session->id)) {
                $session->id = (string) Str::uuid();
            }
            if (empty($session->session_number)) {
                $session->session_number = 'PICK-' . date('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function batch()
    {
        return $this->belongsTo(PickingBatch::class, 'batch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items()
    {
        return $this->hasMany(PickingSessionItem::class, 'picking_session_id');
    }

    public const STATUS_LABELS = [
        'PENDING' => 'Belum mulai',
        'IN_PROGRESS' => 'Sedang dikerjakan',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute()
    {
        return match ($this->status) {
            'PENDING' => 'bg-slate-500/20 text-slate-400',
            'IN_PROGRESS' => 'bg-amber-500/20 text-amber-400',
            'COMPLETED' => 'bg-emerald-500/20 text-emerald-400',
            'CANCELLED' => 'bg-red-500/20 text-red-400',
            default => 'bg-slate-500/20 text-slate-400',
        };
    }
}
