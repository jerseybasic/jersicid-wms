<?php

use Illuminate\Support\Facades\Schedule;

// Backup database harian mulai 01:30 (butuh cron: * * * * * cd /path && php artisan schedule:run). Hasilnya di
// storage/app/private. Dicek tiap 10 menit supaya menyusul bila jamnya terlewat atau gagal; sekali berhasil per hari.
// wms:backup-harian menjalankan backup:clean lalu backup:run --only-db.
Schedule::command('wms:backup-harian')->everyTenMinutes()->withoutOverlapping(60);

// Kiriman WhatsApp terjadwal (stok menipis 08:00, pengingat batas kirim 2 jam sebelum jam potong, laporan kiriman
// harian di jam Pengaturan). Dicek tiap menit, masing-masing sekali per hari. Agen WhatsApp yang tersambung juga
// memicu pengecekan yang sama, jadi tetap jalan walau cron belum dipasang.
Schedule::command('wms:jadwal-whatsapp')->everyMinute()->withoutOverlapping(5);

// Order Dikirim lebih dari N hari (Pengaturan, bawaan 3) otomatis Selesai. Dicek tiap jam; agen WhatsApp yang
// tersambung juga memicunya (paling sering sekali per jam) sebagai cadangan bila cron belum dipasang.
Schedule::command('wms:selesaikan-order')->hourlyAt(5)->withoutOverlapping(30);
