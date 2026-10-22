import { chromium } from 'playwright';
import { rm } from 'node:fs/promises';

/**
 * Satu jendela WhatsApp Web yang tetap login (profil browser disimpan di SESSION_DIR).
 * Pesan dikirim satu per satu lewat https://web.whatsapp.com/send?phone=...&text=...
 */
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

const SEL = {
  loggedIn: '#pane-side',
  qr: 'div[data-ref] canvas, canvas[aria-label]',
  compose: 'footer div[contenteditable="true"]',
  pending: 'span[data-icon="msg-time"]',
  popup: 'div[role="dialog"], div[data-animate-modal-popup="true"]',
};

export class Sender {
  constructor({ baseUrl = 'https://web.whatsapp.com', sessionDir = './session', headless = true, delayMs = 4000, timeoutMs = 60000 } = {}) {
    Object.assign(this, { baseUrl: baseUrl.replace(/\/$/, ''), sessionDir, headless, delayMs, timeoutMs });
    this.context = null;
    this.page = null;
    this.queue = Promise.resolve();
  }

  async start() {
    this.context = await chromium.launchPersistentContext(this.sessionDir, {
      headless: this.headless,
      userAgent: USER_AGENT,
      viewport: { width: 1280, height: 900 },
      args: ['--disable-blink-features=AutomationControlled'],
    });
    this.page = this.context.pages()[0] ?? (await this.context.newPage());
    await this.page.goto(this.baseUrl, { waitUntil: 'domcontentloaded' });
  }

  async stop() {
    await this.context?.close().catch(() => {});
    this.context = null;
    this.page = null;
  }

  /** READY (login), QR (perlu scan), atau LOADING */
  async state() {
    if (!this.page) return 'LOADING';
    if (await this.page.locator(SEL.loggedIn).count()) return 'READY';
    if (await this.page.locator(SEL.qr).count()) return 'QR';
    return 'LOADING';
  }

  /** Nomor yang sedang login, dari penyimpanan WhatsApp Web (jika tersedia) */
  async me() {
    const wid = await this.page?.evaluate(() => localStorage.getItem('last-wid-md') || localStorage.getItem('last-wid')).catch(() => null);
    const match = wid && String(wid).match(/(\d{8,15})/);
    return match ? '+' + match[1] : null;
  }

  async qrPng() {
    if ((await this.state()) !== 'QR') return null;
    const qr = this.page.locator(SEL.qr).first();
    // Area putih di sekitar QR supaya mudah di-scan
    return (await qr.locator('xpath=..').count()) ? qr.locator('xpath=..').screenshot() : qr.screenshot();
  }

  /** Keluar dari nomor sekarang: hapus sesi lalu buka ulang (QR baru muncul) */
  async logout() {
    return this.enqueue(async () => {
      await this.stop();
      await rm(this.sessionDir, { recursive: true, force: true });
      await this.start();
    });
  }

  /** Kirim ke beberapa nomor secara berurutan. Hasil per nomor: { to, ok, error } */
  async sendMany(numbers, message) {
    return this.enqueue(async () => {
      if ((await this.state()) !== 'READY') {
        const err = new Error('WhatsApp belum login. Scan QR di halaman Pengaturan WMS.');
        err.status = 503;
        throw err;
      }
      const results = [];
      for (const [i, to] of numbers.entries()) {
        if (i > 0) await this.page.waitForTimeout(this.delayMs);
        try {
          await this.sendOne(to, message);
          results.push({ to, ok: true });
        } catch (e) {
          results.push({ to, ok: false, error: e.message });
        }
      }
      // Kembali ke daftar chat supaya status berikutnya terbaca normal
      await this.page.goto(this.baseUrl, { waitUntil: 'domcontentloaded' }).catch(() => {});
      return results;
    });
  }

  async sendOne(to, message) {
    const url = `${this.baseUrl}/send?phone=${encodeURIComponent(to)}&text=${encodeURIComponent(message)}`;
    await this.page.goto(url, { waitUntil: 'domcontentloaded' });

    const compose = this.page.locator(SEL.compose).last();
    const popup = this.page.locator(SEL.popup).filter({ hasText: /invalid|tidak valid|not on WhatsApp|tidak terdaftar/i });
    const first = await Promise.race([
      compose.waitFor({ timeout: this.timeoutMs }).then(() => 'compose'),
      popup.first().waitFor({ timeout: this.timeoutMs }).then(() => 'invalid'),
    ]).catch(() => 'timeout');

    if (first === 'invalid') throw new Error('nomor tidak terdaftar di WhatsApp');
    if (first === 'timeout') throw new Error('WhatsApp Web tidak merespons');

    // Teks sudah terisi dari URL; tunggu sebentar lalu tekan Enter
    await this.page.waitForFunction((sel) => {
      const boxes = document.querySelectorAll(sel);
      return boxes.length && boxes[boxes.length - 1].innerText.trim().length > 0;
    }, SEL.compose, { timeout: 15000 }).catch(() => {});
    await compose.click();
    await this.page.keyboard.press('Enter');

    // Tunggu ikon jam (pesan tertunda) hilang
    await this.page.waitForTimeout(1500);
    await this.page.locator(SEL.pending).first().waitFor({ state: 'detached', timeout: 30000 }).catch(() => {
      throw new Error('pesan belum terkirim (masih tertunda)');
    });
  }

  enqueue(task) {
    const run = this.queue.then(task, task);
    this.queue = run.catch(() => {});
    return run;
  }
}

/** 08xx / +628xx / 628xx → 628xx; null jika bukan nomor */
export function normalize(number) {
  const digits = String(number ?? '').replace(/\D/g, '');
  if (digits.length < 9 || digits.length > 15) return null;
  return digits.startsWith('0') ? '62' + digits.slice(1) : digits;
}
