#!/usr/bin/env node
// WhatsApp Web headless dengan menu di terminal.
// - Login via QR di terminal, sesi tersimpan per akun
// - Kirim chat ke banyak nama/nomor dengan interval & count
// - Multi akun: ganti/tambah/hapus akun; tiap akun bisa jalan di terminal terpisah
//
// Opsi: --gui (tampilkan jendela browser)
//       --wms [--akun nama]  kirim notifikasi dari antrean JERSIC.ID WMS terus-menerus

const fs = require("fs");
const os = require("os");
const path = require("path");
const readline = require("readline");
const qrcode = require("qrcode-terminal");
const puppeteer = require("puppeteer-core");

const WA_URL = process.env.WA_URL || "https://web.whatsapp.com";
const MIN_INTERVAL = Number(process.env.WA_MIN_INTERVAL || 10); // detik antar putaran
const MIN_GAP = Number(process.env.WA_MIN_GAP || 3); // detik antar tujuan
const JITTER = 0.2; // jeda diacak +0..20% agar tidak terlalu robotik

const HOME_DIR = process.env.WA_HOME || path.join(os.homedir(), ".wa-terminal");
const ACC_DIR = path.join(HOME_DIR, "accounts");
const LAST_FILE = path.join(HOME_DIR, "last-account");

// ================= util =================
const C = {
  g: (s) => `\x1b[32m${s}\x1b[0m`,
  y: (s) => `\x1b[33m${s}\x1b[0m`,
  r: (s) => `\x1b[31m${s}\x1b[0m`,
  c: (s) => `\x1b[36m${s}\x1b[0m`,
  b: (s) => `\x1b[1m${s}\x1b[0m`,
  d: (s) => `\x1b[2m${s}\x1b[0m`,
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const now = () => new Date().toLocaleTimeString("id-ID");
const log = (s) => console.log(`  [${now()}] ${s}`);
const jitter = (sec) => (sec + sec * JITTER * Math.random()) * 1000;

function parseArgs(argv) {
  const out = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith("--")) {
      const next = argv[i + 1];
      if (next === undefined || next.startsWith("--")) out[a.slice(2)] = true;
      else out[a.slice(2)] = argv[++i];
    } else out._.push(a);
  }
  return out;
}

function findChrome() {
  if (process.env.CHROME_PATH) return process.env.CHROME_PATH;
  const names =
    process.platform === "linux"
      ? ["chromium", "chromium-browser", "google-chrome", "google-chrome-stable", "brave-browser", "microsoft-edge"]
      : [];
  for (const n of names) {
    for (const dir of (process.env.PATH || "").split(path.delimiter)) {
      const p = path.join(dir, n);
      if (fs.existsSync(p)) return p;
    }
  }
  const fixed = [
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Chromium.app/Contents/MacOS/Chromium",
    path.join(process.env.ProgramFiles || "", "Google/Chrome/Application/chrome.exe"),
    path.join(process.env["ProgramFiles(x86)"] || "", "Google/Chrome/Application/chrome.exe"),
    path.join(process.env.LOCALAPPDATA || "", "Google/Chrome/Application/chrome.exe"),
    path.join(process.env["ProgramFiles(x86)"] || "", "Microsoft/Edge/Application/msedge.exe"),
  ];
  return fixed.find((p) => p && fs.existsSync(p));
}

const isPhone = (to) => /^\+?[\d\s-]{8,}$/.test(String(to).trim());

function normPhone(v) {
  let d = String(v).replace(/\D/g, "");
  if (d.startsWith("0")) d = "62" + d.slice(1); // 0812... -> 62812...
  return d;
}

function parsePhones(text) {
  const found = [];
  for (const piece of String(text).split(/[,;|\t\r\n]+/)) {
    for (const m of piece.match(/\+?\d[\d\s\-().]{6,}\d/g) || []) {
      // "0812... 0813..." (dipisah spasi) -> pecah per bagian
      if (m.replace(/\D/g, "").length > 15) found.push(...m.split(/\s+/).filter(Boolean));
      else found.push(m);
    }
  }
  const ok = [];
  const bad = [];
  for (const raw of found) {
    const d = normPhone(raw);
    if (d.length >= 9 && d.length <= 15) ok.push(d);
    else bad.push(raw.trim());
  }
  return { phones: [...new Set(ok)], bad };
}

const parseNames = (text) => [
  ...new Set(
    String(text)
      .split(/[,;\n]/)
      .map((s) => s.trim())
      .filter(Boolean),
  ),
];

function preview(list, max = 2) {
  if (!list.length) return "";
  const head = list.slice(0, max).join(", ");
  return list.length > max ? `${head} (+${list.length - max} lagi)` : head;
}

// ================= akun =================
const accPath = (name) => path.join(ACC_DIR, name);
const profileOf = (name) => path.join(accPath(name), "profile");
const settingsOf = (name) => path.join(accPath(name), "settings.json");

function migrateOld() {
  // versi lama menyimpan sesi di ~/.wa-terminal/profile
  const oldProfile = path.join(HOME_DIR, "profile");
  if (fs.existsSync(oldProfile) && !fs.existsSync(ACC_DIR)) {
    fs.mkdirSync(accPath("akun1"), { recursive: true });
    fs.renameSync(oldProfile, profileOf("akun1"));
    const oldSet = path.join(HOME_DIR, "settings.json");
    if (fs.existsSync(oldSet)) {
      try {
        const s = JSON.parse(fs.readFileSync(oldSet, "utf8"));
        s.nama = s.nama ? parseNames(s.nama) : [];
        s.nomor = s.nomor ? [s.nomor] : [];
        fs.writeFileSync(settingsOf("akun1"), JSON.stringify(s, null, 2));
        fs.rmSync(oldSet);
      } catch {}
    }
    fs.writeFileSync(path.join(accPath("akun1"), ".loggedin"), "migrasi");
    fs.writeFileSync(LAST_FILE, "akun1");
  }
}

function listAccounts() {
  try {
    return fs
      .readdirSync(ACC_DIR, { withFileTypes: true })
      .filter((d) => d.isDirectory())
      .map((d) => d.name)
      .sort();
  } catch {
    return [];
  }
}

// Kunci akun sendiri (Chromium headless tidak mengunci profilnya), isi: PID pemakai
const lockOf = (name) => path.join(accPath(name), ".lock");
function inUse(name) {
  try {
    const pid = Number(fs.readFileSync(lockOf(name), "utf8"));
    if (!pid || pid === process.pid) return false;
    process.kill(pid, 0); // lempar error jika proses sudah mati (lock basi)
    return true;
  } catch (e) {
    return e.code === "EPERM"; // proses ada tapi milik user lain
  }
}
function lockAcc(name) {
  fs.mkdirSync(accPath(name), { recursive: true });
  fs.writeFileSync(lockOf(name), String(process.pid));
}
function unlockAcc(name) {
  try {
    if (Number(fs.readFileSync(lockOf(name), "utf8")) === process.pid) fs.rmSync(lockOf(name));
  } catch {}
}

const loginMark = (name) => path.join(accPath(name), ".loggedin");
const loggedBefore = (name) => fs.existsSync(loginMark(name));

function cleanName(v) {
  return String(v)
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 30);
}

// Penerima: { nama, nomor, data: {kolom CSV tambahan} }
//  - punya nomor  -> dikirim lewat nomor
//  - hanya nama   -> dicari berdasarkan nama kontak di WhatsApp
// Grup kirim: { name, members: [penerima] }  (bukan grup WhatsApp)
const arr = (v) => (Array.isArray(v) ? v : v ? [v] : []);
const newSettings = () => ({
  groups: [],
  selected: [], // nama grup kirim yang dipilih
  message: null, // { title, text, values }
  vars: {}, // isian variabel terakhir (jadi nilai bawaan)
  interval: 60,
  count: 1,
  jeda: 8,
  skipKosong: true, // lewati penerima yang variabel pesannya kosong
});
function loadSettings(acc) {
  let s;
  try {
    s = JSON.parse(fs.readFileSync(settingsOf(acc), "utf8"));
  } catch {
    return newSettings();
  }
  // migrasi versi lama (nama/kontak/grup/nomor/chat) -> grup kirim "Tujuan lama"
  if (!s.groups) {
    const names = [...arr(s.nama), ...arr(s.kontak), ...arr(s.grup)];
    const members = [
      ...names.map((n) => ({ nama: n, nomor: "", data: {} })),
      ...arr(s.nomor).map((n) => ({ nama: "", nomor: n, data: {} })),
    ];
    const old = s;
    s = newSettings();
    for (const k of ["interval", "count", "jeda"]) if (old[k] !== undefined) s[k] = old[k];
    if (members.length) {
      s.groups.push({ name: "Tujuan lama", members });
      s.selected = ["Tujuan lama"];
    }
    if (old.chat) s.message = { title: "Custom", text: old.chat, values: {} };
  }
  return { ...newSettings(), ...s };
}
function saveSettings(acc, s) {
  fs.mkdirSync(accPath(acc), { recursive: true });
  fs.writeFileSync(settingsOf(acc), JSON.stringify(s, null, 2));
}

// ================= browser =================
let browser;

async function launch(profile, gui) {
  const exe = findChrome();
  if (!exe) throw new Error("Chromium/Chrome tidak ditemukan. Pasang: sudo apt install chromium");
  fs.mkdirSync(profile, { recursive: true });
  try {
    browser = await puppeteer.launch({
      executablePath: exe,
      headless: !gui,
      handleSIGINT: false, // Ctrl+C ditangani sendiri
      userDataDir: profile,
      defaultViewport: { width: 1280, height: 900 },
      args: [
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-dev-shm-usage",
        "--window-size=1280,900",
        ...(process.getuid && process.getuid() === 0 ? ["--no-sandbox"] : []),
      ],
    });
  } catch (e) {
    browser = null;
    if (/already running|SingletonLock|ProcessSingleton/i.test(e.message))
      throw new Error("Akun ini sedang dipakai di terminal lain.");
    throw new Error("Gagal membuka browser: " + e.message.split("\n")[0]);
  }
  const page = (await browser.pages())[0] || (await browser.newPage());
  // WhatsApp menolak user-agent "HeadlessChrome"
  await page.setUserAgent((await browser.userAgent()).replace("HeadlessChrome", "Chrome"));
  await page.goto(WA_URL, { waitUntil: "domcontentloaded", timeout: 90000 });
  return page;
}

async function closeBrowser() {
  try {
    if (browser) await browser.close();
  } catch {}
  browser = null;
}

async function shutdown(code = 0) {
  await closeBrowser();
  if (session.acc) unlockAcc(session.acc);
  process.exit(code);
}
process.on("exit", () => session.acc && unlockAcc(session.acc));

// Ctrl+C: saat mengirim -> kembali ke menu; saat login -> kembali ke daftar akun; selain itu -> keluar
let sending = false;
let abortSend = false;
let loggingIn = false;
let abortLogin = false;
function onCtrlC() {
  if (sending) {
    abortSend = true;
    console.log(C.y("\n  Menghentikan pengiriman, kembali ke menu..."));
    return;
  }
  if (loggingIn) {
    abortLogin = true;
    return;
  }
  console.log(C.y("\n  Keluar. Menutup browser..."));
  shutdown(0);
}
process.on("SIGINT", onCtrlC);
process.on("SIGTERM", () => shutdown(0)); // pm2 / systemd

// ================= login =================
async function ensureLogin(page, acc, timeoutSec = 180) {
  let lastRef = null;
  let lastState = null;
  const deadline = Date.now() + timeoutSec * 1000;
  loggingIn = true;
  abortLogin = false;
  try {
    while (Date.now() < deadline) {
      if (abortLogin) {
        log(C.y("Login dibatalkan."));
        return false;
      }
      const s = await page
        .evaluate(() => {
          if (document.querySelector("#pane-side")) return { state: "in" };
          const holder = document.querySelector("div[data-ref]");
          const btn = holder && holder.querySelector("button");
          if (btn) {
            btn.click();
            return { state: "reload" };
          }
          const ref = holder && holder.getAttribute("data-ref");
          if (ref) return { state: "qr", ref };
          return { state: "wait" };
        })
        .catch(() => ({ state: "wait" }));

      if (s.state === "in") {
        log(C.b(C.g(`✔ Akun "${acc}" terhubung.`)));
        return true;
      }
      if (s.state === "qr" && s.ref !== lastRef) {
        lastRef = s.ref;
        console.clear();
        console.log(C.b(C.g(`  WhatsApp Terminal  -  Login akun "${acc}"\n`)));
        qrcode.generate(s.ref, { small: true }, (q) => console.log(q));
        log(C.y("Scan: WhatsApp > Perangkat tertaut > Tautkan perangkat"));
        log(C.d("Ctrl+C untuk batal"));
      } else if (s.state !== lastState) {
        if (s.state === "reload") log(C.y("QR kedaluwarsa, memuat ulang..."));
        if (s.state === "wait") log(lastRef ? "QR terbaca, memuat chat..." : `Membuka WhatsApp akun "${acc}"...`);
      }
      lastState = s.state;
      await sleep(1000);
    }
    log(C.r("Waktu login habis."));
    return false;
  } finally {
    loggingIn = false;
  }
}

// ================= kirim chat =================
const SEARCH_SEL = [
  "#side div[contenteditable='true'][role='textbox']",
  "#side input[role='textbox']",
  "#side input[type='text']",
  "#side div[contenteditable='true']",
];
const COMPOSE_SEL = [
  "#main footer div[contenteditable='true'][role='textbox']",
  "#main footer div[contenteditable='true']",
];

async function firstSel(page, list, timeout = 15000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    for (const sel of list) {
      const el = await page.$(sel);
      if (el) return el;
    }
    await sleep(300);
  }
  return null;
}

async function openByPhone(page, phone) {
  const digits = normPhone(phone);
  await page.goto(`${WA_URL}/send?phone=${digits}`, { waitUntil: "domcontentloaded", timeout: 90000 });
  const deadline = Date.now() + 60000;
  while (Date.now() < deadline) {
    const r = await page
      .evaluate((compose) => {
        if (compose.some((s) => document.querySelector(s))) return "ok";
        const dlg = document.querySelector("[role='dialog'], div[data-animate-modal-popup='true']");
        if (dlg && /invalid|tidak valid|tidak terdaftar|not on whatsapp/i.test(dlg.textContent)) {
          const b = dlg.querySelector("button");
          if (b) b.click();
          return "invalid";
        }
        return "wait";
      }, COMPOSE_SEL)
      .catch(() => "wait");
    if (r === "ok") return true;
    if (r === "invalid") throw new Error("nomor tidak terdaftar di WhatsApp");
    await sleep(500);
  }
  throw new Error("gagal membuka chat (timeout)");
}

async function openByName(page, name) {
  const box = await firstSel(page, SEARCH_SEL);
  if (!box) throw new Error("kotak pencarian tidak ditemukan");
  await box.click();
  const mod = process.platform === "darwin" ? "Meta" : "Control";
  await page.keyboard.down(mod);
  await page.keyboard.press("KeyA");
  await page.keyboard.up(mod);
  await page.keyboard.press("Backspace");
  await page.keyboard.type(name, { delay: 30 });

  const want = name.toLowerCase();
  const deadline = Date.now() + 10000;
  while (Date.now() < deadline) {
    await sleep(700);
    let partial = null;
    for (const t of await page.$$("#pane-side span[title]")) {
      const title = ((await t.evaluate((e) => e.getAttribute("title"))) || "").toLowerCase();
      if (title === want) {
        await t.click();
        return verifyOpened(page);
      }
      if (!partial && title.includes(want)) partial = t;
    }
    if (partial) {
      await partial.click();
      return verifyOpened(page);
    }
  }
  throw new Error("kontak/grup tidak ditemukan");
}

async function verifyOpened(page) {
  if (!(await firstSel(page, COMPOSE_SEL, 10000))) throw new Error("chat tidak bisa dibuka (grup khusus admin?)");
  return true;
}

async function sendText(page, text) {
  const box = await firstSel(page, COMPOSE_SEL, 10000);
  if (!box) throw new Error("kolom pesan tidak ditemukan");
  await box.click();
  const lines = text.split("\n");
  for (let i = 0; i < lines.length; i++) {
    if (lines[i]) await page.keyboard.type(lines[i], { delay: 15 });
    if (i < lines.length - 1) {
      await page.keyboard.down("Shift");
      await page.keyboard.press("Enter");
      await page.keyboard.up("Shift");
    }
  }
  await sleep(300);
  await page.keyboard.press("Enter");
  const deadline = Date.now() + 8000;
  while (Date.now() < deadline) {
    if (await box.evaluate((e) => !e.textContent.trim()).catch(() => true)) return true;
    await sleep(250);
  }
  throw new Error("pesan tidak terkirim");
}

let currentTarget = null;
async function sendTo(page, to, text) {
  const open = currentTarget === to && (await page.$(COMPOSE_SEL[1]));
  if (!open) {
    currentTarget = null;
    if (isPhone(to)) await openByPhone(page, to);
    else await openByName(page, to);
    currentTarget = to;
  }
  await sendText(page, text);
}

// Tunggu sambil tetap responsif terhadap Ctrl+C
async function waitAbortable(ms, label) {
  const end = Date.now() + ms;
  if (label) log(C.d(label));
  while (Date.now() < end && !abortSend) await sleep(Math.min(500, end - Date.now()));
}

// ================= template & variabel =================
const TPL_FILE = path.join(HOME_DIR, "templates.json");
const BUILTIN_TPL = [
  {
    title: "Sapaan pelanggan",
    text: "Halo {nama}, terima kasih sudah menghubungi {toko} 😊\\nAda yang bisa kami bantu?",
  },
  {
    title: "Konfirmasi pesanan",
    text: "Halo {nama}, pesanan *{produk}* sudah kami terima dan sedang diproses 🙏\\nTotal: *{total}*\\nTerima kasih sudah belanja di {toko}!",
  },
  {
    title: "Info resi pengiriman",
    text: "Halo {nama}, pesanan kamu sudah dikirim 📦\\nKurir: {kurir}\\nNo. resi: *{resi}*\\nTerima kasih sudah belanja di {toko}!",
  },
  {
    title: "Pengingat pembayaran",
    text: "Halo {nama}, kami ingin mengingatkan pembayaran pesanan *{produk}* sebesar *{total}* belum kami terima.\\nMohon diselesaikan sebelum {batas} ya. Terima kasih 🙏",
  },
  {
    title: "Promo",
    text: "Halo {nama}! 🎉 Ada promo spesial dari {toko}:\\n*{promo}*\\nBerlaku sampai {batas}. Yuk cek sekarang!",
  },
  {
    title: "Restock / stok baru",
    text: "Halo {nama}, *{produk}* sudah ready stock lagi ✨\\nStok terbatas, silakan order sebelum kehabisan ya!",
  },
  {
    title: "Terima kasih & minta ulasan",
    text: "Halo {nama}, terima kasih sudah belanja di {toko} 🙏\\nSemoga pesanannya sesuai harapan. Kalau berkenan, bantu kami dengan ulasan bintang 5 ya ⭐",
  },
  {
    title: "Follow-up pelanggan",
    text: "Halo {nama}, kami mau follow up pertanyaan kamu tentang *{produk}*.\\nMasih berminat? Kami siap bantu 😊",
  },
];

function loadUserTpl() {
  try {
    return JSON.parse(fs.readFileSync(TPL_FILE, "utf8"));
  } catch {
    return [];
  }
}
function saveUserTpl(list) {
  fs.mkdirSync(HOME_DIR, { recursive: true });
  fs.writeFileSync(TPL_FILE, JSON.stringify(list, null, 2));
}

// Variabel yang terisi otomatis per penerima / waktu
const AUTO_VARS = ["nama", "nomor", "to", "n", "time", "date"];
const varsOf = (text) => [...new Set([...String(text).matchAll(/\{(\w+)\}/g)].map((m) => m[1].toLowerCase()))];
const askVars = (text) => varsOf(text).filter((v) => !AUTO_VARS.includes(v));

const targetOf = (m) => m.nomor || m.nama;
const labelOf = (m) => (m.nama && m.nomor ? `${m.nama} (${m.nomor})` : m.nama || m.nomor);
const keyOf = (m) => m.nomor || "n:" + m.nama.toLowerCase();

function fill(text, m, values = {}, n = 1) {
  const d = new Date();
  const auto = {
    nama: m.nama || "Kak",
    nomor: m.nomor || "",
    to: m.nama || m.nomor,
    n: String(n),
    time: d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" }),
    date: d.toLocaleDateString("id-ID"),
  };
  return String(text)
    .replace(/\\n/g, "\n")
    .replace(/\{(\w+)\}/g, (_, k) => {
      const key = k.toLowerCase();
      if (m.data && m.data[key]) return m.data[key]; // kolom file penerima
      if (key in auto) return auto[key];
      return values[key] || "";
    });
}

// Variabel yang belum punya isi untuk penerima tertentu
const missingFor = (text, m, values = {}) => askVars(text).filter((v) => !(m.data && m.data[v]) && !values[v]);

// ================= kirim =================
async function sendCampaign(page, acc, members, msg, s) {
  const rounds = Number(s.count);
  const failed = new Map(); // key -> {m, why}
  let ok = 0;

  console.clear();
  console.log(C.b(C.g(`\n  Mengirim "${msg.title}" dari akun "${acc}"`)));
  log(
    C.c(
      `${members.length} penerima · ${rounds === 0 ? "tanpa batas" : rounds + " putaran"} · ` +
        `jeda ${s.jeda}s · interval ${s.interval}s`,
    ),
  );
  console.log(C.y("  Ctrl+C untuk berhenti.\n"));

  sending = true;
  abortSend = false;
  currentTarget = null;

  for (let round = 1; (rounds === 0 || round <= rounds) && !abortSend; round++) {
    const live = members.filter((m) => !failed.has(keyOf(m)));
    if (!live.length) {
      log(C.r("Semua penerima gagal, pengiriman dihentikan."));
      break;
    }
    if (rounds !== 1) log(C.b(`Putaran ${round}${rounds ? "/" + rounds : ""}`));

    for (let i = 0; i < live.length && !abortSend; i++) {
      const m = live[i];
      const text = fill(msg.text, m, msg.values, round);
      try {
        await sendTo(page, targetOf(m), text);
        ok++;
        log(C.g(`✔ (${i + 1}/${live.length}) ${labelOf(m)}: `) + text.split("\n")[0].slice(0, 45));
      } catch (e) {
        log(C.r(`✖ (${i + 1}/${live.length}) ${labelOf(m)}: ${e.message}`));
        currentTarget = null;
        failed.set(keyOf(m), { m, why: e.message });
      }
      if (i < live.length - 1) await waitAbortable(jitter(s.jeda));
    }

    const more = rounds === 0 || round < rounds;
    if (more && !abortSend) {
      const ms = jitter(s.interval);
      await waitAbortable(ms, `Putaran berikutnya ${new Date(Date.now() + ms).toLocaleTimeString("id-ID")}...`);
    }
  }
  sending = false;

  console.log("");
  log(abortSend ? C.y("Pengiriman dihentikan.") : C.b(C.g("Selesai.")));
  log(`Terkirim: ${C.g(ok)}   Gagal: ${failed.size ? C.r(failed.size) : 0}`);
  if (failed.size) {
    const d = new Date();
    const stamp = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 19)
      .replace(/[:T]/g, "-");
    const file = path.join(accPath(acc), `gagal-${stamp}.csv`);
    const rows = [...failed.values()].map(({ m, why }) => [m.nama, m.nomor, why].map((c) => `"${c || ""}"`).join(","));
    fs.writeFileSync(file, "nama,nomor,alasan\n" + rows.join("\n") + "\n");
    log(C.d("Daftar gagal disimpan (bisa diimpor ulang): " + file));
  }
}

// ================= logout =================
async function clickText(page, re) {
  return page
    .evaluate((src) => {
      const rx = new RegExp(src, "i");
      for (const el of document.querySelectorAll(
        "button, [role='button'], [role='menuitem'], li, div[tabindex], span",
      )) {
        const t = (el.innerText || el.getAttribute("aria-label") || "").trim();
        if (t && rx.test(t) && el.offsetParent !== null) {
          (el.closest("button, [role='button'], [role='menuitem'], li") || el).click();
          return true;
        }
      }
      return false;
    }, re.source)
    .catch(() => false);
}

async function waLogout(page) {
  const LOGOUT = /^(keluar|log ?out|logout)$/;
  const openers = [
    "[data-icon='menu']",
    "[data-icon='more-refreshed']",
    "[aria-label='Menu']",
    "[aria-label='Lainnya']",
    "[aria-label='Setelan']",
    "[aria-label='Settings']",
    "[data-icon='settings-refreshed']",
  ];
  for (const sel of openers) {
    const el = await page.$(sel);
    if (!el) continue;
    await el.click().catch(() => {});
    await sleep(1000);
    if (await clickText(page, LOGOUT)) {
      await sleep(1000);
      await clickText(page, LOGOUT); // konfirmasi
      await sleep(3000);
      return true;
    }
    await page.keyboard.press("Escape");
    await sleep(300);
  }
  return false;
}

// ================= input terminal =================
// prefill: teks awal yang sudah terketik dan bisa diedit (untuk ubah template)
function ask(q, prefill = "") {
  return new Promise((resolve) => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    rl.on("SIGINT", () => {
      rl.close();
      onCtrlC();
    });
    rl.question(q, (a) => {
      rl.close();
      resolve(a.trim());
    });
    if (prefill) rl.write(prefill);
  });
}
const pause = () => ask(C.c("\n  Tekan Enter untuk kembali ke menu..."));
const LINE = C.c("  " + "─".repeat(56));

function expandPath(p) {
  p = p.trim().replace(/^['"]|['"]$/g, ""); // hasil drag & drop file ke terminal
  if (p.startsWith("file://")) p = decodeURIComponent(p.slice(7));
  if (p.startsWith("~")) p = path.join(os.homedir(), p.slice(1));
  return p;
}

// ================= sesi (akun aktif) =================
const session = { acc: null, page: null, gui: false, ready: false };

async function openAccount(acc) {
  if (inUse(acc)) throw new Error(`Akun "${acc}" sedang dipakai terminal lain.`);
  await closeBrowser();
  session.ready = false;
  if (session.acc && session.acc !== acc) unlockAcc(session.acc);
  lockAcc(acc);
  session.acc = acc;
  session.page = await launch(profileOf(acc), session.gui);
  if (!(await ensureLogin(session.page, acc))) {
    await closeBrowser();
    throw new Error(abortLogin ? `Login akun "${acc}" dibatalkan.` : `Login akun "${acc}" gagal / waktu habis.`);
  }
  session.ready = true;
  fs.writeFileSync(loginMark(acc), new Date().toISOString());
  fs.writeFileSync(LAST_FILE, acc);
  await sleep(800);
}

function header(sub) {
  console.clear();
  const acc = session.acc && session.ready ? `   akun: ${C.b(session.acc)} ${C.g("● terhubung")}` : "";
  console.log(C.b(C.g("\n  WhatsApp Terminal")) + C.c(`  ›  ${sub}`) + acc);
  console.log(LINE);
}

function summary(s) {
  if (!s.groups.length) return "belum ada grup kirim";
  const total = s.groups.reduce((a, g) => a + g.members.length, 0);
  return `${s.groups.length} grup kirim · ${total} penerima`;
}

// Tampilkan teks pesan dengan variabel disorot
function showText(text, indent = "     ") {
  String(text)
    .replace(/\\n/g, "\n")
    .split("\n")
    .forEach((l) => console.log(indent + l.replace(/\{(\w+)\}/g, (m) => C.y(m))));
}

function dedupeMembers(list) {
  const seen = new Map();
  for (const m of list) {
    const k = keyOf(m);
    if (!seen.has(k)) seen.set(k, m);
    else {
      // gabungkan info (mis. nama dari file kedua)
      const o = seen.get(k);
      if (!o.nama && m.nama) o.nama = m.nama;
      o.data = { ...m.data, ...o.data };
    }
  }
  return [...seen.values()];
}

// Baca .txt/.csv: kolom nomor wajib, kolom nama & kolom lain (resi, produk, ...) opsional.
// Kolom lain bisa dipakai di pesan sebagai {namakolom}.
function parseRecipients(text) {
  const lines = String(text)
    .replace(/^﻿/, "")
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);
  const split = (l) => l.split(/[,;\t|]/).map((c) => c.trim().replace(/^"|"$/g, ""));
  const isPhoneCell = (c) => /^[+\d][\d\s\-().]*$/.test(c) && normPhone(c).length >= 9 && normPhone(c).length <= 15;
  const members = [];
  let bad = 0;
  if (!lines.length) return { members, bad, columns: [] };

  const first = split(lines[0]);
  const header = !first.some(isPhoneCell) && lines.length > 1;
  let columns = [];
  if (header) {
    const h = first.map((c) => c.toLowerCase().replace(/[^a-z0-9_]/g, ""));
    let iNo = h.findIndex((c) =>
      /^(no|nomor|nomer|nohp|hp|phone|telp|telepon|wa|nowa|whatsapp|handphone|nomorhp|nomorwa)$/.test(c),
    );
    const iNama = h.findIndex((c) => /^(nama|name|customer|pelanggan|namapelanggan|penerima)$/.test(c));
    if (iNo < 0) iNo = split(lines[1]).findIndex(isPhoneCell);
    columns = h.filter((c, i) => i !== iNo && i !== iNama && c);
    for (const l of lines.slice(1)) {
      const cells = split(l);
      const no = cells[iNo] || "";
      if (!isPhoneCell(no)) {
        bad++;
        continue;
      }
      const data = {};
      h.forEach((c, i) => {
        if (i !== iNo && i !== iNama && c && cells[i]) data[c] = cells[i];
      });
      members.push({ nama: iNama >= 0 ? cells[iNama] || "" : "", nomor: normPhone(no), data });
    }
  } else {
    for (const l of lines) {
      const cells = split(l);
      const phones = cells.filter(isPhoneCell);
      if (!phones.length) {
        const p = parsePhones(l).phones; // mis. "0812 0813" dalam satu baris
        if (p.length) p.forEach((n) => members.push({ nama: "", nomor: n, data: {} }));
        else bad++;
        continue;
      }
      const nama = cells.find((c) => c && !isPhoneCell(c)) || "";
      phones.forEach((p) => members.push({ nama: phones.length === 1 ? nama : "", nomor: normPhone(p), data: {} }));
    }
  }
  return { members: dedupeMembers(members), bad, columns };
}

async function showMembers(list, title) {
  console.log("\n  " + C.b(title));
  list.forEach((m, i) => {
    const extra = Object.entries(m.data || {})
      .map(([k, v]) => `${k}=${v}`)
      .join(" ");
    console.log(`   ${String(i + 1).padStart(3)}. ${labelOf(m)} ${C.d(extra)}`);
  });
  await pause();
}

function parseIndexes(v, max) {
  const out = new Set();
  for (const part of String(v).split(/[,\s]+/)) {
    const r = part.match(/^(\d+)-(\d+)$/);
    if (r) for (let i = Number(r[1]); i <= Number(r[2]); i++) out.add(i);
    else if (/^\d+$/.test(part)) out.add(Number(part));
  }
  return [...out].filter((n) => n >= 1 && n <= max);
}

// ================= editor grup kirim =================
async function groupEditor(s, g) {
  let info = "";
  while (true) {
    header(`Grup kirim: ${g.name}`);
    console.log(`   Penerima : ${C.b(g.members.length)} ${C.d(preview(g.members.map(labelOf), 3))}`);
    const cols = [...new Set(g.members.flatMap((m) => Object.keys(m.data || {})))];
    if (cols.length)
      console.log(`   Kolom    : ${cols.map((c) => C.y(`{${c}}`)).join(" ")} ${C.d("bisa dipakai di pesan")}`);
    console.log(LINE);
    console.log("   1. Tambah nomor (ketik)");
    console.log("   2. Tambah kontak tersimpan (nama di WhatsApp)");
    console.log("   3. Impor dari file .txt / .csv");
    console.log("   4. Lihat penerima");
    console.log("   5. Hapus sebagian penerima");
    console.log("   6. Ganti nama grup");
    console.log("   0. Selesai");
    console.log(LINE);
    if (info) console.log("  " + info + "\n");
    info = "";

    const pick = await ask("  Pilih [0-6]: ");
    const before = g.members.length;
    if (pick === "1") {
      console.log(C.d("  Pisahkan koma/spasi. 08xx otomatis jadi 628xx."));
      const { phones, bad } = parsePhones(await ask("  Nomor: "));
      g.members = dedupeMembers([...g.members, ...phones.map((n) => ({ nama: "", nomor: n, data: {} }))]);
      info = C.g(`✔ +${g.members.length - before} nomor`) + (bad.length ? C.y(` · ${bad.length} tidak valid`) : "");
    } else if (pick === "2") {
      console.log(C.d("  Tulis persis seperti nama kontak di WhatsApp, pisahkan koma. Contoh: Budi, Siti Rahma"));
      const names = parseNames(await ask("  Nama kontak: "));
      g.members = dedupeMembers([...g.members, ...names.map((n) => ({ nama: n, nomor: "", data: {} }))]);
      info = C.g(`✔ +${g.members.length - before} kontak`);
    } else if (pick === "3") {
      console.log(C.d("  Satu penerima per baris. CSV boleh punya header, mis.: nama,nomor,resi,produk"));
      console.log(C.d("  Kolom selain nama & nomor bisa dipakai di pesan sebagai {resi}, {produk}, dst."));
      const p = expandPath(await ask("  Path file (bisa drag & drop): "));
      if (!p) continue;
      if (!fs.existsSync(p)) {
        info = C.r("✖ File tidak ditemukan: " + p);
        continue;
      }
      const r = parseRecipients(fs.readFileSync(p, "utf8"));
      if (!r.members.length) {
        info = C.r("✖ Tidak ada nomor valid di file");
        continue;
      }
      if (
        g.members.length &&
        (await ask(`  Grup sudah berisi ${g.members.length}. Tambahkan (t) atau ganti (g)? [t/g]: `)).toLowerCase() ===
          "g"
      )
        g.members = [];
      const mid = g.members.length;
      g.members = dedupeMembers([...g.members, ...r.members]);
      info =
        C.g(`✔ ${g.members.length - mid} penerima diimpor`) +
        (r.columns.length ? C.c(` · kolom: ${r.columns.map((c) => `{${c}}`).join(" ")}`) : "") +
        (r.bad ? C.y(` · ${r.bad} baris dilewati`) : "");
    } else if (pick === "4") {
      if (!g.members.length) info = C.y("Grup masih kosong");
      else await showMembers(g.members, `Penerima "${g.name}"`);
    } else if (pick === "5") {
      if (!g.members.length) {
        info = C.y("Grup masih kosong");
        continue;
      }
      g.members.forEach((m, i) => console.log(`   ${String(i + 1).padStart(3)}. ${labelOf(m)}`));
      const idx = new Set(parseIndexes(await ask("  Nomor urut yang dihapus (contoh 1,3,5-8): "), g.members.length));
      g.members = g.members.filter((_, i) => !idx.has(i + 1));
      info = C.g(`✔ ${idx.size} penerima dihapus`);
    } else if (pick === "6") {
      const n = (await ask("  Nama grup baru: ")).trim();
      if (!n) continue;
      if (s.groups.some((x) => x !== g && x.name.toLowerCase() === n.toLowerCase())) info = C.r("✖ Nama sudah dipakai");
      else {
        s.selected = s.selected.map((x) => (x === g.name ? n : x));
        g.name = n;
        info = C.g("✔ Nama diganti");
      }
    } else if (pick === "0") return;
    else if (pick !== "") info = C.r("✖ Pilihan tidak dikenal");
    saveSettings(session.acc, s);
  }
}

// ================= layar 1: akun =================
// Hasil: nama akun terpilih, atau null untuk keluar
async function accountScreen(flash = "") {
  let info = flash;
  while (true) {
    const accs = listAccounts();
    header("Pilih akun pengirim");
    if (!accs.length) console.log(C.y("   Belum ada akun. Ketik t untuk menambah akun."));
    accs.forEach((a, i) => {
      const tags = [];
      if (a === session.acc && session.ready) tags.push(C.g("● aktif"));
      else if (inUse(a)) tags.push(C.y("sedang dipakai terminal lain"));
      else if (!loggedBefore(a)) tags.push(C.y("belum login"));
      const s = loadSettings(a);
      console.log(`   ${i + 1}. ${C.b(a.padEnd(14))} ${C.d(summary(s))}  ${tags.join("  ")}`);
    });
    console.log(LINE);
    console.log("   t. Tambah akun baru");
    if (accs.length) console.log("   h. Hapus akun");
    if (session.acc && session.ready) console.log(`   l. Logout akun aktif (${session.acc})`);
    console.log("   w. Mode WMS (kirim notifikasi dari JERSIC.ID WMS)");
    console.log("   0. Keluar");
    console.log(LINE);
    if (info) console.log("  " + info + "\n");
    info = "";

    const range = accs.length ? `[1-${accs.length}] / ` : "";
    const lo = session.acc && session.ready ? " / l" : "";
    const pick = (await ask(`  Pilih ${range}t${accs.length ? " / h" : ""}${lo} / 0: `)).toLowerCase();

    if (pick === "0") return null;
    if (pick === "w") return "__wms__";
    if (pick === "l" && lo) {
      if ((await ask(C.y(`  Logout akun "${session.acc}" dari WhatsApp? (y/n): `))).toLowerCase() === "y") {
        console.log("  Logout...");
        const unlinked = await waLogout(session.page);
        await closeBrowser();
        session.ready = false;
        fs.rmSync(profileOf(session.acc), { recursive: true, force: true });
        fs.rmSync(loginMark(session.acc), { force: true });
        info = unlinked
          ? C.g(`✔ Akun "${session.acc}" logout. Pilih akun itu lagi untuk login ulang.`)
          : C.y(`Sesi lokal "${session.acc}" dihapus. Hapus juga perangkatnya di HP: Perangkat tertaut.`);
      }
      continue;
    }
    if (pick === "t") {
      const name = cleanName(await ask("  Nama akun baru (contoh: toko1, pribadi): "));
      if (!name) info = C.r("✖ Nama tidak valid");
      else if (accs.includes(name)) info = C.r(`✖ Akun "${name}" sudah ada`);
      else {
        fs.mkdirSync(accPath(name), { recursive: true });
        return name;
      }
      continue;
    }
    if (pick === "h" && accs.length) {
      const a = accs[Number(await ask("  Nomor akun yang dihapus: ")) - 1];
      if (!a) info = C.r("✖ Pilihan tidak ada");
      else if (a === session.acc && session.ready) info = C.r("✖ Akun aktif tidak bisa dihapus. Pilih akun lain dulu.");
      else if (inUse(a)) info = C.r("✖ Akun sedang dipakai terminal lain");
      else if ((await ask(C.y(`  Hapus akun "${a}" beserta sesi & tujuannya? (y/n): `))).toLowerCase() === "y") {
        fs.rmSync(accPath(a), { recursive: true, force: true });
        if (a === session.acc) session.acc = null;
        info = C.g(`✔ Akun "${a}" dihapus. Hapus juga perangkatnya di HP (Perangkat tertaut).`);
      }
      continue;
    }
    const a = accs[Number(pick) - 1];
    if (!a) info = C.r("✖ Pilihan tidak dikenal");
    else if (inUse(a)) info = C.r(`✖ Akun "${a}" sedang dipakai terminal lain`);
    else return a;
  }
}

// ================= layar 2: pesan =================
// Isi variabel yang tidak otomatis ({toko}, {produk}, ...). Nilai terakhir jadi bawaan.
async function fillValues(s, text) {
  const values = {};
  const vars = askVars(text);
  if (!vars.length) return values;
  console.log(
    C.c("\n  Isi variabel pesan. Enter = pakai nilai dalam [ ], '-' = kosong / ambil dari kolom file penerima"),
  );
  for (const v of vars) {
    const def = s.vars[v] || "";
    const a = await ask(`  {${v}}${def ? C.d(` [${def}]`) : ""}: `);
    const val = a === "-" ? "" : a || def;
    values[v] = val;
    if (val) s.vars[v] = val;
  }
  return values;
}

// Hasil: "akun" atau "tujuan"
async function messageScreen() {
  const s = loadSettings(session.acc);
  let info = s.message
    ? C.c(`Enter = pakai pesan terakhir: "${s.message.title}"`)
    : C.c("Pilih template atau tulis custom chat.");
  while (true) {
    const user = loadUserTpl();
    const all = [...BUILTIN_TPL.map((t) => ({ ...t })), ...user.map((t) => ({ ...t, mine: true }))];
    header("1/3 Pilih pesan");
    console.log(C.b("   Template:"));
    all.forEach((t, i) => {
      const first = t.text.replace(/\\n/g, " ").slice(0, 34);
      console.log(
        `   ${String(i + 1).padStart(2)}. ${(t.mine ? C.c("★ ") : "") + t.title.padEnd(t.mine ? 26 : 28)} ${C.d(first + "…")}`,
      );
    });
    console.log(LINE);
    console.log(`    c. ${C.g("Custom chat")} (tulis sendiri)`);
    console.log("    t. Buat template baru       h. Hapus template ★ milikmu");
    console.log("    0. Kembali ke daftar akun");
    console.log(LINE);
    if (s.message) console.log(`   Pesan terakhir: ${C.b(s.message.title)}`);
    if (info) console.log("  " + info + "\n");
    info = "";

    const pick = (await ask(`  Pilih [1-${all.length}] / c / t / h / 0: `)).toLowerCase();

    let chosen = null;
    if (pick === "" && s.message) {
      saveSettings(session.acc, s);
      return "tujuan";
    } else if (pick === "0") return "akun";
    else if (pick === "c") {
      console.log(C.c("\n  Variabel: {nama} nama penerima, {toko}/{produk}/... diisi sesudahnya, \\n baris baru"));
      const text = await ask("  Isi chat: ");
      if (!text) continue;
      chosen = { title: "Custom", text };
      const title = await ask(C.d("  Simpan sebagai template? Ketik judulnya (Enter = tidak): "));
      if (title) {
        saveUserTpl([...user, { title, text }]);
        chosen.title = title;
      }
    } else if (pick === "t") {
      const title = await ask("  Judul template: ");
      if (!title) continue;
      console.log(C.c("  Variabel: {nama} nama penerima, {produk}, {resi}, ... bebas; \\n baris baru"));
      const text = await ask("  Isi template: ");
      if (!text) continue;
      saveUserTpl([...user, { title, text }]);
      info = C.g(`✔ Template "${title}" disimpan`);
      continue;
    } else if (pick === "h") {
      if (!user.length) {
        info = C.y("Belum ada template milikmu");
        continue;
      }
      const n = Number(await ask(`  Nomor template ★ yang dihapus [${BUILTIN_TPL.length + 1}-${all.length}]: `));
      const idx = n - BUILTIN_TPL.length - 1;
      if (idx < 0 || idx >= user.length) info = C.r("✖ Hanya template ★ milikmu yang bisa dihapus");
      else {
        const [del] = user.splice(idx, 1);
        saveUserTpl(user);
        info = C.g(`✔ Template "${del.title}" dihapus`);
      }
      continue;
    } else {
      const t = all[Number(pick) - 1];
      if (!t) {
        info = C.r("✖ Pilihan tidak dikenal");
        continue;
      }
      chosen = { title: t.title, text: t.text };
    }

    // pratinjau + ubah teks + isi variabel
    while (true) {
      header(`1/3 Pesan: ${chosen.title}`);
      showText(chosen.text);
      console.log(LINE);
      const a = (await ask("  Enter = pakai, e = ubah teks, 0 = batal: ")).toLowerCase();
      if (a === "0") {
        chosen = null;
        break;
      }
      if (a === "e") {
        const t = await ask("  Teks: ", chosen.text);
        if (t) chosen.text = t;
        continue;
      }
      break;
    }
    if (!chosen) continue;

    chosen.values = await fillValues(s, chosen.text);
    s.message = chosen;
    saveSettings(session.acc, s);

    header(`1/3 Pratinjau: ${chosen.title}`);
    showText(fill(chosen.text, { nama: "Budi", nomor: "6281234567890", data: {} }, chosen.values));
    console.log(LINE);
    console.log(C.d("  Contoh untuk penerima bernama Budi. Variabel kosong akan diambil dari kolom file penerima."));
    const go = (await ask("  Enter = lanjut pilih tujuan, 0 = pilih ulang pesan: ")).toLowerCase();
    if (go !== "0") return "tujuan";
  }
}

// ================= layar 3: tujuan =================
// Hasil: { next: "pesan" } atau { next: "kirim", members, label }
async function targetScreen() {
  const s = loadSettings(session.acc);
  let info = C.c("Centang satu atau beberapa grup kirim (contoh: 1 atau 1,3), lalu l untuk lanjut.");
  while (true) {
    s.selected = s.selected.filter((n) => s.groups.some((g) => g.name === n));
    const sel = s.groups.filter((g) => s.selected.includes(g.name));
    const members = dedupeMembers(sel.flatMap((g) => g.members.map((m) => ({ ...m, data: { ...m.data } }))));

    header("2/3 Pilih tujuan");
    console.log(`   Pesan  : ${C.b(s.message ? s.message.title : "-")}`);
    console.log(LINE);
    console.log(C.b("   Grup kirim:"));
    if (!s.groups.length) console.log(C.y("   (belum ada) ketik b untuk membuat grup kirim pertama"));
    s.groups.forEach((g, i) => {
      const on = s.selected.includes(g.name);
      console.log(
        `   ${String(i + 1).padStart(2)}. ${on ? C.g("[x]") : "[ ]"} ${C.b(g.name.padEnd(22))} ${C.d(g.members.length + " penerima")}`,
      );
    });
    console.log(LINE);
    console.log(
      `   Dipilih: ${members.length ? C.b(C.g(`${sel.length} grup · ${members.length} penerima`)) : C.y("belum ada")}`,
    );
    console.log(LINE);
    console.log("    b. Buat grup kirim baru    e. Edit grup    h. Hapus grup");
    console.log("    s. Kirim satuan (ketik nomor tanpa membuat grup)");
    console.log(`    l. ${C.g("Lanjut ▶")}                  0. Kembali ke pesan`);
    console.log(LINE);
    if (info) console.log("  " + info + "\n");
    info = "";

    const pick = (await ask("  Pilih: ")).toLowerCase();

    if (pick === "0") {
      saveSettings(session.acc, s);
      return { next: "pesan" };
    } else if (pick === "l") {
      if (!members.length) info = C.r("✖ Pilih minimal satu grup kirim yang berisi penerima");
      else {
        saveSettings(session.acc, s);
        return { next: "kirim", members, label: sel.map((g) => g.name).join(", ") };
      }
    } else if (pick === "b") {
      const name = (await ask("  Nama grup kirim (contoh: Pelanggan Bandung, Reseller): ")).trim();
      if (!name) continue;
      if (s.groups.some((g) => g.name.toLowerCase() === name.toLowerCase())) {
        info = C.r("✖ Nama sudah dipakai");
        continue;
      }
      const g = { name, members: [] };
      s.groups.push(g);
      s.selected.push(name);
      saveSettings(session.acc, s);
      await groupEditor(s, g);
      info = C.g(`✔ Grup "${name}" (${g.members.length} penerima) dibuat dan dicentang`);
    } else if (pick === "e" || pick === "h") {
      if (!s.groups.length) {
        info = C.y("Belum ada grup kirim");
        continue;
      }
      const g = s.groups[Number(await ask(`  Nomor grup [1-${s.groups.length}]: `)) - 1];
      if (!g) info = C.r("✖ Pilihan tidak ada");
      else if (pick === "e") await groupEditor(s, g);
      else if (
        (await ask(C.y(`  Hapus grup kirim "${g.name}" (${g.members.length} penerima)? (y/n): `))).toLowerCase() === "y"
      ) {
        s.groups = s.groups.filter((x) => x !== g);
        info = C.g(`✔ Grup "${g.name}" dihapus`);
      }
    } else if (pick === "s") {
      console.log(C.d("  Pisahkan koma/spasi. Nama kontak juga boleh, contoh: 081234567890, Budi"));
      const raw = await ask("  Tujuan: ");
      const items = raw
        .split(/[,;\n]+/)
        .map((x) => x.trim())
        .filter(Boolean);
      const list = [];
      for (const it of items) {
        const { phones } = parsePhones(it);
        if (phones.length) phones.forEach((n) => list.push({ nama: "", nomor: n, data: {} }));
        else list.push({ nama: it, nomor: "", data: {} });
      }
      if (!list.length) continue;
      saveSettings(session.acc, s);
      return { next: "kirim", members: dedupeMembers(list), label: "Kirim satuan" };
    } else if (pick) {
      const idx = parseIndexes(pick, s.groups.length);
      if (!idx.length) info = C.r("✖ Pilihan tidak dikenal");
      for (const i of idx) {
        const n = s.groups[i - 1].name;
        s.selected = s.selected.includes(n) ? s.selected.filter((x) => x !== n) : [...s.selected, n];
      }
    }
    saveSettings(session.acc, s);
  }
}

// ================= layar 4: kirim =================
// Hasil: "tujuan" atau "pesan"
async function sendScreen(target) {
  const s = loadSettings(session.acc);
  const msg = s.message;
  let info = "";
  while (true) {
    const lacking = target.members.filter((m) => missingFor(msg.text, m, msg.values).length);
    const lackVars = [...new Set(lacking.flatMap((m) => missingFor(msg.text, m, msg.values)))];

    header("3/3 Kirim");
    console.log(`   Pesan    : ${C.b(msg.title)}`);
    console.log(`   Tujuan   : ${C.b(target.label)} ${C.d(`(${target.members.length} penerima)`)}`);
    console.log(C.d(`   Contoh untuk ${labelOf(target.members[0])}:`));
    showText(fill(msg.text, target.members[0], msg.values), "     │ ");
    const sendList = s.skipKosong ? target.members.filter((m) => !lacking.includes(m)) : target.members;
    if (lacking.length)
      console.log(C.y(`   ⚠ ${lackVars.map((v) => `{${v}}`).join(" ")} kosong untuk ${lacking.length} penerima`));
    console.log(LINE);
    console.log(`   1. Interval  : ${C.b(s.interval + " detik")} ${C.d("antar putaran")}`);
    console.log(
      `   2. Count     : ${C.b(Number(s.count) === 0 ? "tanpa batas" : s.count + "x")} ${C.d("jumlah putaran")}`,
    );
    console.log(`   3. Jeda      : ${C.b(s.jeda + " detik")} ${C.d("antar penerima")}`);
    if (lacking.length)
      console.log(
        `   4. Data kosong: ${s.skipKosong ? C.b(`lewati ${lacking.length} penerima`) : C.y("tetap kirim tanpa isi")}`,
      );
    console.log(LINE);
    console.log(`   5. ${C.g("▶ Mulai kirim")}${C.c(`  → ${sendList.length} penerima`)}`);
    console.log("   9. Ganti pesan");
    console.log("   0. Kembali ke tujuan");
    console.log(LINE);
    if (info) console.log("  " + info + "\n");
    info = "";

    const pick = await ask(`  Pilih [0-${lacking.length ? 5 : "3, 5"}, 9]: `);
    if (pick === "1") {
      const v = Number(await ask(`  Interval antar putaran (detik, minimal ${MIN_INTERVAL}): `));
      if (!Number.isFinite(v) || v <= 0) info = C.r("✖ Masukkan angka detik");
      else {
        s.interval = Math.max(MIN_INTERVAL, Math.round(v));
        info = C.g(`✔ Interval ${s.interval} detik`) + (v < MIN_INTERVAL ? C.y(" (dinaikkan ke minimum)") : "");
      }
    } else if (pick === "2") {
      const v = Number(await ask("  Jumlah putaran (0 = terus sampai Ctrl+C): "));
      if (!Number.isInteger(v) || v < 0) info = C.r("✖ Masukkan angka bulat 0 atau lebih");
      else {
        s.count = v;
        info = C.g("✔ Count " + (v === 0 ? "tanpa batas" : v + "x"));
      }
    } else if (pick === "3") {
      const v = Number(await ask(`  Jeda antar penerima (detik, minimal ${MIN_GAP}): `));
      if (!Number.isFinite(v) || v <= 0) info = C.r("✖ Masukkan angka detik");
      else {
        s.jeda = Math.max(MIN_GAP, Math.round(v));
        info = C.g(`✔ Jeda ${s.jeda} detik`) + (v < MIN_GAP ? C.y(" (dinaikkan ke minimum)") : "");
      }
    } else if (pick === "4" && lacking.length) {
      s.skipKosong = !s.skipKosong;
      info = s.skipKosong
        ? C.g("✔ Penerima dengan data kosong dilewati")
        : C.y("Penerima dengan data kosong tetap dikirimi");
    } else if (pick === "5") {
      if (!sendList.length) {
        info = C.r("✖ Tidak ada penerima dengan data lengkap. Isi variabelnya di pesan (9) atau ubah opsi 4.");
        continue;
      }
      saveSettings(session.acc, s);
      await sendCampaign(session.page, session.acc, sendList, msg, s);
      await pause();
    } else if (pick === "9") {
      saveSettings(session.acc, s);
      return "pesan";
    } else if (pick === "0") {
      saveSettings(session.acc, s);
      return "tujuan";
    } else if (pick !== "") info = C.r("✖ Pilihan tidak dikenal");
    saveSettings(session.acc, s);
  }
}

// ================= mode WMS =================
// Ambil antrean notifikasi dari JERSIC.ID WMS, kirim lewat akun ini, lalu lapor terkirim/gagal.
// Pengaturan disimpan di ~/.wa-terminal/wms.json; bisa ditimpa env WMS_URL, WMS_TOKEN, WMS_AKUN.
const WMS_FILE = path.join(HOME_DIR, "wms.json");

function loadWms() {
  let c = {};
  try {
    c = JSON.parse(fs.readFileSync(WMS_FILE, "utf8"));
  } catch {}
  return {
    url: (process.env.WMS_URL || c.url || "").replace(/\/+$/, ""),
    token: process.env.WMS_TOKEN || c.token || "",
    akun: process.env.WMS_AKUN || c.akun || "",
    poll: Number(process.env.WMS_POLL || c.poll || 60), // detik antar cek antrean
    jeda: Number(process.env.WMS_JEDA || c.jeda || 8), // detik antar pesan
  };
}
function saveWms(c) {
  fs.mkdirSync(HOME_DIR, { recursive: true });
  fs.writeFileSync(WMS_FILE, JSON.stringify(c, null, 2), { mode: 0o600 });
}

// Tanya alamat WMS, token, dan akun pengirim (nilai lama jadi bawaan)
async function wmsSetup(c) {
  header("Mode WMS - pengaturan");
  console.log("   Token dibuat di WMS: Pengaturan > Notifikasi WhatsApp > Buat token.\n");
  const url = (await ask("  Alamat WMS (contoh https://wms.jersic.id): ", c.url)).replace(/\/+$/, "");
  const token = await ask("  Token makro (wa_...): ", c.token);
  const accs = listAccounts();
  accs.forEach((a, i) => console.log(`   ${i + 1}. ${a}`));
  const pick = await ask(`  Akun pengirim [1-${accs.length}] atau nama akun baru: `, c.akun);
  const akun = accs[Number(pick) - 1] || cleanName(pick);
  const next = { ...c, url, token, akun };
  if (!/^https?:\/\//.test(url) || !token || !akun) throw new Error("Alamat, token, dan akun wajib diisi.");
  saveWms({ url, token, akun, poll: next.poll, jeda: next.jeda });
  return next;
}

async function wmsCall(c, suffix, body) {
  const res = await fetch(`${c.url}/api/whatsapp/antrean${suffix}`, {
    method: body === undefined ? "GET" : "POST",
    headers: { Authorization: `Bearer ${c.token}`, Accept: "application/json", "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
    signal: AbortSignal.timeout(30000),
  });
  if (res.status === 401) throw new Error("token ditolak WMS (buat token baru di Pengaturan, lalu jalankan --wms --setup)");
  if (!res.ok) throw new Error(`WMS membalas HTTP ${res.status}`);
  return res.json();
}

async function wmsLoop(c) {
  if (!session.ready || session.acc !== c.akun || !browser) await openAccount(c.akun);
  console.clear();
  console.log(C.b(C.g(`\n  Mode WMS  -  akun "${c.akun}"`)) + C.d(`  ›  ${c.url}`));
  log(C.c(`Cek antrean tiap ${c.poll}s · jeda antar pesan ${c.jeda}s`));
  console.log(C.y("  Ctrl+C untuk berhenti.\n"));

  sending = true;
  abortSend = false;
  currentTarget = null;
  let sent = 0;
  let failed = 0;
  while (!abortSend) {
    try {
      // Sesi WhatsApp terputus (QR muncul lagi): minta scan ulang
      if (await session.page.$("div[data-ref]")) {
        log(C.y("WhatsApp logout, perlu scan QR lagi."));
        sending = false;
        const ok = await ensureLogin(session.page, c.akun, 600);
        sending = true;
        if (!ok) throw new Error("login ulang gagal");
      }

      const { messages = [] } = await wmsCall(c, "?limit=10");
      if (messages.length) log(C.c(`${messages.length} pesan dari WMS`));
      for (let i = 0; i < messages.length && !abortSend; i++) {
        const m = messages[i];
        try {
          await sendTo(session.page, m.to, m.message);
          await wmsCall(c, `/${m.id}/terkirim`, {});
          sent++;
          log(C.g(`✔ ${m.to}: `) + String(m.message).split("\n")[0].slice(0, 45));
        } catch (e) {
          currentTarget = null;
          failed++;
          log(C.r(`✖ ${m.to}: ${e.message}`));
          await wmsCall(c, `/${m.id}/gagal`, { error: e.message }).catch((err) => log(C.r("Gagal lapor ke WMS: " + err.message)));
        }
        if (i < messages.length - 1) await waitAbortable(jitter(c.jeda));
      }
    } catch (e) {
      log(C.r("WMS: " + e.message));
    }
    await waitAbortable(c.poll * 1000);
  }
  sending = false;
  log(C.y(`Mode WMS berhenti. Terkirim: ${sent}, gagal: ${failed}.`));
}

// Dari menu (w) atau baris perintah (--wms). --setup untuk mengubah pengaturan.
async function wmsMode(args = {}) {
  let c = loadWms();
  if (args.akun) c.akun = cleanName(args.akun);
  if (args.setup || !c.url || !c.token || !c.akun) {
    if (!process.stdin.isTTY) throw new Error("Pengaturan WMS belum lengkap. Isi WMS_URL, WMS_TOKEN, WMS_AKUN atau jalankan sekali: ./wa-login.sh --wms --setup");
    c = await wmsSetup(c);
  }
  if (inUse(c.akun)) throw new Error(`Akun "${c.akun}" sedang dipakai terminal lain.`);
  await wmsLoop(c);
}

// ================= main =================
(async () => {
  const args = parseArgs(process.argv.slice(2));
  session.gui = !!args.gui;
  fs.mkdirSync(HOME_DIR, { recursive: true });
  migrateOld();
  // sekali jalan: akun dari versi sebelumnya yang sudah punya sesi dianggap sudah login
  const v5 = path.join(HOME_DIR, ".v5");
  if (!fs.existsSync(v5)) {
    for (const a of listAccounts())
      if (fs.existsSync(path.join(profileOf(a), "Default"))) fs.writeFileSync(loginMark(a), "migrasi");
    fs.writeFileSync(v5, "");
  }

  if (args.wms) {
    await wmsMode(args);
    return shutdown(0);
  }

  let screen = "akun";
  let flash = "";
  let target = null;
  while (true) {
    if (screen === "akun") {
      const acc = await accountScreen(flash);
      flash = "";
      if (!acc) {
        console.log(C.y("  Menutup browser..."));
        return shutdown(0);
      }
      if (acc === "__wms__") {
        try {
          await wmsMode();
        } catch (e) {
          flash = C.r("✖ " + e.message);
        }
        continue;
      }
      if (acc !== session.acc || !session.ready || !browser) {
        try {
          await openAccount(acc);
        } catch (e) {
          flash = C.r("✖ " + e.message);
          continue;
        }
      }
      screen = "pesan";
    } else if (screen === "pesan") {
      screen = await messageScreen();
    } else if (screen === "tujuan") {
      const r = await targetScreen();
      screen = r.next;
      target = r;
    } else if (screen === "kirim") {
      screen = await sendScreen(target);
    }
  }
})().catch((e) => {
  console.error(C.r("\n  Error: " + e.message));
  shutdown(1);
});
