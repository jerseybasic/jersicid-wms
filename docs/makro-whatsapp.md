# Menghubungkan makro WhatsApp (Playwright) ke WMS

WMS tidak mengirim WhatsApp sendiri. WMS hanya menaruh pesan di **antrean**, satu pesan untuk setiap penerima. Makro Playwright milik toko mengambil antrean itu, mengirim pesannya lewat WhatsApp Web, lalu melaporkan hasilnya ke WMS.

```
WMS (jadwal 08:00 / Kirim Tes) ──► antrean ──► makro: GET /api/whatsapp/antrean
                                                makro kirim di WhatsApp Web
                                   ◄────────── makro: POST .../terkirim atau .../gagal
```

Karena makro yang mengambil pesan, makro bisa berjalan di komputer mana pun yang bisa membuka alamat WMS, termasuk laptop kantor yang sudah login WhatsApp Web. Pesan juga tidak hilang saat makro mati. Pesan akan terkirim begitu makro jalan lagi.

## 1. Siapkan di WMS

1. Di **Kelola User**, isi **No. WhatsApp** setiap anggota yang perlu menerima notifikasi, lalu centang **Kirim notifikasi WhatsApp**.
2. Di **Pengaturan → Notifikasi WhatsApp**, klik **Buat token**. Salin tokennya ke makro, karena token hanya ditampilkan sekali.
3. Klik **Kirim Tes**. Pesan tes akan masuk antrean dan menunggu diambil makro.

## 2. API untuk makro

Setiap permintaan wajib memakai header `Authorization: Bearer <token>`.

| Permintaan | Keterangan |
|---|---|
| `GET /api/whatsapp/antrean?limit=10` | Mengambil sampai 10 pesan yang menunggu. Hasilnya: `{"messages": [{"id": 12, "to": "6281234567890", "message": "..."}]}` |
| `POST /api/whatsapp/antrean/{id}/terkirim` | Menandai pesan terkirim. |
| `POST /api/whatsapp/antrean/{id}/gagal` dengan body JSON `{"error": "alasan"}` | Menandai pesan gagal. Pesan akan diberikan lagi sampai 3 kali percobaan. Setelah itu statusnya **Gagal**, dan alasannya tampil di Pengaturan. |

Hal yang perlu diperhatikan:
- Nomor `to` sudah dalam format `62…`, sehingga bisa langsung dipakai di `https://web.whatsapp.com/send?phone=…`.
- Pesan yang sudah diambil tidak akan diberikan lagi. Pengecualiannya: jika dalam 10 menit pesan itu belum dilaporkan terkirim atau gagal (misalnya makro tiba-tiba mati), pesan tersebut akan diberikan lagi.
- Isi pesan memakai format WhatsApp: `*tebal*` dan baris baru `\n`.
- Batas permintaan 120 kali per menit. Mengambil antrean setiap 30 detik sampai 1 menit sudah lebih dari cukup.
- Halaman Pengaturan menampilkan kapan terakhir kali makro mengambil antrean. Ini berguna untuk mengecek apakah makro masih berjalan.

## 3. Contoh makro (Node.js + Playwright)

Contoh di bawah ini bisa disesuaikan atau digabung dengan makro yang sudah ada. Login WhatsApp Web disimpan di folder profil (`./wa-profile`). Cookies saja tidak cukup, karena WhatsApp Web juga menyimpan kunci login di IndexedDB.

```js
// makro-wa.mjs — jalankan: WMS_URL=https://wms-anda WMS_TOKEN=wa_xxx node makro-wa.mjs
import { chromium } from 'playwright';

const WMS = process.env.WMS_URL;
const headers = { Authorization: `Bearer ${process.env.WMS_TOKEN}`, 'Content-Type': 'application/json', Accept: 'application/json' };

const context = await chromium.launchPersistentContext('./wa-profile', { headless: false });
const page = context.pages()[0] ?? await context.newPage();
await page.goto('https://web.whatsapp.com');
await page.waitForSelector('#pane-side', { timeout: 0 }); // scan QR sekali, lalu login tersimpan di wa-profile

async function kirim(to, text) {
  await page.goto(`https://web.whatsapp.com/send?phone=${to}&text=${encodeURIComponent(text)}`);
  const box = page.locator('footer div[contenteditable="true"]').last();
  await box.waitFor({ timeout: 60000 });
  await page.waitForTimeout(1500);
  await box.press('Enter');
  await page.waitForTimeout(3000);
}

while (true) {
  try {
    const { messages } = await (await fetch(`${WMS}/api/whatsapp/antrean?limit=10`, { headers })).json();
    for (const m of messages) {
      try {
        await kirim(m.to, m.message);
        await fetch(`${WMS}/api/whatsapp/antrean/${m.id}/terkirim`, { method: 'POST', headers });
      } catch (e) {
        await fetch(`${WMS}/api/whatsapp/antrean/${m.id}/gagal`, { method: 'POST', headers, body: JSON.stringify({ error: e.message }) });
      }
      await page.waitForTimeout(4000); // jeda antar pesan
    }
  } catch (e) {
    console.error('Gagal menghubungi WMS:', e.message);
  }
  await new Promise((r) => setTimeout(r, 60000)); // cek antrean tiap 1 menit
}
```

## Perlu diketahui

- Mengotomatiskan WhatsApp Web bukan cara resmi WhatsApp, sehingga ada risiko nomor dibatasi jika dipakai berlebihan. Untuk jangka panjang, sebaiknya pakai nomor khusus toko.
- Untuk ganti nomor pengirim (misalnya dari nomor pribadi ke nomor kantor), cukup logout di WhatsApp Web makro lalu scan QR dengan nomor baru. Tidak ada yang perlu diubah di WMS.
