<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Pengaturan Sistem') }}</h2>
            <p class="text-sm text-slate-400 mt-1">Konfigurasi aplikasi dan informasi sistem</p>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <!-- System Info Card -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Informasi Sistem
            </h3>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">Laravel Version</p>
                    <p class="text-lg font-bold text-cyan-400">{{ $stats['laravel_version'] }}</p>
                </div>
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">PHP Version</p>
                    <p class="text-lg font-bold text-cyan-400">{{ $stats['php_version'] }}</p>
                </div>
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">Total Users</p>
                    <p class="text-lg font-bold text-white">{{ $stats['total_users'] }}</p>
                </div>
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">Active SKUs</p>
                    <p class="text-lg font-bold text-white">{{ number_format($stats['active_skus']) }}</p>
                </div>
            </div>
        </div>

        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ $errors->first() }}</div>
        @endif

        <!-- Data perusahaan & stok -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-1">Data Perusahaan &amp; Stok</h3>
            <p class="text-sm text-slate-400 mb-4">Data perusahaan dicetak sebagai pengirim di label kirim.</p>

            <form method="POST" action="{{ route('settings.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="company_name" class="block text-sm font-medium text-slate-300 mb-2">Nama perusahaan / toko</label>
                        <input type="text" id="company_name" name="company_name" required maxlength="100"
                            value="{{ old('company_name', $company['company_name']) }}" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label for="company_phone" class="block text-sm font-medium text-slate-300 mb-2">No. telepon</label>
                        <input type="text" id="company_phone" name="company_phone" maxlength="30"
                            value="{{ old('company_phone', $company['company_phone']) }}" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                </div>
                <div>
                    <label for="company_address" class="block text-sm font-medium text-slate-300 mb-2">Alamat pengirim</label>
                    <input type="text" id="company_address" name="company_address" maxlength="255"
                        value="{{ old('company_address', $company['company_address']) }}" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                </div>

                <div class="pt-4 border-t border-slate-700/50">
                    <label for="low_stock_threshold" class="block text-sm font-medium text-slate-300 mb-2">Batas stok menipis umum (pcs)</label>
                    <input type="number" id="low_stock_threshold" name="low_stock_threshold" required min="0"
                        value="{{ old('low_stock_threshold', $company['low_stock_threshold']) }}" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all md:w-48"/>
                    <p class="text-xs text-slate-500 mt-1">
                        Dipakai untuk SKU yang min stoknya 0 dan pernah ada stok di gudang. Isi 0 jika peringatan hanya memakai min stok per SKU.
                    </p>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Simpan Pengaturan
                    </button>
                </div>
            </form>
        </div>

        <!-- Notifikasi WhatsApp -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl"
             x-data="{ driver: @js(old('whatsapp_driver', $whatsapp['driver'])) }">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h3 class="text-lg font-semibold text-white">Notifikasi WhatsApp</h3>
                @if ($whatsapp['ready'])
                    <span class="px-2 py-1 text-xs font-semibold rounded-lg bg-emerald-500/15 text-emerald-300">Aktif</span>
                @else
                    <span class="px-2 py-1 text-xs font-semibold rounded-lg bg-slate-700 text-slate-300">Belum aktif</span>
                @endif
            </div>
            <p class="text-sm text-slate-400 mb-4">Setiap pagi pukul 08:00, daftar SKU yang stoknya menipis dikirim ke WhatsApp anggota. Jika tidak ada yang menipis, tidak ada pesan.</p>

            @error('whatsapp')
                <div class="mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-sm text-red-300">{{ $message }}</div>
            @enderror

            <div class="mb-5 p-4 bg-slate-900/60 border border-slate-700 rounded-xl">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                    <p class="text-sm font-semibold text-white">Penerima: {{ $whatsapp['members']->count() }} anggota</p>
                    <a href="{{ route('users.index') }}" class="text-xs font-semibold text-cyan-300 hover:text-cyan-200">Atur di Kelola User →</a>
                </div>
                @forelse ($whatsapp['members'] as $member)
                    <p class="text-sm text-slate-300">{{ $member->name }} <span class="text-slate-500">· {{ $member->role }} · {{ $member->whatsapp }}</span></p>
                @empty
                    <p class="text-sm text-slate-400">Belum ada. Di Kelola User, isi No. WhatsApp anggota dan centang "Kirim notifikasi WhatsApp".</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('settings.whatsapp') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <p class="block text-sm font-medium text-slate-300 mb-2">Dikirim lewat</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (\App\Services\WhatsApp::DRIVERS as $value => $label)
                            <label class="flex items-center gap-2 px-4 py-2 rounded-xl border cursor-pointer text-sm"
                                   :class="driver === '{{ $value }}' ? 'border-cyan-400 bg-cyan-500/10 text-white' : 'border-slate-700 text-slate-300'">
                                <input type="radio" name="whatsapp_driver" value="{{ $value }}" x-model="driver" class="text-cyan-500 bg-slate-800 border-slate-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div x-show="driver === 'fonnte'" x-cloak>
                    <label for="whatsapp_token" class="block text-sm font-medium text-slate-300 mb-2">Token Fonnte</label>
                    <input type="password" id="whatsapp_token" name="whatsapp_token" autocomplete="off"
                        placeholder="{{ $whatsapp['has_token'] ? 'Tersimpan, kosongkan jika tidak diganti' : 'Token perangkat dari dashboard Fonnte' }}" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    @error('whatsapp_token')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    @if ($whatsapp['has_token'])
                        <label class="flex items-center gap-2 mt-2 text-sm text-slate-400">
                            <input type="checkbox" name="whatsapp_clear" value="1" class="rounded bg-slate-800 border-slate-600"> Hapus token Fonnte
                        </label>
                    @endif
                </div>

                <div>
                    <label for="whatsapp_target" class="block text-sm font-medium text-slate-300 mb-2">Nomor tambahan (opsional)</label>
                    <input type="text" id="whatsapp_target" name="whatsapp_target" value="{{ old('whatsapp_target', $whatsapp['extra']) }}"
                        placeholder="Nomor di luar anggota, dipisah koma" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    @error('whatsapp_target')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="flex flex-wrap justify-end gap-3 pt-2">
                    @if ($whatsapp['ready'])
                        <button type="submit" form="whatsapp-test" class="px-4 py-3 bg-slate-800 hover:bg-slate-700 text-cyan-300 text-sm font-semibold rounded-xl border border-cyan-500/40 transition-all">Kirim Tes</button>
                    @endif
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Simpan WhatsApp
                    </button>
                </div>
            </form>
            <form id="whatsapp-test" method="POST" action="{{ route('settings.whatsapp.test') }}">@csrf</form>

            @if ($whatsapp['driver'] === 'server')
                <div class="mt-5 pt-5 border-t border-slate-700/50">
                    <p class="text-sm font-semibold text-white mb-2">Nomor pengirim (WhatsApp Web di server)</p>
                    @if (!$whatsapp['sender_ready'])
                        <p class="text-sm text-amber-300">Pengirim belum diatur. Jalankan folder <code>whatsapp-sender</code> di server, lalu isi <code>WA_SENDER_URL</code> dan <code>WA_SENDER_KEY</code> di file <code>.env</code> WMS.</p>
                    @else
                        <div x-data="waSender(@js(route('settings.whatsapp.status')), @js(route('settings.whatsapp.qr')))" x-init="start()" class="space-y-3">
                            <p class="text-sm" :class="color()" x-text="label()"></p>
                            <template x-if="state === 'QR'">
                                <div>
                                    <img :src="qrSrc" alt="QR WhatsApp" class="w-64 h-64 bg-white rounded-xl p-2">
                                    <p class="text-xs text-slate-400 mt-2">Di HP: WhatsApp → Perangkat tertaut → Tautkan perangkat, lalu scan QR ini.</p>
                                </div>
                            </template>
                            <form x-show="state === 'READY'" method="POST" action="{{ route('settings.whatsapp.logout') }}"
                                  onsubmit="return confirm('Keluarkan nomor ini dari pengirim? Setelah itu scan QR dengan nomor lain.')">
                                @csrf
                                <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-lg border border-slate-700 transition-all">Ganti nomor pengirim</button>
                            </form>
                        </div>
                        <script>
                            function waSender(statusUrl, qrUrl) {
                                return {
                                    state: 'LOADING', me: null, error: null, qrSrc: '',
                                    start() { this.poll(); setInterval(() => this.poll(), 5000); },
                                    async poll() {
                                        try {
                                            const data = await (await fetch(statusUrl, { headers: { Accept: 'application/json' } })).json();
                                            this.state = data.state; this.me = data.me ?? null; this.error = data.error ?? null;
                                            if (this.state === 'QR') this.qrSrc = qrUrl + '?t=' + Date.now();
                                        } catch (e) { this.state = 'OFFLINE'; this.error = null; }
                                    },
                                    label() {
                                        return {
                                            READY: 'Terhubung' + (this.me ? ' sebagai ' + this.me : '') + '. Siap mengirim.',
                                            QR: 'Belum login. Scan QR di bawah dengan WhatsApp nomor pengirim.',
                                            LOADING: 'Membuka WhatsApp Web…',
                                            OFFLINE: this.error || 'Pengirim tidak berjalan.',
                                        }[this.state] || this.state;
                                    },
                                    color() { return { READY: 'text-emerald-300', QR: 'text-amber-300', OFFLINE: 'text-red-300' }[this.state] || 'text-slate-300'; },
                                };
                            }
                        </script>
                    @endif
                </div>
            @endif
        </div>

        <!-- System Maintenance -->
        <div class="bg-gradient-to-br from-red-900/20 to-slate-900 border border-red-500/30 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                Zona Berbahaya
            </h3>
            <p class="text-sm text-slate-400 mb-4">Tindakan di bawah ini tidak dapat dibatalkan. Harap berhati-hati.</p>
            <div class="flex gap-3">
                <form method="POST" action="{{ route('settings.clear-cache') }}" onsubmit="return confirm('Yakin ingin clear cache?')">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-lg border border-slate-700 transition-all">
                        Clear Cache
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>