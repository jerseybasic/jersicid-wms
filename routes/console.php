<?php

use Illuminate\Support\Facades\Schedule;

// Backup database harian (butuh cron: * * * * * php /path/artisan schedule:run). Hasilnya di storage/app/private.
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run --only-db')->dailyAt('01:30');

// Ringkasan stok menipis ke WhatsApp setiap pagi (hanya jika WhatsApp sudah diatur di Pengaturan)
Schedule::command('wms:kirim-stok-menipis')->dailyAt('08:00');
