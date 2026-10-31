<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.opname') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Buat Stock Opname Baru') }}</h2>
                <p class="text-sm text-slate-400 mt-1">Pilih produk dan rak yang mau dihitung. Tidak harus seluruh gudang.</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto">
        @if (session('error'))
            <div class="mb-4 p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ $errors->first() }}</div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form method="POST" action="{{ route('gudang.opname.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Pilih Gudang <span
                            class="text-red-400">*</span></label>
                    <select name="warehouse_id" required
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                        <option value="">-- Pilih Gudang --</option>
                        @foreach($warehouses as $wh)
                            <option value="{{ $wh->id }}" @selected(old('warehouse_id', $warehouses->count() === 1 ? $wh->id : null) == $wh->id)>{{ $wh->name }} ({{ $wh->code }})</option>
                        @endforeach
                    </select>
                </div>

                @php
                    $oldSkus = collect(old('skus', []))->filter()->values();
                    $productLabel = fn ($p) => "{$p->sku} — {$p->product_name} {$p->size}";
                @endphp
                <div x-data="opnameProducts(@js(old('scope', 'ALL')), @js($oldSkus), @js($products->mapWithKeys(fn ($p) => [$p->sku => ['name' => trim($p->product_name . ' ' . $p->size), 'stock' => (int) $p->stock, 'bins' => (int) $p->bins]])))">
                    <p class="block text-sm font-medium text-slate-300 mb-1">Produk yang dihitung</p>
                    <p class="text-xs text-slate-500 mb-3">Hitung satu produk saja, beberapa produk, atau semua produk.</p>
                    <div class="grid grid-cols-3 gap-2" role="radiogroup">
                        @foreach (\App\Http\Controllers\Warehouse\StockOpnameController::PRODUCT_SCOPES as $value => $label)
                            <label class="flex items-center justify-center text-center p-3 rounded-xl border cursor-pointer select-none text-sm font-semibold text-white border-slate-700 bg-slate-800 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10">
                                <input type="radio" name="scope" value="{{ $value }}" x-model="scope" class="sr-only">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div x-show="scope !== 'ALL'" x-cloak class="mt-3 space-y-2">
                        <datalist id="opname-products">
                            @foreach ($products as $p)
                                <option value="{{ $productLabel($p) }}">stok {{ (int) $p->stock }}</option>
                            @endforeach
                        </datalist>
                        <div class="flex gap-2">
                            <label for="product-input" class="sr-only">Cari produk</label>
                            <input id="product-input" type="text" list="opname-products" x-model="query" autocomplete="off"
                                @keydown.enter.prevent="add()" @change="add()"
                                :placeholder="scope === 'ONE' ? 'Ketik atau scan SKU / nama produk' : 'Ketik atau scan SKU, lalu Enter untuk menambah'"
                                class="flex-1 min-w-0 px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none">
                            <button type="button" @click="add()" class="px-4 py-3 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl">Tambah</button>
                        </div>
                        <p x-show="error" x-text="error" class="text-xs text-amber-400"></p>
                        <ul class="space-y-2">
                            <template x-for="sku in picked" :key="sku">
                                <li class="flex items-center gap-3 p-3 rounded-xl border border-slate-700 bg-slate-800/60">
                                    <input type="hidden" name="skus[]" :value="sku">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-mono text-sm text-cyan-300 break-all" x-text="sku"></p>
                                        <p class="text-xs text-slate-400 truncate" x-text="info(sku)"></p>
                                    </div>
                                    <button type="button" @click="remove(sku)" class="shrink-0 px-3 py-1.5 text-xs text-slate-300 hover:text-red-400 border border-slate-700 rounded-lg" :aria-label="'Hapus ' + sku">Hapus</button>
                                </li>
                            </template>
                        </ul>
                        <p x-show="picked.length === 0" class="text-xs text-slate-500">Belum ada produk dipilih.</p>
                    </div>
                </div>

                <div>
                    <p class="block text-sm font-medium text-slate-300 mb-1">Rak yang dihitung</p>
                    <p class="text-xs text-slate-500 mb-3">Opsional. Tidak memilih rak = semua rak. Rak yang terkunci oleh opname semua produk tidak bisa dipilih.</p>
                    @if ($racks->isEmpty())
                        <p class="text-sm text-slate-500">Belum ada rak atau stok.</p>
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                            @foreach ($racks as $rack)
                                <label class="flex flex-col items-center justify-center gap-1 p-3 rounded-xl border cursor-pointer select-none
                                    {{ $rack['busy'] ? 'border-slate-800 bg-slate-900 opacity-50 cursor-not-allowed' : 'border-slate-700 bg-slate-800 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10' }}">
                                    <input type="checkbox" name="racks[]" value="{{ $rack['code'] }}" class="sr-only"
                                        @checked(in_array($rack['code'], old('racks', []))) @disabled($rack['busy'])>
                                    <span class="text-lg font-bold text-white">Rak {{ $rack['code'] }}</span>
                                    <span class="text-xs {{ $rack['busy'] ? 'text-amber-400' : 'text-slate-400' }}">
                                        {{ $rack['busy'] ? 'sedang diopname' : $rack['bins'] . ' lokasi berisi' }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($openOpnames->isNotEmpty())
                    <div class="p-3 rounded-xl border border-amber-500/30 bg-amber-500/5 text-xs text-amber-300 space-y-1">
                        <p class="font-semibold">Opname yang belum selesai (barangnya tidak bisa dihitung dua kali):</p>
                        @foreach ($openOpnames as $open)
                            <p><a href="{{ route('gudang.opname.show', $open->id) }}" class="font-mono underline">{{ $open->opname_number }}</a> · {{ $open->scope_label }}</p>
                        @endforeach
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Catatan / Periode</label>
                    <textarea name="notes" rows="2" placeholder="Contoh: Cek jersey Gothic yang sering selisih"
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">{{ old('notes') }}</textarea>
                </div>

                <div class="pt-4 flex items-center gap-3">
                    <a href="{{ route('gudang.opname') }}"
                        class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                    <button type="submit"
                        class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Buat Daftar Hitung
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function opnameProducts(scope, picked, products) {
            return {
                scope, picked, products, query: '', error: '',
                add() {
                    // Pilihan dari daftar berbentuk "SKU — nama produk"; ambil kodenya saja
                    const sku = this.query.split(' — ')[0].trim().toUpperCase();
                    this.error = '';
                    if (!sku) return;
                    if (!this.products[sku]) {
                        this.error = sku + ' tidak ada di Master SKU.';
                        return;
                    }
                    if (this.scope === 'ONE') this.picked = [sku];
                    else if (!this.picked.includes(sku)) this.picked.push(sku);
                    this.query = '';
                },
                remove(sku) {
                    this.picked = this.picked.filter(s => s !== sku);
                },
                info(sku) {
                    const p = this.products[sku];
                    if (!p) return '';
                    return p.name + ' · stok ' + p.stock + (p.bins ? ' di ' + p.bins + ' lokasi' : ' (belum ada stok)');
                },
            };
        }
    </script>
</x-app-layout>