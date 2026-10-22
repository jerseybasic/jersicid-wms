<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim pesan WhatsApp ke anggota yang mengaktifkan notifikasi di Kelola User (plus nomor tambahan di Pengaturan).
 *
 * Dua cara kirim:
 * - "server": pengirim sendiri di folder whatsapp-sender (Node.js + Playwright, login WhatsApp Web lewat QR).
 * - "fonnte": gateway fonnte.com dengan token perangkat.
 */
class WhatsApp
{
    public const DRIVERS = ['server' => 'Server sendiri (WhatsApp Web)', 'fonnte' => 'Fonnte'];
    public const FONNTE_URL = 'https://api.fonnte.com/send';
    public const TOKEN_PATTERN = '/^[A-Za-z0-9!@#$%^&*()_+\-=.~]{10,100}$/';
    /** Nomor tambahan (08xx / 628xx) dipisah koma */
    public const TARGET_PATTERN = '/^\s*(\+?\d{9,15})(\s*,\s*(\+?\d{9,15}))*\s*$/';

    public static function driver(): string
    {
        $driver = SystemSetting::get('whatsapp_driver', 'server');

        return array_key_exists($driver, self::DRIVERS) ? $driver : 'server';
    }

    public static function saveDriver(string $driver): void
    {
        SystemSetting::set('whatsapp_driver', $driver, 'NOTIFICATION', 'Cara kirim WhatsApp (server/fonnte)');
    }

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

    public static function saveToken(?string $token): void
    {
        SystemSetting::set('whatsapp_token', $token ? Crypt::encryptString($token) : '', 'NOTIFICATION', 'Token perangkat Fonnte (terenkripsi)');
    }

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

    public static function senderReady(): bool
    {
        return self::driver() === 'fonnte'
            ? self::token() !== null
            : filled(config('services.whatsapp_sender.url')) && filled(config('services.whatsapp_sender.key'));
    }

    public static function configured(): bool
    {
        return self::senderReady() && self::recipients() !== [];
    }

    /**
     * Kirim pesan ke semua penerima (format WhatsApp: *tebal*). Mengembalikan pesan error, atau null jika berhasil.
     */
    public static function send(string $message): ?string
    {
        if (!self::senderReady()) {
            return self::driver() === 'fonnte'
                ? 'Token Fonnte belum diisi.'
                : 'Pengirim WhatsApp belum diatur: isi WA_SENDER_URL dan WA_SENDER_KEY di file .env server.';
        }
        $recipients = self::recipients();
        if ($recipients === []) {
            return 'Belum ada penerima. Isi nomor WhatsApp anggota di Kelola User dan centang "Kirim notifikasi WhatsApp".';
        }

        return self::driver() === 'fonnte' ? self::sendFonnte($recipients, $message) : self::sendServer($recipients, $message);
    }

    /** Status pengirim sendiri: READY, QR (perlu scan), LOADING, atau OFFLINE. */
    public static function serverStatus(): array
    {
        $response = self::server('get', '/status', [], 10);

        return $response['error'] ? ['state' => 'OFFLINE', 'error' => $response['error']] : $response['data'] + ['error' => null];
    }

    /** Gambar QR login WhatsApp Web (PNG) dari pengirim sendiri, atau null. */
    public static function serverQr(): ?string
    {
        try {
            $response = Http::timeout(15)->withHeaders(['X-Api-Key' => (string) config('services.whatsapp_sender.key')])
                ->get(rtrim((string) config('services.whatsapp_sender.url'), '/') . '/qr');
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/') ? $response->body() : null;
    }

    /** Keluarkan nomor WhatsApp dari pengirim sendiri (untuk ganti nomor). */
    public static function serverLogout(): ?string
    {
        return self::server('post', '/logout', [], 30)['error'];
    }

    private static function sendServer(array $recipients, string $message): ?string
    {
        // Pengirim mengetik pesan satu per satu, jadi waktunya bertambah per nomor
        $response = self::server('post', '/send', ['to' => $recipients, 'message' => $message], 30 + 20 * count($recipients));
        if ($response['error']) {
            return $response['error'];
        }

        $failed = collect($response['data']['results'] ?? [])->reject(fn ($r) => $r['ok'] ?? false);
        if ($failed->isNotEmpty()) {
            return 'Gagal ke ' . $failed->map(fn ($r) => ($r['to'] ?? '?') . ' (' . ($r['error'] ?? 'tidak diketahui') . ')')->implode(', ');
        }

        return null;
    }

    private static function server(string $method, string $path, array $payload, int $timeout): array
    {
        $url = rtrim((string) config('services.whatsapp_sender.url'), '/') . $path;

        try {
            $request = Http::timeout($timeout)->acceptJson()->withHeaders(['X-Api-Key' => (string) config('services.whatsapp_sender.key')]);
            $response = $method === 'get' ? $request->get($url) : $request->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('Pengirim WhatsApp tidak bisa dihubungi', ['url' => $url, 'error' => $e->getMessage()]);

            return ['data' => null, 'error' => 'Pengirim WhatsApp tidak berjalan atau tidak bisa dihubungi. Jalankan whatsapp-sender di server.'];
        }

        if (!$response->successful() || $response->json('ok') === false) {
            $reason = $response->json('error') ?? ('HTTP ' . $response->status());
            Log::warning('Pengirim WhatsApp menolak', ['path' => $path, 'reason' => $reason]);

            return ['data' => null, 'error' => 'WhatsApp gagal: ' . $reason];
        }

        return ['data' => $response->json() ?? [], 'error' => null];
    }

    private static function sendFonnte(array $recipients, string $message): ?string
    {
        try {
            $response = Http::timeout(20)->asForm()
                ->withHeaders(['Authorization' => self::token()])
                ->post(self::FONNTE_URL, [
                    'target' => implode(',', $recipients),
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
