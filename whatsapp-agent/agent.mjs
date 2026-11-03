#!/usr/bin/env node
// Agen WhatsApp ringan untuk JERSIC.ID WMS.
//
// Tidak membuka browser: tersambung langsung ke WhatsApp sebagai "perangkat tertaut" (seperti WhatsApp Web)
// lewat library Baileys, jadi memorinya jauh lebih kecil dari makro Playwright.
// Semua diatur dari WMS (Pengaturan → Notifikasi WhatsApp): QR untuk scan tampil di sana, begitu juga
// status koneksi, daftar grup, dan tombol putuskan/sambung ulang. Agen cukup dibiarkan berjalan.
//
//   npm run setup   isi alamat WMS dan token (sekali saja)
//   npm start       jalankan agen

import fs from "node:fs";
import path from "node:path";
import readline from "node:readline/promises";
import { fileURLToPath } from "node:url";
import makeWASocket, { Browsers, DisconnectReason, fetchLatestBaileysVersion, useMultiFileAuthState } from "@whiskeysockets/baileys";
import pino from "pino";
import { createWms, processQueue } from "./lib.mjs";

const VERSION = "1.0.0";
const DIR = path.dirname(fileURLToPath(import.meta.url));
const CONFIG_FILE = path.join(DIR, "config.json");
const AUTH_DIR = path.join(DIR, "sesi-whatsapp");

const log = (msg) => console.log(`[${new Date().toLocaleTimeString("id-ID")}] ${msg}`);

// ---------- pengaturan ----------
function loadConfig() {
  let c = {};
  try {
    c = JSON.parse(fs.readFileSync(CONFIG_FILE, "utf8"));
  } catch {}
  return {
    url: (process.env.WMS_URL || c.url || "").replace(/\/+$/, ""),
    token: process.env.WMS_TOKEN || c.token || "",
    poll: Number(process.env.WMS_POLL || c.poll || 15), // detik antar cek antrean
    jeda: Number(process.env.WMS_JEDA || c.jeda || 4), // detik antar pesan
  };
}

async function setup() {
  const c = loadConfig();
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  console.log("\nPengaturan agen WhatsApp JERSIC.ID WMS");
  console.log("Token dibuat di WMS: Pengaturan → Notifikasi WhatsApp → Buat token.\n");
  const url = (await rl.question(`Alamat WMS${c.url ? ` [${c.url}]` : " (contoh https://wms.jersic.id)"}: `)).trim() || c.url;
  const token = (await rl.question(`Token${c.token ? " [tetap pakai token lama]" : ""}: `)).trim() || c.token;
  rl.close();
  if (!url || !token) throw new Error("Alamat WMS dan token wajib diisi.");
  fs.writeFileSync(CONFIG_FILE, JSON.stringify({ url: url.replace(/\/+$/, ""), token, poll: c.poll, jeda: c.jeda }, null, 2), { mode: 0o600 });
  console.log(`\nTersimpan di ${CONFIG_FILE}. Jalankan: npm start\n`);
}

// ---------- agen ----------
async function run() {
  const config = loadConfig();
  if (!config.url || !config.token) {
    console.error("Belum diatur. Jalankan dulu: npm run setup (atau isi env WMS_URL dan WMS_TOKEN).");
    process.exit(1);
  }
  const wms = createWms(config);

  const status = { state: "connecting", qr: null, phone: null, name: null, groups: [] };
  let sock = null;
  let busy = false;
  let retry = 0;
  let stopping = false;

  async function report() {
    try {
      const res = await wms("/api/whatsapp/agen", {
        ...status,
        version: VERSION,
        memory_mb: Math.round(process.memoryUsage().rss / 1048576),
      });
      if (res.command) await handleCommand(res.command);
    } catch (e) {
      log("WMS: " + e.message);
    }
  }

  async function handleCommand(command) {
    log(`Perintah dari WMS: ${command}`);
    if (command === "logout") {
      // Putuskan nomor ini; QR baru muncul di WMS untuk nomor lain
      const old = sock;
      sock = null;
      old?.ev.removeAllListeners("connection.update");
      try {
        await old?.logout();
      } catch {}
      fs.rmSync(AUTH_DIR, { recursive: true, force: true });
      Object.assign(status, { state: "connecting", qr: null, phone: null, name: null, groups: [] });
      setTimeout(connect, 1000);
    } else if (command === "restart") {
      restart();
    } else if (command === "refresh_groups") {
      await loadGroups();
      await report();
    }
  }

  async function loadGroups() {
    if (!sock || status.state !== "connected") return;
    try {
      const all = await sock.groupFetchAllParticipating();
      status.groups = Object.values(all)
        .map((g) => ({ id: g.id, name: g.subject || g.id }))
        .sort((a, b) => a.name.localeCompare(b.name));
    } catch (e) {
      log("Gagal memuat grup: " + e.message);
    }
  }

  function restart() {
    const old = sock;
    sock = null;
    old?.ev.removeAllListeners();
    old?.end(undefined);
    Object.assign(status, { state: "connecting", qr: null });
    setTimeout(connect, 1000);
  }

  async function connect() {
    if (stopping) return;
    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }));
    const current = makeWASocket({
      version,
      auth: state,
      browser: Browsers.ubuntu("JERSIC WMS"),
      logger: pino({ level: "silent" }),
      // Hemat memori dan kuota: tidak menarik riwayat chat, tidak tampil online
      syncFullHistory: false,
      shouldSyncHistoryMessage: () => false,
      markOnlineOnConnect: false,
      generateHighQualityLinkPreview: false,
      getMessage: async () => undefined,
    });
    sock = current;

    current.ev.on("creds.update", saveCreds);
    current.ev.on("connection.update", async (u) => {
      if (sock !== current) return;
      if (u.qr) {
        Object.assign(status, { state: "qr", qr: u.qr });
        log("Menunggu scan QR. Buka WMS → Pengaturan → Notifikasi WhatsApp.");
        await report();
      }
      if (u.connection === "open") {
        retry = 0;
        const me = current.user || {};
        Object.assign(status, { state: "connected", qr: null, phone: String(me.id || "").split(/[:@]/)[0] || null, name: me.name || null });
        log(`Tersambung sebagai ${status.phone}${status.name ? ` (${status.name})` : ""}.`);
        await loadGroups();
        await report();
      }
      if (u.connection === "close") {
        const code = u.lastDisconnect?.error?.output?.statusCode;
        sock = null;
        if (code === DisconnectReason.loggedOut) {
          // Perangkat dihapus dari HP: sesi dibuang, QR baru muncul di WMS
          log("Perangkat dikeluarkan dari WhatsApp. Scan QR lagi di WMS.");
          fs.rmSync(AUTH_DIR, { recursive: true, force: true });
          Object.assign(status, { state: "logged_out", qr: null, phone: null, name: null, groups: [] });
          await report();
          setTimeout(connect, 2000);
        } else {
          // QR kedaluwarsa tanpa di-scan: langsung buat QR baru supaya tetap tampil di WMS
          const wasQr = status.state === "qr";
          const wait = code === DisconnectReason.restartRequired || wasQr ? 1000 : Math.min(60000, 3000 * 2 ** retry++);
          Object.assign(status, { state: "connecting", qr: null });
          log(`Koneksi terputus (${code ?? "?"}), sambung ulang dalam ${Math.round(wait / 1000)} detik.`);
          setTimeout(connect, wait);
        }
      }
    });
  }

  async function sendQueue() {
    if (busy || !sock || status.state !== "connected") return;
    busy = true;
    const current = sock;
    try {
      const { sent, failed } = await processQueue({
        wms,
        groups: status.groups,
        delayMs: config.jeda * 1000,
        log,
        sendText: (jid, text) => current.sendMessage(jid, { text }),
        checkNumber: async (jid) => {
          const [r] = await current.onWhatsApp(jid.split("@")[0]);
          return Boolean(r?.exists);
        },
      });
      if (sent || failed) log(`Antrean: ${sent} terkirim, ${failed} gagal.`);
    } catch (e) {
      log("Antrean: " + e.message);
    } finally {
      busy = false;
    }
  }

  const shutdown = () => {
    stopping = true;
    log("Agen berhenti.");
    sock?.end(undefined);
    process.exit(0);
  };
  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);

  log(`Agen WhatsApp JERSIC.ID v${VERSION} · ${config.url} · cek antrean tiap ${config.poll} detik`);
  await connect();
  setInterval(report, 20000); // status ke WMS + ambil perintah
  setInterval(sendQueue, config.poll * 1000);
  setInterval(loadGroups, 30 * 60000); // grup baru ikut terdaftar
  report();
}

if (process.argv.includes("--setup")) {
  setup().catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
} else {
  run().catch((e) => {
    console.error(e);
    process.exit(1);
  });
}
