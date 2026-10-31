<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Packing gabungan: beberapa keranjang dikemas di satu layar.
 * Tiap order tetap punya sesi packing sendiri, ditandai nomor keranjang (basket_no).
 */
class PackingBatch extends Model
{
    use HasUuids;

    /** Batas order per packing gabungan, sama dengan picking gabungan */
    public const MAX_ORDERS = PickingBatch::MAX_ORDERS;

    protected $fillable = ['batch_number', 'user_id', 'status', 'active_session_id', 'started_at', 'completed_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PackingBatch $batch) {
            $batch->batch_number ??= 'PACKB-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        });
    }

    public function sessions()
    {
        return $this->hasMany(PackingSession::class, 'packing_batch_id')->orderBy('basket_no');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
