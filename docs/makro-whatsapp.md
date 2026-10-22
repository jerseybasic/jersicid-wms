# Notifikasi WhatsApp lewat makro toko

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

## API antrean (untuk referensi)

Setiap permintaan wajib memakai header `Authorization: Bearer <token>`.

| Permintaan | Keterangan |
|---|---|
| `GET /api/whatsapp/antrean?limit=10` | Mengambil sampai 10 pesan yang menunggu. Hasilnya: `{"messages": [{"id": 12, "to": "6281234567890", "message": "..."}]}` |
| `POST /api/whatsapp/antrean/{id}/terkirim` | Menandai pesan terkirim. |
| `POST /api/whatsapp/antrean/{id}/gagal` dengan `{"error": "alasan"}` | Menandai pesan gagal. Pesan diberikan lagi sampai 3 kali percobaan, lalu statusnya menjadi **Gagal** dan alasannya tampil di Pengaturan. |

- Nomor `to` sudah dalam format `62…`.
- Pesan yang sudah diambil tidak diberikan lagi. Kecuali jika dalam 10 menit belum dilaporkan terkirim atau gagal, pesan itu diberikan lagi.
- Batas permintaan adalah 120 kali per menit.

## Perlu diketahui

- Mengotomatiskan WhatsApp Web bukan cara resmi WhatsApp. Ada risiko nomor dibatasi jika dipakai berlebihan. Untuk jangka panjang, pakai nomor khusus toko.
- Untuk ganti nomor pengirim (misalnya dari nomor pribadi ke nomor kantor), tambahkan akun baru di menu makro lalu scan QR dengan nomor itu. Setelah itu jalankan `./wa-login.sh --wms --setup` dan pilih akun baru tersebut. Tidak ada yang perlu diubah di WMS.
