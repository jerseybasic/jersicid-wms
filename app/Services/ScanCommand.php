<?php

namespace App\Services;

/**
 * QR perintah: kode pendek yang dicetak sekali lalu ditempel di meja/dinding.
 * Di-scan di kotak scan mana pun untuk memulai pekerjaan tanpa mencari menu.
 */
class ScanCommand
{
    public const PREFIX = 'CMD-';

    public const COMMANDS = [
        'CMD-PICKING' => [
            'title' => 'MULAI PICKING',
            'hint' => 'Lanjutkan picking milik Anda, atau ambil order terlama yang menunggu picking.',
        ],
        'CMD-PACKING' => [
            'title' => 'MULAI PACKING',
            'hint' => 'Lanjutkan packing milik Anda, atau ambil order terlama yang selesai picking.',
        ],
        'CMD-RETUR' => [
            'title' => 'CEK RETUR',
            'hint' => 'Buka layar penerimaan retur, lalu scan label kirim atau resi paket retur.',
        ],
        'CMD-TUTUP' => [
            'title' => 'TUTUP KIRIMAN HARI INI',
            'hint' => 'Buka ringkasan kiriman hari ini untuk ditutup setelah semua kurir datang.',
        ],
    ];

    /** Kode perintah yang dikenal, atau null bila bukan QR perintah */
    public static function parse(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return isset(self::COMMANDS[$code]) ? $code : null;
    }
}
