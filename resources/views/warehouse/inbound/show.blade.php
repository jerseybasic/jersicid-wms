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

        <!-- Cetak label SKU untuk barang yang baru diterima -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row md:items-center gap-3">
            <p class="flex-1 text-sm text-slate-300">Tempel label SKU di setiap barang sebelum disimpan ke rak, supaya bisa di-scan saat picking dan opname.</p>
            <a href="{{ route('gudang.label.sku', ['ref' => $inbound->reference_no]) }}" target="_blank"
                class="px-4 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 text-sm font-bold rounded-xl text-center">Cetak label SKU ({{ $items->sum('qty') }})</a>
            <a href="{{ route('gudang.label.sku', ['ref' => $inbound->reference_no, 'ukuran' => 'a4']) }}" target="_blank"
                class="px-4 py-3 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl text-center">Versi kertas A4</a>
        </div>

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
                    <p class="text-xs text-slate-400 mb-1">Total Item</p>
                    <p class="text-lg font-bold text-emerald-400">{{ $items->count() }}</p>
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
                            <th class="text-right py-3 px-4">Qty</th>
                            <th class="text-left py-3 px-4">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach($items as $item)
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-3 px-4 font-mono text-cyan-400 font-bold">{{ $item->sku }}</td>
                            <td class="py-3 px-4 text-white">{{ $item->masterSku?->product_name ?? '-' }}</td>
                            <td class="py-3 px-4 font-mono text-slate-300">{{ $item->bin_code }}</td>
                            <td class="py-3 px-4 text-right font-bold text-emerald-400">+{{ number_format($item->qty) }}</td>
                            <td class="py-3 px-4 text-xs text-slate-400">{{ $item->notes ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>