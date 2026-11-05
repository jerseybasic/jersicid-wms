<?php

namespace App\Services;

use App\Console\Commands\SendLowStockAlert;
use App\Console\Commands\SendShipReminder;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Semua kiriman WhatsApp terjadwal: laporan kiriman harian, stok menipis, dan pengingat batas kirim.
 *
 * Jadwal bisa dipicu dari dua jalur, mana saja yang hidup:
 *  1. cron `schedule:run` di server (tiap menit), dan
 *  2. agen WhatsApp yang sedang tersambung (melapor ke WMS tiap ~20 detik) atau makro yang mengambil antrean.
 * Jalur kedua membuat laporan tetap terkirim tepat waktu walau cron belum dipasang di hosting.
 *
 * Tiap jadwal hanya jalan sekali per hari: tanggal terakhirnya disimpan di Pengaturan dan
 * pengecekannya dikunci (Cache lock), jadi cron dan agen yang memicu bersamaan tidak membuat kiriman ganda.
 */
class WhatsAppSchedule
{
    public const JOBS = [
        'daily_report' => 'Laporan kiriman harian',
        'low_stock' => 'Stok menipis',
        'ship_reminder' => 'Pengingat batas kirim',
    ];

    public const LOW_STOCK_TIME = '08:00';

    /** Agen/makro memicu pengecekan paling sering sekali per sekian detik */
    public const TICK_SECONDS = 30;

    private const LOG_KEY = 'whatsapp_schedule_log';
    private const CRON_SEEN_KEY = 'whatsapp_schedule_cron_seen_at';
    private const LOCK = 'whatsapp-schedule';

    /**
     * Jalankan jadwal yang sudah waktunya. $trigger: cron, agen, atau makro.
     *
     * @return array<string, string> job => hasil, hanya untuk jadwal yang dijalankan
     */
    public static function runDue(string $trigger = 'cron', ?Carbon $now = null): array
    {
        if ($trigger === 'cron') {
            SystemSetting::set(self::CRON_SEEN_KEY, now()->toDateTimeString(), 'NOTIFICATION', 'Terakhir cron schedule:run memicu jadwal WhatsApp');
        }

        $lock = Cache::lock(self::LOCK, 120);
        if (!$lock->get()) {
            return []; // pemicu lain sedang menjalankan jadwal yang sama
        }

        try {
            // Baca ulang pengaturan dari database: pemicu lain mungkin baru saja menandai terkirim
            app()->forgetInstance(SystemSetting::MEMO);
            $now ??= now();
            $results = [];
            foreach (array_keys(self::JOBS) as $job) {
                if (!self::due($job, $now)) {
                    continue;
                }
                try {
                    $results[$job] = self::run($job, $now);
                } catch (\Throwable $e) {
                    report($e);
                    $results[$job] = 'Gagal: ' . $e->getMessage();
                }
                self::record($job, $now, $results[$job], $trigger);
            }

            return $results;
        } finally {
            $lock->release();
        }
    }

    /**
     * Dipanggil dari API agen/makro. Dibatasi sekali per TICK_SECONDS dan tidak pernah menggagalkan request.
     */
    public static function tick(string $trigger): void
    {
        try {
            if (Cache::add('whatsapp-schedule-tick', 1, self::TICK_SECONDS)) {
                self::runDue($trigger);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Cadangan bila cron belum dipasang: order Dikirim yang sudah lewat batas hari jadi Selesai
        OrderAutoComplete::tick($trigger);
    }

    /** Sudah waktunya dan belum jalan hari ini */
    public static function due(string $job, ?Carbon $now = null): bool
    {
        $now ??= now();
        if (self::lastDate($job) === $now->toDateString()) {
            return false;
        }

        return match ($job) {
            'daily_report' => DailyShipmentReport::due($now),
            'low_stock' => WhatsApp::configured() && $now->format('H:i') >= self::LOW_STOCK_TIME,
            // Pengingat hanya berguna sebelum jam potong lewat
            'ship_reminder' => WhatsApp::configured()
                && $now->hour >= SendShipReminder::hour()
                && $now->hour <= max(SendShipReminder::hour(), ShipDeadline::cutoffHour()),
            default => false,
        };
    }

    /** Jam kirim tiap jadwal, untuk ditampilkan */
    public static function time(string $job): string
    {
        return match ($job) {
            'daily_report' => DailyShipmentReport::time(),
            'low_stock' => self::LOW_STOCK_TIME,
            'ship_reminder' => sprintf('%02d:00', SendShipReminder::hour()),
        };
    }

    private static function run(string $job, Carbon $now): string
    {
        switch ($job) {
            case 'daily_report':
                $error = DailyShipmentReport::queue($now->copy());

                return $error ? 'Gagal: ' . $error : 'Masuk antrean untuk ' . count(DailyShipmentReport::targets()) . ' penerima';
            case 'low_stock':
                $message = SendLowStockAlert::message();
                if ($message === null) {
                    return 'Tidak ada stok menipis, tidak ada yang dikirim';
                }

                return self::sendToMembers($message);
            case 'ship_reminder':
                $message = SendShipReminder::message();
                if ($message === null) {
                    return 'Tidak ada order terlambat atau jatuh tempo hari ini';
                }

                return self::sendToMembers($message);
        }

        return 'Jadwal tidak dikenal';
    }

    private static function sendToMembers(string $message): string
    {
        $error = WhatsApp::send($message);

        return $error ? 'Gagal: ' . $error : 'Masuk antrean untuk ' . count(WhatsApp::recipients()) . ' penerima';
    }

    // ---- Riwayat -----------------------------------------------------------

    /** @return array<string, array{date: string, at: string, result: string, trigger: string}> */
    public static function log(): array
    {
        return json_decode((string) SystemSetting::get(self::LOG_KEY, ''), true) ?: [];
    }

    private static function lastDate(string $job): ?string
    {
        // Laporan harian juga punya tandanya sendiri di DailyShipmentReport::due (dipakai sejak versi sebelumnya)
        return self::log()[$job]['date'] ?? null;
    }

    private static function record(string $job, Carbon $now, string $result, string $trigger): void
    {
        $log = self::log();
        $log[$job] = ['date' => $now->toDateString(), 'at' => $now->toDateTimeString(), 'result' => $result, 'trigger' => $trigger];
        SystemSetting::set(self::LOG_KEY, json_encode($log), 'NOTIFICATION', 'Hasil terakhir jadwal WhatsApp otomatis');
    }

    public static function cronSeenAt(): ?Carbon
    {
        $seen = SystemSetting::get(self::CRON_SEEN_KEY);

        return $seen ? Carbon::parse($seen) : null;
    }

    /** Ringkasan untuk halaman Pengaturan */
    public static function overview(): array
    {
        $log = self::log();
        $jobs = [];
        foreach (self::JOBS as $job => $label) {
            $last = $log[$job] ?? null;
            $jobs[] = [
                'label' => $label,
                'time' => self::time($job),
                'last_at' => $last ? Carbon::parse($last['at']) : null,
                'result' => $last['result'] ?? null,
                'trigger' => $last['trigger'] ?? null,
                'failed' => $last && str_starts_with($last['result'], 'Gagal'),
            ];
        }
        $cron = self::cronSeenAt();

        return [
            'jobs' => $jobs,
            'cron_seen_at' => $cron,
            'cron_active' => $cron !== null && $cron->gt(now()->subMinutes(5)),
        ];
    }
}
