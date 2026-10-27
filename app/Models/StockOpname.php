<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StockOpname extends Model
{
    use HasFactory, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'opname_number',
        'warehouse_id',
        'rack_codes',
        'status',
        'notes',
        'created_by',
        'submitted_by',
        'approved_by',
        'rejection_note',
        'started_at',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($opname) {
            if (empty($opname->id)) {
                $opname->id = (string) Str::uuid();
            }
            if (empty($opname->opname_number)) {
                $opname->opname_number = 'OPN-' . date('Ymd') . '-' . strtoupper(Str::random(4));
            }
        });
    }

    /**
     * Rak yang dihitung di opname ini; array kosong = seluruh gudang.
     */
    public function getRacksAttribute(): array
    {
        return $this->rack_codes ? explode(',', $this->rack_codes) : [];
    }

    public function getScopeLabelAttribute(): string
    {
        return $this->racks ? 'Rak ' . implode(', ', $this->racks) : 'Seluruh gudang';
    }

    // ✅ Relasi ke Warehouse (meskipun bukan foreign key constraint)
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class, 'stock_opname_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public const STATUS_LABELS = [
        'DRAFT' => 'Draft',
        'IN_PROGRESS' => 'Sedang dihitung',
        'SUBMITTED' => 'Menunggu persetujuan',
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
            'DRAFT' => 'bg-slate-500/20 text-slate-400',
            'IN_PROGRESS' => 'bg-amber-500/20 text-amber-400',
            'SUBMITTED' => 'bg-blue-500/20 text-blue-400',
            'COMPLETED' => 'bg-emerald-500/20 text-emerald-400',
            'CANCELLED' => 'bg-red-500/20 text-red-400',
            default => 'bg-slate-500/20 text-slate-400',
        };
    }
}
