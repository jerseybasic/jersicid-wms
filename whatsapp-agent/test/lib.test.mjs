import { test } from "node:test";
import assert from "node:assert/strict";
import { createWms, isPhone, processQueue, toJid } from "../lib.mjs";

const groups = [
  { id: "120363001@g.us", name: "Gudang Jersic" },
  { id: "120363002@g.us", name: "Gudang Jersic Lama" },
];

test("nomor menjadi chat pribadi, nama grup dicocokkan persis", () => {
  assert.equal(toJid("6281234567890", groups), "6281234567890@s.whatsapp.net");
  assert.equal(toJid("0812-3456-7890", groups), "6281234567890@s.whatsapp.net");
  assert.equal(toJid("gudang  JERSIC", groups), "120363001@g.us");
  assert.equal(toJid("120363009@g.us", groups), "120363009@g.us");
  assert.throws(() => toJid("Gudang", groups), /grup "Gudang" tidak ditemukan/);
  assert.equal(isPhone("Gudang 2026"), false);
});

/** WMS tiruan: antrean + catatan laporan */
function fakeWms(messages) {
  const calls = [];
  const wms = async (path, body) => {
    calls.push([path, body]);
    return path.startsWith("/api/whatsapp/antrean?") ? { messages } : { ok: true };
  };
  return { wms, calls };
}

test("antrean dikirim lalu dilaporkan terkirim atau gagal", async () => {
  const { wms, calls } = fakeWms([
    { id: 1, to: "6281234567890", message: "*Laporan*\nisi" },
    { id: 2, to: "Gudang Jersic", message: "laporan grup" },
    { id: 3, to: "Grup Tidak Ada", message: "x" },
    { id: 4, to: "6289999999999", message: "y" },
  ]);
  const sent = [];
  const result = await processQueue({
    wms,
    groups,
    sleep: async () => {},
    sendText: async (jid, text) => sent.push([jid, text]),
    checkNumber: async (jid) => jid !== "6289999999999@s.whatsapp.net",
  });

  assert.deepEqual(result, { sent: 2, failed: 2 });
  assert.deepEqual(sent, [
    ["6281234567890@s.whatsapp.net", "*Laporan*\nisi"],
    ["120363001@g.us", "laporan grup"],
  ]);
  assert.deepEqual(calls.slice(1).map(([p, b]) => [p, b?.error]), [
    ["/api/whatsapp/antrean/1/terkirim", undefined],
    ["/api/whatsapp/antrean/2/terkirim", undefined],
    ["/api/whatsapp/antrean/3/gagal", 'grup "Grup Tidak Ada" tidak ditemukan. Nama harus sama persis dan nomor pengirim harus anggota grup.'],
    ["/api/whatsapp/antrean/4/gagal", "nomor tidak terdaftar di WhatsApp"],
  ]);
});

test("gagal kirim dilaporkan dengan alasannya", async () => {
  const { wms, calls } = fakeWms([{ id: 9, to: "6281234567890", message: "x" }]);
  await processQueue({ wms, groups, sleep: async () => {}, sendText: async () => { throw new Error("koneksi putus"); } });
  assert.deepEqual(calls[1], ["/api/whatsapp/antrean/9/gagal", { error: "koneksi putus" }]);
});

test("klien WMS memakai token dan menolak token salah", async () => {
  let seen;
  const ok = createWms({ url: "https://wms.test/", token: "wa_abc", fetchImpl: async (url, opt) => { seen = [url, opt]; return { ok: true, status: 200, json: async () => ({ command: null }) }; } });
  assert.deepEqual(await ok("/api/whatsapp/agen", { state: "qr" }), { command: null });
  assert.equal(seen[0], "https://wms.test/api/whatsapp/agen");
  assert.equal(seen[1].method, "POST");
  assert.equal(seen[1].headers.Authorization, "Bearer wa_abc");

  const bad = createWms({ url: "https://wms.test", token: "x", fetchImpl: async () => ({ ok: false, status: 401 }) });
  await assert.rejects(bad("/api/whatsapp/antrean?limit=1"), /token ditolak/);
});
