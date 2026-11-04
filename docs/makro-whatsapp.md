# Notifikasi WhatsApp lewat makro toko

> **Disarankan:** pakai [agen WhatsApp ringan](../whatsapp-agent/README.md) (folder `whatsapp-agent/`). Agen tidak membuka browser sehingga jauh lebih hemat memori, dan scan QR, status, serta putus/sambung nomor dilakukan dari WMS (Pengaturan → Notifikasi WhatsApp → Koneksi WhatsApp). Antrean dan API di bawah dipakai keduanya. Makro di bawah tetap bisa dipakai, tetapi jalankan hanya salah satu untuk antrean WMS.

WMS tidak mengirim WhatsApp sendiri. WMS menaruh pesan di **antrean**, satu pesan untuk setiap penerima. Makro WhatsApp toko di folder [`whatsapp-macro/`](../whatsapp-macro/README.md) mengambil antrean itu dalam **Mode WMS**, mengirim pesannya lewat WhatsApp Web, lalu melaporkan hasilnya ke WMS.

```
WMS (jadwal 08:00 / Kirim Tes) ──► antrean ──► makro: GET /api/whatsapp/antrean
                                                makro kirim di WhatsApp Web
                                   ◄────────── makro: POST .../terkirim atau .../gagal
```

Makro bisa berjalan di komputer mana pun yang bisa membuka alamat WMS, misalnya PC kantor atau server. Pesan tidak hilang saat makro mati, karena pesan akan terkirim begitu makro jalan lagi.

## Menyiapkan

1. Di **Kelola User**, isi **No. WhatsApp** setiap anggota yang perlu menerima notifikasi, lalu centang **Kirim notifikasi WhatsApp**.
2. Di **Pengaturan → Notifikasi WhatsApp**, klik **Buat token**. Token hanya ditampilkan sekali, jadi langsung salin.
3. Di folder `whatsapp-macro`, jalankan `./wa-login.sh --wms --setup` (Windows: `wa-login.bat --wms --setup`). Isi alamat WMS, token, dan akun pengirim. Jika akun belum login, scan QR yang muncul.
4. Klik **Kirim Tes** di Pengaturan WMS. Dalam sekitar satu menit, pesan akan terkirim dan statusnya berubah menjadi **Terkirim**.

Makro memakai Chromium/Chrome, atau Firefox jika Chrome tidak ada. Untuk memaksa Firefox, tambahkan `--firefox`, misalnya `./wa-login.sh --wms --setup --firefox`.

Untuk menjalankan berikutnya cukup `./wa-login.sh --wms`. Cara menjalankannya terus-menerus di server ada di README makro.

## Laporan kiriman harian (nomor pribadi atau grup)

Di **Pengaturan → Laporan Kiriman Harian**, centang **Kirim laporan otomatis setiap hari**, isi jam kirim (bawaan 17:00), lalu isi penerima satu per baris:

- nomor pribadi, misalnya `081234567890`;
- nama grup WhatsApp, persis seperti tertulis di aplikasi, misalnya `Gudang Jersic`.

Untuk grup, nomor pengirim yang dipakai makro harus sudah menjadi anggota grup itu. Makro membuka grup lewat kotak pencarian WhatsApp Web dan hanya mengirim jika namanya sama persis (huruf besar/kecil tidak berpengaruh). Jika tidak ketemu, pesan berstatus gagal dengan alasan "grup/kontak tidak ditemukan".

Tombol **Kirim uji sekarang** langsung mengantrekan laporan hari ini tanpa mengubah jadwal. Riwayatnya tampil di **Log pengiriman laporan**.

## Jadwal otomatis (tanpa klik)

Laporan kiriman harian (jam di Pengaturan), stok menipis (08:00), dan pengingat batas kirim (2 jam sebelum jam potong) terkirim sendiri pada jamnya, masing-masing **sekali sehari**. Pengecekannya dipicu dua jalur, mana saja yang hidup:

1. **Agen WhatsApp yang tersambung.** Agen melapor ke WMS tiap ~20 detik; selama statusnya tersambung, WMS sekalian menjalankan jadwal yang sudah waktunya. Jadi laporan tetap terkirim walau cron belum dipasang. Kalau agen baru tersambung setelah jamnya lewat (misal jam 17:10 untuk jadwal 16:50), laporan dikirim saat itu juga.
2. **Cron server** `* * * * * cd /path/ke/jersicid-wms && php artisan schedule:run >> /dev/null 2>&1` (sama dengan backup harian). Pesan masuk antrean dan terkirim begitu agen tersambung.

Keduanya boleh hidup bersamaan: tanggal terakhir tiap jadwal disimpan dan pengecekannya dikunci, jadi tidak ada kiriman ganda. Status dan hasil terakhir tiap jadwal tampil di **Pengaturan → Laporan Kiriman Harian → Jadwal otomatis WhatsApp**. Untuk menjalankan pengecekan manual: `php artisan wms:jadwal-whatsapp`.

## API antrean (untuk referensi)

Setiap permintaan wajib memakai header `Authorization: Bearer <token>`.

| Permintaan | Keterangan |
|---|---|
| `GET /api/whatsapp/antrean?limit=10` | Mengambil sampai 10 pesan yang menunggu. Hasilnya: `{"messages": [{"id": 12, "to": "6281234567890", "message": "..."}]}` |
| `POST /api/whatsapp/antrean/{id}/terkirim` | Menandai pesan terkirim. |
| `POST /api/whatsapp/agen` | Khusus agen ringan: melapor status (`state`, `qr`, `phone`, `groups`). Balasannya `{"command": ...}` berisi `null`, `"logout"`, `"restart"`, atau `"refresh_groups"`. |
| `POST /api/whatsapp/antrean/{id}/gagal` dengan `{"error": "alasan"}` | Menandai pesan gagal. Pesan diberikan lagi sampai 3 kali percobaan, lalu statusnya menjadi **Gagal** dan alasannya tampil di Pengaturan. |

- `to` berisi nomor dalam format `62…`, atau nama grup WhatsApp untuk laporan kiriman harian.
- Pesan yang sudah diambil tidak diberikan lagi. Kecuali jika dalam 10 menit belum dilaporkan terkirim atau gagal, pesan itu diberikan lagi.
- Batas permintaan adalah 120 kali per menit.

## Perlu diketahui

- Mengotomatiskan WhatsApp Web bukan cara resmi WhatsApp. Ada risiko nomor dibatasi jika dipakai berlebihan. Untuk jangka panjang, pakai nomor khusus toko.
- Untuk ganti nomor pengirim (misalnya dari nomor pribadi ke nomor kantor), tambahkan akun baru di menu makro lalu scan QR dengan nomor itu. Setelah itu jalankan `./wa-login.sh --wms --setup` dan pilih akun baru tersebut. Tidak ada yang perlu diubah di WMS.
