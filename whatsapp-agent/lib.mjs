// Logika agen yang tidak bergantung pada koneksi WhatsApp, supaya bisa dites tanpa HP.

/** Nomor 08xx / +62 8xx / 628xx → 628xx (hanya angka) */
export function normalizePhone(value) {
  const digits = String(value).replace(/\D/g, "");
  return digits.startsWith("0") ? "62" + digits.slice(1) : digits;
}

export function isPhone(value) {
  const text = String(value).trim();
  const digits = text.replace(/\D/g, "");
  return /^\+?[\d\s-]+$/.test(text) && digits.length >= 9 && digits.length <= 15;
}

const groupKey = (name) => String(name).trim().replace(/\s+/g, " ").toLowerCase();

/**
 * Tujuan pesan dari antrean WMS → JID WhatsApp.
 * Nomor menjadi chat pribadi; selain itu dianggap nama grup dan dicocokkan persis
 * (huruf besar/kecil dan spasi ganda diabaikan) dengan grup yang diikuti akun ini.
 */
export function toJid(to, groups = []) {
  const target = String(to ?? "").trim();
  if (/@(g\.us|s\.whatsapp\.net)$/.test(target)) return target;
  if (isPhone(target)) return `${normalizePhone(target)}@s.whatsapp.net`;

  const found = groups.find((g) => groupKey(g.name) === groupKey(target));
  if (!found) {
    throw new Error(`grup "${target}" tidak ditemukan. Nama harus sama persis dan nomor pengirim harus anggota grup.`);
  }
  return found.id;
}

/** Klien API WMS (token dari Pengaturan → Notifikasi WhatsApp) */
export function createWms({ url, token, fetchImpl = globalThis.fetch, timeoutMs = 20000 }) {
  const base = String(url).replace(/\/+$/, "");
  return async function call(path, body) {
    const res = await fetchImpl(base + path, {
      method: body === undefined ? "GET" : "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(timeoutMs),
    });
    if (res.status === 401) throw new Error("token ditolak WMS. Buat token baru di Pengaturan, lalu jalankan: npm run setup");
    if (!res.ok) throw new Error(`WMS membalas HTTP ${res.status}`);
    return res.json();
  };
}

/**
 * Ambil antrean, kirim satu per satu, lalu lapor terkirim/gagal ke WMS.
 * sendText(jid, text) mengirim pesan; checkNumber(jid) → true jika nomor terdaftar di WhatsApp.
 */
export async function processQueue({ wms, sendText, checkNumber, groups, delayMs = 4000, sleep, log = () => {}, limit = 10 }) {
  const wait = sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
  const { messages = [] } = await wms(`/api/whatsapp/antrean?limit=${limit}`);
  let sent = 0;
  let failed = 0;

  for (let i = 0; i < messages.length; i++) {
    const m = messages[i];
    try {
      const jid = toJid(m.to, groups);
      if (jid.endsWith("@s.whatsapp.net") && checkNumber && !(await checkNumber(jid))) {
        throw new Error("nomor tidak terdaftar di WhatsApp");
      }
      await sendText(jid, String(m.message));
      await wms(`/api/whatsapp/antrean/${m.id}/terkirim`, {});
      sent++;
      log(`✔ ${m.to}: ${String(m.message).split("\n")[0].slice(0, 50)}`);
    } catch (e) {
      failed++;
      log(`✖ ${m.to}: ${e.message}`);
      await wms(`/api/whatsapp/antrean/${m.id}/gagal`, { error: String(e.message).slice(0, 250) }).catch((err) =>
        log(`Gagal lapor ke WMS: ${err.message}`),
      );
    }
    if (i < messages.length - 1) await wait(delayMs);
  }

  return { sent, failed };
}
