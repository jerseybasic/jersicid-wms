<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Str;

/**
 * Notifikasi WhatsApp lewat makro Playwright milik toko.
 *
 * WMS hanya menaruh pesan di antrean (whatsapp_messages), satu baris per penerima. Makro mengambil
 * antrean lewat API dengan token, mengirimnya di WhatsApp Web, lalu melapor terkirim atau gagal.
 * Penerima: anggota aktif yang mencentang notifikasi di Kelola User, plus nomor tambahan di Pengaturan.
 */
class WhatsApp
{
    /** Nomor tambahan (08xx / 628xx) dipisah koma */
    public const TARGET_PATTERN = '/^\s*(\+?\d{9,15})(\s*,\s*(\+?\d{9,15}))*\s*$/';
    /** Pesan yang sudah diambil makro tapi belum dilaporkan dianggap macet setelah sekian menit, lalu boleh diambil lagi */
    public const CLAIM_TIMEOUT_MINUTES = 10;
    public const MAX_ATTEMPTS = 3;

    /** Nomor tambahan di luar anggota, dipisah koma */
    public static function extraTargets(): ?string
    {
        return SystemSetting::get('whatsapp_target') ?: null;
    }

    public static function saveExtraTargets(?string $target): void
    {
        $target = $target ? preg_replace('/\s*,\s*/', ',', trim($target)) : '';
        SystemSetting::set('whatsapp_target', $target, 'NOTIFICATION', 'Nomor WhatsApp tambahan penerima notifikasi');
    }

    /** 08xx / +628xx / 628xx → 628xx */
    public static function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        return str_starts_with($digits, '0') ? '62' . substr($digits, 1) : $digits;
    }

    /** Anggota aktif yang menerima notifikasi WhatsApp */
    public static function members()
    {
        return User::where('status', 'AKTIF')->where('notify_whatsapp', true)
            ->whereNotNull('whatsapp')->where('whatsapp', '!=', '')
            ->orderBy('name')->get(['id', 'name', 'role', 'whatsapp']);
    }

    /** Semua nomor tujuan (sudah dinormalisasi, tanpa duplikat) */
    public static function recipients(): array
    {
        $numbers = self::members()->pluck('whatsapp')->all();
        foreach (explode(',', (string) self::extraTargets()) as $extra) {
            if (trim($extra) !== '') {
                $numbers[] = $extra;
            }
        }

        return array_values(array_unique(array_map([self::class, 'normalize'], $numbers)));
    }

    public static function configured(): bool
    {
        return self::hasToken() && self::recipients() !== [];
    }

    /**
     * Taruh pesan (format WhatsApp: *tebal*) di antrean untuk semua penerima.
     * Mengembalikan pesan error, atau null jika berhasil masuk antrean.
     */
    public static function send(string $message): ?string
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return 'Belum ada penerima. Isi nomor WhatsApp anggota di Kelola User dan centang "Kirim notifikasi WhatsApp".';
        }

        foreach ($recipients as $recipient) {
            WhatsAppMessage::create(['recipient' => $recipient, 'body' => $message]);
        }

        return null;
    }

    // ---- Token makro -------------------------------------------------------

    public static function hasToken(): bool
    {
        return (bool) SystemSetting::get('whatsapp_macro_token');
    }

    /** Buat token baru (token lama tidak berlaku lagi). Token asli hanya dikembalikan sekali; yang disimpan hash-nya. */
    public static function generateToken(): string
    {
        $token = 'wa_' . Str::random(40);
        SystemSetting::set('whatsapp_macro_token', hash('sha256', $token), 'NOTIFICATION', 'Hash token makro WhatsApp');

        return $token;
    }

    public static function revokeToken(): void
    {
        SystemSetting::set('whatsapp_macro_token', '', 'NOTIFICATION', 'Hash token makro WhatsApp');
    }

    public static function tokenValid(?string $token): bool
    {
        $hash = SystemSetting::get('whatsapp_macro_token');

        return $hash && $token && hash_equals($hash, hash('sha256', $token));
    }

    // ---- Antrean untuk makro ----------------------------------------------

    /** Ambil sampai $limit pesan untuk dikirim, dan tandai sedang diambil. */
    public static function claim(int $limit): \Illuminate\Support\Collection
    {
        SystemSetting::set('whatsapp_macro_seen_at', now()->toDateTimeString(), 'NOTIFICATION', 'Terakhir makro WhatsApp mengambil antrean');

        return \Illuminate\Support\Facades\DB::transaction(function () use ($limit) {
            $messages = WhatsAppMessage::where('status', 'PENDING')
                ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)))
                ->orderBy('id')->limit($limit)->lockForUpdate()->get();

            WhatsAppMessage::whereIn('id', $messages->pluck('id'))->update(['claimed_at' => now()]);

            return $messages;
        });
    }

    public static function markSent(WhatsAppMessage $message): void
    {
        $message->update(['status' => 'SENT', 'sent_at' => now(), 'error' => null]);
    }

    /** Gagal: dicoba lagi sampai MAX_ATTEMPTS, setelah itu berstatus FAILED. */
    public static function markFailed(WhatsAppMessage $message, string $error): void
    {
        $attempts = $message->attempts + 1;
        $message->update([
            'attempts' => $attempts,
            'error' => Str::limit($error, 250),
            'claimed_at' => null,
            'status' => $attempts >= self::MAX_ATTEMPTS ? 'FAILED' : 'PENDING',
        ]);
    }

    public static function macroSeenAt(): ?\Carbon\Carbon
    {
        $seen = SystemSetting::get('whatsapp_macro_seen_at');

        return $seen ? \Carbon\Carbon::parse($seen) : null;
    }
}
