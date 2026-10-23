<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierHandoverItem extends Model
{
    protected $fillable = ['courier_handover_id', 'order_id'];

    public function handover()
    {
        return $this->belongsTo(CourierHandover::class, 'courier_handover_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
