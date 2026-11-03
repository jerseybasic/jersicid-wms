<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'description'];

    protected $casts = [
        'value' => 'string',
    ];

    /** Nama instance di container: semua setting, dibaca sekali per request (atau per job antrean) */
    public const MEMO = 'system_settings.all';

    protected static function booted(): void
    {
        // Setting berubah: baca ulang dari database pada pemanggilan berikutnya
        static::saved(fn () => app()->forgetInstance(self::MEMO));
        static::deleted(fn () => app()->forgetInstance(self::MEMO));
    }

    /**
     * Get setting value by key.
     *
     * Semua setting diambil dengan satu query lalu disimpan selama request berjalan, jadi
     * halaman yang memanggil setting() berkali-kali (label, pengaturan, dashboard) tidak lagi
     * menjalankan satu query per pemanggilan.
     */
    public static function get(string $key, $default = null)
    {
        $all = self::allValues();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, string|null> */
    public static function allValues(): array
    {
        return app(self::MEMO);
    }

    /**
     * Set setting value
     */
    public static function set(string $key, $value, string $group = 'GENERAL', ?string $description = null)
    {
        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'group' => $group,
                'description' => $description,
            ]
        );
    }

    /**
     * Get all settings by group
     */
    public static function getByGroup(string $group)
    {
        return self::where('group', $group)->pluck('value', 'key')->toArray();
    }
}