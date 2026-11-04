<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'user_id',
        'sku',
        'bin_code',
        'type',
        'qty',
        'qty_expected',
        'qty_before',
        'qty_after',
        'reference_no',
        'reference_type',
        'notes',
        'ip_address',
    ];

    protected $casts = [
        'qty_expected' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }
}
