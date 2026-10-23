<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ShipDeadline;
use App\Services\WhatsApp;
use Illuminate\Console\Command;

/**
 * Pengingat ke antrean WhatsApp: order yang sudah lewat batas kirim atau harus dikirim hari ini,
 * dikelompokkan per tahap. Dijadwalkan 2 jam sebelum jam potong; tidak mengirim apa-apa jika semua aman.
 */
class SendShipReminder extends Command
{
    protected $signature = 'wms:kirim-pengingat-kirim';

    protected $description = 'Kirim daftar order yang terlambat atau harus dikirim hari ini ke WhatsApp';

    private const MAX_LINES = 20;

    private const STAGES = [
        'PENDING' => 'Belum dikonfirmasi',
        'CONFIRMED' => 'Belum diambil',
        'PICKING' => 'Sedang diambil',
        'PACKING' => 'Belum/sedang dikemas',
        'READY_TO_SHIP' => 'Siap kirim, tunggu kurir',
    ];

    public function handle(): int
    {
        if (!WhatsApp::configured()) {
            $this->info('WhatsApp belum diatur (token makro atau penerima kosong), tidak ada yang diantrekan.');

            return self::SUCCESS;
        }

        $message = self::message();
        if ($message === null) {
            $this->info('Tidak ada order yang terlambat atau harus dikirim hari ini.');

            return self::SUCCESS;
        }

        if ($error = WhatsApp::send($message)) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info('Pengingat batas kirim masuk antrean WhatsApp.');

        return self::SUCCESS;
    }

    /** Jam kirim terjadwal: 2 jam sebelum jam potong (paling pagi jam 0) */
    public static function hour(): int
    {
        return max(0, ShipDeadline::cutoffHour() - 2);
    }

    /** Isi pesan, atau null jika tidak ada order yang terlambat atau jatuh tempo hari ini. */
    public static function message(): ?string
    {
        $orders = Order::whereIn('status', ShipDeadline::OPEN_STATUSES)
            ->where('ship_by', '<=', now()->endOfDay())
            ->orderBy('ship_by')
            ->get(['id', 'order_number', 'status', 'courier', 'ship_by', 'created_at']);
        if ($orders->isEmpty()) {
            return null;
        }

        $late = $orders->filter(fn ($o) => $o->ship_by->isPast())->count();
        $today = $orders->count() - $late;

        $lines = ['*Pengingat batas kirim* (' . now()->format('d/m/Y H:i') . ')'];
        $lines[] = collect([$late ? "{$late} order terlambat" : null, $today ? "{$today} order harus dikirim hari ini" : null])->filter()->implode(' · ');

        $shown = 0;
        foreach (self::STAGES as $status => $label) {
            $group = $orders->where('status', $status);
            if ($group->isEmpty()) {
                continue;
            }
            $lines[] = '';
            $lines[] = "*{$label}: {$group->count()}*";
            foreach ($group as $order) {
                if ($shown >= self::MAX_LINES) {
                    break;
                }
                $mark = $order->ship_by->isPast() ? '⚠ terlambat' : 'hari ini';
                $lines[] = "• {$order->order_number}" . ($order->courier ? " ({$order->courier})" : '') . " — {$mark}";
                $shown++;
            }
        }
        if ($orders->count() > $shown) {
            $lines[] = '…dan ' . ($orders->count() - $shown) . ' order lainnya.';
        }
        $lines[] = '';
        $lines[] = 'Buka tugas: ' . route('gudang.tugas');

        return implode("\n", $lines);
    }
}
