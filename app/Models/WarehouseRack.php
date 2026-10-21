<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WarehouseRack extends Model
{
    // ✅ FIX: Konfigurasi untuk UUID primary key
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'code',
        'aisle_number',
        'color',
        'rows_count',
        'position_x',
        'position_y',
        'is_top_row',
        'status'
    ];

    protected $casts = [
        'is_top_row' => 'boolean',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'rows_count' => 'integer',
        'aisle_number' => 'integer',
    ];

    // ✅ FIX: Auto-generate UUID saat create
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Kode rak dari kode bin: "A-01-01" dan "RAK-A-01" masuk ke rak "A".
     */
    public static function codeFromBin(?string $binCode): ?string
    {
        $bin = strtoupper(trim((string) $binCode));
        if ($bin === '') {
            return null;
        }

        if (str_starts_with($bin, 'RAK-')) {
            $bin = substr($bin, 4);
        }

        $code = explode('-', $bin)[0];

        return $code === '' ? null : $code;
    }
}
