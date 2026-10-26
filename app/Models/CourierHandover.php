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

    protected $fillable = ['handover_number', 'courier', 'driver_name', 'signature_path', 'photo_path', 'user_id', 'status', 'completed_at'];

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
     * Nama jasa kirim yang umum dari Shopee/TikTok/BigSeller, dicocokkan dari awal nama.
     * Yang lebih panjang dicek dulu.
     */
    public const DEFAULT_BRANDS = [
        'SHOPEE XPRESS' => 'SPX',
        'SHOPEE EXPRESS' => 'SPX',
        'SPX' => 'SPX',
        'J&T CARGO' => 'J&T CARGO',
        'J&T' => 'J&T',
        'JNT' => 'J&T',
        'ID EXPRESS' => 'ID EXPRESS',
        'IDEXPRESS' => 'ID EXPRESS',
        'NINJA' => 'NINJA',
        'SICEPAT' => 'SICEPAT',
        'ANTERAJA' => 'ANTERAJA',
        'POS ' => 'POS',
        'JNE' => 'JNE',
        'LION' => 'LION',
        'GOSEND' => 'GOSEND',
        'GRAB' => 'GRAB',
    ];

    /** Nama jasa kirim dirapikan: huruf besar, spasi tunggal */
    public static function normalizeName(?string $courier): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', (string) $courier)));
    }

    /**
     * Ekspedisi untuk mengelompokkan paket. Urutannya: pemetaan yang diatur di halaman
     * Serah Terima, daftar nama umum (DEFAULT_BRANDS), lalu kata pertama nama jasa kirim.
     * "SPX Standard", "SPX Instant", dan "Shopee Xpress" sama-sama diambil kurir SPX.
     */
    public static function brand(?string $courier): string
    {
        $name = self::normalizeName($courier);
        if ($name === '') {
            return self::NO_COURIER;
        }

        if ($mapped = CourierAlias::map()[$name] ?? null) {
            return $mapped;
        }

        foreach (self::DEFAULT_BRANDS as $prefix => $brand) {
            if (str_starts_with($name . ' ', $prefix)) {
                return $brand;
            }
        }

        return strtok($name, " \t-/(") ?: self::NO_COURIER;
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
