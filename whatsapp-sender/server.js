import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { Sender, normalize } from './sender.js';

/**
 * API kecil untuk WMS (hanya dari server ini, wajib header X-Api-Key):
 *   GET  /status  → { ok, state: READY|QR|LOADING, me }
 *   GET  /qr      → gambar PNG QR login (jika belum login)
 *   POST /send    → { to: ["0812..."], message } → { ok, results: [{ to, ok, error }] }
 *   POST /logout  → keluarkan nomor sekarang (untuk ganti nomor)
 */
export function createServer(sender, apiKey) {
  const keyBuf = Buffer.from(apiKey);
  const authorized = (req) => {
    const given = Buffer.from(String(req.headers['x-api-key'] ?? ''));
    return given.length === keyBuf.length && timingSafeEqual(given, keyBuf);
  };
  const json = (res, status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
  };
  const readBody = (req) => new Promise((resolve, reject) => {
    let data = '';
    req.on('data', (chunk) => {
      data += chunk;
      if (data.length > 100_000) reject(new Error('terlalu besar'));
    });
    req.on('end', () => resolve(data));
    req.on('error', reject);
  });

  return http.createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    if (!authorized(req)) return json(res, 401, { ok: false, error: 'kunci API salah' });

    try {
      if (req.method === 'GET' && path === '/status') {
        const state = await sender.state();
        return json(res, 200, { ok: true, state, me: state === 'READY' ? await sender.me() : null });
      }
      if (req.method === 'GET' && path === '/qr') {
        const png = await sender.qrPng();
        if (!png) return json(res, 404, { ok: false, error: 'QR tidak tersedia (sudah login atau masih memuat)' });
        res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'no-store' });
        return res.end(png);
      }
      if (req.method === 'POST' && path === '/logout') {
        await sender.logout();
        return json(res, 200, { ok: true });
      }
      if (req.method === 'POST' && path === '/send') {
        let body;
        try {
          body = JSON.parse(await readBody(req));
        } catch {
          return json(res, 400, { ok: false, error: 'format permintaan salah' });
        }
        const message = String(body.message ?? '').trim();
        const numbers = [...new Set((Array.isArray(body.to) ? body.to : []).map(normalize).filter(Boolean))];
        if (!message || message.length > 4000) return json(res, 422, { ok: false, error: 'pesan kosong atau terlalu panjang' });
        if (!numbers.length || numbers.length > 50) return json(res, 422, { ok: false, error: 'nomor tujuan kosong atau terlalu banyak' });

        const results = await sender.sendMany(numbers, message);
        return json(res, 200, { ok: true, results });
      }
      return json(res, 404, { ok: false, error: 'tidak ditemukan' });
    } catch (e) {
      return json(res, e.status ?? 500, { ok: false, error: e.message });
    }
  });
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const apiKey = process.env.API_KEY ?? '';
  if (apiKey.length < 16) {
    console.error('Isi API_KEY di whatsapp-sender/.env (minimal 16 karakter), sama dengan WA_SENDER_KEY di .env WMS.');
    process.exit(1);
  }
  const sender = new Sender({
    baseUrl: process.env.WA_URL || 'https://web.whatsapp.com',
    sessionDir: process.env.SESSION_DIR || './session',
    headless: process.env.HEADLESS !== 'false',
    delayMs: Number(process.env.DELAY_MS || 4000),
  });
  await sender.start();
  const host = process.env.HOST || '127.0.0.1';
  const port = Number(process.env.PORT || 3100);
  createServer(sender, apiKey).listen(port, host, () => console.log(`Pengirim WhatsApp berjalan di http://${host}:${port}`));

  const shutdown = async () => {
    await sender.stop();
    process.exit(0);
  };
  process.on('SIGINT', shutdown);
  process.on('SIGTERM', shutdown);
}
