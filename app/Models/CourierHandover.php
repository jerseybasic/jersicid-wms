<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Serah terima paket ke kurir (manifest). Paket Siap Kirim di-scan satu per satu;
 * saat diserahkan, semua paket di daftar berubah menjadi Dikirim.
 */
class CourierHandover extends Model
{
    use HasUuids;

    public const NO_COURIER = 'TANPA KURIR';

    protected $fillable = ['handover_number', 'courier', 'driver_name', 'user_id', 'status', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (CourierHandover $handover) {
            $handover->handover_number ??= 'STK-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        });
    }

    public function items()
    {
        return $this->hasMany(CourierHandoverItem::class);
    }

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'courier_handover_items')->withTimestamps();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Nama kurir untuk mengelompokkan paket: kata pertama, huruf besar.
     * "SPX Standard" dan "SPX Instant" sama-sama diambil kurir SPX.
     */
    public static function brand(?string $courier): string
    {
        $word = strtoupper(strtok(trim((string) $courier), " \t-/(") ?: '');

        return $word !== '' ? $word : self::NO_COURIER;
    }

    /** Paket Siap Kirim untuk kurir ini yang belum masuk serah terima lain */
    public static function readyOrders(string $courier, ?string $handoverId = null): Collection
    {
        return Order::where('status', 'READY_TO_SHIP')
            ->where(fn ($q) => $q->whereDoesntHave('handoverItem')
                ->when($handoverId, fn ($q) => $q->orWhereHas('handoverItem', fn ($i) => $i->where('courier_handover_id', $handoverId))))
            ->with('handoverItem')
            ->withSum('items', 'qty_ordered')
            ->orderBy('packed_at')
            ->get()
            ->filter(fn (Order $order) => self::brand($order->courier) === $courier)
            ->values();
    }
}
