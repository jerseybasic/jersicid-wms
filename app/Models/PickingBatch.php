<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Picking gabungan: beberapa order diambil dalam satu jalan.
 * Tiap order tetap punya sesi picking sendiri, ditandai nomor keranjang (basket_no).
 */
class PickingBatch extends Model
{
    use HasUuids;

    /** Batas order per picking gabungan, supaya keranjang tidak tertukar */
    public const MAX_ORDERS = 20;

    /** Jumlah order yang diambil tombol/QR "gabung otomatis" */
    public const AUTO_ORDERS = 10;

    protected $fillable = ['batch_number', 'user_id', 'status', 'started_at', 'completed_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PickingBatch $batch) {
            $batch->batch_number ??= 'BATCH-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        });
    }

    public function sessions()
    {
        return $this->hasMany(PickingSession::class, 'batch_id')->orderBy('basket_no');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
