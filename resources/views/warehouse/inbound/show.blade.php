<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.inbound') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Detail Penerimaan: {{ $inbound->reference_no }}</h2>
                <p class="text-sm text-slate-400 mt-1">{{ $inbound->created_at->format('d M Y H:i') }} • {{ $inbound->user?->name }}</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-6xl mx-auto space-y-6">
        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">
                <ul class="text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($canCorrect)
            <!-- Koreksi penerimaan yang salah input -->
            <div x-data="{ hapus: {{ $errors->has('reason') ? 'true' : 'false' }} }" class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="flex-1 min-w-[12rem] text-sm text-slate-400">Salah input? Edit atau hapus penerimaan ini; stok dan qty PO ikut disesuaikan.</p>
                    <a href="{{ route('gudang.inbound.edit', $inbound->id) }}"
                        class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-xl">Edit penerimaan</a>
                    <button type="button" @click="hapus = !hapus" x-show="!hapus"
                        class="px-4 py-2 bg-slate-700 hover:bg-red-600 text-white text-sm font-semibold rounded-xl">Hapus penerimaan</button>
                </div>
                <form x-show="hapus" x-cloak method="POST" action="{{ route('gudang.inbound.destroy', $inbound->id) }}"
                    class="mt-3 flex flex-wrap items-center gap-2 p-3 rounded-xl border border-red-500/40 bg-red-900/10">
                    @csrf @method('DELETE')
                    <p class="w-full text-xs text-red-300">Stok {{ number_format($items->sum('qty')) }} pcs dari penerimaan ini dikurangi lagi dari raknya{{ $po ? ', dan qty diterima di PO ' . $po->po_number . ' dikembalikan' : '' }}. Ditolak jika barangnya sudah dipindah atau dipakai order.</p>
                    <input type="text" name="reason" required maxlength="500" placeholder="Alasan dihapus (wajib), mis. salah pilih PO"
                        class="flex-1 min-w-[12rem] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-red-400 focus:outline-none">
                    <button type="submit" onclick="return confirm('Hapus penerimaan {{ $inbound->reference_no }}?')"
                        class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl">Ya, hapus</button>
                    <button type="button" @click="hapus = false" class="text-sm text-slate-400 hover:text-white">Batal</button>
                </form>
            </div>
        @endif

        <!-- Cetak label SKU untuk barang yang baru diterima -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row md:items-center gap-3">
            <p class="flex-1 text-sm text-slate-300">Tempel label SKU di setiap barang sebelum disimpan ke rak, supaya bisa di-scan saat picking dan opname.</p>
            <a href="{{ route('gudang.label.sku', ['ref' => $inbound->reference_no]) }}" target="_blank"
                class="px-4 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 text-sm font-bold rounded-xl text-center">Cetak label SKU ({{ $items->sum('qty') }})</a>
            <a href="{{ route('gudang.label.sku', ['ref' => $inbound->reference_no, 'ukuran' => 'a4']) }}" target="_blank"
                class="px-4 py-3 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl text-center">Versi kertas A4</a>
        </div>

        @if ($po)
            <!-- PO terkait: berapa yang sudah datang dan sisa yang masih ditunggu -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <p class="text-sm text-slate-300">Dari PO
                        <a href="{{ route('po.show', $po->id) }}" class="font-mono font-bold text-cyan-400 hover:text-cyan-300">{{ $po->po_number }}</a>
                        <span class="ml-2 text-xs px-2 py-1 rounded-full {{ $po->status_color }}">{{ $po->status_label }}</span>
                    </p>
                    <p class="text-sm text-slate-400">Diterima {{ number_format($po->qty_received_total) }} / {{ number_format($po->qty_ordered_total) }} pcs
                        @if ($po->qty_remaining_total > 0)
                            · <span class="text-orange-400 font-semibold">sisa {{ number_format($po->qty_remaining_total) }} pcs belum datang</span>
                        @endif
                    </p>
                </div>
                @if ($po->qty_remaining_total > 0 && $po->status !== 'CANCELLED')
                    <ul class="text-xs text-slate-400 space-y-1">
                        @foreach ($po->items->filter(fn ($i) => $i->qty_remaining > 0) as $poItem)
                            <li><span class="font-mono text-cyan-400">{{ $poItem->sku }}</span>: sisa {{ number_format($poItem->qty_remaining) }} dari {{ number_format($poItem->qty_ordered) }} pcs</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <!-- Info Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <p class="text-xs text-slate-400 mb-1">No. Receipt</p>
                    <p class="text-lg font-bold text-cyan-400 font-mono">{{ $inbound->reference_no }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 mb-1">Tanggal</p>
                    <p class="text-lg font-bold text-white">{{ $inbound->created_at->format('d M Y') }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 mb-1">Diterima Oleh</p>
                    <p class="text-lg font-bold text-white">{{ $inbound->user?->name }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 mb-1">Total Diterima</p>
                    <p class="text-lg font-bold text-emerald-400">{{ number_format($items->sum('qty')) }} pcs
                        <span class="text-xs font-normal text-slate-400">· {{ $items->pluck('sku')->unique()->count() }} SKU</span></p>
                    @if ($po && $po->isReceivable() && $po->qty_remaining_total > 0)
                        <span class="inline-block mt-1 text-xs px-2 py-1 rounded-full bg-orange-500/20 text-orange-400">Belum Komplit · baru sebagian jadi</span>
                    @else
                        <span class="inline-block mt-1 text-xs px-2 py-1 rounded-full bg-emerald-500/20 text-emerald-400">Komplit</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Item Diterima</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-3 px-4">SKU</th>
                            <th class="text-left py-3 px-4">Produk</th>
                            <th class="text-left py-3 px-4">Bin</th>
                            <th class="text-right py-3 px-4">Diterima di sini</th>
                            @if ($po)
                                <th class="text-right py-3 px-4">Dipesan (PO)</th>
                                <th class="text-right py-3 px-4">Total diterima</th>
                                <th class="text-right py-3 px-4">Sisa diproses</th>
                            @endif
                            <th class="text-left py-3 px-4">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @php $poItems = $po ? $po->items->keyBy('sku') : collect(); @endphp
                        @foreach($items as $item)
                        @php $poItem = $poItems[$item->sku] ?? null; @endphp
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-3 px-4 font-mono text-cyan-400 font-bold">{{ $item->sku }}</td>
                            <td class="py-3 px-4 text-white">{{ $item->masterSku?->product_name ?? '-' }} {{ $item->masterSku?->size }}</td>
                            <td class="py-3 px-4 font-mono text-slate-300">{{ $item->bin_code }}</td>
                            <td class="py-3 px-4 text-right font-bold text-emerald-400">+{{ number_format($item->qty) }}</td>
                            @if ($po)
                                <td class="py-3 px-4 text-right text-white">{{ $poItem ? number_format($poItem->qty_ordered) : '-' }}</td>
                                <td class="py-3 px-4 text-right text-slate-300">{{ $poItem ? number_format($poItem->qty_received) : '-' }}</td>
                                <td class="py-3 px-4 text-right font-semibold whitespace-nowrap">
                                    @if (!$poItem)
                                        <span class="text-slate-500">-</span>
                                    @elseif ($poItem->qty_remaining > 0)
                                        <span class="text-orange-400">{{ number_format($poItem->qty_remaining) }} pcs</span>
                                    @else
                                        <span class="text-emerald-400">Lengkap</span>
                                    @endif
                                </td>
                            @endif
                            <td class="py-3 px-4 text-xs text-slate-400">{{ $item->notes ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>