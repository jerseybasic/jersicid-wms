<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan SKU marketplace ke kode Master SKU, dipakai saat import pesanan BigSeller.
 */
class SkuAlias extends Model
{
    /** Role yang boleh menambah pemetaan dari pratinjau import */
    public const MANAGER_ROLES = ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'];

    protected $fillable = ['alias', 'sku', 'created_by'];

    public function masterSku()
    {
        return $this->belongsTo(MasterSku::class, 'sku', 'sku');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Kode disimpan huruf besar tanpa spasi di ujung, sama seperti SKU dari file import */
    public static function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /**
     * Simpan atau ganti pemetaan. Mengembalikan pesan error jika tidak valid, null jika berhasil.
     */
    public static function map(string $alias, string $sku, ?string $userId = null): ?string
    {
        $alias = self::normalize($alias);
        $sku = self::normalize($sku);

        if ($alias === '' || mb_strlen($alias) > 100) {
            return 'SKU marketplace wajib diisi (maksimal 100 karakter).';
        }
        if (MasterSku::where('sku', $alias)->exists()) {
            return "{$alias} sudah ada di Master SKU, jadi tidak perlu dipetakan.";
        }
        if (!MasterSku::where('sku', $sku)->exists()) {
            return "SKU gudang {$sku} tidak ada di Master SKU.";
        }

        self::updateOrCreate(['alias' => $alias], ['sku' => $sku, 'created_by' => $userId]);

        return null;
    }
}
