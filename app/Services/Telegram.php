<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim pesan ke Telegram lewat bot milik toko. Token bot disimpan terenkripsi di pengaturan.
 */
class Telegram
{
    public const TOKEN_PATTERN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/';
    public const CHAT_PATTERN = '/^(-?\d{3,}|@[A-Za-z0-9_]{5,})$/';

    public static function token(): ?string
    {
        $stored = SystemSetting::get('telegram_bot_token');
        if (!$stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function chatId(): ?string
    {
        return SystemSetting::get('telegram_chat_id') ?: null;
    }

    public static function saveToken(?string $token): void
    {
        SystemSetting::set('telegram_bot_token', $token ? Crypt::encryptString($token) : '', 'NOTIFICATION', 'Token bot Telegram (terenkripsi)');
    }

    public static function saveChatId(?string $chatId): void
    {
        SystemSetting::set('telegram_chat_id', $chatId ?? '', 'NOTIFICATION', 'Chat ID Telegram penerima notifikasi');
    }

    public static function configured(): bool
    {
        return self::token() !== null && self::chatId() !== null;
    }

    /**
     * Kirim pesan (format HTML Telegram). Mengembalikan pesan error, atau null jika berhasil.
     */
    public static function send(string $html): ?string
    {
        if (!self::configured()) {
            return 'Token bot atau chat ID Telegram belum diisi.';
        }

        $result = self::call('sendMessage', [
            'chat_id' => self::chatId(),
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        return $result['error'];
    }

    /**
     * Chat ID dari pesan terakhir yang diterima bot (setelah pengguna mengirim /start ke bot).
     */
    public static function latestChat(): array
    {
        if (self::token() === null) {
            return ['chat' => null, 'error' => 'Simpan token bot dulu.'];
        }

        $result = self::call('getUpdates', ['limit' => 20]);
        if ($result['error']) {
            return ['chat' => null, 'error' => $result['error']];
        }

        $chat = collect($result['data'])->reverse()
            ->map(fn ($update) => data_get($update, 'message.chat') ?? data_get($update, 'my_chat_member.chat') ?? data_get($update, 'channel_post.chat'))
            ->filter()
            ->first();

        return $chat
            ? ['chat' => ['id' => (string) $chat['id'], 'name' => $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))], 'error' => null]
            : ['chat' => null, 'error' => 'Bot belum menerima pesan. Kirim /start ke bot di Telegram, lalu coba lagi.'];
    }

    private static function call(string $method, array $payload): array
    {
        try {
            $response = Http::timeout(15)->asForm()->post('https://api.telegram.org/bot' . self::token() . '/' . $method, $payload);
        } catch (\Throwable $e) {
            Log::warning('Telegram tidak bisa dihubungi', ['method' => $method, 'error' => $e->getMessage()]);

            return ['data' => null, 'error' => 'Telegram tidak bisa dihubungi. Periksa koneksi internet server.'];
        }

        if (!$response->successful() || !$response->json('ok')) {
            $reason = $response->json('description') ?? ('HTTP ' . $response->status());
            Log::warning('Telegram menolak permintaan', ['method' => $method, 'reason' => $reason]);

            return ['data' => null, 'error' => 'Telegram menolak: ' . $reason];
        }

        return ['data' => $response->json('result'), 'error' => null];
    }
}
