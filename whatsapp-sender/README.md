# Pengirim WhatsApp (whatsapp-sender)

Program kecil Node.js yang membuka **WhatsApp Web** dengan Playwright di server, lalu mengirim notifikasi WMS ke nomor anggota. WMS memanggilnya lewat `http://127.0.0.1:3100`. Program ini hanya bisa diakses dari server itu sendiri, dan setiap permintaan wajib membawa kunci rahasia.

## Tentang login (cookies)

WhatsApp Web tidak menyimpan login hanya di cookies. Kunci enkripsinya ada di penyimpanan browser (IndexedDB), jadi menyalin cookies saja tidak cukup untuk login. Sebagai gantinya, program ini memakai **profil browser tetap** di folder `session/`:

- Login cukup sekali, dengan scan QR dari halaman **Pengaturan → Notifikasi WhatsApp** di WMS.
- Setelah itu login tersimpan di `session/`, termasuk cookies dan IndexedDB, dan tetap berlaku walaupun program atau server direstart.
- Untuk ganti nomor (misalnya dari nomor pribadi ke nomor kantor), klik **Ganti nomor pengirim** di Pengaturan, lalu scan QR dengan nomor baru.

Folder `session/` sama dengan akses penuh ke WhatsApp nomor itu. Jangan dibagikan atau di-commit, karena folder ini sudah ada di `.gitignore`.

## Pasang di server

Program ini butuh Node.js 20.6 atau lebih baru.

```bash
cd whatsapp-sender
npm install
npx playwright install --with-deps chromium
cp .env.example .env
```

Isi `.env`:

```
API_KEY=isi-dengan-kunci-acak-panjang
```

Kunci acak bisa dibuat dengan `openssl rand -hex 24`. Masukkan kunci yang **sama** ke `.env` WMS:

```
WA_SENDER_URL=http://127.0.0.1:3100
WA_SENDER_KEY=isi-dengan-kunci-acak-panjang
```

Lalu jalankan `php artisan config:clear` di folder WMS.

## Menjalankan terus-menerus

Program harus terus berjalan. Contoh dengan **pm2**:

```bash
npm install -g pm2
cd /path/ke/jersicid-wms/whatsapp-sender
pm2 start "npm start" --name wa-sender
pm2 save
pm2 startup   # ikuti perintah yang muncul agar ikut jalan saat server restart
```

Lihat log dengan `pm2 logs wa-sender`. Untuk restart, jalankan `pm2 restart wa-sender`.

## Mencoba di komputer sendiri

Isi `HEADLESS=false` di `.env`, lalu jalankan `npm start`. Jendela Chromium akan terbuka, dan proses scan QR serta pengiriman pesan bisa dilihat langsung.

## Hal yang perlu diketahui

- Cara ini memakai WhatsApp Web secara otomatis. Itu bukan cara resmi WhatsApp, jadi ada risiko nomor dibatasi atau diblokir jika dipakai berlebihan. Pakai **nomor khusus toko**. Program sudah memberi jeda antar pesan (`DELAY_MS`, bawaan 4 detik) dan hanya mengirim satu ringkasan per hari.
- Kalau tampilan WhatsApp Web berubah, pengiriman bisa gagal dan pesan error-nya tampil di Pengaturan WMS. Bagian yang perlu disesuaikan ada di `SEL` dalam `sender.js`.
- HP nomor pengirim tidak perlu selalu online. Namun jika WhatsApp di HP tidak dibuka lebih dari sekitar 14 hari, perangkat tertaut biasanya dikeluarkan dan QR perlu di-scan ulang.

## API (untuk referensi)

Semua permintaan memakai header `X-Api-Key`.

| Permintaan | Hasil |
|---|---|
| `GET /status` | `{ ok, state: READY \| QR \| LOADING, me }` |
| `GET /qr` | Gambar PNG QR login |
| `POST /send` `{ to: ["0812..."], message }` | `{ ok, results: [{ to, ok, error }] }` |
| `POST /logout` | Mengeluarkan nomor sekarang, lalu QR baru muncul |

Tes otomatis (memakai WhatsApp Web tiruan): `npm test`
