<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditTrail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /** Nama modul di halaman Riwayat Aktivitas */
    public const MODULE_LABELS = [
        'AUTH' => 'Login',
        'USER' => 'Kelola User',
        'SUPPLIER' => 'Supplier',
        'SETTINGS' => 'Pengaturan',
        'PRINTER' => 'Thermal Printer',
        'MASTER_SKU' => 'Master SKU',
        'SKU_ALIAS' => 'Pemetaan SKU',
        'PURCHASE_ORDER' => 'Purchase Order',
        'INBOUND' => 'Inbound',
        'OUTBOUND' => 'Outbound',
        'PICKING' => 'Picking',
        'PACKING' => 'Packing',
        'EKSPEDISI' => 'Serah Terima',
        'TUTUP_KIRIMAN' => 'Tutup Kiriman',
        'RETURN' => 'Retur',
        'WAREHOUSE' => 'Gudang',
        'WAREHOUSE_MAPPING' => 'Denah Gudang',
        'STOCK_OPNAME' => 'Stock Opname',
    ];

    /** Nama aksi di halaman Riwayat Aktivitas; aksi lain tampil apa adanya */
    public const ACTION_LABELS = [
        'LOGIN' => 'Login',
        'LOGOUT' => 'Logout',
        'LOGIN_FAILED' => 'Login gagal',
        'CREATE' => 'Tambah',
        'UPDATE' => 'Ubah',
        'DELETE' => 'Hapus',
        'TOGGLE_STATUS' => 'Ubah status',
        'UPDATE_STATUS' => 'Ubah status',
        'UPDATE_PROFILE' => 'Ubah profil sendiri',
        'UPDATE_WHATSAPP' => 'Ubah nomor WhatsApp',
        'NEW_TOKEN' => 'Buat token makro baru',
        'CLEAR_CACHE' => 'Bersihkan cache',
        'IMPORT' => 'Import',
        'UNDO_IMPORT' => 'Batalkan import',
        'SUBMIT' => 'Kirim untuk disetujui',
        'APPROVE' => 'Setujui',
        'REJECT' => 'Tolak',
        'REPORT_MISSING' => 'Lapor barang tidak ada',
        'APPROVE_MISSING' => 'Setujui barang tidak ada',
        'REJECT_MISSING' => 'Tolak laporan barang tidak ada',
        'MANUAL_PICK' => 'Ambil manual',
        'MANUAL_PACK' => 'Kemas manual',
        'MANUAL_QTY' => 'Ubah jumlah manual',
        'PACK_UNDO' => 'Koreksi −1 packing',
        'PACK_PROBLEM' => 'Masalah saat packing',
        'WAITING_STOCK' => 'Menunggu stok',
        'REACTIVATE' => 'Aktifkan lagi',
        'HANDOVER' => 'Serah terima',
        'TRANSFER' => 'Transfer stok',
        'INBOUND' => 'Barang masuk',
    ];

    public function user()
    {
        // User yang sudah dihapus tetap tampil namanya di riwayat
        return $this->belongsTo(User::class, 'user_id', 'id')->withTrashed();
    }

    /** Satu nilai untuk ditampilkan: kosong jadi "-", ya/tidak untuk boolean */
    public static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '-',
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * Catat satu aksi dari request yang sedang berjalan.
     */
    public static function record(string $action, string $module, ?array $new = null, ?array $old = null, ?string $userId = null): self
    {
        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'module' => $module,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Kolom yang berubah saja: [nilai lama, nilai baru]. Kosong berarti tidak ada yang berubah.
     *
     * @return array{0: array, 1: array}
     */
    public static function changes(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            if ((string) ($before[$key] ?? '') !== (string) ($value ?? '')) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    public function getModuleLabelAttribute(): string
    {
        return self::MODULE_LABELS[$this->module] ?? $this->module;
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /** Nilai lama/baru selalu sebagai array (catatan lama ada yang tersimpan sebagai teks JSON) */
    public function valuesOf(string $column): array
    {
        $value = $this->{$column};
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
