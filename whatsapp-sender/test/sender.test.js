import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Sender, normalize } from '../sender.js';
import { createServer } from '../server.js';

/**
 * WhatsApp Web palsu: cukup meniru elemen yang dipakai pengirim (QR, daftar chat, kotak ketik,
 * popup nomor tidak valid). Selektor WhatsApp asli tetap perlu dicoba dengan nomor sungguhan.
 */
let fake, fakeUrl, loggedIn = false;
const sent = [];

const page = (body) => `<!doctype html><html><head><meta charset="utf-8"></head><body>${body}</body></html>`;
const app = (extra = '') => page(`<div id="pane-side">chats</div>${extra}`);

before(async () => {
  fake = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://x');
    res.writeHead(200, { 'Content-Type': 'text/html' });
    if (req.method === 'POST' && url.pathname === '/__sent') {
      let data = '';
      req.on('data', (c) => (data += c));
      return req.on('end', () => { sent.push(JSON.parse(data)); res.end('ok'); });
    }
    if (!loggedIn) return res.end(page('<div data-ref="abc" style="padding:20px;background:#fff"><canvas aria-label="Scan me" width="100" height="100"></canvas></div>'));
    if (url.pathname === '/send') {
      const phone = url.searchParams.get('phone');
      const text = url.searchParams.get('text');
      if (phone === '620000000000') return res.end(app('<div role="dialog">Phone number shared via url is invalid.</div>'));
      return res.end(app(`<footer><div contenteditable="true" id="box"></div></footer>
        <script>
          const box = document.getElementById('box');
          setTimeout(() => { box.innerText = ${JSON.stringify(text)}; }, 200);
          box.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const clock = document.createElement('span'); clock.dataset.icon = 'msg-time'; document.body.append(clock);
            fetch('/__sent', { method: 'POST', body: JSON.stringify({ phone: ${JSON.stringify(phone)}, text: box.innerText }) })
              .then(() => setTimeout(() => clock.remove(), 300));
          });
        </script>`));
    }
    return res.end(app('<script>localStorage.setItem("last-wid-md", "\\"6281200001111:5@c.us\\"")</script>'));
  });
  await new Promise((r) => fake.listen(0, '127.0.0.1', r));
  fakeUrl = `http://127.0.0.1:${fake.address().port}`;
});

after(() => fake.close());

test('normalize nomor Indonesia', () => {
  assert.equal(normalize('0812-3456-7890'), '6281234567890');
  assert.equal(normalize('+62 812 3456 7890'), '6281234567890');
  assert.equal(normalize('123'), null);
});

test('alur QR, kirim, nomor tidak valid, keamanan API, dan keluar', async () => {
  const sessionDir = await mkdtemp(join(tmpdir(), 'wa-session-'));
  const sender = new Sender({ baseUrl: fakeUrl, sessionDir, delayMs: 100, timeoutMs: 5000 });
  await sender.start();
  const api = createServer(sender, 'kunci-rahasia-panjang-123');
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${api.address().port}`;
  const call = (path, opts = {}) => fetch(base + path, { ...opts, headers: { 'X-Api-Key': 'kunci-rahasia-panjang-123', 'Content-Type': 'application/json', ...(opts.headers ?? {}) } });

  try {
    assert.equal((await fetch(base + '/status')).status, 401);

    // Belum login: QR tersedia, kirim ditolak
    assert.equal((await (await call('/status')).json()).state, 'QR');
    const qr = await call('/qr');
    assert.equal(qr.headers.get('content-type'), 'image/png');
    assert.ok((await qr.arrayBuffer()).byteLength > 100);
    const refused = await call('/send', { method: 'POST', body: JSON.stringify({ to: ['081200002222'], message: 'tes' }) });
    assert.equal(refused.status, 503);

    // Setelah "scan": siap, kirim ke dua nomor (satu tidak valid)
    loggedIn = true;
    await sender.page.reload();
    const status = await (await call('/status')).json();
    assert.equal(status.state, 'READY');
    assert.equal(status.me, '+6281200001111');

    const message = '*Stok menipis: 1 SKU*\n• JSC-A-L sisa *2*';
    const res = await (await call('/send', { method: 'POST', body: JSON.stringify({ to: ['081200002222', '+62 000 000 0000', '6281200002222'], message }) })).json();
    assert.deepEqual(res.results.map((r) => [r.to, r.ok]), [['6281200002222', true], ['620000000000', false]]);
    assert.match(res.results[1].error, /tidak terdaftar/);
    assert.deepEqual(sent, [{ phone: '6281200002222', text: message }]);

    // Validasi
    assert.equal((await call('/send', { method: 'POST', body: JSON.stringify({ to: [], message: 'x' }) })).status, 422);

    // Keluar: sesi dihapus, QR muncul lagi
    loggedIn = false;
    assert.equal((await call('/logout', { method: 'POST' })).status, 200);
    assert.equal((await (await call('/status')).json()).state, 'QR');
  } finally {
    api.close();
    await sender.stop();
    await rm(sessionDir, { recursive: true, force: true });
  }
});
