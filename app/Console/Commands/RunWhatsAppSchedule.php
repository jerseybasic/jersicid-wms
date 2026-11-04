<?php

namespace App\Console\Commands;

use App\Services\WhatsAppSchedule;
use Illuminate\Console\Command;

/**
 * Dijalankan cron `schedule:run` tiap menit: kirim laporan harian, stok menipis, dan pengingat batas kirim
 * yang sudah waktunya. Masing-masing sekali per hari; agen WhatsApp juga memicu pengecekan yang sama.
 */
class RunWhatsAppSchedule extends Command
{
    protected $signature = 'wms:jadwal-whatsapp';

    protected $description = 'Jalankan kiriman WhatsApp terjadwal yang sudah waktunya (sekali per hari per jadwal)';

    public function handle(): int
    {
        $results = WhatsAppSchedule::runDue('cron');
        if ($results === []) {
            $this->info('Belum ada jadwal WhatsApp yang waktunya, atau semuanya sudah jalan hari ini.');
        }
        foreach ($results as $job => $result) {
            $this->line(WhatsAppSchedule::JOBS[$job] . ': ' . $result);
        }

        return self::SUCCESS;
    }
}
