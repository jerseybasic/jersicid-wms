<?php

use Illuminate\Support\Facades\Schedule;

// Backup database harian (butuh cron: * * * * * php /path/artisan schedule:run). Hasilnya di storage/app/private.
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run --only-db')->dailyAt('01:30');
