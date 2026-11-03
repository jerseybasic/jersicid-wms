# Uji beban (k6)

Skrip `k6-wms.js` meniru pemakaian harian gudang:

| Peran | Porsi VU | Yang dibuka |
|---|---|---|
| Staf gudang | 70% | Tugas Saya, Picking, daftar Outbound (halaman acak), detail order, Cek barang (scan), Pencarian |
| Admin gudang | 20% | Dashboard gudang, Outbound, filter Terlambat, Inbound, Peta rak, Serah terima, dan 50% kemungkinan membuat 1 order baru |
| Owner | 10% | Dashboard owner, laporan Kinerja, Stok, Ketepatan kirim, Master SKU, Audit trail |

Setiap user diam 1-3 detik di antara halaman, jadi "50 VU" kira-kira sama dengan 50 orang yang sedang aktif memakai aplikasi pada waktu yang sama.

## Jangan jalankan di server production

Uji beban membuat order baru (kecuali `WRITE=0`) dan membebani server. Pakai server staging dengan spesifikasi yang sama, atau salinan database.

## Langkah

1. Pasang k6: https://grafana.com/docs/k6/latest/set-up/install-k6/
2. Di server staging, buat akun dan data uji (20.000 order kira-kira 3 bulan pemakaian):

   ```bash
   LOADTEST_ORDERS=20000 php artisan db:seed --class=LoadTestSeeder
   php artisan optimize
   ```

   Akun yang dibuat: `loadtest-owner@jersic.test`, `loadtest-admin@jersic.test`, `loadtest-staf1..5@jersic.test`, password `password`.

3. Jalankan dari komputer lain (supaya k6 tidak ikut memakai CPU server), naikkan jumlah user bertahap:

   ```bash
   for v in 10 25 50 100 150 200; do
     k6 run -e BASE_URL=https://staging.jersic.id -e VUS=$v -e DURATION=2m -e INSECURE=0 tests/load/k6-wms.js
   done
   ```

   Login dibatasi 10x/menit per IP, jadi skrip login sekali per akun lalu sesinya dipakai bersama. Kalau login ditolak karena batas itu, skrip menunggu lalu mencoba lagi.

4. Baca hasilnya:
   - **Gagal** harus di bawah 1%.
   - **p95** (95% halaman selesai dalam waktu ini) idealnya di bawah 1,5 detik.
   - Tingkat VU tertinggi yang masih memenuhi keduanya adalah kapasitas server untuk pola pemakaian ini.

Variabel lain: `WRITE=0` (tanpa membuat order), `DEBUG=1` (cetak request yang gagal), `SUMMARY_JSON=hasil.json` (simpan ringkasan lengkap).

## Hapus data uji

```sql
DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.order_number LIKE 'LT-%' OR o.customer_name LIKE 'Uji Beban%';
DELETE FROM orders WHERE order_number LIKE 'LT-%' OR customer_name LIKE 'Uji Beban%';
DELETE FROM stock_movements WHERE reference_no LIKE 'LT-%';
DELETE FROM stock_actives WHERE sku LIKE 'LT-%';
DELETE FROM master_skus WHERE sku LIKE 'LT-%';
DELETE FROM users WHERE email LIKE 'loadtest-%@jersic.test';
```
