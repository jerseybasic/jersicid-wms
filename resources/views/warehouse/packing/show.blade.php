<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('gudang.packing') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Kembali
                </a>
                <div>
                    <h2 class="font-semibold text-xl text-white leading-tight">Packing: {{ $session->session_number }}</h2>
                    <p class="text-sm text-slate-400 mt-1">Order: {{ $session->order?->order_number }}</p>
                </div>
            </div>
            <span class="px-3 py-1 rounded-full text-sm font-bold {{ $session->status_color }}">{{ $session->status }}</span>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Action Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between">
            <div class="text-sm text-slate-400">
                Total Items: <span class="text-white font-bold">{{ $session->items->count() }}</span> |
                Completed: <span class="text-emerald-400 font-bold">{{ $session->items->where('status', 'COMPLETED')->count() }}</span>
            </div>

            @if($session->status === 'PENDING')
                <form method="POST" action="{{ route('gudang.packing.start', $session->id) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg text-sm transition-all">
                        ▶ Mulai Packing
                    </button>
                </form>
            @endif
        </div>

        @if($session->status === 'IN_PROGRESS')
            <x-scan-box :endpoint="route('gudang.packing.scan', $session->id)" storage-key="packing-{{ $session->id }}"
                :with-bin="false" hint="Scan setiap barang yang dimasukkan ke paket. Barang yang bukan pesanan ini akan ditolak." />
        @endif

        @if($session->status === 'COMPLETED' && $session->order)
            <div class="bg-emerald-900/20 border border-emerald-500/40 rounded-xl p-4 flex flex-col md:flex-row md:items-center gap-3">
                <p class="flex-1 text-sm text-emerald-300">Packing selesai. Cetak label kirim lalu tempel di paket.</p>
                <a href="{{ route('gudang.label.shipping', $session->order->id) }}" target="_blank"
                    class="px-5 py-3 bg-emerald-500 hover:bg-emerald-400 text-slate-900 text-sm font-bold rounded-xl text-center">Cetak label kirim</a>
            </div>
        @endif

        <!-- Items List -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Daftar Item</h3>
            </div>

            <div class="divide-y divide-slate-800">
                @foreach($session->items as $item)
                    <div class="p-4 hover:bg-slate-800/30 transition-colors">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <p class="text-sm font-mono font-bold text-cyan-400">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400">{{ $item->masterSku?->product_name }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm text-slate-300">Dikemas: <span class="font-bold text-emerald-400" data-scan-qty="{{ $item->id }}">{{ $item->qty_packed }}</span> / {{ $item->qty_ordered }}</p>
                                <p class="text-xs text-slate-400">Status: <span class="{{ $item->status === 'COMPLETED' ? 'text-emerald-400' : 'text-amber-400' }}">{{ $item->status }}</span></p>
                            </div>
                        </div>

                        @if($session->status === 'IN_PROGRESS' && $item->status !== 'COMPLETED')
                            <form method="POST" action="{{ route('gudang.packing.pack', [$session->id, $item->id]) }}" class="flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="flex-1 min-w-[8rem]">
                                    <label class="block text-xs text-slate-400 mb-1">Qty Dikemas</label>
                                    <input type="number" name="qty_packed" value="{{ $item->qty_packed ?: $item->qty_ordered }}" min="0" max="{{ $item->qty_ordered }}" required
                                           class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                                </div>
                                <div class="flex items-end">
                                    <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg transition-all">
                                        Update
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="flex items-center gap-4 text-xs text-slate-400">
                                <span>Ordered: <span class="text-white font-bold">{{ $item->qty_ordered }}</span></span>
                                <span>Dikemas: <span class="text-emerald-400 font-bold">{{ $item->qty_packed }}</span></span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>