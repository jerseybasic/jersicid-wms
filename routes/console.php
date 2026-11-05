<?php

use Illuminate\Support\Facades\Schedule;

// Backup database harian (butuh cron: * * * * * php /path/artisan schedule:run). Hasilnya di storage/app/private.
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run --only-db')->dailyAt('01:30');

// Kiriman WhatsApp terjadwal (stok menipis 08:00, pengingat batas kirim 2 jam sebelum jam potong, laporan kiriman
// harian di jam Pengaturan). Dicek tiap menit, masing-masing sekali per hari. Agen WhatsApp yang tersambung juga
// memicu pengecekan yang sama, jadi tetap jalan walau cron belum dipasang.
Schedule::command('wms:jadwal-whatsapp')->everyMinute()->withoutOverlapping(5);

// Order Dikirim lebih dari N hari (Pengaturan, bawaan 3) otomatis Selesai. Dicek tiap jam; agen WhatsApp yang
// tersambung juga memicunya (paling sering sekali per jam) sebagai cadangan bila cron belum dipasang.
Schedule::command('wms:selesaikan-order')->hourlyAt(5)->withoutOverlapping(30);
