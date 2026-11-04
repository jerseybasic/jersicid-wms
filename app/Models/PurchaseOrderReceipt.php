<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Satu kedatangan barang untuk satu item PO. PO 5 pcs yang datang 2 pcs lalu 3 pcs punya dua baris di sini.
 */
class PurchaseOrderReceipt extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'purchase_order_id', 'purchase_order_item_id', 'sku', 'qty', 'qty_rejected',
        'receipt_number', 'received_date', 'bin_code', 'stock_movement_id', 'received_by', 'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
        'qty_rejected' => 'integer',
        'received_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $receipt->id ??= (string) Str::uuid();
        });
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }
}
