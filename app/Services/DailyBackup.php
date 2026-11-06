<?php

namespace App\Services;

use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Backup database harian lewat cron `schedule:run`.
 *
 * Dicek beberapa kali per jam, bukan hanya tepat jam 01:30: kalau server mati atau cron belum jalan pada jamnya,
 * backup hari itu menyusul pada pengecekan berikutnya. Tiap hari hanya sekali (tanggal terakhir disimpan di
 * Pengaturan, pengecekan dikunci). Jika gagal, dicoba lagi pada pengecekan berikutnya sampai MAX_ATTEMPTS per hari,
 * dan anggota penerima notifikasi WhatsApp diberi tahu sekali.
 */
class DailyBackup
{
    public const TIME = '01:30';
    public const MAX_ATTEMPTS = 3;

    private const LOG_KEY = 'backup_log';
    private const LOCK = 'daily-backup';

    /** Jalankan backup jika sudah waktunya dan belum berhasil hari ini. Mengembalikan hasil, atau null jika tidak dijalankan. */
    public static function runDue(string $trigger = 'cron', ?Carbon $now = null): ?string
    {
        $lock = Cache::lock(self::LOCK, 1800);
        if (!$lock->get()) {
            return null; // backup lain sedang berjalan
        }

        try {
            app()->forgetInstance(SystemSetting::MEMO);
            $now ??= now();
            if (!self::due($now)) {
                return null;
            }

            return self::run($now, $trigger);
        } finally {
            $lock->release();
        }
    }

    /** Sudah lewat jam backup, belum berhasil hari ini, dan belum habis jatah percobaan */
    public static function due(?Carbon $now = null): bool
    {
        $now ??= now();
        if ($now->format('H:i') < self::TIME) {
            return false;
        }
        $last = self::log();
        if (($last['date'] ?? null) !== $now->toDateString()) {
            return true;
        }

        return !($last['ok'] ?? false) && ($last['attempts'] ?? 0) < self::MAX_ATTEMPTS;
    }

    private static function run(Carbon $now, string $trigger): string
    {
        $ok = false;
        try {
            // Rapikan backup lama dulu supaya ruang disk cukup; kegagalannya tidak membatalkan backup
            Artisan::call('backup:clean', ['--disable-notifications' => true]);
            $code = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
            $ok = $code === 0;
            $result = $ok ? 'Berhasil' : 'Gagal: ' . self::failureReason(Artisan::output());
        } catch (\Throwable $e) {
            report($e);
            $result = 'Gagal: ' . $e->getMessage();
        }

        $previous = self::log();
        $attempts = ($previous['date'] ?? null) === $now->toDateString() ? ($previous['attempts'] ?? 0) + 1 : 1;
        $newest = $ok ? self::newest() : null;
        if ($newest) {
            $result .= ' (' . self::humanSize($newest['size']) . ')';
        }
        SystemSetting::set(self::LOG_KEY, json_encode([
            'date' => $now->toDateString(),
            'at' => $now->toDateTimeString(),
            'ok' => $ok,
            'attempts' => $attempts,
            'result' => Str::limit($result, 250),
            'trigger' => $trigger,
        ]), 'SYSTEM', 'Hasil terakhir backup database harian');

        // Beri tahu sekali per hari, pada kegagalan pertama
        if (!$ok && $attempts === 1 && WhatsApp::configured()) {
            WhatsApp::send("*Backup database gagal* ({$now->format('d/m/Y H:i')})\n" . Str::limit($result, 300)
                . "\nSistem mencoba lagi otomatis. Jika tetap gagal, cek ruang disk dan mysqldump di server.");
        }

        return $result;
    }

    /** @return array{date?: string, at?: string, ok?: bool, attempts?: int, result?: string, trigger?: string} */
    public static function log(): array
    {
        return json_decode((string) SystemSetting::get(self::LOG_KEY, ''), true) ?: [];
    }

    /** File backup terbaru di disk backup pertama: ['path', 'at' (Carbon), 'size' (byte)] */
    public static function newest(): ?array
    {
        try {
            $disk = Storage::disk(config('backup.backup.destination.disks.0', 'local'));
            $newest = collect($disk->files(config('backup.backup.name')))
                ->filter(fn ($path) => str_ends_with($path, '.zip'))
                ->map(fn ($path) => ['path' => $path, 'time' => $disk->lastModified($path)])
                ->sortByDesc('time')->first();
            if (!$newest) {
                return null;
            }

            return [
                'path' => $newest['path'],
                'at' => Carbon::createFromTimestamp($newest['time'])->setTimezone(config('app.timezone')),
                'size' => $disk->size($newest['path']),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
    }

    /** Ringkasan untuk halaman Pengaturan */
    public static function overview(): array
    {
        $log = self::log();
        $newest = self::newest();

        return [
            'time' => self::TIME,
            'last_at' => isset($log['at']) ? Carbon::parse($log['at']) : null,
            'result' => $log['result'] ?? null,
            'trigger' => $log['trigger'] ?? null,
            'failed' => isset($log['ok']) && !$log['ok'],
            'newest_at' => $newest['at'] ?? null,
            'newest_size' => $newest ? self::humanSize($newest['size']) : null,
            // Backup terbaru lebih tua dari 2 hari: kemungkinan cron tidak jalan
            'stale' => !$newest || $newest['at']->lt(now()->subDays(2)),
        ];
    }

    /** Ambil alasan gagal dari keluaran backup:run, tanpa stack trace */
    private static function failureReason(string $output): string
    {
        $reason = preg_match('/failed because: (.+)/', $output, $m) ? trim($m[1]) : null;
        // Pesan error dari mysqldump/sqlite3 ada di bawah judul "Error Output"
        if (preg_match('/Error Output\s*\n=+\s*\n\s*(.+)/', $output, $m)) {
            $detail = trim($m[1]);
            $reason = $reason ? $reason . ' (' . $detail . ')' : $detail;
        }
        if ($reason) {
            return $reason;
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($line) => strlen($line) > 2));

        return $lines ? end($lines) : 'tidak ada keterangan';
    }
}
