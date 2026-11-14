# Panduan singkat per role

Panduan ini berisi pekerjaan harian tiap role di JERSIC.ID WMS. Semua layar bisa dipakai di HP. Kotak scan menerima scanner barcode, kamera HP, atau ketikan manual.

Di semua role, ikon **lonceng** di kanan atas menunjukkan pekerjaan yang sedang menunggu. Isinya sama dengan kartu **Tugas Hari Ini** di dashboard. Menu **Tugas Saya** berisi daftar kerja ringkas untuk HP.

Setiap anggota bisa memasang **foto profil** sendiri: klik nama di kanan atas, pilih **Profil**, lalu **Pilih foto** (JPG, PNG, atau WEBP, maksimal 5 MB). Foto dipotong persegi otomatis dan tampil di header serta di Kelola User. Tanpa foto, yang tampil adalah inisial nama.

---

## STAFF_GUDANG

### Menerima barang (Inbound)
1. Buka **Inbound**, lalu klik **Terima Barang Baru**.
2. Jika barangnya dari PO, pilih PO-nya agar daftar barang terisi otomatis. Kolom **Sisa PO** berisi jumlah yang belum datang.
3. Scan lokasi rak, lalu scan barangnya, kemudian klik **Periksa & Proses Penerimaan**.
4. Muncul **Pengecekan Terakhir**: ringkasan surat jalan, supplier, PO, dan setiap baris SKU, produk, lokasi rak, qty diterima, dan reject. Cocokkan dengan barang fisik dan surat jalan. Baris merah (mis. lokasi rak kosong, melebihi sisa PO) harus dibetulkan dulu; baris kuning adalah peringatan (mis. rak tidak ada di denah, SKU biasanya disimpan di rak lain, baris qty 0 tidak ikut disimpan). Centang konfirmasinya, lalu klik **Ya, Simpan Penerimaan**. Penerimaan tidak bisa disimpan tanpa centang ini.
5. Cetak label SKU dari tombol **Cetak** jika barang belum berlabel.

Barang PO boleh datang bertahap. Contoh: PO 5 pcs, hari ini datang 2 pcs. Isi **Diterima** 2 dan simpan; stok bertambah 2 pcs dan PO menjadi **Diterima Sebagian** dengan sisa 3 pcs. Saat sisanya datang, buat penerimaan baru dengan nomor surat jalan yang baru dan pilih PO yang sama (atau klik **Terima Sisa Barang** di detail PO). Setelah semua lengkap, PO otomatis menjadi **Sudah Diterima**.
- Di bawah tiap barang tampil jumlah dipesan, yang sudah datang sebelumnya, dan sisa setelah penerimaan ini.
- Jumlah diterima tidak boleh melebihi sisa PO, dan barang yang tidak ada di PO ditolak. Jika supplier memang mengirim lebih, minta Owner/Supervisor menambah qty di PO dulu, atau terima barang lebihnya tanpa PO.

### Order keluar (Outbound)
1. Buka **Outbound**, lalu klik **Buat Order Baru** untuk order yang masuk.
2. Periksa order berstatus baru, lalu klik **Konfirmasi** agar order masuk ke daftar picking. Jika stok yang belum dipesan order lain tidak cukup, konfirmasi ditolak dan muncul SKU yang kurang.
3. Setelah order siap kirim, klik **Kirim** dan isi nomor resi.
4. Untuk mencetak semua label sekaligus, klik **Cetak N Label Siap Kirim** di Outbound.

### Import pesanan marketplace (BigSeller)
1. Di BigSeller, ekspor pesanan per SKU ke Excel.
2. Buka **Outbound**, klik **Import BigSeller**, upload filenya, lalu klik **Lihat Pratinjau**.
3. Periksa daftarnya. Pesanan yang SKU-nya belum ada di Master SKU, sudah pernah diimpor, atau dibatalkan akan dilewati, lengkap dengan alasannya.
   - Jika kode SKU di marketplace berbeda dengan Master SKU (misalnya `GOTHIC-BLK-M` di Shopee untuk `JER-GOTHIC-HITAM-M`), isi SKU gudangnya di kotak **SKU belum dikenal**, lalu klik **Simpan pemetaan dan periksa ulang**. Tidak perlu upload ulang, dan import berikutnya otomatis memakai SKU gudang yang benar. Bisa dilakukan oleh Owner, Supervisor, dan Admin Gudang.
   - Owner dan Supervisor bisa melihat, menambah, dan menghapus semua pemetaan di **Master Data → Pemetaan SKU**.
   - Jika produknya memang belum ada di gudang, Owner dan Supervisor bisa klik **Buat SKU baru** langsung di kotak itu. Formnya sudah terisi dari kode dan nama produk di file; setelah disimpan, kembali ke pratinjau yang sama. Jika kode SKU gudang dibuat berbeda, kode marketplace otomatis dipetakan.
   - Semua kode yang belum terdaftar tercatat di **Pemetaan SKU → SKU Terhambat** (juga untuk Admin Gudang), beserta jumlah pesanan yang tertahan. Di halaman yang sama ada daftar SKU aktif yang belum punya lokasi rak.
4. Klik **Import**. Jika **Langsung konfirmasi** dicentang, pesanan langsung masuk ke daftar picking. Nomor resi marketplace ikut tersimpan.

### Input resi yang sudah dikirim lewat BigSeller (Owner, Supervisor, Admin Gudang)
Dipakai jika pesanan sudah diproses dan dikirim di BigSeller tapi tidak lewat picking/packing di aplikasi (misalnya staf pengganti belum memakai aplikasinya), supaya stok di sistem sama dengan barang di rak.
1. Pastikan pesanannya sudah ada di aplikasi. Jika belum, import dulu file ekspor pesanannya lewat **Import BigSeller**.
2. Buka **Outbound**, klik **Input Resi BigSeller**, tempel daftar nomor resi (atau nomor pesanan), satu per baris, lalu klik **Cek Resi**.
3. Periksa daftarnya, lalu klik **Proses**:
   - Order Pending / Siap Picking: stok dipotong dari rak yang ada isinya, order jadi **Dikirim**.
   - Order Siap Kirim: cukup ditandai **Dikirim** (stoknya sudah dipotong saat picking).
   - Dilewati dengan alasannya: resi tidak ditemukan, order sudah dikirim (resi yang sama aman diinput dua kali), sedang picking/packing, dibatalkan, atau stok di sistem kurang (cek lewat stock opname).
4. Semua tercatat di Pergerakan Stok, Riwayat Aktivitas, dan riwayat tiap order.

### Mengambil barang untuk order (Picking)
1. Buka **Tugas Saya** atau **Picking**, lalu pilih order yang perlu diambil.
2. Mulai sesi, pergi ke rak yang disarankan, scan rak, lalu scan setiap barang.
3. Setelah semua barang terambil, order otomatis pindah ke packing.

Jika tidak ada satu rak yang cukup, satu barang dibagi ke beberapa rak dan tampil sebagai beberapa baris (mis. 4 pcs dari B-01-01 dan 1 pcs dari A-01-01). Jika rak habis di tengah jalan, scan rak lain yang masih ada barangnya lalu lanjutkan scan barang, atau ketuk **Barang tidak ada**: yang sudah diambil tetap tercatat, dan sisanya diarahkan ke rak lain.

### Picking gabungan (beberapa order sekaligus)
1. Di **Tugas Saya**, klik **Ambil beberapa order sekaligus** (atau **Picking Gabungan** di halaman Picking).
2. Centang order yang mau diambil (2 sampai 20), atau klik **5 terlama** / **10 terlama**, lalu klik **Mulai picking gabungan**.
3. Siapkan keranjang bernomor 1, 2, 3, dan seterusnya. Keranjang 1 untuk order terlama.
4. Ikuti **Daftar ambil**: setiap SKU cukup diambil sekali per rak, sudah diurutkan per rak. Scan rak, lalu scan barang. Layar menampilkan **Masukkan ke keranjang N** dengan angka besar.
5. Begitu satu keranjang lengkap, order-nya langsung masuk **Perlu dikemas**, lengkap dengan nomor keranjangnya.
6. Jika harus berhenti, klik **Batalkan keranjang yang belum lengkap**, lalu kembalikan barang dari keranjang itu ke raknya. Stoknya otomatis dikembalikan dan order kembali ke antrean picking.

### Mengemas (Packing)
1. Buka **Packing**, lalu pilih order yang perlu dikemas.
2. Mulai sesi, lalu scan setiap barang yang masuk ke paket.
3. Setelah semua barang lengkap, order otomatis berstatus siap kirim. Cetak label kirim lewat **Cetak label kirim**.

### Serah terima paket ke kurir
1. Saat kurir datang, buka **Serah Terima Kurir** (atau **Serahkan ke kurir** di Tugas Saya), lalu klik **Mulai scan** di kurir yang sesuai. Paket dikelompokkan per kurir, misalnya SPX Standard dan SPX Instant masuk ke SPX.
2. Scan resi di label setiap paket yang diserahkan. Paket untuk kurir lain, paket yang belum selesai dikemas, paket tanpa resi, dan paket yang sudah pernah dikirim akan ditolak dengan bunyi peringatan.
3. Daftar di bawah menunjukkan paket kurir itu yang **Belum di-scan**, jadi paket yang tertinggal mudah ketahuan. Jika kurir menolak satu paket, klik **keluarkan**.
4. Isi nama kurir (opsional), lalu klik **Serahkan dan cetak manifest**. Semua paket di daftar berubah menjadi **Dikirim**, dan manifest A4 terbuka untuk dicetak dan ditandatangani kedua pihak.
5. Manifest lama bisa dibuka lagi dari **Riwayat** di halaman Serah Terima Kurir.

### Retur dari pelanggan
1. Buka **Retur**, lalu klik **Terima Retur**.
2. Scan QR di label kirim atau ketik nomor order/resi.
3. Isi jumlah barang yang masih bagus dan lokasi raknya. Barang yang rusak cukup dicatat.
4. Barang yang bagus otomatis masuk lagi ke stok.
5. Order yang paketnya kembali berubah status menjadi **Diretur**, baik yang masih **Dikirim** maupun yang sudah **Selesai**.

### Order Dikirim otomatis Selesai
Order **Dikirim** otomatis menjadi **Selesai** setelah lebih dari 3 hari sejak dikirim (Owner bisa mengubah jumlah harinya di **Pengaturan**, isi 0 untuk mematikan). Tidak perlu klik **Selesai** satu per satu. Detail order menampilkan kapan order itu akan selesai otomatis, dan riwayat order menulis **Otomatis** dengan pelaku **Sistem**.

### Memindahkan stok (Transfer Stok)
Buka **Transfer Stok**, isi SKU, rak asal, rak tujuan, dan jumlahnya, lalu simpan. Pergerakannya tercatat di riwayat stok.

### Stock opname
1. Buka **Stock Opname**, lalu klik **Buat Opname**. Pilih produk yang dihitung: **Semua produk**, **Satu produk**, atau **Beberapa produk** (ketik/scan SKU lalu Enter). Pilih rak jika perlu; jika tidak ada rak yang dipilih, produk itu dihitung di semua rak. Satu barang (produk di rak yang sama) hanya bisa ada di satu opname yang belum selesai.
2. Klik **Mulai Opname**. Scan lokasi rak, lalu scan barangnya satu per satu.
3. Jika ada barang yang tidak ada di daftar, form **Tambah barang** otomatis terisi saat barangnya di-scan.
4. Klik **Simpan Sementara** sesering mungkin. Setelah semua lokasi dihitung, klik **Kirim untuk Disetujui**. Stok sistem belum berubah sampai Owner/Supervisor menyetujui.
5. Jika opname dikembalikan, alasannya tampil di atas daftar. Hitung ulang bagian itu, lalu kirim lagi.

### Menata barang ke rak (Denah Gudang)
1. Buka **Denah Gudang**. Jika ada tulisan "stok belum punya lokasi rak", artinya stok hasil import atau form SKU masih tercatat di DEFAULT-01 atau GUDANG-UTAMA.
2. Klik rak tujuan, cari produknya, lalu masukkan ke keranjang.
3. Biarkan **Pindahkan stok yang belum punya rak** tercentang, isi lokasinya (misalnya A-01-01), lalu klik **Simpan**. Stoknya pindah ke rak itu dan tercatat sebagai transfer.

### Aturan baris rak (satu baris = satu kata kunci produk + satu size)
1. Di halaman rak, buka **Aturan Baris Rak**. Isi kata kunci produk dan size untuk tiap baris, atau pakai **Isi cepat**: kata kunci `ONE PIECE`, size `M, L, XL, XXL`, mulai baris 1, lalu **Terapkan ke baris**. Klik **Simpan Aturan**.
2. Baris N memakai bin `RAK-0N-01`, misalnya baris 2 rak A = `A-02-01`. Kata kunci (misalnya `ONE PIECE`, `NARUTO`, `JUJUTSU KAISEN`) dicari di nama produk, artikel, versi, dan kode SKU. Semua kata harus ada, urutan dan tanda baca bebas. Size harus sama persis.
3. Di **Keranjang Produk** muncul **Isi sesuai aturan baris**. Klik **Masukkan semua** atau tombol per baris, lalu **Simpan**. Setiap SKU dan stok yang belum punya rak masuk ke bin barisnya masing-masing.
4. Pencarian di keranjang otomatis hanya menampilkan SKU yang cocok aturan rak. Hapus centangnya untuk mencari semua SKU.
5. Di **Inbound**, SKU yang belum punya stok atau rak disarankan masuk ke bin baris yang aturannya cocok.

### Cek barang dan lokasi
Buka **Cek Barang & Lokasi**, lalu scan barcode barang untuk melihat stok di setiap rak, atau scan rak untuk melihat isinya.

---

## ADMIN_GUDANG

Semua pekerjaan STAFF_GUDANG, ditambah:

### Purchase order
1. Buka **Purchase Order** untuk melihat PO yang sudah disetujui.
2. Saat supplier mulai mengerjakan, klik **Mulai Produksi**.
3. Saat barang siap, klik **Siap Diambil**. Penerimaan barangnya dilakukan lewat Inbound, boleh bertahap.
4. Klik **Cetak PO** untuk membuka lembar PO A4. Dari sana PO bisa dicetak atau disimpan sebagai PDF, teksnya disalin, atau langsung dikirim ke WhatsApp supplier (nomor diambil dari data supplier). PO yang belum disetujui diberi cap DRAFT.
5. Pantau barang yang belum datang:
   - Daftar PO punya kolom **Diterima** (mis. 2/5) dan kartu **Menunggu Sisa Barang** untuk membuka semua PO yang baru diterima sebagian. PO yang sisanya belum datang padahal estimasi tiba sudah lewat diberi tanda **Lewat target**.
   - Detail PO menampilkan kolom **Sisa** per SKU dan **Riwayat Penerimaan**: setiap kedatangan dengan tanggal, nomor surat jalan, penerima, jumlah, dan rak tujuannya.
   - Klik **Tagih Sisa ke Supplier (WA)** untuk mengirim daftar sisa barang ke WhatsApp supplier. Jika nomor supplier belum diisi, tombolnya menjadi **Salin Teks Tagihan Sisa**.

---

## SUPERVISOR

Semua pekerjaan ADMIN_GUDANG, ditambah:

### Persetujuan stok
Buka **Persetujuan Stok** (juga muncul di lonceng):
- **Stock opname** yang dikirim staf: buka, periksa baris yang selisih, lalu klik **Setujui & Sesuaikan Stok**, atau **Kembalikan untuk dihitung ulang** dengan alasan. Opname yang dibuat dan diselesaikan Owner/Supervisor sendiri langsung menyesuaikan stok.
- **Barang tidak ada di rak** yang dilaporkan picker: cek raknya. Jika memang kosong, klik **Setujui, nolkan stok**. Jika barangnya ada, klik **Tolak, barang ada**, dan order yang tadi ditunda kembali ke antrean picking. Selama belum diputuskan, rak itu tidak dijatah ke picking.

### Mengedit atau menghapus penerimaan barang (Inbound)
Jika penerimaan salah input, buka detailnya di **Inbound** (atau klik **Edit** di daftar):
- **Edit penerimaan**: ubah nomor surat jalan, catatan, dan qty per baris (isi 0 untuk membuang baris yang salah). Alasan wajib diisi.
- **Dipesan** (penerimaan tanpa PO): isi jumlah yang diorder per baris. Kalau yang datang masih kurang, status di daftar Inbound jadi **Belum Komplit** dan kolom Pesanan menunjukkan sisa yang masih diproses. Saat sisanya datang, naikkan qty-nya sampai sama dengan pesanan, statusnya jadi **Komplit**. Angka ini juga bisa diisi langsung di kolom **Order** saat terima barang.
- **Hapus penerimaan**: seluruh penerimaan dibatalkan, dengan alasan.
- Stok di rak dan qty diterima di PO ikut disesuaikan, termasuk status PO (mis. kembali menjadi Diterima Sebagian). Qty tidak bisa dikurangi atau dihapus jika barangnya sudah dipindah, diambil, atau dijatah untuk order yang dikonfirmasi; koreksi seperti itu lewat stock opname. Menambah qty dari PO tidak boleh melebihi sisa PO.
- Pindah rak tidak lewat edit, tapi lewat **Transfer Stok**.
- Isi sebelum dan sesudahnya tercatat di **Audit Trail**.

### Koreksi SKU yang salah input (PO dan Inbound, juga yang sudah selesai)
Dipakai jika SKU yang tercatat salah, misalnya salah ukuran, padahal barangnya sudah masuk rak. Barangnya tidak pindah tempat: stok SKU lama di bin itu dikurangi dan stok SKU yang benar di bin yang sama ditambah.
- **Koreksi SKU di PO** (tombol **Koreksi SKU** di detail PO, juga untuk PO Sudah Diterima): isi SKU yang benar di baris yang salah. Qty pesanan, qty diterima, dan harga ikut pindah. Semua penerimaan untuk baris itu ikut dikoreksi stoknya. Jika SKU yang benar sudah ada di PO, kedua baris digabung. Pakai ini jika SKU di PO-nya yang salah.
- **Koreksi SKU di penerimaan** (tombol **Koreksi SKU** di detail penerimaan, juga untuk yang sudah Komplit): pilih SKU yang benar per baris. Untuk penerimaan dari PO, pilihannya hanya SKU di PO itu, dan qty diterima PO ikut pindah ke SKU yang benar. Pakai ini jika PO-nya benar tapi salah pilih SKU saat terima barang.
- Klik **Periksa Ringkasan**, cocokkan SKU lama dan SKU benar, qty, dan bin dengan barang fisik, centang konfirmasi, lalu **Simpan Koreksi SKU**. Alasan wajib diisi.
- Ditolak jika stok SKU lama di bin itu sudah dipindah, diambil, atau dijatah untuk order yang dikonfirmasi; koreksi seperti itu lewat stock opname. Penerimaan lama yang belum terhubung ke PO perlu dihubungkan dulu lewat **Edit penerimaan** (PO Terkait).
- Setelah koreksi, cetak ulang label SKU untuk barangnya. SKU lama dan baru, qty, bin, dan alasannya tercatat di **Audit Trail**, catatan PO, dan **Riwayat Koreksi SKU** di detail penerimaan.

### Membuat dan menyetujui PO
1. Buka **Purchase Order**, lalu buat PO baru. Tombol **PO dari Stok Menipis** mengisi otomatis SKU yang stoknya di bawah batas. Saat disimpan muncul **Pengecekan Terakhir** berisi SKU, nama produk, qty, dan subtotal; cocokkan dengan pesanan, centang, lalu **Ya, Simpan PO**.
2. Klik **Ajukan untuk Approval** jika PO sudah siap.
3. PO yang menunggu persetujuan bisa disetujui (**Approve PO**) atau dikembalikan (**Tolak**) dengan alasan. PO yang ditolak kembali menjadi draft dan bisa diedit.
   - Jika supplier tidak sanggup mengirim sisa PO yang sudah diterima sebagian, klik **Batalkan PO**. Barang yang sudah datang tetap di stok; hanya sisanya yang dibatalkan. Jika jumlah pesanan berubah, edit qty PO; qty tidak bisa diturunkan di bawah yang sudah diterima, dan jika diturunkan sampai sama, PO otomatis selesai.
4. Setiap perubahan status PO (diajukan, disetujui, ditolak, mulai produksi, siap diambil, diterima sebagian, diterima lengkap, dibatalkan) dikirim ke antrean WhatsApp anggota. Matikan lewat centang **Kirim notifikasi WhatsApp saat status PO berubah** di Pengaturan.

### Laporan
Buka **Laporan** untuk melihat:
- Stok saat ini, pergerakan stok, dan daftar opname (semuanya bisa diekspor ke Excel)
- **Kinerja Picker & Packer**: order dan pcs per orang beserta rata-rata waktunya
- **Barang Lambat Laku**: SKU yang lama tidak keluar
- **Akurasi Opname**: persen hitungan yang cocok dan SKU yang sering selisih
- **Nilai Stok**: nilai jual, nilai modal (HPP), dan potensi laba per kategori dan per produk

### Riwayat Aktivitas
Berisi catatan siapa mengubah apa dan kapan, termasuk nilai sebelum dan sesudah (dicoret merah → hijau). Gunakan untuk menelusuri selisih stok atau perubahan PO.
- Saring per user, modul, aksi, dan tanggal, atau cari SKU, nomor order, atau nama.
- Yang tercatat juga: login, login gagal, dan logout; perubahan user (role, status, password diganti, tanpa isi password); supplier; pengaturan; template printer; serta opname dan laporan barang tidak ada di rak.
- **Export Excel** mengunduh hasil filter (maksimal 5.000 baris).

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
