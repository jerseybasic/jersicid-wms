<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sekali jalan setelah zona waktu aplikasi diganti dari UTC ke WIB:
 * waktu lama di database tersimpan dalam UTC, jadi dimajukan 7 jam agar tampil benar.
 */
class ShiftLegacyTimestamps extends Command
{
    protected $signature = 'wms:geser-waktu-lama
        {--jam=7 : Selisih jam dari UTC ke zona waktu baru}
        {--sebelum= : Hanya geser waktu sebelum titik ini (format Y-m-d H:i:s, bawaan: sekarang)}
        {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Majukan waktu lama (tersimpan dalam UTC) ke WIB. Cukup dijalankan sekali.';

    /** Tabel milik framework yang tidak perlu digeser */
    private const SKIP_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const DONE_KEY = 'timestamps_shifted_to_local';

    public function handle(): int
    {
        if (SystemSetting::get(self::DONE_KEY)) {
            $this->warn('Waktu lama sudah pernah digeser pada ' . SystemSetting::get(self::DONE_KEY) . '. Tidak ada yang diubah.');

            return self::SUCCESS;
        }

        $hours = (int) $this->option('jam');
        $before = $this->option('sebelum') ?: now()->format('Y-m-d H:i:s');

        if (!$this->option('force') && !$this->confirm("Semua waktu sebelum {$before} akan dimajukan {$hours} jam. Sudah backup database?")) {
            return self::FAILURE;
        }

        $driver = DB::connection()->getDriverName();
        $total = 0;

        DB::transaction(function () use ($hours, $before, $driver, &$total) {
            foreach (Schema::getTables() as $table) {
                $name = $table['name'];
                if (in_array($name, self::SKIP_TABLES)) {
                    continue;
                }

                foreach (Schema::getColumns($name) as $column) {
                    if (!in_array(strtolower($column['type_name']), ['timestamp', 'datetime'])) {
                        continue;
                    }

                    $col = $column['name'];
                    $expr = $driver === 'sqlite'
                        ? "datetime(\"{$col}\", '+{$hours} hours')"
                        : "DATE_ADD(`{$col}`, INTERVAL {$hours} HOUR)";

                    $total += DB::table($name)->whereNotNull($col)->where($col, '<', $before)
                        ->update([$col => DB::raw($expr)]);
                }
            }

            SystemSetting::set(self::DONE_KEY, now()->format('Y-m-d H:i:s'), 'SYSTEM');
        });

        $this->info("Selesai: {$total} nilai waktu dimajukan {$hours} jam.");

        return self::SUCCESS;
    }
}
