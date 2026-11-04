# Agen WhatsApp ringan untuk JERSIC.ID WMS

Agen ini mengirim notifikasi WMS ke WhatsApp: stok menipis, pengingat kirim, status PO, dan **laporan kiriman harian** ke nomor pribadi atau grup.

Bedanya dengan makro `whatsapp-macro/`: agen ini **tidak membuka browser**. Agen tersambung langsung ke WhatsApp sebagai *perangkat tertaut* (seperti WhatsApp Web), jadi memorinya sekitar 100–150 MB. Makro Playwright memerlukan Chromium/Firefox yang bisa memakan ratusan MB sampai lebih dari 1 GB.

Semua diatur dari WMS di **Pengaturan → Notifikasi WhatsApp → Koneksi WhatsApp**:
- QR untuk scan tampil di WMS, jadi tidak perlu membuka terminal untuk login;
- status tersambung, nomor pengirim, jumlah grup, dan memori agen;
- tombol **Putuskan / ganti nomor**, **Muat ulang grup**, dan **Sambung ulang**;
- grup yang diikuti nomor pengirim bisa langsung dipilih sebagai penerima Laporan Kiriman Harian.

## Syarat

- Node.js **20 atau lebih baru** (`node -v`). Jika masih versi lama, pasang dari https://nodejs.org.
- Komputer/server yang bisa membuka alamat WMS. Bisa server WMS itu sendiri.

## Memasang (sekali saja)

1. Di WMS, buka **Pengaturan → Notifikasi WhatsApp**. Klik **Buat token** dan salin tokennya.
2. Di folder `whatsapp-agent`, jalankan:
   ```bash
   ./start.sh
   ```
   (Windows: `start.bat`.) Pertama kali, dependensi dipasang otomatis, lalu Anda diminta mengisi alamat WMS dan token.
3. Buka lagi **Pengaturan → Notifikasi WhatsApp** di WMS. Dalam beberapa detik QR muncul di kotak **Koneksi WhatsApp**.
4. Di HP nomor pengirim, buka WhatsApp → **Perangkat tertaut** → **Tautkan perangkat**, lalu scan QR itu. Statusnya berubah menjadi **Tersambung**.

Sesi login disimpan di folder `sesi-whatsapp/`, jadi agen langsung tersambung lagi setelah komputer menyala ulang tanpa perlu scan QR lagi.

## Jalan otomatis di latar belakang (Linux)

Supaya agen tetap jalan tanpa terminal terbuka dan otomatis menyala bersama komputer:

```bash
./start.sh --pasang-otomatis
```

- Cek status: `systemctl --user status jersic-wa-agent`
- Lihat log: `journalctl --user -u jersic-wa-agent -f`
- Hentikan dan hapus: `./start.sh --hapus-otomatis`

Setelah ini, semuanya dikendalikan dari WMS.

## Mengubah pengaturan

- Ganti alamat WMS atau token: `./start.sh --setup`, lalu jalankan ulang agen. Jika memakai jalan otomatis: `systemctl --user restart jersic-wa-agent`.
- Ganti nomor pengirim: klik **Putuskan / ganti nomor** di WMS, lalu scan QR baru dengan nomor lain.
- Opsi lewat env: `WMS_URL`, `WMS_TOKEN`, `WMS_POLL` (detik antar cek antrean, bawaan 15), `WMS_JEDA` (detik antar pesan, bawaan 4).

## Mengirim ke grup

Tulis nama grup persis seperti di WhatsApp di daftar penerima, atau pilih dari daftar grup di Pengaturan. Nomor pengirim harus menjadi anggota grup itu. Jika grup tidak ketemu, pesan berstatus **Gagal** dengan alasannya.

## Perlu diketahui

- Seperti makro WhatsApp Web, cara ini bukan API resmi WhatsApp. Pakai nomor khusus toko dan jangan untuk kirim massal ke pelanggan.
- Jalankan **satu** pengirim saja untuk antrean WMS: agen ini **atau** Mode WMS di makro lama. Kalau keduanya jalan, pesan tidak dobel karena WMS membagi antrean, tetapi dikirim dari dua nomor.
- Makro `whatsapp-macro/` tetap bisa dipakai untuk kirim manual ke banyak pelanggan dari menu terminal.
- Tes logika agen (tanpa WhatsApp sungguhan): `npm test`.
