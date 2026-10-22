# Panduan singkat per role

Panduan ini berisi pekerjaan harian tiap role di JERSIC.ID WMS. Semua layar bisa dipakai di HP. Kotak scan menerima scanner barcode, kamera HP, atau ketikan manual.

Di semua role, ikon **lonceng** di kanan atas menunjukkan pekerjaan yang sedang menunggu. Isinya sama dengan kartu **Tugas Hari Ini** di dashboard. Menu **Tugas Saya** berisi daftar kerja ringkas untuk HP.

---

## STAFF_GUDANG

### Menerima barang (Inbound)
1. Buka **Inbound**, lalu klik **Terima Barang Baru**.
2. Jika barangnya dari PO, pilih PO-nya agar daftar barang terisi otomatis.
3. Scan lokasi rak, lalu scan barangnya, kemudian simpan.
4. Cetak label SKU dari tombol **Cetak** jika barang belum berlabel.

### Order keluar (Outbound)
1. Buka **Outbound**, lalu klik **Buat Order Baru** untuk order yang masuk.
2. Periksa order berstatus baru, lalu klik **Konfirmasi** agar order masuk ke daftar picking. Jika stok yang belum dipesan order lain tidak cukup, konfirmasi ditolak dan muncul SKU yang kurang.
3. Setelah order siap kirim, klik **Kirim** dan isi nomor resi.
4. Untuk mencetak semua label sekaligus, klik **Cetak N Label Siap Kirim** di Outbound.

### Import pesanan marketplace (BigSeller)
1. Di BigSeller, ekspor pesanan per SKU ke Excel.
2. Buka **Outbound**, klik **Import BigSeller**, upload filenya, lalu klik **Lihat Pratinjau**.
3. Periksa daftarnya. Pesanan yang SKU-nya belum ada di Master SKU, sudah pernah diimpor, atau dibatalkan akan dilewati, lengkap dengan alasannya.
4. Klik **Import**. Jika **Langsung konfirmasi** dicentang, pesanan langsung masuk ke daftar picking. Nomor resi marketplace ikut tersimpan.

### Mengambil barang untuk order (Picking)
1. Buka **Tugas Saya** atau **Picking**, lalu pilih order yang perlu diambil.
2. Mulai sesi, pergi ke rak yang disarankan, scan rak, lalu scan setiap barang.
3. Setelah semua barang terambil, order otomatis pindah ke packing.

### Mengemas (Packing)
1. Buka **Packing**, lalu pilih order yang perlu dikemas.
2. Mulai sesi, lalu scan setiap barang yang masuk ke paket.
3. Setelah semua barang lengkap, order otomatis berstatus siap kirim. Cetak label kirim lewat **Cetak label kirim**.

### Retur dari pelanggan
1. Buka **Retur**, lalu klik **Terima Retur**.
2. Scan QR di label kirim atau ketik nomor order/resi.
3. Isi jumlah barang yang masih bagus dan lokasi raknya. Barang yang rusak cukup dicatat.
4. Barang yang bagus otomatis masuk lagi ke stok.

### Memindahkan stok (Transfer Stok)
Buka **Transfer Stok**, isi SKU, rak asal, rak tujuan, dan jumlahnya, lalu simpan. Pergerakannya tercatat di riwayat stok.

### Stock opname
1. Buka **Stock Opname**, lalu klik **Buat Opname**. Pilih rak yang mau dihitung. Jika tidak ada rak yang dipilih, yang dihitung seluruh gudang.
2. Klik **Mulai Opname**. Scan lokasi rak, lalu scan barangnya satu per satu.
3. Jika ada barang yang tidak ada di daftar, form **Tambah barang** otomatis terisi saat barangnya di-scan.
4. Klik **Simpan Sementara** sesering mungkin. Setelah semua lokasi dihitung, klik **Selesaikan & Adjust Stok**.

### Menata barang ke rak (Denah Gudang)
1. Buka **Denah Gudang**. Jika ada tulisan "stok belum punya lokasi rak", artinya stok hasil import atau form SKU masih tercatat di DEFAULT-01 atau GUDANG-UTAMA.
2. Klik rak tujuan, cari produknya, lalu masukkan ke keranjang.
3. Biarkan **Pindahkan stok yang belum punya rak** tercentang, isi lokasinya (misalnya A-01-01), lalu klik **Simpan**. Stoknya pindah ke rak itu dan tercatat sebagai transfer.

### Cek barang dan lokasi
Buka **Cek Barang & Lokasi**, lalu scan barcode barang untuk melihat stok di setiap rak, atau scan rak untuk melihat isinya.

---

## ADMIN_GUDANG

Semua pekerjaan STAFF_GUDANG, ditambah:

### Purchase order
1. Buka **Purchase Order** untuk melihat PO yang sudah disetujui.
2. Saat supplier mulai mengerjakan, klik **Mulai Produksi**.
3. Saat barang siap, klik **Siap Diambil**. Penerimaan barangnya dilakukan lewat Inbound.

---

## SUPERVISOR

Semua pekerjaan ADMIN_GUDANG, ditambah:

### Membuat dan menyetujui PO
1. Buka **Purchase Order**, lalu buat PO baru. Tombol **PO dari Stok Menipis** mengisi otomatis SKU yang stoknya di bawah batas.
2. Klik **Ajukan untuk Approval** jika PO sudah siap.
3. PO yang menunggu persetujuan bisa disetujui (**Approve PO**) atau dikembalikan (**Tolak**) dengan alasan. PO yang ditolak kembali menjadi draft dan bisa diedit.

### Laporan
Buka **Laporan** untuk melihat:
- Stok saat ini, pergerakan stok, dan daftar opname (semuanya bisa diekspor ke Excel)
- **Kinerja Picker & Packer**: order dan pcs per orang beserta rata-rata waktunya
- **Barang Lambat Laku**: SKU yang lama tidak keluar
- **Akurasi Opname**: persen hitungan yang cocok dan SKU yang sering selisih
- **Nilai Stok**: nilai jual, nilai modal (HPP), dan potensi laba per kategori dan per produk

### Riwayat Aktivitas
Berisi catatan siapa mengubah apa dan kapan. Gunakan untuk menelusuri selisih stok atau perubahan PO.

---

## OWNER

Semua menu, ditambah:

- **Master SKU dan Supplier**: tambah dan ubah data produk serta supplier. Import SKU massal tersedia di **Laporan → Import Master Data**.
- **Harga modal (HPP)**: isi di form Master SKU, atau lewat kolom `COST_PRICE` (boleh juga `HARGA_MODAL` atau `HPP`) di file import. Jika kolom itu tidak ada di file, HPP yang sudah tersimpan tidak berubah. HPP dipakai di laporan Nilai Stok dan menjadi harga awal saat membuat PO.
- **Kelola User**: buat akun untuk setiap karyawan dan pilih role-nya. Karyawan tidak bisa mendaftar sendiri.
- **Pengaturan** (menu di bawah nama Anda, kanan atas): nama, telepon, dan alamat perusahaan untuk label kirim, serta batas stok menipis umum. Batas khusus per SKU diisi di kolom stok minimum Master SKU.
- **Notifikasi WhatsApp**: daftar stok menipis dimasukkan ke antrean setiap pagi pukul 08:00, lalu dikirim ke WhatsApp anggota oleh makro toko (folder `whatsapp-macro`, Mode WMS).
  1. Di **Kelola User**, isi **No. WhatsApp** setiap anggota yang perlu menerima notifikasi, lalu centang **Kirim notifikasi WhatsApp**.
  2. Di **Pengaturan → Notifikasi WhatsApp**, klik **Buat token**, lalu salin tokennya ke makro. Lalu di komputer makro jalankan `./wa-login.sh --wms --setup` dan tempel tokennya. Detailnya ada di [makro-whatsapp.md](makro-whatsapp.md).
  3. Klik **Kirim Tes**. Status pesan (menunggu, terkirim, atau gagal) dan kapan makro terakhir mengambil antrean tampil di halaman yang sama.
- **Menghapus PO**: hanya owner yang bisa.
