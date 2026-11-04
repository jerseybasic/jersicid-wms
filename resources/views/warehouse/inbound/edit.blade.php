<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.inbound.show', $inbound->id) }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all border border-slate-700">← Kembali</a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Edit Penerimaan: {{ $inbound->reference_no }}</h2>
                <p class="text-sm text-slate-400 mt-1">Stok di rak dan qty diterima di PO ikut disesuaikan</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">
        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">
                <ul class="text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            $poItems = $po ? $po->items->keyBy('sku') : collect();
            // Qty diterima PO di luar penerimaan ini, per SKU
            $otherReceived = $items->groupBy('sku')->map(fn ($rows, $sku) => ($poItems[$sku]->qty_received ?? 0) - $rows->sum('qty'));
            $initial = [
                'lines' => $items->mapWithKeys(fn ($i) => [$i->id => (int) old('qty.' . $i->id, $i->qty)]),
                'ordered' => $items->pluck('sku')->unique()->filter(fn ($sku) => isset($poItems[$sku]))
                    ->mapWithKeys(fn ($sku) => [$sku => (int) old('po_qty.' . $sku, $poItems[$sku]->qty_ordered)]),
                'skuOf' => $items->pluck('sku', 'id'),
                'other' => $otherReceived,
            ];
        @endphp
        <form method="POST" action="{{ route('gudang.inbound.update', $inbound->id) }}" class="space-y-6"
            x-data="{ ...@js($initial),
                received(sku) { return (this.other[sku] || 0) + Object.keys(this.lines).filter(id => this.skuOf[id] === sku).reduce((t, id) => t + (parseInt(this.lines[id]) || 0), 0); },
                remaining(sku) { return (parseInt(this.ordered[sku]) || 0) - this.received(sku); } }">
            @csrf @method('PUT')

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">No. Surat Jalan <span class="text-red-400">*</span></label>
                    <input type="text" name="receipt_number" required maxlength="100" value="{{ old('receipt_number', $inbound->reference_no) }}"
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white font-mono focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <p class="block text-sm font-medium text-slate-300 mb-2">PO Terkait</p>
                    <p class="px-4 py-3 text-sm text-slate-300">
                        @if ($po)
                            <a href="{{ route('po.show', $po->id) }}" class="font-mono text-cyan-400">{{ $po->po_number }}</a>
                            · {{ $po->status_label }} · sisa {{ number_format($po->qty_remaining_total) }} pcs
                        @else
                            Tanpa PO
                        @endif
                    </p>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Catatan</label>
                    <textarea name="notes" rows="2" maxlength="1000"
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none">{{ old('notes', $inbound->notes) }}</textarea>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <div class="p-4 border-b border-slate-800">
                    <h3 class="text-base font-semibold text-white">Qty Diterima</h3>
                    <p class="text-xs text-slate-400 mt-1">Isi 0 untuk membuang baris yang salah. Pindah rak lewat menu Transfer Stok. @if ($po) Kolom Dipesan (PO) mengubah qty pesanan di PO {{ $po->po_number }}; tidak boleh di bawah yang sudah diterima. @endif</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                                <th class="text-left py-3 px-4">SKU</th>
                                <th class="text-left py-3 px-4">Produk</th>
                                <th class="text-left py-3 px-4">Bin</th>
                                <th class="text-right py-3 px-4">Qty sekarang</th>
                                <th class="text-right py-3 px-4 w-32">Qty benar</th>
                                @if ($po)
                                    <th class="text-right py-3 px-4 w-32">Dipesan (PO)</th>
                                    <th class="text-right py-3 px-4">Sisa diproses</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @php $shownSku = []; @endphp
                            @foreach ($items as $item)
                                <tr>
                                    <td class="py-3 px-4 font-mono text-cyan-400 font-bold">{{ $item->sku }}</td>
                                    <td class="py-3 px-4 text-white">{{ $item->masterSku?->product_name ?? '-' }} {{ $item->masterSku?->size }}</td>
                                    <td class="py-3 px-4 font-mono text-slate-300">{{ $item->bin_code }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">{{ number_format($item->qty) }}</td>
                                    <td class="py-3 px-4 text-right">
                                        <input type="number" name="qty[{{ $item->id }}]" min="0" required x-model="lines['{{ $item->id }}']"
                                            class="w-24 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-right focus:border-cyan-400 focus:outline-none">
                                    </td>
                                    @if ($po)
                                        @php $poItem = $poItems[$item->sku] ?? null; $first = $poItem && !in_array($item->sku, $shownSku, true); $shownSku[] = $item->sku; @endphp
                                        <td class="py-3 px-4 text-right">
                                            @if ($first)
                                                <input type="number" name="po_qty[{{ $item->sku }}]" min="1" required x-model="ordered['{{ $item->sku }}']"
                                                    class="w-24 px-3 py-2 bg-slate-800 border border-amber-500/40 rounded-lg text-white text-right focus:border-amber-400 focus:outline-none">
                                            @elseif (!$poItem)
                                                <span class="text-xs text-slate-500">tidak di PO</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-right whitespace-nowrap">
                                            @if ($first)
                                                <span class="font-semibold" :class="remaining('{{ $item->sku }}') > 0 ? 'text-orange-400' : (remaining('{{ $item->sku }}') === 0 ? 'text-emerald-400' : 'text-red-400')"
                                                    x-text="remaining('{{ $item->sku }}') > 0 ? remaining('{{ $item->sku }}') + ' pcs' : (remaining('{{ $item->sku }}') === 0 ? 'Lengkap' : 'Lebih ' + (-remaining('{{ $item->sku }}')) + ' pcs')"></span>
                                                <span class="block text-[11px] text-slate-500" x-text="'diterima ' + received('{{ $item->sku }}') + ' dari ' + (ordered['{{ $item->sku }}'] || 0)"></span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6">
                <label class="block text-sm font-medium text-slate-300 mb-2">Alasan perubahan <span class="text-red-400">*</span></label>
                <input type="text" name="reason" required maxlength="500" value="{{ old('reason') }}" placeholder="mis. salah hitung, yang datang ternyata 3 pcs"
                    class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none">
                <p class="text-xs text-slate-500 mt-2">Isi sebelum dan sesudah perubahan tercatat di Audit Trail. Qty tidak bisa dikurangi jika barangnya sudah dipindah atau dijatah untuk order.</p>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('gudang.inbound.show', $inbound->id) }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700">Batal</a>
                <button type="submit" class="flex-1 py-3 bg-cyan-600 hover:bg-cyan-500 text-on-accent font-bold rounded-xl">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</x-app-layout>
