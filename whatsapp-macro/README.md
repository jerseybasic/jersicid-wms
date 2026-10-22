# WA Terminal — menu WhatsApp Web di terminal

Menjalankan WhatsApp Web di **Chromium/Chrome atau Firefox headless** (tanpa jendela). QR login tampil di
terminal, semua diatur lewat menu angka. Mendukung **banyak tujuan** (nama & nomor) dan
**banyak akun WhatsApp**.

## Mode WMS (notifikasi JERSIC.ID WMS)
Makro ini juga mengirim notifikasi dari WMS, misalnya daftar stok menipis setiap pukul 08:00.
WMS menaruh pesan di antrean. Mode WMS mengambil antrean itu tiap menit, mengirimnya lewat akun
yang dipilih, lalu melapor ke WMS (terkirim atau gagal).

1. Di WMS: isi **No. WhatsApp** anggota di **Kelola User** dan centang **Kirim notifikasi WhatsApp**.
   Lalu buka **Pengaturan → Notifikasi WhatsApp → Buat token** dan salin tokennya.
2. Jalankan sekali untuk mengisi pengaturan (alamat WMS, token, akun pengirim):
   ```bash
   ./wa-login.sh --wms --setup
   ```
   Bisa juga lewat menu akun: ketik **w**. Pengaturan disimpan di `~/.wa-terminal/wms.json`.
3. Berikutnya cukup `./wa-login.sh --wms` (Windows: `wa-login.bat --wms`). Biarkan terminal terbuka.
   Ctrl+C untuk berhenti.

Keterangan:
- Akun pengirim harus sudah login (scan QR). Jika belum, QR muncul di terminal saat Mode WMS mulai.
- Status antrean (menunggu, terkirim, gagal) dan kapan makro terakhir mengambil antrean bisa
  dilihat di halaman Pengaturan WMS.
- Nomor yang gagal dicoba sampai 3 kali. Alasan gagalnya tampil di WMS.
- Pesan yang sudah diambil tetapi belum dilaporkan dalam 10 menit (misalnya komputer mati) diberikan
  lagi oleh WMS. Jadi tidak ada pesan yang hilang.
- Jika Mode WMS dan menu kirim manual dipakai bersamaan, jalankan keduanya dengan akun berbeda
  (satu akun hanya bisa dibuka di satu terminal).
- Untuk berjalan otomatis di server Linux tanpa layar (setelah login pertama dengan scan QR),
  pengaturan bisa diberikan lewat env lalu dijalankan dengan pm2:
  ```bash
  WMS_URL=https://wms-anda WMS_TOKEN=wa_xxx WMS_AKUN=toko1 pm2 start bot/bot.js --name wa-wms -- --wms
  ```
  Opsi lain: `WMS_POLL` (detik antar cek, bawaan 60) dan `WMS_JEDA` (detik antar pesan, bawaan 8).

## Syarat
- Node.js 18+ — Linux Mint: `sudo apt install nodejs npm`
- Chromium/Google Chrome **atau Firefox**, dicari otomatis. Jika keduanya ada, yang dipakai Chromium/Chrome.
  - Paksa Firefox: `./wa-login.sh --firefox` (atau env `WA_BROWSER=firefox`). Paksa Chrome: `--chrome`.
  - Path manual: `CHROME_PATH=/usr/bin/google-chrome` atau `FIREFOX_PATH=/usr/bin/firefox`.
  - Login Chrome dan Firefox terpisah, jadi saat pertama memakai Firefox perlu scan QR sekali lagi.
    Akun yang sudah login di Firefox otomatis tetap memakai Firefox.
  - Firefox versi snap (Ubuntu) didukung; profilnya disimpan di `~/snap/firefox/common/wa-terminal/`.

## Jalankan
```bash
chmod +x wa-login.sh
./wa-login.sh
```
Windows: klik dua kali `wa-login.bat`. Untuk Mode WMS: `./wa-login.sh --wms` (lihat di atas). Belum login → QR muncul, scan dari
WhatsApp → **Perangkat tertaut** → **Tautkan perangkat**.

## Alur menu
```
 Akun  ──▶  1/3 Pesan  ──▶  2/3 Tujuan  ──▶  3/3 Kirim
            (template /     (grup kirim)     (interval, count,
             custom chat)                     jeda, mulai)
```
`0` di setiap layar kembali ke layar sebelumnya.

### Akun
```
   1. toko1          2 grup kirim · 37 penerima  ● aktif
   2. pribadi        belum ada grup kirim
   t. Tambah akun baru   h. Hapus akun   l. Logout akun aktif   0. Keluar
```
Setiap akun punya sesi login dan grup kirim sendiri. Akun baru langsung menampilkan QR
(Ctrl+C membatalkan login).

### 1/3 Pesan — template atau custom chat
8 template siap pakai untuk toko online: sapaan, konfirmasi pesanan, info resi, pengingat
pembayaran, promo, restock, terima kasih & ulasan, follow-up.

- Pilih nomor template → lihat isinya → **Enter** pakai / **e** ubah teks.
- **c** = custom chat (tulis sendiri), bisa langsung disimpan sebagai template ★.
- **t** buat template baru, **h** hapus template ★ milikmu. Template berlaku untuk semua akun.
- Variabel:
  - `{nama}` otomatis nama penerima (atau "Kak" jika hanya nomor)
  - `{toko}`, `{produk}`, `{promo}`, ... ditanyakan sekali; isian terakhir diingat
  - kosongkan (`-`) jika diambil dari **kolom file penerima** (mis. `{resi}` beda tiap orang)
  - `{date}` tanggal, `{time}` jam, `\n` baris baru

### 2/3 Tujuan — grup kirim
**Grup kirim** = daftar penerima yang disimpan (mis. "Pelanggan Bandung", "Reseller"),
bukan grup WhatsApp. Pesan dikirim satu per satu ke setiap penerima.
```
   1. [x] Pelanggan Bandung      25 penerima
   2. [ ] Reseller               12 penerima
   Dipilih: 1 grup · 25 penerima
   b. Buat grup kirim baru   e. Edit grup   h. Hapus grup
   s. Kirim satuan (ketik nomor tanpa membuat grup)
   l. Lanjut ▶   0. Kembali ke pesan
```
Ketik nomor grup untuk centang/lepas (bisa `1,2`). Isi grup: ketik nomor, tambah kontak
tersimpan (nama di WhatsApp), atau impor file .txt/.csv.

### 3/3 Kirim
```
   Pesan    : Info resi pengiriman
   Tujuan   : Pelanggan Bandung (25 penerima)
   Contoh untuk Siti (6285711112222):
     │ Halo Siti, pesanan kamu sudah dikirim 📦 ...
   1. Interval  2. Count  3. Jeda  4. Data kosong: lewati
   5. ▶ Mulai kirim   9. Ganti pesan   0. Kembali ke tujuan
```
- Satu putaran = kirim ke semua penerima dengan **Jeda** di antaranya. **Count** `0` = terus.
- **Data kosong**: penerima yang variabelnya kosong (mis. belum ada resi) dilewati — bisa diubah.
- **Ctrl+C** saat mengirim menghentikan dan kembali ke menu.
- Penerima gagal dicatat ke `~/.wa-terminal/accounts/<akun>/gagal-<tanggal>.csv`
  (bisa diimpor ulang ke grup kirim).

## File penerima (.csv / .txt)
Header boleh ada; kolom nomor dikenali dari nama `nomor`, `no hp`, `wa`, `phone`, dst.
Kolom selain nama & nomor otomatis jadi variabel pesan (lihat `contoh-penerima.csv`):
```
nama,nomor,resi,produk
Siti,085711112222,JNT777,Kaos Jersey
Rina,082133334444,JNE123,Topi
```
→ pesan `No. resi: {resi} untuk {produk}` terisi berbeda untuk setiap penerima.
Tanpa header juga bisa: satu nomor per baris (`contoh-nomor.txt`).

## Beberapa akun bersamaan
Buka terminal lain, jalankan `./wa-login.sh`, pilih akun berbeda. Akun yang sedang dipakai
ditandai "sedang dipakai terminal lain" dan tidak bisa dibuka dua kali.

## Catatan
- **Risiko blokir:** WhatsApp bisa membatasi akun yang mengirim pesan otomatis dalam jumlah
  besar atau ke orang yang tidak menyimpan nomormu. Pakai untuk pelanggan/kontak yang memang
  berhubungan denganmu, jaga jeda & interval wajar.
- Data versi sebelumnya otomatis dipindahkan ke grup kirim "Tujuan lama", tidak perlu scan ulang.
- Jika bot tidak menemukan kolom pesan/pencarian (WhatsApp mengubah tampilan), jalankan
  `./wa-login.sh --gui` untuk melihat browsernya.
- Mode lama (Chrome biasa + ekstensi, hanya QR): `./wa-login.sh --browser`
