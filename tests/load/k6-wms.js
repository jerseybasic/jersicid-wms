// Uji beban JERSIC WMS dengan k6.
//
// Meniru pemakaian harian: staf gudang (70%) membuka Tugas, picking, daftar order, detail order,
// cek barang, dan pencarian; admin gudang (20%) membuka dashboard, outbound, inbound, peta rak,
// serah terima, dan sesekali membuat order baru; owner (10%) membuka dashboard dan laporan.
// Setiap user diam 1-3 detik di antara halaman, seperti orang sungguhan.
//
// Contoh:
//   k6 run -e BASE_URL=https://staging.jersic.id -e VUS=50 -e DURATION=2m tests/load/k6-wms.js
//
// Variabel:
//   BASE_URL   alamat aplikasi (default https://localhost)
//   VUS        jumlah user serentak (default 20)
//   DURATION   lama uji per tingkat (default 1m)
//   PASSWORD   password akun uji (default "password", dari LoadTestSeeder)
//   WRITE      1 = admin juga membuat order baru (default 1). Pakai 0 di server yang datanya penting.
//   INSECURE   1 = abaikan sertifikat HTTPS self-signed (default 1)

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE = (__ENV.BASE_URL || 'https://localhost').replace(/\/$/, '');
const PASSWORD = __ENV.PASSWORD || 'password';
const WRITE = (__ENV.WRITE || '1') === '1';

export const options = {
  setupTimeout: '3m',
  insecureSkipTLSVerify: (__ENV.INSECURE || '1') === '1',
  scenarios: {
    harian: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '1m',
      gracefulStop: '15s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],          // < 1% request gagal
    http_req_duration: ['p(95)<1500'],       // 95% halaman selesai < 1,5 detik
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max'],
};

const pageErrors = new Rate('halaman_error');
const tPages = {
  tugas: new Trend('t_tugas', true),
  outbound: new Trend('t_outbound', true),
  detail_order: new Trend('t_detail_order', true),
  cek_barang: new Trend('t_cek_barang', true),
  cari: new Trend('t_cari', true),
  dashboard: new Trend('t_dashboard', true),
  laporan: new Trend('t_laporan', true),
  buat_order: new Trend('t_buat_order', true),
};

// Login sekali per akun di setup (login dibatasi 10x/menit per IP), lalu sesi dipakai bersama oleh VU.
const ACCOUNTS = ['owner', 'admin', 'staf1', 'staf2', 'staf3', 'staf4', 'staf5'];

function login(account) {
  // Jar terpisah per akun supaya sesi akun lain tidak tertimpa
  const jar = new http.CookieJar();
  const page = http.get(`${BASE}/login`, { jar });
  const token = (page.body.match(/name="_token"\s+value="([^"]+)"/) || [])[1];
  const res = http.post(`${BASE}/login`, { _token: token, email: `loadtest-${account}@jersic.test`, password: PASSWORD }, { redirects: 0, jar });
  if (res.status !== 302 || String(res.headers.Location || '').endsWith('/login')) return {};
  const home = http.get(`${BASE}/gudang/scanner`, { jar, redirects: 0 });
  const csrf = home.status === 200 ? (home.body.match(/name="csrf-token" content="([^"]+)"/) || [])[1] : undefined;
  const cookies = jar.cookiesForURL(BASE);
  return { cookies: Object.fromEntries(Object.entries(cookies).map(([k, v]) => [k, v[0]])), csrf };
}

export function setup() {
  const sessions = {};
  for (const account of ACCOUNTS) {
    // Login dibatasi 10x/menit per IP: kalau ditolak, tunggu lalu coba lagi
    for (let attempt = 1; attempt <= 8 && !sessions[account]; attempt++) {
      const session = login(account);
      if (session.csrf) sessions[account] = session;
      else sleep(10);
    }
    if (!sessions[account]) throw new Error(`Login ${account} gagal. Sudah jalankan LoadTestSeeder?`);
  }
  return { sessions };
}

function get(url, trend, extra = {}) {
  const res = http.get(`${BASE}${url}`, { redirects: 0, ...extra });
  const ok = check(res, { [`GET ${url.split('?')[0].replace(/[0-9a-f-]{36}/, '{id}')} 200`]: (r) => r.status === 200 });
  pageErrors.add(!ok);
  if (!ok && __ENV.DEBUG) console.log(`VU${__VU} ${url} -> ${res.status} ${res.headers.Location || ''}`);
  if (trend) trend.add(res.timings.duration);
  return res;
}

const randInt = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
const think = () => sleep(1 + Math.random() * 2);
const randomSku = () => `LT-ART${String(randInt(1, 100)).padStart(3, '0')}-V1-${['S', 'M', 'L', 'XL', 'XXL', '3XL'][randInt(0, 5)]}`;

function useSession(session) {
  const jar = http.cookieJar();
  for (const [name, value] of Object.entries(session.cookies)) {
    jar.set(BASE, name, value);
  }
}

function staffFlow() {
  group('staf', () => {
    get('/gudang/tugas', tPages.tugas); think();
    get('/gudang/picking'); think();
    const list = get(`/gudang/outbound?page=${randInt(1, 20)}`, tPages.outbound); think();
    const ids = [...list.body.matchAll(/\/gudang\/outbound\/([0-9a-f-]{36})"/g)].map((m) => m[1]);
    if (ids.length) {
      get(`/gudang/outbound/${ids[randInt(0, ids.length - 1)]}`, tPages.detail_order); think();
    }
    get(`/gudang/scanner/lookup?code=${randomSku()}`, tPages.cek_barang, { headers: { Accept: 'application/json' } }); think();
    get(`/cari?q=ART${String(randInt(1, 100)).padStart(3, '0')}`, tPages.cari); think();
  });
}

function adminFlow(session) {
  group('admin', () => {
    get('/dashboard/gudang', tPages.dashboard); think();
    get('/gudang/outbound', tPages.outbound); think();
    get('/gudang/outbound?batas=terlambat', tPages.outbound); think();
    get('/gudang/inbound'); think();
    get('/gudang/mapping'); think();
    get('/gudang/serah-terima'); think();
    if (WRITE && Math.random() < 0.5) {
      const res = http.post(`${BASE}/gudang/outbound`, {
        _token: session.csrf,
        customer_name: `Uji Beban ${__VU}-${__ITER}`,
        shipping_address: 'Jl. Uji Beban, Bandung',
        courier: 'JNE',
        'items[0][sku]': randomSku(),
        'items[0][qty]': '1',
      }, { redirects: 0 });
      const ok = check(res, { 'POST buat order 302': (r) => r.status === 302 && String(r.headers.Location || '').endsWith('/gudang/outbound') });
      pageErrors.add(!ok);
      tPages.buat_order.add(res.timings.duration);
      think();
    }
  });
}

function ownerFlow() {
  group('owner', () => {
    get('/dashboard/owner', tPages.dashboard); think();
    get('/reports/kinerja', tPages.laporan); think();
    get('/reports/stock', tPages.laporan); think();
    get('/reports/ketepatan-kirim', tPages.laporan); think();
    get('/master-skus'); think();
    get('/audit-trails'); think();
  });
}

export default function (data) {
  // Peran tetap per VU: 10% owner, 20% admin, 70% staf
  const slot = __VU % 10;
  // k6 mengosongkan cookie di awal setiap iterasi, jadi sesi dipasang lagi tiap iterasi
  const account = slot === 0 ? 'owner' : slot <= 2 ? 'admin' : `staf${(__VU % 5) + 1}`;
  useSession(data.sessions[account]);
  if (slot === 0) ownerFlow();
  else if (slot <= 2) adminFlow(data.sessions.admin);
  else staffFlow();
}

export function handleSummary(data) {
  const out = {};
  if (__ENV.SUMMARY_JSON) out[__ENV.SUMMARY_JSON] = JSON.stringify(data, null, 2);
  out.stdout = textSummary(data);
  return out;
}

function textSummary(data) {
  const m = data.metrics;
  const ms = (v) => (v === undefined ? '-' : `${Math.round(v)} ms`);
  const lines = [
    `VU serentak     : ${__ENV.VUS || 20}`,
    `Request/detik   : ${(m.http_reqs.values.rate).toFixed(1)}`,
    `Total request   : ${m.http_reqs.values.count}`,
    `Gagal           : ${(m.http_req_failed.values.rate * 100).toFixed(2)}%`,
    `Waktu respons   : median ${ms(m.http_req_duration.values.med)}, p95 ${ms(m.http_req_duration.values['p(95)'])}, max ${ms(m.http_req_duration.values.max)}`,
  ];
  for (const [name, key] of Object.entries({ Tugas: 't_tugas', Outbound: 't_outbound', 'Detail order': 't_detail_order', 'Cek barang': 't_cek_barang', Cari: 't_cari', Dashboard: 't_dashboard', Laporan: 't_laporan', 'Buat order': 't_buat_order' })) {
    if (m[key]) lines.push(`  ${name.padEnd(13)}: median ${ms(m[key].values.med)}, p95 ${ms(m[key].values['p(95)'])}`);
  }
  const failed = [];
  const walk = (g) => {
    for (const c of g.checks || []) if (c.fails > 0) failed.push(`  ${c.name}: ${c.fails} gagal dari ${c.passes + c.fails}`);
    for (const sub of g.groups || []) walk(sub);
  };
  walk(data.root_group);
  if (failed.length) lines.push('Pemeriksaan yang gagal:', ...failed);
  return lines.join('\n') + '\n';
}
