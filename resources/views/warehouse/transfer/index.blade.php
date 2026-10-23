<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Transfer Stok</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">Scan bin asal, pilih barang yang dipindah, lalu scan bin tujuan</p>
        </div>
    </x-slot>

    @php
        $field = 'w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none';
    @endphp

    <div class="max-w-4xl mx-auto space-y-6" x-data="transferFlow(@js(route('gudang.transfer.lookup')), @js(old('bin_from', '')))"
        @scan-code.window="scan($event.detail)">

        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl text-sm">{{ $errors->first() }}</div>
        @endif

        {{-- Langkah --}}
        <ol class="grid grid-cols-3 gap-2 text-xs sm:text-sm">
            <template x-for="(label, index) in ['Bin asal', 'Barang & jumlah', 'Bin tujuan']" :key="index">
                <li class="px-3 py-2 rounded-xl border text-center font-semibold"
                    :class="step === index + 1 ? 'border-cyan-400 bg-cyan-500/10 text-cyan-300' : (step > index + 1 ? 'border-emerald-500/40 text-emerald-400' : 'border-slate-800 text-slate-500')"
                    x-text="(index + 1) + '. ' + label"></li>
            </template>
        </ol>

        <x-scan-box storage-key="transfer" :with-bin="false"
            hint="Scan atau ketik kode bin asal lalu Enter. Bisa juga scan SKU untuk melihat di bin mana barang itu berada." />

        {{-- SKU di-scan duluan: pilih bin asalnya --}}
        <div x-show="skuBins" x-cloak class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
            <p class="text-sm text-slate-300">Bin yang berisi <span class="font-mono font-bold text-cyan-400" x-text="skuBins?.sku"></span>:</p>
            <div class="flex flex-wrap gap-2">
                <template x-for="bin in (skuBins?.bins || [])" :key="bin.bin_code">
                    <button type="button" @click="openBin(bin.bin_code, skuBins.sku)"
                        class="px-4 py-3 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl text-left">
                        <span class="block font-mono font-bold text-white" x-text="bin.bin_code"></span>
                        <span class="text-xs text-slate-400" x-text="bin.qty + ' pcs'"></span>
                    </button>
                </template>
                <p x-show="skuBins && !skuBins.bins.length" class="text-sm text-amber-400">Stok SKU ini sedang kosong di semua bin.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('gudang.transfer.store') }}" x-show="from" x-cloak
            class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden" @submit="return confirmSubmit($event)">
            @csrf
            <input type="hidden" name="bin_from" :value="from">

            <div class="p-5 border-b border-slate-800 flex flex-wrap items-center gap-3">
                <div class="mr-auto">
                    <p class="text-xs text-slate-400">Dari bin</p>
                    <p class="text-2xl font-mono font-bold text-amber-400" x-text="from"></p>
                </div>
                <button type="button" @click="fillAll()" class="px-3 py-2 text-sm font-semibold rounded-lg bg-slate-700 text-white">Pindahkan semua isi bin</button>
                <button type="button" @click="clearQty()" class="px-3 py-2 text-sm font-semibold rounded-lg bg-slate-800 text-slate-300">Nolkan</button>
                <button type="button" @click="reset()" class="px-3 py-2 text-sm font-semibold rounded-lg bg-slate-800 text-slate-300">Ganti bin asal</button>
            </div>

            <div class="divide-y divide-slate-800">
                <template x-for="(item, index) in items" :key="item.sku">
                    <div class="flex flex-wrap items-center gap-3 px-5 py-3" :class="item.move > 0 ? 'bg-cyan-500/5' : ''">
                        <input type="hidden" :name="'items[' + index + '][sku]'" :value="item.sku">
                        <div class="flex-1 min-w-[10rem]">
                            <p class="font-mono font-semibold text-cyan-400" x-text="item.sku"></p>
                            <p class="text-xs text-slate-400" x-text="item.name"></p>
                            <p class="text-xs text-amber-400" x-show="item.locked > 0" x-text="item.locked + ' pcs ditunggu picking, tidak bisa dipindah'"></p>
                        </div>
                        <p class="text-sm text-slate-400 w-20 text-right">stok <span class="font-bold text-white" x-text="item.qty"></span></p>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="item.move = Math.max(0, item.move - 1)" class="w-10 h-10 rounded-lg bg-slate-800 text-white text-lg">−</button>
                            <input type="number" min="0" :max="max(item)" :name="'items[' + index + '][qty]'" x-model.number="item.move"
                                @input="item.move = Math.min(max(item), Math.max(0, item.move || 0))"
                                class="w-20 h-10 px-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-center font-bold">
                            <button type="button" @click="item.move = Math.min(max(item), item.move + 1)" class="w-10 h-10 rounded-lg bg-slate-800 text-white text-lg">+</button>
                            <button type="button" @click="item.move = max(item)" class="h-10 px-3 rounded-lg bg-slate-700 text-white text-sm font-semibold">Semua</button>
                        </div>
                    </div>
                </template>
                <p x-show="!items.length" class="px-5 py-6 text-sm text-slate-500">Bin ini kosong.</p>
            </div>

            <div class="p-5 border-t border-slate-800 space-y-3 bg-slate-950/40">
                <p class="text-xs text-slate-400">Scan barang untuk menambah 1, atau isi jumlahnya. Setelah itu scan atau ketik bin tujuan.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="block text-sm text-slate-300">Ke bin
                        <input type="text" name="bin_to" x-model="to" @input="to = to.toUpperCase()" required placeholder="Contoh: A-02-01"
                            class="{{ $field }} mt-1 font-mono uppercase text-lg">
                    </label>
                    <label class="block text-sm text-slate-300">Catatan (opsional)
                        <input type="text" name="notes" maxlength="500" placeholder="Contoh: merapikan rak penerimaan" class="{{ $field }} mt-1">
                    </label>
                </div>
                <button type="submit" :disabled="!total || !to"
                    class="w-full py-4 rounded-xl text-base font-bold bg-cyan-500 hover:bg-cyan-400 text-slate-900 disabled:opacity-40"
                    x-text="total ? 'Pindahkan ' + total + ' pcs (' + chosen + ' SKU) ke ' + (to || '...') : 'Isi jumlah yang dipindah'"></button>
            </div>
        </form>

        {{-- Riwayat Transfer --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <form method="GET" class="p-4 border-b border-slate-800 flex flex-wrap items-center gap-3">
                <h3 class="text-base font-semibold text-white mr-auto">Riwayat perpindahan</h3>
                <input type="text" name="cari" value="{{ $search }}" placeholder="Cari SKU, bin, atau no. transfer"
                    class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm w-56">
                <button class="px-3 py-2 bg-slate-700 text-white text-sm rounded-lg">Cari</button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-3 px-4">Tanggal</th>
                            <th class="text-left py-3 px-4">SKU</th>
                            <th class="text-left py-3 px-4">Dari → Ke</th>
                            <th class="text-right py-3 px-4">Qty</th>
                            <th class="text-left py-3 px-4">Oleh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($transfers as $transfer)
                            @php
                                preg_match('/ ke ([A-Z0-9][A-Z0-9\-]*)/i', (string) $transfer->notes, $dest);
                            @endphp
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 text-xs text-slate-400 whitespace-nowrap">
                                    {{ $transfer->created_at->format('d M Y H:i') }}
                                    <span class="block font-mono text-slate-500">{{ $transfer->reference_no }}</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-mono text-white">{{ $transfer->sku }}</span>
                                    <span class="block text-xs text-slate-500">{{ $transfer->masterSku?->product_name }} {{ $transfer->masterSku?->size }}</span>
                                </td>
                                <td class="py-3 px-4 font-mono whitespace-nowrap">
                                    <span class="text-amber-400">{{ $transfer->bin_code }}</span>
                                    <span class="text-slate-500">→</span>
                                    <span class="text-emerald-400">{{ $dest[1] ?? '-' }}</span>
                                </td>
                                <td class="py-3 px-4 text-right font-bold text-cyan-400">{{ number_format($transfer->qty) }}</td>
                                <td class="py-3 px-4 text-xs text-slate-300">{{ $transfer->user?->name ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-12 text-slate-500">{{ $search !== '' ? 'Tidak ada transfer yang cocok.' : 'Belum ada riwayat transfer.' }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transfers->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">{{ $transfers->links() }}</div>
            @endif
        </div>
    </div>

    <script>
        function transferFlow(lookupUrl, initialBin) {
            return {
                lookupUrl: lookupUrl,
                from: '',
                to: '',
                items: [],
                skuBins: null,

                init() {
                    if (initialBin) {
                        this.openBin(initialBin);
                    }
                },

                get step() {
                    if (!this.from) return 1;
                    return this.total ? 3 : 2;
                },

                get total() {
                    return this.items.reduce((sum, item) => sum + (item.move || 0), 0);
                },

                get chosen() {
                    return this.items.filter((item) => item.move > 0).length;
                },

                max(item) {
                    return Math.max(0, item.qty - item.locked);
                },

                async lookup(code) {
                    const response = await fetch(this.lookupUrl + '?code=' + encodeURIComponent(code), { headers: { 'Accept': 'application/json' } });
                    return response.json();
                },

                async openBin(code, focusSku = null) {
                    const data = await this.lookup(code);
                    if (!data.ok || data.type !== 'bin') {
                        return data;
                    }
                    this.from = data.bin_code;
                    this.to = '';
                    this.skuBins = null;
                    this.items = data.items.map((item) => ({ ...item, move: 0 }));
                    if (focusSku) {
                        const item = this.items.find((row) => row.sku === focusSku);
                        if (item) item.move = Math.min(1, this.max(item));
                    }
                    return data;
                },

                async scan({ code, respond }) {
                    try {
                        // Langkah 2: scan barang di bin asal menambah 1
                        const item = this.items.find((row) => row.sku === code);
                        if (this.from && item) {
                            if (item.move >= this.max(item)) {
                                return respond({ ok: false, message: `${code}: semua stok yang bisa dipindah sudah dihitung (${this.max(item)} pcs).` });
                            }
                            item.move++;
                            return respond({ ok: true, message: `${code}: ${item.move} pcs akan dipindah.` });
                        }

                        const data = await this.lookup(code);
                        if (!data.ok) {
                            return respond(data);
                        }

                        if (data.type === 'sku') {
                            if (this.from) {
                                return respond({ ok: false, message: `${code} tidak ada di bin ${this.from}.` });
                            }
                            this.skuBins = { sku: data.sku.sku, bins: data.bins };
                            return respond(data);
                        }

                        // Kode bin: bin asal di langkah 1, bin tujuan setelahnya
                        if (!this.from) {
                            return respond(await this.openBin(code));
                        }
                        if (data.bin_code === this.from) {
                            return respond({ ok: false, message: 'Bin tujuan tidak boleh sama dengan bin asal.' });
                        }
                        this.to = data.bin_code;
                        return respond({ ok: true, message: `Bin tujuan ${data.bin_code}. Periksa jumlahnya lalu klik Pindahkan.` });
                    } catch (e) {
                        respond({ ok: false, message: 'Koneksi gagal. Coba scan lagi.' });
                    }
                },

                fillAll() {
                    this.items.forEach((item) => item.move = this.max(item));
                },

                clearQty() {
                    this.items.forEach((item) => item.move = 0);
                },

                reset() {
                    this.from = '';
                    this.to = '';
                    this.items = [];
                    this.skuBins = null;
                },

                confirmSubmit(event) {
                    if (!confirm(`Pindahkan ${this.total} pcs dari ${this.from} ke ${this.to}?`)) {
                        event.preventDefault();
                        return false;
                    }
                    return true;
                },
            };
        }
    </script>
</x-app-layout>
