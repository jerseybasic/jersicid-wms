@props([
    // URL POST untuk mode server (picking/packing). Kosong = mode lokal: halaman menangani event "scan-code".
    'endpoint' => null,
    // Kunci sessionStorage untuk mengingat lokasi bin yang terakhir di-scan
    'storageKey' => 'scan-bin',
    // Tampilkan kotak lokasi bin (picking, inbound, opname)
    'withBin' => true,
    'hint' => 'Scan lokasi rak dulu, lalu scan barang. Setiap scan barang menambah 1.',
])

<div x-data="scanBox(@js($endpoint), @js($storageKey), @js(route('gudang.command')))"
    @scan-result.window="handle($event.detail)"
    class="rounded-2xl border-2 p-4 transition-colors"
    :class="{
        'border-slate-700 bg-slate-900': status === 'idle',
        'border-emerald-500 bg-emerald-900/30': status === 'ok',
        'border-amber-400 bg-amber-900/30': status === 'warn',
        'border-red-500 bg-red-900/40': status === 'error'
    }">
    <div class="flex flex-col md:flex-row md:items-end gap-3">
        <div class="flex-1">
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-300">Scan barcode</label>
                {{-- Scanner Bluetooth di HP: keyboard layar tidak perlu muncul setiap kali kotak scan aktif --}}
                <button type="button" @click="toggleKeyboard()" class="text-xs text-slate-400 hover:text-cyan-300"
                    x-text="scannerMode ? 'Mode scanner (keyboard disembunyikan)' : 'Mode ketik'"></button>
            </div>
            <input type="text" x-ref="input" @keydown.enter.prevent="submit()" autocomplete="off" autofocus
                :inputmode="scannerMode ? 'none' : 'text'"
                placeholder="Arahkan scanner ke barcode..."
                class="w-full px-4 py-3 bg-slate-800 border border-slate-600 rounded-xl text-white text-lg font-mono uppercase placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
        </div>
        @if ($withBin)
            <div class="md:w-56">
                <p class="text-xs font-semibold text-slate-300 mb-1">Lokasi aktif</p>
                <div class="flex items-center gap-2 px-4 py-3 bg-slate-800 border border-slate-600 rounded-xl">
                    <span class="flex-1 font-mono text-lg" :class="bin ? 'text-cyan-400' : 'text-slate-500'"
                        x-text="bin || 'belum dipilih'"></span>
                    <button type="button" x-show="bin" @click="setBin(''); focus()"
                        class="text-xs text-slate-400 hover:text-red-400">hapus</button>
                </div>
            </div>
        @endif
    </div>
    {{-- Picking gabungan: nomor keranjang tujuan barang yang baru di-scan --}}
    <div x-show="basket" x-cloak class="mt-3 flex items-center gap-3 px-4 py-3 rounded-xl bg-cyan-500 text-slate-900">
        <span class="text-sm font-bold uppercase">Masukkan ke keranjang</span>
        <span class="ml-auto text-4xl font-black leading-none" x-text="basket"></span>
    </div>
    <p class="mt-3 text-sm font-semibold"
        :class="{ 'text-slate-400': status === 'idle', 'text-emerald-400': status === 'ok', 'text-amber-300': status === 'warn', 'text-red-400': status === 'error' }"
        x-text="message || @js($hint)"></p>
</div>

@once
    <script>
        /** Ketuk barang di daftar (+1/-1): hasilnya ditampilkan di kotak scan seperti hasil scan */
        async function scanTap(url, body) {
            let result;
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(body),
                });
                const data = await response.json();
                result = data.message && data.ok !== undefined ? data : { ok: false, message: data.message || 'Gagal.' };
            } catch (e) {
                result = { ok: false, message: 'Koneksi gagal. Coba lagi.' };
            }
            window.dispatchEvent(new CustomEvent('scan-result', { detail: result }));
        }

        function scanBox(endpoint, storageKey, commandUrl) {
            return {
                endpoint: endpoint,
                commandUrl: commandUrl,
                storageKey: storageKey,
                bin: '',
                status: 'idle',
                message: '',
                basket: null,
                busy: false,
                audio: null,
                scannerMode: false,

                init() {
                    try {
                        this.bin = sessionStorage.getItem(this.storageKey) || '';
                        this.scannerMode = localStorage.getItem('scan-scanner-mode') === '1';
                    } catch (e) {}
                },

                toggleKeyboard() {
                    this.scannerMode = !this.scannerMode;
                    try {
                        localStorage.setItem('scan-scanner-mode', this.scannerMode ? '1' : '0');
                    } catch (e) {}
                    this.$refs.input.blur();
                    this.focus();
                },

                focus() {
                    this.$nextTick(() => this.$refs.input.focus());
                },

                setBin(code) {
                    this.bin = code;
                    try {
                        sessionStorage.setItem(this.storageKey, code);
                    } catch (e) {}
                },

                async submit() {
                    const code = this.$refs.input.value.trim().toUpperCase();
                    this.$refs.input.value = '';
                    if (!code || this.busy) {
                        return;
                    }

                    // QR perintah (CMD-...) bekerja di kotak scan mana pun
                    const url = code.startsWith('CMD-') ? this.commandUrl : this.endpoint;

                    if (!url) {
                        // Mode lokal: halaman memproses kode lalu memanggil respond()
                        window.dispatchEvent(new CustomEvent('scan-code', {
                            detail: { code: code, bin: this.bin, respond: (result) => this.handle(result) }
                        }));
                        return;
                    }

                    this.busy = true;
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ code: code, bin_code: this.bin }),
                        });
                        const data = await response.json();
                        this.handle(data.message && data.ok !== undefined ? data : { ok: false, message: data.message || 'Scan gagal.' });
                    } catch (e) {
                        this.handle({ ok: false, message: 'Koneksi gagal. Coba scan lagi.' });
                    } finally {
                        this.busy = false;
                        this.focus();
                    }
                },

                handle(result) {
                    if (result.type === 'bin' && result.bin_code) {
                        this.setBin(result.bin_code);
                    }
                    // Rak yang benar-benar dipakai (bisa berbeda dari lokasi aktif yang tertinggal)
                    if (result.ok && result.active_bin && this.bin) {
                        this.setBin(result.active_bin);
                    }

                    (result.items || []).forEach((item) => {
                        document.querySelectorAll('[data-scan-qty="' + item.id + '"]').forEach((el) => {
                            if ('value' in el && el.tagName === 'INPUT') {
                                el.value = item.qty;
                            } else {
                                el.textContent = item.qty;
                            }
                        });
                    });

                    this.basket = result.ok && result.basket && result.type !== 'undo' ? result.basket : null;
                    this.status = result.ok ? (result.warning ? 'warn' : 'ok') : 'error';
                    this.message = result.message;
                    this.beep(result.ok);
                    this.focus();

                    if (result.ok && result.redirect) {
                        setTimeout(() => window.location.href = result.redirect, 400);
                        return;
                    }

                    if (result.completed) {
                        this.message = result.message + ' Semua item selesai.';
                        setTimeout(() => window.location.reload(), 1200);
                    }
                },

                beep(ok) {
                    try {
                        this.audio = this.audio || new (window.AudioContext || window.webkitAudioContext)();
                        const tones = ok ? [[880, 0, 0.12]] : [[220, 0, 0.25], [220, 0.35, 0.25]];
                        tones.forEach(([freq, start, length]) => {
                            const osc = this.audio.createOscillator();
                            const gain = this.audio.createGain();
                            osc.type = ok ? 'sine' : 'square';
                            osc.frequency.value = freq;
                            gain.gain.value = 0.2;
                            osc.connect(gain);
                            gain.connect(this.audio.destination);
                            osc.start(this.audio.currentTime + start);
                            osc.stop(this.audio.currentTime + start + length);
                        });
                    } catch (e) {}
                    if (!ok && navigator.vibrate) {
                        navigator.vibrate([200, 100, 200]);
                    }
                },
            };
        }
    </script>
@endonce
