<?php

namespace App\Services;

use App\Models\CourierHandover;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan kiriman harian ke WhatsApp: paket yang dikirim hari ini per ekspedisi (dipickup kurir
 * atau drop sendiri), paket siap kirim yang belum diserahkan, order yang masih diproses, dan yang tertunda.
 *
 * Pengiriman memakai antrean WhatsApp yang sama dengan notifikasi lain (diambil makro toko).
 * Penerima boleh nomor pribadi (08xx/628xx) atau nama grup WhatsApp persis seperti di aplikasi;
 * untuk grup, akun pengirim di makro harus menjadi anggota grup itu.
 */
class DailyShipmentReport
{
    public const TOPIC = 'LAPORAN_HARIAN';
    public const DEFAULT_TIME = '17:00';
    public const MAX_TARGETS = 10;

    private const LAST_SENT_KEY = 'daily_report_last_date';

    // ---- Pengaturan --------------------------------------------------------

    public static function enabled(): bool
    {
        return SystemSetting::get('daily_report_enabled') === '1';
    }

    /** Jam kirim HH:MM */
    public static function time(): string
    {
        $time = (string) SystemSetting::get('daily_report_time', self::DEFAULT_TIME);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::DEFAULT_TIME;
    }

    /** Penerima apa adanya (satu per baris), untuk ditampilkan di Pengaturan */
    public static function rawTargets(): string
    {
        return (string) SystemSetting::get('daily_report_targets', '');
    }

    /**
     * Penerima siap kirim: nomor dinormalisasi ke 62xx, nama grup dibiarkan apa adanya.
     *
     * @return array<int, array{to: string, type: string}>
     */
    public static function targets(?string $raw = null): array
    {
        $targets = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw ?? self::rawTargets()) as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));
            if ($line === '') {
                continue;
            }
            $targets[] = self::isPhone($line)
                ? ['to' => WhatsApp::normalize($line), 'type' => 'nomor']
                : ['to' => $line, 'type' => 'grup'];
        }

        return collect($targets)->unique('to')->values()->all();
    }

    /** Nomor HP: hanya angka, spasi, tanda + atau -, panjang 9–15 digit */
    public static function isPhone(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);

        return (bool) preg_match('/^\+?[\d\s-]+$/', trim($value)) && strlen($digits) >= 9 && strlen($digits) <= 15;
    }

    public static function save(bool $enabled, string $time, ?string $targets): void
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', (string) $targets))->map(fn ($l) => trim($l))->filter()->implode("\n");

        SystemSetting::set('daily_report_enabled', $enabled ? '1' : '0', 'NOTIFICATION', 'Laporan kiriman harian ke WhatsApp aktif');
        SystemSetting::set('daily_report_time', $time, 'NOTIFICATION', 'Jam kirim laporan kiriman harian (HH:MM)');
        SystemSetting::set('daily_report_targets', $lines, 'NOTIFICATION', 'Penerima laporan kiriman harian: nomor atau nama grup WhatsApp, satu per baris');
    }

    /** Sudah waktunya kirim laporan hari ini dan belum terkirim (dicek tiap menit oleh scheduler) */
    public static function due(?Carbon $now = null): bool
    {
        $now ??= now();

        return self::enabled()
            && WhatsApp::hasToken()
            && self::targets() !== []
            && $now->format('H:i') >= self::time()
            && SystemSetting::get(self::LAST_SENT_KEY) !== $now->toDateString();
    }

    // ---- Kirim -------------------------------------------------------------

    /**
     * Taruh laporan di antrean WhatsApp untuk semua penerima laporan.
     * Mengembalikan pesan error, atau null jika berhasil.
     */
    public static function queue(?Carbon $date = null, bool $markSent = true): ?string
    {
        if (!WhatsApp::hasToken()) {
            return 'Token makro WhatsApp belum dibuat. Buat token di Pengaturan → Notifikasi WhatsApp.';
        }
        $targets = self::targets();
        if ($targets === []) {
            return 'Belum ada penerima laporan. Isi nomor atau nama grup WhatsApp di Pengaturan → Laporan Kiriman Harian.';
        }

        $date ??= today();
        $body = self::message($date);
        foreach ($targets as $target) {
            WhatsAppMessage::create(['recipient' => $target['to'], 'topic' => self::TOPIC, 'body' => $body]);
        }
        if ($markSent) {
            SystemSetting::set(self::LAST_SENT_KEY, $date->toDateString(), 'NOTIFICATION', 'Tanggal terakhir laporan kiriman harian diantrekan');
        }

        return null;
    }

    /** Riwayat pengiriman laporan (terbaru dulu) */
    public static function log(int $limit = 10): Collection
    {
        return WhatsAppMessage::where('topic', self::TOPIC)->latest('id')->limit($limit)->get();
    }

    // ---- Isi laporan -------------------------------------------------------

    /**
     * Angka laporan untuk satu tanggal.
     *
     * @return array{shipped: Collection, pickup: int, drop: int, unscanned: int, ready: Collection, picking: int, packing: int, pending: int, waitingStock: int, late: int}
     */
    public static function data(Carbon $date): array
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        // Paket yang diserahkan lewat serah terima, per ekspedisi dan cara serah terima
        $handovers = CourierHandover::where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$from, $to])
            ->withCount('items')
            ->get(['id', 'courier', 'method', 'drop_point']);

        $shipped = $handovers->groupBy('courier')->map(function ($group, $courier) {
            $drop = $group->where('method', CourierHandover::DROP);

            return [
                'courier' => $courier,
                'total' => (int) $group->sum('items_count'),
                'pickup' => (int) $group->where('method', '!=', CourierHandover::DROP)->sum('items_count'),
                'drop' => (int) $drop->sum('items_count'),
                'drop_points' => $drop->pluck('drop_point')->filter()->unique()->values()->all(),
            ];
        })->filter(fn ($row) => $row['total'] > 0)->sortKeys();

        // Dikirim hari itu tanpa scan serah terima (misalnya diubah manual)
        $unscanned = Order::whereIn('status', Order::SENT_STATUSES)
            ->whereBetween('shipped_at', [$from, $to])
            ->whereDoesntHave('handoverItem')
            ->count();

        // Kondisi sekarang (bukan per tanggal): yang masih menunggu di gudang
        $ready = Order::where('status', 'READY_TO_SHIP')->get(['id', 'courier'])
            ->countBy(fn ($order) => CourierHandover::brand($order->courier))->sortKeys();

        $open = Order::whereIn('status', ShipDeadline::OPEN_STATUSES);

        return [
            'shipped' => $shipped,
            'pickup' => (int) $shipped->sum('pickup'),
            'drop' => (int) $shipped->sum('drop'),
            'unscanned' => $unscanned,
            'ready' => $ready,
            'picking' => Order::whereIn('status', ['CONFIRMED', 'PICKING'])->count(),
            'packing' => Order::where('status', 'PACKING')->count(),
            'pending' => Order::where('status', 'PENDING')->whereNull('waiting_stock_at')->count(),
            'waitingStock' => Order::where('status', 'PENDING')->whereNotNull('waiting_stock_at')->count(),
            'late' => (clone $open)->whereNotNull('ship_by')->where('ship_by', '<', now())->count(),
        ];
    }

    /** Isi pesan WhatsApp (format *tebal*) */
    public static function message(?Carbon $date = null): string
    {
        $date ??= today();
        $d = self::data($date);
        $company = SystemSetting::get('company_name', config('app.name'));
        $totalShipped = (int) $d['shipped']->sum('total') + $d['unscanned'];

        $lines = ["*Laporan kiriman harian {$company}*"];
        $lines[] = $date->locale('id')->translatedFormat('l, d/m/Y') . ' · dibuat ' . now()->format('H:i');

        $lines[] = '';
        $lines[] = "*Dikirim: {$totalShipped} paket*";
        if ($totalShipped === 0) {
            $lines[] = 'Belum ada paket yang diserahkan.';
        }
        foreach ($d['shipped'] as $row) {
            $how = collect([
                $row['pickup'] ? "dipickup {$row['pickup']}" : null,
                $row['drop'] ? "drop sendiri {$row['drop']}" . ($row['drop_points'] ? ' di ' . implode(', ', $row['drop_points']) : '') : null,
            ])->filter()->implode(' · ');
            $lines[] = "• {$row['courier']}: {$row['total']} ({$how})";
        }
        if ($d['unscanned']) {
            $lines[] = "• Tanpa scan serah terima: {$d['unscanned']}";
        }
        if ($d['shipped']->isNotEmpty()) {
            $lines[] = "Dipickup kurir: {$d['pickup']} · Drop sendiri: {$d['drop']}";
        }

        $readyTotal = (int) $d['ready']->sum();
        $lines[] = '';
        $lines[] = "*Siap kirim, belum diserahkan: {$readyTotal}*";
        foreach ($d['ready'] as $courier => $count) {
            $lines[] = "• {$courier}: {$count}";
        }
        if ($readyTotal) {
            $lines[] = 'Kalau kurir tidak menjemput, pilih "Drop sendiri" di Serah Terima Kurir.';
        }

        $lines[] = '';
        $lines[] = '*Masih diproses: ' . ($d['picking'] + $d['packing']) . "* (picking {$d['picking']} · packing {$d['packing']})";
        $lines[] = '*Tertunda: ' . ($d['pending'] + $d['waitingStock']) . "* (belum dikonfirmasi {$d['pending']} · menunggu stok {$d['waitingStock']})";
        if ($d['late']) {
            $lines[] = "⚠ Lewat batas kirim: {$d['late']} order";
        }

        $lines[] = '';
        $lines[] = 'Detail: ' . route('gudang.handover');

        return implode("\n", $lines);
    }
}
