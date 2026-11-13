<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.inbound.show', $inbound->id) }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all border border-slate-700">← Kembali</a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Koreksi SKU: {{ $inbound->reference_no }}</h2>
                <p class="text-sm text-slate-400 mt-1">Untuk SKU yang salah input, juga pada penerimaan yang sudah Komplit</p>
            </div>
        </div>
    </x-slot>

    @php
        $skuInfo = collect($skus)->mapWithKeys(fn ($s) => [$s['sku'] => trim(($s['product_name'] ?? '') . ' ' . ($s['size'] ?? ''))]);
        $initial = [
            'lines' => $items->map(fn ($i) => [
                'id' => $i->id,
                'sku' => $i->sku,
                'name' => trim(($i->masterSku?->product_name ?? '') . ' ' . ($i->masterSku?->size ?? '')),
                'bin' => $i->bin_code,
                'qty' => (int) $i->qty,
            ])->values(),
            'choice' => $items->mapWithKeys(fn ($i) => [$i->id => (string) old('sku.' . $i->id, $i->sku)]),
            'info' => $skuInfo,
            'remaining' => $po ? collect($skus)->mapWithKeys(fn ($s) => [$s['sku'] => $s['remaining']]) : (object) [],
            'reason' => (string) old('reason'),
            'reviewing' => $errors->has('final_check'),
            'checked' => false,
        ];
    @endphp

    <div class="max-w-5xl mx-auto space-y-6" x-data="{ ...@js($initial),
        changes() { return this.lines.filter(l => (this.choice[l.id] || '').trim().toUpperCase() !== l.sku && (this.choice[l.id] || '').trim() !== ''); },
        newSku(l) { return (this.choice[l.id] || '').trim().toUpperCase(); },
        unknown() { return this.changes().filter(l => !(this.newSku(l) in this.info)); },
        added(sku) { return this.changes().filter(l => this.newSku(l) === sku).reduce((t, l) => t + l.qty, 0) - this.changes().filter(l => l.sku === sku).reduce((t, l) => t + l.qty, 0); },
        overPo() { return {{ $po ? 'true' : 'false' }} ? [...new Set(this.changes().map(l => this.newSku(l)))].filter(s => this.added(s) > (this.remaining[s] ?? 0)) : []; },
        review() { if (!this.$refs.form.reportValidity()) return; this.reviewing = true; this.checked = false; this.$nextTick(() => this.$refs.summary.scrollIntoView({ behavior: 'smooth', block: 'start' })); } }">

        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">
                <ul class="text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl text-sm text-slate-300 space-y-1">
            <p>Pilih SKU yang benar untuk baris yang salah. Barangnya tetap di bin yang sama: stok SKU lama di bin itu dikurangi, stok SKU yang benar ditambah.</p>
            @if ($po)
                <p class="text-slate-400">Penerimaan ini dari PO <a href="{{ route('po.show', $po->id) }}" class="font-mono text-cyan-400">{{ $po->po_number }}</a> ({{ $po->status_label }}), jadi pilihan SKU hanya yang ada di PO itu. Qty diterima di PO ikut dipindah. Kalau SKU di PO-nya juga salah, pakai <a href="{{ route('po.correct-sku.form', $po->id) }}" class="text-amber-400 underline">Koreksi SKU di PO</a>; penerimaan ini ikut terkoreksi.</p>
            @endif
            <p class="text-slate-400">Ditolak jika barang SKU lama sudah dipindah, diambil, atau dijatah untuk order. Setelah disimpan, cetak ulang label SKU untuk barangnya.</p>
        </div>

        <form method="POST" action="{{ route('gudang.inbound.correct-sku', $inbound->id) }}" x-ref="form" class="space-y-6">
            @csrf @method('PUT')

            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                                <th class="text-left py-3 px-4">SKU sekarang</th>
                                <th class="text-left py-3 px-4">Produk</th>
                                <th class="text-left py-3 px-4">Bin</th>
                                <th class="text-right py-3 px-4">Qty</th>
                                <th class="text-left py-3 px-4 min-w-[16rem]">SKU benar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($items as $item)
                                <tr :class="newSku({ id: {{ $item->id }} }) !== @js($item->sku) ? 'bg-amber-500/5' : ''">
                                    <td class="py-3 px-4 font-mono text-cyan-400 font-bold">{{ $item->sku }}</td>
                                    <td class="py-3 px-4 text-white">{{ $item->masterSku?->product_name ?? '-' }} {{ $item->masterSku?->size }}</td>
                                    <td class="py-3 px-4 font-mono text-slate-300">{{ $item->bin_code }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">{{ number_format($item->qty) }}</td>
                                    <td class="py-3 px-4">
                                        @if ($po)
                                            <select name="sku[{{ $item->id }}]" x-model="choice['{{ $item->id }}']" @change="reviewing = false"
                                                class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white font-mono text-sm focus:border-amber-400 focus:outline-none">
                                                @foreach ($skus as $option)
                                                    <option value="{{ $option['sku'] }}">{{ $option['sku'] }} · {{ $option['product_name'] }} {{ $option['size'] }}{{ $option['sku'] === $item->sku ? ' (sekarang)' : ' · sisa PO ' . $option['remaining'] }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input type="text" name="sku[{{ $item->id }}]" x-model="choice['{{ $item->id }}']" list="sku-options" @input="reviewing = false" autocomplete="off"
                                                class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white font-mono text-sm uppercase focus:border-amber-400 focus:outline-none">
                                            <p class="text-[11px] mt-1" :class="newSku({ id: {{ $item->id }} }) in info ? 'text-slate-500' : 'text-red-400'"
                                                x-text="newSku({ id: {{ $item->id }} }) in info ? info[newSku({ id: {{ $item->id }} })] : 'SKU tidak ada di Master SKU'"></p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @unless ($po)
                <datalist id="sku-options">
                    @foreach ($skus as $option)
                        <option value="{{ $option['sku'] }}">{{ $option['product_name'] }} {{ $option['size'] }}</option>
                    @endforeach
                </datalist>
            @endunless

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Alasan koreksi <span class="text-red-400">*</span></label>
                <input type="text" name="reason" required maxlength="500" x-model="reason" placeholder="mis. salah pilih ukuran, yang datang ternyata XL"
                    class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-amber-400 focus:outline-none">
                <p class="text-xs text-slate-500 mt-2">SKU lama dan baru, qty, bin, dan alasannya tercatat di Audit Trail dan di riwayat koreksi penerimaan ini.</p>
            </div>

            <div class="flex items-center gap-3" x-show="!reviewing">
                <a href="{{ route('gudang.inbound.show', $inbound->id) }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700">Batal</a>
                <button type="button" @click="review()" :disabled="changes().length === 0"
                    class="flex-1 py-3 bg-amber-600 hover:bg-amber-500 disabled:opacity-40 disabled:cursor-not-allowed text-on-accent font-bold rounded-xl"
                    x-text="changes().length === 0 ? 'Belum ada SKU yang diganti' : 'Periksa Ringkasan (' + changes().length + ' baris)'"></button>
            </div>

            <!-- Pengecekan terakhir sebelum stok dipindah -->
            <div x-show="reviewing" x-cloak x-ref="summary" class="bg-slate-900 border-2 border-amber-500/60 rounded-2xl p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-white">Pengecekan Terakhir</h3>
                    <p class="text-sm text-slate-400">Cocokkan dengan barang fisik dan label di raknya sebelum disimpan.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-400 border-b border-slate-800">
                                <th class="text-left py-2 pr-4">Bin</th>
                                <th class="text-left py-2 pr-4">SKU lama</th>
                                <th class="text-left py-2 pr-4">SKU benar</th>
                                <th class="text-right py-2">Qty dipindah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <template x-for="l in changes()" :key="l.id">
                                <tr>
                                    <td class="py-2 pr-4 font-mono text-slate-300" x-text="l.bin"></td>
                                    <td class="py-2 pr-4"><span class="font-mono text-red-400 line-through" x-text="l.sku"></span><span class="block text-xs text-slate-500" x-text="l.name"></span></td>
                                    <td class="py-2 pr-4"><span class="font-mono text-emerald-400 font-bold" x-text="newSku(l)"></span><span class="block text-xs text-slate-500" x-text="info[newSku(l)] || 'tidak dikenal'"></span></td>
                                    <td class="py-2 text-right text-white font-semibold" x-text="l.qty + ' pcs'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <ul class="text-xs text-slate-400 space-y-1">
                    <template x-for="l in changes()" :key="'fx' + l.id">
                        <li>Stok <span class="font-mono" x-text="l.sku"></span> di <span class="font-mono" x-text="l.bin"></span> −<span x-text="l.qty"></span>, stok <span class="font-mono" x-text="newSku(l)"></span> di <span class="font-mono" x-text="l.bin"></span> +<span x-text="l.qty"></span>{{ $po ? '; qty diterima PO ikut pindah' : '' }}.</li>
                    </template>
                    <li>Alasan: <span class="text-slate-200" x-text="reason || '-'"></span></li>
                </ul>
                <template x-if="unknown().length">
                    <p class="text-sm text-red-400 font-semibold">Ada SKU yang tidak ada di Master SKU. Perbaiki dulu.</p>
                </template>
                <template x-if="overPo().length">
                    <p class="text-sm text-red-400 font-semibold">Melebihi sisa PO: <span x-text="overPo().join(', ')"></span>. Naikkan qty PO lewat Edit PO, atau pakai Koreksi SKU di PO.</p>
                </template>
                <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-800/60 border border-slate-700 cursor-pointer">
                    <input type="checkbox" name="final_check" value="1" x-model="checked" class="mt-1 w-5 h-5 rounded">
                    <span class="text-sm text-white">Saya sudah mengecek barang fisiknya: SKU benar, qty, dan lokasi raknya sesuai ringkasan di atas.</span>
                </label>
                <div class="flex items-center gap-3">
                    <button type="button" @click="reviewing = false" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700">Ubah lagi</button>
                    <button type="submit" :disabled="!checked || unknown().length > 0 || overPo().length > 0"
                        class="flex-1 py-3 bg-amber-600 hover:bg-amber-500 disabled:opacity-40 disabled:cursor-not-allowed text-on-accent font-bold rounded-xl">Simpan Koreksi SKU</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
