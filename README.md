# JERSIC.ID WMS

Sistem gudang JERSIC.ID: stok per lokasi rak, purchase order ke supplier, barang masuk, order keluar (picking, packing, kirim), retur, transfer, stock opname, cetak label, dan laporan.

Panduan pemakaian untuk tiap role ada di [docs/panduan-per-role.md](docs/panduan-per-role.md).

## Teknologi

- Laravel 12, PHP 8.3
- MySQL (produksi), SQLite (tes otomatis)
- Blade, Alpine.js, Tailwind CSS (dibangun dengan Vite)
- maatwebsite/excel untuk import dan export Excel

## Pasang pertama kali

```bash
git clone https://github.com/jerseybasic/jersicid-wms.git
cd jersicid-wms
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Isi `.env` minimal:

```
APP_NAME="JERSIC.ID WMS"
APP_URL=https://alamat-wms-anda
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=jersicid_wms
DB_USERNAME=...
DB_PASSWORD=...
```

Untuk server produksi tambahkan juga `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, dan `LOG_LEVEL=warning`.

Lalu siapkan database dan tampilan:

```bash
php artisan migrate --seed
npm run build
php artisan optimize
```

`--seed` membuat akun owner pertama (`owner@jersic.id`), gudang, denah rak, dan pengaturan awal. Kata sandi awal ada di `database/seeders/OwnerUserSeeder.php`. **Segera ganti setelah login pertama.** User lain dibuat owner dari menu **Kelola User**.

## Update ke versi baru

```bash
git pull
composer install --no-dev
php artisan migrate
npm run build
php artisan optimize:clear
php artisan optimize
```

`npm run build` wajib dijalankan setiap kali ada perubahan tampilan, karena folder `public/build` tidak ikut di Git.

## Backup otomatis

Database dibackup setiap hari pukul 01:30 WIB ke `storage/app/private`. Backup yang lama dirapikan otomatis: semua disimpan 7 hari, lalu satu per hari selama 16 hari. Agar jadwal ini berjalan, pasang satu baris cron di server (butuh `mysqldump`):

```
* * * * * cd /path/ke/jersicid-wms && php artisan schedule:run >> /dev/null 2>&1
```

Untuk mencoba backup secara manual, jalankan `php artisan backup:run --only-db`. Salin file backup ke tempat lain secara berkala, misalnya ke Google Drive.

## Zona waktu

Aplikasi memakai WIB (`Asia/Jakarta`). Data yang dibuat sebelum versi ini tersimpan dalam UTC. Jalankan sekali `php artisan wms:geser-waktu-lama` setelah backup supaya data lama ikut tampil dalam WIB.

## Tes otomatis

```bash
php artisan test
```

Tes memakai database SQLite di memori, jadi tidak menyentuh data asli. GitHub Actions menjalankan tes yang sama di setiap pull request (`.github/workflows`).

## Role

| Role | Untuk siapa | Akses utama |
|---|---|---|
| OWNER | Pemilik | Semua menu, termasuk Kelola User, Pengaturan, dan menghapus PO |
| SUPERVISOR | Kepala gudang | Semua operasional, Master SKU, Supplier, membuat dan menyetujui PO, Laporan, Riwayat Aktivitas |
| ADMIN_GUDANG | Admin gudang | Semua operasional gudang, melihat PO dan mengisi tahap produksi |
| STAFF_GUDANG | Staf gudang | Barang masuk, order keluar, picking, packing, retur, transfer, opname, cek barang, cetak label |

## Alur utama

- **Order:** PENDING → CONFIRMED → PICKING → PACKING → READY_TO_SHIP → SHIPPED → COMPLETED. Status picking dan packing berubah otomatis dari sesi picking dan packing.
- **Purchase order:** DRAFT → PENDING → APPROVED → IN_PRODUCTION → READY_FOR_PICKUP → RECEIVED. Status RECEIVED terisi otomatis saat barangnya diterima lewat Inbound. PO yang menunggu persetujuan bisa ditolak kembali ke DRAFT dengan alasan.
- **Stok:** tersimpan per SKU per lokasi rak (`stock_actives`). Setiap perubahan stok dicatat di `stock_movements` (INBOUND, OUTBOUND, TRANSFER, ADJUSTMENT, RETURN).

## Struktur kode

| Folder | Isi |
|---|---|
| `app/Http/Controllers/Warehouse` | Operasional gudang: inbound, outbound, picking, packing, retur, transfer, opname, PO, label, printer |
| `app/Http/Controllers/Admin` | Dashboard, master SKU, supplier, user, laporan, pengaturan |
| `app/Services` | Logika bersama: daftar tugas (`WorkQueue`), stok menipis (`LowStock`), printer |
| `resources/views/warehouse`, `resources/views/reports` | Tampilan |
| `tests/Feature` | Tes alur utama |
