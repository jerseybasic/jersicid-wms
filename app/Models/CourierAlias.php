<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan nama jasa kirim ke ekspedisi, diatur Owner/Supervisor/Admin Gudang
 * di halaman Serah Terima. Dipakai CourierHandover::brand().
 */
class CourierAlias extends Model
{
    protected $fillable = ['name', 'courier'];

    protected static function booted(): void
    {
        // Peta disimpan sekali per request; kosongkan setelah diubah
        $forget = fn () => app()->forgetInstance('courier.aliases');
        static::saved($forget);
        static::deleted($forget);
    }

    /** @return array<string, string> nama (huruf besar) => ekspedisi */
    public static function map(): array
    {
        if (!app()->bound('courier.aliases')) {
            app()->instance('courier.aliases', static::pluck('courier', 'name')->all());
        }

        return app('courier.aliases');
    }
}
