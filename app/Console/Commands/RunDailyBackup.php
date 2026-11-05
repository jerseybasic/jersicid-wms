<?php

namespace App\Console\Commands;

use App\Services\DailyBackup;
use Illuminate\Console\Command;

/**
 * Dijalankan cron `schedule:run`: backup database sekali per hari mulai jam 01:30, dan menyusul
 * jika jam itu terlewat (server mati, cron baru dipasang) atau backup gagal.
 */
class RunDailyBackup extends Command
{
    protected $signature = 'wms:backup-harian';

    protected $description = 'Backup database harian jika sudah waktunya dan belum berhasil hari ini';

    public function handle(): int
    {
        $result = DailyBackup::runDue('cron');
        $this->line($result ?? 'Belum waktunya, atau backup hari ini sudah berhasil.');

        return $result !== null && str_starts_with($result, 'Gagal') ? self::FAILURE : self::SUCCESS;
    }
}
