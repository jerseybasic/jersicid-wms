<?php

namespace App\Console\Commands;

use App\Services\DailyShipmentReport;
use Illuminate\Console\Command;

/**
 * Laporan kiriman harian ke antrean WhatsApp. Scheduler mengecek tiap menit dan menjalankannya
 * sekali sehari setelah jam kirim di Pengaturan. --paksa mengirim sekarang walau sudah terkirim hari ini.
 */
class SendDailyShipmentReport extends Command
{
    protected $signature = 'wms:kirim-laporan-kiriman {--paksa : Kirim sekarang walau belum jamnya atau sudah terkirim hari ini}';

    protected $description = 'Kirim laporan kiriman harian ke WhatsApp (nomor pribadi atau grup)';

    public function handle(): int
    {
        if (!$this->option('paksa') && !DailyShipmentReport::due()) {
            $this->info('Laporan kiriman harian tidak aktif, belum jamnya, atau sudah terkirim hari ini.');

            return self::SUCCESS;
        }

        if ($error = DailyShipmentReport::queue()) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info('Laporan kiriman harian masuk antrean WhatsApp untuk ' . count(DailyShipmentReport::targets()) . ' penerima.');

        return self::SUCCESS;
    }
}
