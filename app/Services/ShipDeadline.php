<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Batas kirim order. Order yang masuk sebelum jam potong harus dikirim hari itu,
 * sesudahnya besok; ditambah jumlah hari tenggang dari Pengaturan.
 */
class ShipDeadline
{
    public const LATE = 'late';
    public const TODAY = 'today';
    public const TOMORROW = 'tomorrow';
    public const LATER = 'later';

    /** Status order yang masih harus dikirim */
    public const OPEN_STATUSES = ['PENDING', 'CONFIRMED', 'PICKING', 'PACKING', 'READY_TO_SHIP'];

    private const MONTHS = [
        'Januari' => 'January', 'Februari' => 'February', 'Maret' => 'March', 'Mei' => 'May', 'Juni' => 'June',
        'Juli' => 'July', 'Agustus' => 'August', 'Oktober' => 'October', 'Desember' => 'December',
        'Agu ' => 'Aug ', 'Agt ' => 'Aug ', 'Okt ' => 'Oct ', 'Des ' => 'Dec ',
    ];

    public static function cutoffHour(): int
    {
        return min(23, max(0, (int) SystemSetting::get('ship_cutoff_hour', 15)));
    }

    public static function graceDays(): int
    {
        return min(14, max(0, (int) SystemSetting::get('ship_grace_days', 0)));
    }

    /** Batas kirim (akhir hari) untuk order yang masuk pada waktu ini */
    public static function forOrderedAt(CarbonInterface $orderedAt): Carbon
    {
        $day = Carbon::instance($orderedAt)->setTimezone(config('app.timezone'))->startOfDay();
        if ($orderedAt->copy()->setTimezone(config('app.timezone'))->hour >= self::cutoffHour()) {
            $day->addDay();
        }

        return $day->addDays(self::graceDays())->endOfDay();
    }

    /** Batas kirim order: kolom ship_by, atau dihitung dari waktu order dibuat */
    public static function of(Order $order): Carbon
    {
        return $order->ship_by ? Carbon::instance($order->ship_by) : self::forOrderedAt($order->created_at ?? now());
    }

    /** late / today / tomorrow / later */
    public static function status(Order $order): string
    {
        $deadline = self::of($order);

        return match (true) {
            $deadline->isPast() => self::LATE,
            $deadline->isToday() => self::TODAY,
            $deadline->isTomorrow() => self::TOMORROW,
            default => self::LATER,
        };
    }

    /**
     * Waktu dari file marketplace: teks tanggal ("2026-10-23 14:05") atau angka tanggal Excel.
     */
    public static function parse(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value, config('app.timezone')));
            }

            // Ekspor BigSeller memakai nama bulan Indonesia ("19 Okt 2026 00:34")
            $text = str_ireplace(array_keys(self::MONTHS), array_values(self::MONTHS), (string) $value);

            return Carbon::parse($text, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
