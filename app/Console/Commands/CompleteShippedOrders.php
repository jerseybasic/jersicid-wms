<?php

namespace App\Console\Commands;

use App\Services\OrderAutoComplete;
use Illuminate\Console\Command;

/**
 * Order Dikirim yang sudah lebih dari N hari (Pengaturan, bawaan 3) sejak dikirim otomatis menjadi Selesai.
 * Dijadwalkan tiap jam lewat `schedule:run`; agen WhatsApp yang tersambung juga memicunya sebagai cadangan.
 */
class CompleteShippedOrders extends Command
{
    protected $signature = 'wms:selesaikan-order';

    protected $description = 'Ubah order Dikirim yang sudah lewat batas hari di Pengaturan menjadi Selesai';

    public function handle(): int
    {
        if (!OrderAutoComplete::enabled()) {
            $this->info('Selesai otomatis dimatikan di Pengaturan (0 hari).');

            return self::SUCCESS;
        }

        $count = OrderAutoComplete::run('cron');
        $this->info("{$count} order Dikirim lebih dari " . OrderAutoComplete::days() . ' hari diubah menjadi Selesai.');

        return self::SUCCESS;
    }
}
