<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.tugas') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Tugas</span>
            </a>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight truncate">Picking {{ $session->order?->order_number }}</h2>
                <p class="text-xs sm:text-sm text-slate-400 mt-0.5 font-mono truncate">{{ $session->session_number }}</p>
            </div>
            <span class="hidden sm:inline-block shrink-0 px-3 py-1 rounded-full text-sm font-bold {{ $session->status_color }}">{{ $session->status_label }}</span>
        </div>
    </x-slot>

    @php
        $running = $session->status === 'IN_PROGRESS';
        $canManual = in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'], true);
        $order = $session->order;
    @endphp

    <div class="max-w-3xl mx-auto space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        @if ($session->batch_id)
            <div class="p-3 bg-cyan-900/20 border border-cyan-500/40 text-cyan-200 rounded-xl text-sm">
                Order ini bagian dari picking gabungan (keranjang {{ $session->basket_no }}).
                <a href="{{ route('gudang.picking-batch.show', $session->batch_id) }}" class="font-semibold underline">Buka picking gabungan</a>
            </div>
        @endif

        @if ($order)
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-sm text-slate-300 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>{{ $order->customer_name }}</span>
                <span class="text-slate-500">·</span>
                <span>{{ $order->courier ?: 'Ekspedisi belum diisi' }}</span>
                <x-ship-badge :order="$order" />
            </div>
        @endif

        @if ($session->status === 'PENDING')
            <form method="POST" action="{{ route('gudang.picking.start', $session->id) }}">
                @csrf
                <button type="submit" class="w-full py-4 bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold rounded-xl">Mulai picking</button>
            </form>
        @elseif ($running)
            <x-scan-box :endpoint="route('gudang.picking.scan', $session->id)" storage-key="picking-bin-{{ $session->id }}"
                hint="Scan rak lalu scan barang, atau ketuk tombol +1 di barang yang belum ada barcode-nya." />
        @elseif ($session->status === 'COMPLETED')
            <div class="bg-emerald-900/20 border border-emerald-500/40 rounded-xl p-4 space-y-3">
                <p class="text-sm text-emerald-300">Semua barang sudah diambil. Bawa ke meja packing.</p>
                <form method="POST" action="{{ route('gudang.picking-batch.auto') }}">
                    @csrf
                    <button type="submit" class="w-full py-4 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold rounded-xl">Ambil picking berikutnya</button>
                </form>
            </div>
        @endif

        {{-- Daftar ambil, urut per rak --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <h3 class="px-4 py-3 border-b border-slate-800 text-base font-semibold text-white">Daftar ambil</h3>
            <div class="divide-y divide-slate-800">
                @foreach ($session->items as $item)
                    @php $complete = $item->qty_picked >= $item->qty_ordered; @endphp
                    <div class="p-4 space-y-3 {{ $complete ? 'opacity-60' : '' }}">
                        <div class="flex gap-3">
                            <div class="shrink-0 w-20">
                                <p class="text-[11px] text-slate-500 uppercase">Rak</p>
                                <p class="font-mono text-sm font-bold text-cyan-400 break-all">{{ $item->bin_code }}</p>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-white break-all">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ $item->masterSku?->product_name }}</p>
                                @if ($item->masterSku?->size)
                                    <span class="inline-block mt-1 px-2 py-0.5 rounded-md bg-slate-800 border border-slate-700 text-sm font-bold text-white">Ukuran {{ $item->masterSku->size }}</span>
                                @endif
                            </div>
                            <p class="shrink-0 text-right">
                                <span class="text-2xl font-bold {{ $complete ? 'text-emerald-400' : 'text-white' }}" data-scan-qty="{{ $item->id }}">{{ $item->qty_picked }}</span><span class="text-slate-500">/{{ $item->qty_ordered }}</span>
                            </p>
                        </div>

                        @if ($running)
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" onclick="scanTap(@js(route('gudang.picking.tap', [$session->id, $item->id])), { delta: -1 })"
                                    class="py-3 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 font-bold rounded-xl">−1</button>
                                <button type="button" onclick="scanTap(@js(route('gudang.picking.tap', [$session->id, $item->id])), { delta: 1 })"
                                    class="col-span-2 py-3 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl">+1 Ambil</button>
                            </div>

                            @if ($canManual && !$complete)
                                <details class="text-xs text-slate-400">
                                    <summary class="cursor-pointer select-none">Input jumlah manual</summary>
                                    <form method="POST" action="{{ route('gudang.picking.pick', [$session->id, $item->id]) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                        @csrf
                                        <label class="flex-1 min-w-[6rem]">Jumlah diambil
                                            <input type="number" name="qty_picked" value="{{ $item->qty_picked }}" min="0" max="{{ $item->qty_ordered }}" required
                                                class="mt-1 w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                                        </label>
                                        <label class="flex-1 min-w-[6rem]">Rak
                                            <input type="text" name="bin_code" value="{{ $item->bin_code }}" required
                                                class="mt-1 w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm font-mono">
                                        </label>
                                        <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Simpan</button>
                                    </form>
                                </details>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
