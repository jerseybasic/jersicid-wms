// Server kecil yang menerima QR dari ekstensi Chrome dan menggambarnya di terminal.
const http = require("http");
const qrcode = require("qrcode-terminal");

const PORT = Number(process.env.WA_QR_PORT || 8765);
const HOST = "127.0.0.1";

const C = {
  g: (s) => `\x1b[32m${s}\x1b[0m`,
  y: (s) => `\x1b[33m${s}\x1b[0m`,
  c: (s) => `\x1b[36m${s}\x1b[0m`,
  b: (s) => `\x1b[1m${s}\x1b[0m`,
};

function header() {
  console.clear();
  console.log(C.b(C.g("  WhatsApp Web  -  Login via Terminal")));
  console.log(C.c(`  server: http://${HOST}:${PORT}   (Ctrl+C untuk keluar)\n`));
}

function showQR(ref) {
  header();
  qrcode.generate(ref, { small: true }, (out) => {
    console.log(out);
    const t = new Date().toLocaleTimeString("id-ID");
    console.log(C.y(`  [${t}] Scan dengan WhatsApp > Perangkat tertaut > Tautkan perangkat`));
    console.log(C.y("  QR diperbarui otomatis tiap kali WhatsApp mengganti kode."));
  });
}

const statusText = {
  waiting: "Menunggu QR dari WhatsApp Web...",
  qr_reloading: "QR kedaluwarsa, memuat ulang otomatis...",
  logged_in: "LOGIN BERHASIL! WhatsApp Web sudah tertaut.",
};

const server = http.createServer((req, res) => {
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type");
  if (req.method === "OPTIONS") return res.end();

  if (req.method === "POST" && req.url === "/event") {
    let body = "";
    req.on("data", (c) => {
      body += c;
      if (body.length > 1e5) req.destroy();
    });
    req.on("end", () => {
      try {
        const msg = JSON.parse(body);
        if (msg.type === "qr" && typeof msg.ref === "string") {
          showQR(msg.ref);
        } else if (msg.type === "status") {
          header();
          const txt = statusText[msg.status] || msg.status;
          console.log(msg.status === "logged_in" ? C.b(C.g("  ✔ " + txt)) : C.y("  " + txt));
          if (msg.status === "logged_in" && process.env.WA_EXIT_ON_LOGIN === "1") {
            setTimeout(() => process.exit(0), 500);
          }
        }
        res.end("ok");
      } catch {
        res.statusCode = 400;
        res.end("bad json");
      }
    });
    return;
  }

  // Instance baru meminta instance lama berhenti
  if (req.method === "POST" && req.url === "/shutdown") {
    res.end("bye");
    console.log(C.y("\n  Diganti oleh instance baru, menutup server ini..."));
    setTimeout(() => process.exit(0), 100);
    return;
  }

  res.end("WA terminal QR server aktif");
});

function start() {
  server.listen(PORT, HOST, () => {
    header();
    console.log(C.y("  " + statusText.waiting));
    console.log("  Pastikan ekstensi 'WA Terminal QR' aktif dan tab web.whatsapp.com terbuka.");
    console.log("  Tips: refresh tab WhatsApp Web (F5) agar QR langsung terkirim.");
  });
}

let retried = false;
server.on("error", (e) => {
  if (e.code === "EADDRINUSE" && !retried) {
    retried = true;
    // Port terpakai: coba hentikan server WA lama, lalu ulangi
    const req = http.request(
      { host: HOST, port: PORT, path: "/shutdown", method: "POST", timeout: 1500 },
      (res) => {
        res.resume();
        console.log(C.y("  Server lama ditemukan, menggantinya..."));
        setTimeout(start, 700);
      }
    );
    req.on("error", fail);
    req.on("timeout", () => req.destroy(new Error("timeout")));
    req.end();
    return;
  }
  fail(e);
});

function fail(e) {
  console.error(`Gagal membuka port ${PORT}: ${e.message}`);
  console.error(`Port dipakai program lain. Hentikan dengan:  fuser -k ${PORT}/tcp`);
  console.error(`atau pakai port lain:  WA_QR_PORT=9000 ./wa-login.sh`);
  process.exit(1);
}

start();
