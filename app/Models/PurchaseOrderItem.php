<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'purchase_order_id',
        'sku',
        'qty_ordered',
        'qty_received',
        'price',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_received' => 'integer',
        'price' => 'integer',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }

    public function receipts()
    {
        return $this->hasMany(PurchaseOrderReceipt::class, 'purchase_order_item_id');
    }

    /** Sisa yang belum datang dari supplier */
    public function getQtyRemainingAttribute(): int
    {
        return max(0, (int) $this->qty_ordered - (int) $this->qty_received);
    }
}
