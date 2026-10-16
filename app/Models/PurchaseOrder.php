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
        'id', 'po_number', 'supplier_id', 'vendor_id', 'user_id',
        'expected_date', 'status', 'workspace', 'notes', 'total_amount',
        'defect_count', 'defect_notes', 'ready_at',
        'approved_by', 'approved_at', 'rejection_reason',
    ];

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

    // ✅ Relasi ke Vendor
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    // ✅ Relasi ke Supplier (jika ada)
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    // ✅ Relasi ke User (pembuat PO)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ✅ Relasi ke Items
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }

    // ✅ Helper: Cek apakah bisa diupdate
    public function canUpdateStatus($userRole)
    {
        return in_array($userRole, ['ADMIN_GUDANG', 'SUPERVISOR', 'OWNER']);
    }

    // ✅ Helper: Cek apakah status bisa transisi
    public function canTransitionTo($newStatus)
    {
        $allowedTransitions = [
            'DRAFT' => ['PENDING', 'CANCELLED'],
            'PENDING' => ['APPROVED', 'CANCELLED'],
            'APPROVED' => ['IN_PRODUCTION', 'CANCELLED'],
            'IN_PRODUCTION' => ['READY_FOR_PICKUP', 'CANCELLED'],
            'READY_FOR_PICKUP' => ['RECEIVED'],
        ];

        return isset($allowedTransitions[$this->status]) 
            && in_array($newStatus, $allowedTransitions[$this->status]);
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
            'RECEIVED' => 'bg-cyan-500/20 text-cyan-400',
            'CANCELLED' => 'bg-red-500/20 text-red-400',
            default => 'bg-slate-500/20 text-slate-400',
        };
    }
}