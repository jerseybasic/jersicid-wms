<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('po.show', $po->id) }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all border border-slate-700">← Kembali</a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Koreksi SKU PO: {{ $po->po_number }}</h2>
                <p class="text-sm text-slate-400 mt-1">{{ $po->supplier_name }} · {{ $po->status_label }} · untuk SKU yang salah input, juga pada PO yang sudah diterima</p>
            </div>
        </div>
    </x-slot>

    @php
        $info = $skus->mapWithKeys(fn ($s) => [$s->sku => trim($s->product_name . ' ' . $s->size)]);
        $initial = [
            'lines' => $po->items->map(fn ($i) => [
                'id' => $i->id,
                'sku' => $i->sku,
                'name' => trim(($i->masterSku?->product_name ?? '') . ' ' . ($i->masterSku?->size ?? '')),
                'ordered' => (int) $i->qty_ordered,
                'received' => (int) $i->qty_received,
                'bins' => (object) ($bins[$i->id] ?? []),
            ])->values(),
            'choice' => $po->items->mapWithKeys(fn ($i) => [$i->id => (string) old('sku.' . $i->id, $i->sku)]),
            'info' => $info,
            'reason' => (string) old('reason'),
            'reviewing' => $errors->has('final_check'),
            'checked' => false,
        ];
    @endphp

    <div class="max-w-5xl mx-auto space-y-6" x-data="{ ...@js($initial),
        newSku(l) { return (this.choice[l.id] || '').trim().toUpperCase(); },
        changes() { return this.lines.filter(l => this.newSku(l) !== '' && this.newSku(l) !== l.sku); },
        unknown() { return this.changes().filter(l => !(this.newSku(l) in this.info)); },
        mergeInto(l) { return this.lines.some(o => o.id !== l.id && o.sku === this.newSku(l)); },
        conflicts() { const news = this.changes().map(l => this.newSku(l)); const olds = this.changes().map(l => l.sku); return news.filter((s, i) => news.indexOf(s) !== i || olds.includes(s)); },
        binsText(l) { const e = Object.entries(l.bins); return e.length ? e.map(([b, q]) => b + ' (' + q + ')').join(', ') : '-'; },
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
            <p>Ganti SKU baris yang salah. Qty pesanan, qty diterima, dan harga ikut pindah ke SKU yang benar. Jika SKU yang benar sudah ada di PO ini, kedua baris digabung.</p>
            <p class="text-slate-400">Barang yang sudah diterima untuk baris itu ikut dikoreksi di setiap penerimaannya: stok SKU lama di bin itu dikurangi dan stok SKU yang benar ditambah. Ditolak jika barang SKU lama sudah dipindah, diambil, atau dijatah untuk order.</p>
        </div>

        <form method="POST" action="{{ route('po.correct-sku', $po->id) }}" x-ref="form" class="space-y-6">
            @csrf @method('PUT')

            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                                <th class="text-left py-3 px-4">SKU sekarang</th>
                                <th class="text-left py-3 px-4">Produk</th>
                                <th class="text-right py-3 px-4">Dipesan</th>
                                <th class="text-right py-3 px-4">Diterima</th>
                                <th class="text-left py-3 px-4">Disimpan di</th>
                                <th class="text-left py-3 px-4 min-w-[16rem]">SKU benar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($po->items as $item)
                                <tr>
                                    <td class="py-3 px-4 font-mono text-cyan-400 font-bold">{{ $item->sku }}</td>
                                    <td class="py-3 px-4 text-white">{{ $item->masterSku?->product_name ?? '-' }} {{ $item->masterSku?->size }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">{{ number_format($item->qty_ordered) }}</td>
                                    <td class="py-3 px-4 text-right text-emerald-400">{{ number_format($item->qty_received) }}</td>
                                    <td class="py-3 px-4 font-mono text-xs text-slate-400">
                                        @forelse ($bins[$item->id] ?? [] as $bin => $qty)
                                            <span class="block">{{ $bin }} ({{ $qty }})</span>
                                        @empty
                                            -
                                        @endforelse
                                    </td>
                                    <td class="py-3 px-4">
                                        <input type="text" name="sku[{{ $item->id }}]" x-model="choice['{{ $item->id }}']" list="sku-options" @input="reviewing = false" autocomplete="off"
                                            class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white font-mono text-sm uppercase focus:border-amber-400 focus:outline-none">
                                        <p class="text-[11px] mt-1" :class="newSku({ id: '{{ $item->id }}' }) in info ? 'text-slate-500' : 'text-red-400'"
                                            x-text="newSku({ id: '{{ $item->id }}' }) in info ? info[newSku({ id: '{{ $item->id }}' })] : 'SKU tidak ada di Master SKU'"></p>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <datalist id="sku-options">
                @foreach ($skus as $option)
                    <option value="{{ $option->sku }}">{{ $option->product_name }} {{ $option->size }}</option>
                @endforeach
            </datalist>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Alasan koreksi <span class="text-red-400">*</span></label>
                <input type="text" name="reason" required maxlength="500" x-model="reason" placeholder="mis. salah ketik SKU saat buat PO, yang dipesan ukuran L"
                    class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-amber-400 focus:outline-none">
                <p class="text-xs text-slate-500 mt-2">SKU lama dan baru beserta alasannya dicatat di catatan PO, riwayat penerimaan, dan Audit Trail.</p>
            </div>

            <div class="flex items-center gap-3" x-show="!reviewing">
                <a href="{{ route('po.show', $po->id) }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700">Batal</a>
                <button type="button" @click="review()" :disabled="changes().length === 0"
                    class="flex-1 py-3 bg-amber-600 hover:bg-amber-500 disabled:opacity-40 disabled:cursor-not-allowed text-on-accent font-bold rounded-xl"
                    x-text="changes().length === 0 ? 'Belum ada SKU yang diganti' : 'Periksa Ringkasan (' + changes().length + ' baris)'"></button>
            </div>

            <!-- Pengecekan terakhir sebelum PO dan stok dikoreksi -->
            <div x-show="reviewing" x-cloak x-ref="summary" class="bg-slate-900 border-2 border-amber-500/60 rounded-2xl p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-white">Pengecekan Terakhir</h3>
                    <p class="text-sm text-slate-400">Cocokkan dengan surat jalan, barang fisik, dan label di raknya sebelum disimpan.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-400 border-b border-slate-800">
                                <th class="text-left py-2 pr-4">SKU lama</th>
                                <th class="text-left py-2 pr-4">SKU benar</th>
                                <th class="text-right py-2 pr-4">Dipesan</th>
                                <th class="text-right py-2 pr-4">Diterima</th>
                                <th class="text-left py-2">Stok dipindah di bin</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <template x-for="l in changes()" :key="l.id">
                                <tr>
                                    <td class="py-2 pr-4"><span class="font-mono text-red-400 line-through" x-text="l.sku"></span><span class="block text-xs text-slate-500" x-text="l.name"></span></td>
                                    <td class="py-2 pr-4"><span class="font-mono text-emerald-400 font-bold" x-text="newSku(l)"></span><span class="block text-xs text-slate-500" x-text="(info[newSku(l)] || 'tidak dikenal') + (mergeInto(l) ? ' · digabung ke baris yang sudah ada' : '')"></span></td>
                                    <td class="py-2 pr-4 text-right text-white" x-text="l.ordered"></td>
                                    <td class="py-2 pr-4 text-right text-emerald-400" x-text="l.received"></td>
                                    <td class="py-2 font-mono text-xs text-slate-300" x-text="binsText(l)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-400">Alasan: <span class="text-slate-200" x-text="reason || '-'"></span></p>
                <template x-if="unknown().length">
                    <p class="text-sm text-red-400 font-semibold">Ada SKU yang tidak ada di Master SKU. Perbaiki dulu.</p>
                </template>
                <template x-if="conflicts().length">
                    <p class="text-sm text-red-400 font-semibold">SKU <span x-text="[...new Set(conflicts())].join(', ')"></span> dipakai dua kali atau sedang ditukar. Koreksi satu per satu.</p>
                </template>
                <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-800/60 border border-slate-700 cursor-pointer">
                    <input type="checkbox" name="final_check" value="1" x-model="checked" class="mt-1 w-5 h-5 rounded">
                    <span class="text-sm text-white">Saya sudah mengecek: SKU yang benar, qty, dan lokasi raknya sesuai ringkasan di atas.</span>
                </label>
                <div class="flex items-center gap-3">
                    <button type="button" @click="reviewing = false" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700">Ubah lagi</button>
                    <button type="submit" :disabled="!checked || unknown().length > 0 || conflicts().length > 0"
                        class="flex-1 py-3 bg-amber-600 hover:bg-amber-500 disabled:opacity-40 disabled:cursor-not-allowed text-on-accent font-bold rounded-xl">Simpan Koreksi SKU</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
