<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vendor extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'type', 'contact_person', 
        'phone', 'email', 'address', 'status'
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

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'vendor_id');
    }

    public function getTypeColorAttribute()
    {
        return match($this->type) {
            'SUBLIMASI' => 'bg-purple-500/20 text-purple-400',
            'KONVEKSI' => 'bg-blue-500/20 text-blue-400',
            'SUPPLIER_BAHAN' => 'bg-emerald-500/20 text-emerald-400',
            default => 'bg-slate-500/20 text-slate-400',
        };
    }
}