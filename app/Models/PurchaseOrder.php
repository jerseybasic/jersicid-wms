<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'po_number', 'supplier_id', 'supplier_name', 'supplier_type',
        'expected_date', 'status', 'created_by', 'notes', 'production_notes',
        'defect_count', 'defect_notes', 'ready_at',
        'approved_by', 'approved_at',
    ];

    public const STATUS_LABELS = [
        'DRAFT' => 'Draft',
        'PENDING' => 'Menunggu Approval',
        'APPROVED' => 'Disetujui',
        'IN_PRODUCTION' => 'Sedang Diproduksi',
        'READY_FOR_PICKUP' => 'Siap Diambil',
        'READY' => 'Siap Diambil',
        'RECEIVED' => 'Sudah Diterima',
        'CANCELLED' => 'Dibatalkan',
    ];

    /** Status yang isi PO-nya masih boleh diubah (belum diterima lengkap dan tidak dibatalkan) */
    public const EDITABLE_STATUSES = ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'READY'];

    /** Status tujuan saat Owner/Supervisor mengoreksi status yang salah karena miskom */
    public const CORRECTABLE_STATUSES = ['DRAFT', 'PENDING', 'APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP'];

    protected $casts = [
        'expected_date' => 'date',
        'ready_at' => 'datetime',
        'approved_at' => 'datetime',
        'defect_count' => 'integer',
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

    // ✅ Relasi ke Supplier (jika ada)
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    // ✅ Relasi ke User (pembuat PO)
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ✅ Relasi ke Items
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function getTotalAmountAttribute(): int
    {
        return (int) $this->items->sum(fn ($item) => $item->qty_ordered * $item->price);
    }

    // ✅ Helper: Cek apakah status bisa transisi
    public function canTransitionTo($newStatus)
    {
        $allowedTransitions = [
            'DRAFT' => ['PENDING', 'CANCELLED'],
            'PENDING' => ['APPROVED', 'DRAFT', 'CANCELLED'], // DRAFT = ditolak, dikembalikan untuk diperbaiki
            'APPROVED' => ['IN_PRODUCTION', 'CANCELLED'],
            'IN_PRODUCTION' => ['READY_FOR_PICKUP', 'CANCELLED'],
            'READY_FOR_PICKUP' => ['CANCELLED'],
            'READY' => ['CANCELLED'],
            // RECEIVED diisi otomatis oleh Inbound saat semua item sudah diterima
        ];

        return isset($allowedTransitions[$this->status]) 
            && in_array($newStatus, $allowedTransitions[$this->status]);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    /** Koreksi status boleh dari status mana saja kecuali Sudah Diterima (diisi otomatis oleh Inbound) */
    public function canCorrectTo(string $newStatus): bool
    {
        return $this->status !== 'RECEIVED'
            && $this->status !== $newStatus
            && in_array($newStatus, self::CORRECTABLE_STATUSES, true);
    }

    // ✅ Helper: Get status badge color
    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'DRAFT' => 'bg-slate-500/20 text-slate-400',
            'PENDING' => 'bg-amber-500/20 text-amber-400',
            'APPROVED' => 'bg-blue-500/20 text-blue-400',
            'IN_PRODUCTION' => 'bg-purple-500/20 text-purple-400',
            'READY_FOR_PICKUP' => 'bg-emerald-500/20 text-emerald-400',
            'READY' => 'bg-emerald-500/20 text-emerald-400',
            'RECEIVED' => 'bg-cyan-500/20 text-cyan-400',
            'CANCELLED' => 'bg-red-500/20 text-red-400',
            default => 'bg-slate-500/20 text-slate-400',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
