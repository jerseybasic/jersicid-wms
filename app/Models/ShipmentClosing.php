<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tutup kiriman harian. Satu baris per tanggal; tutup ulang memperbarui angkanya.
 */
class ShipmentClosing extends Model
{
    protected $fillable = ['closing_date', 'user_id', 'closed_at', 'shipped_count', 'left_count', 'couriers', 'notes'];

    protected $casts = [
        'closing_date' => 'date',
        'closed_at' => 'datetime',
        'couriers' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
