<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim pesan WhatsApp lewat gateway Fonnte (fonnte.com). Token perangkat disimpan terenkripsi di pengaturan.
 */
class WhatsApp
{
    public const API_URL = 'https://api.fonnte.com/send';
    public const TOKEN_PATTERN = '/^[A-Za-z0-9!@#$%^&*()_+\-=.~]{10,100}$/';
    /** Satu atau beberapa nomor (08xx / 628xx) atau ID grup Fonnte (…@g.us), dipisah koma */
    public const TARGET_PATTERN = '/^\s*(\+?\d{9,15}|[\d-]{10,40}@g\.us)(\s*,\s*(\+?\d{9,15}|[\d-]{10,40}@g\.us))*\s*$/';

    public static function token(): ?string
    {
        $stored = SystemSetting::get('whatsapp_token');
        if (!$stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function target(): ?string
    {
        return SystemSetting::get('whatsapp_target') ?: null;
    }

    public static function saveToken(?string $token): void
    {
        SystemSetting::set('whatsapp_token', $token ? Crypt::encryptString($token) : '', 'NOTIFICATION', 'Token perangkat Fonnte (terenkripsi)');
    }

    public static function saveTarget(?string $target): void
    {
        $target = $target ? preg_replace('/\s*,\s*/', ',', trim($target)) : '';
        SystemSetting::set('whatsapp_target', $target, 'NOTIFICATION', 'Nomor/grup WhatsApp penerima notifikasi');
    }

    public static function configured(): bool
    {
        return self::token() !== null && self::target() !== null;
    }

    /**
     * Kirim pesan (format WhatsApp: *tebal*, ```kode```). Mengembalikan pesan error, atau null jika berhasil.
     */
    public static function send(string $message): ?string
    {
        if (!self::configured()) {
            return 'Token Fonnte atau nomor tujuan WhatsApp belum diisi.';
        }

        try {
            $response = Http::timeout(20)->asForm()
                ->withHeaders(['Authorization' => self::token()])
                ->post(self::API_URL, [
                    'target' => self::target(),
                    'message' => $message,
                    'countryCode' => '62',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Fonnte tidak bisa dihubungi', ['error' => $e->getMessage()]);

            return 'Layanan WhatsApp (Fonnte) tidak bisa dihubungi. Periksa koneksi internet server.';
        }

        if (!$response->successful() || $response->json('status') !== true) {
            $reason = $response->json('reason') ?? $response->json('detail') ?? ('HTTP ' . $response->status());
            Log::warning('Fonnte menolak pesan', ['reason' => $reason]);

            return 'WhatsApp gagal dikirim: ' . $reason;
        }

        return null;
    }
}
