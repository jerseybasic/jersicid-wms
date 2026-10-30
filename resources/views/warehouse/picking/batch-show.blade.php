<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.tugas') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Tugas</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight truncate">Picking Gabungan</h2>
                <p class="text-xs sm:text-sm text-slate-400 mt-0.5 font-mono truncate">{{ $batch->batch_number }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $done = $batch->status === 'COMPLETED';
        $totalPicked = $pickList->sum('qty_picked');
        $totalOrdered = $pickList->sum('qty_ordered');
    @endphp

    <div class="max-w-3xl mx-auto space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-3 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-2xl font-bold text-white">{{ $batch->sessions->count() }}</p>
                <p class="text-xs text-slate-400">Keranjang</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-2xl font-bold text-white">{{ $pickList->count() }}</p>
                <p class="text-xs text-slate-400">SKU</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-2xl font-bold {{ $done ? 'text-emerald-400' : 'text-amber-400' }}"><span data-scan-qty="total">{{ $totalPicked }}</span><span class="text-base text-slate-500">/{{ $totalOrdered }}</span></p>
                <p class="text-xs text-slate-400">pcs diambil</p>
            </div>
        </div>

        @if ($done)
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-300 rounded-xl text-sm space-y-3">
                <p>Semua keranjang lengkap. Bawa keranjang ke meja packing, lalu kemas semuanya di satu layar.</p>
                @php $toPackHere = $batch->sessions->filter(fn ($session) => $session->status === 'COMPLETED' && $session->order?->status === 'PACKING' && !$session->order->packingSession)->count(); @endphp
                @if ($toPackHere)
                    {{-- Packing gabungan: nomor keranjangnya sama dengan picking ini --}}
                    <form method="POST" action="{{ route('gudang.packing-batch.from-picking', $batch) }}">
                        @csrf
                        <button type="submit" class="w-full py-4 bg-purple-500 hover:bg-purple-400 text-white font-bold rounded-xl">Kemas {{ $toPackHere }} keranjang sekaligus</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('gudang.picking-batch.auto') }}">
                    @csrf
                    <button type="submit" class="w-full py-3 bg-slate-800 hover:bg-slate-700 border border-cyan-500/50 text-cyan-300 font-bold rounded-xl">Ambil picking berikutnya</button>
                </form>
            </div>
        @else
            <x-pick-next :items="$pickList->map(fn ($row) => [
                'key' => 'sku-' . $row['sku'], 'bin' => $row['bins']->first(), 'sku' => $row['sku'], 'name' => $row['product'],
                'size' => $row['size'], 'need' => $row['qty_ordered'], 'qty' => $row['qty_picked'],
            ])->values()" />
            <x-scan-box :endpoint="route('gudang.picking-batch.scan', $batch)" storage-key="batch-bin-{{ $batch->id }}"
                hint="Scan rak lalu scan barang (atau ketuk +1 di daftar). Layar menunjukkan nomor keranjangnya." />
        @endif

        {{-- Daftar ambil per SKU, urut per rak --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <h3 class="px-4 py-3 border-b border-slate-800 text-base font-semibold text-white">Daftar ambil</h3>
            <div class="divide-y divide-slate-800">
                @foreach ($pickList as $row)
                    @php $complete = $row['qty_picked'] >= $row['qty_ordered']; @endphp
                    <div class="p-4 space-y-3 {{ $complete ? 'opacity-60' : '' }}">
                    <div class="flex gap-3">
                        <div class="shrink-0 w-20">
                            <p class="text-[11px] text-slate-500 uppercase">Rak</p>
                            @foreach ($row['bins'] as $bin)
                                @if ($bin === 'UNKNOWN')
                                    <p class="text-xs font-semibold text-amber-400">Belum ada di rak</p>
                                @else
                                    <p class="font-mono text-sm font-bold text-cyan-400 break-all">{{ $bin }}@if ($row['bins']->count() > 1)<span class="text-slate-400 font-normal"> ×{{ $row['bin_qty'][$bin] }}</span>@endif</p>
                                @endif
                            @endforeach
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm font-bold text-white break-all">{{ $row['sku'] }}</p>
                            @if ($row['name'])
                                <p class="text-xs text-slate-400 truncate">{{ $row['name'] }}</p>
                            @endif
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($row['baskets'] as $basket)
                                    <span class="px-2 py-0.5 rounded-md bg-slate-800 border border-slate-700 text-xs text-slate-300">K{{ $basket['no'] }} <span class="font-bold text-white">×{{ $basket['qty'] }}</span></span>
                                @endforeach
                            </div>
                        </div>
                        <p class="shrink-0 text-right">
                            <span class="text-2xl font-bold {{ $complete ? 'text-emerald-400' : 'text-white' }}" data-scan-qty="sku-{{ $row['sku'] }}">{{ $row['qty_picked'] }}</span><span class="text-slate-500">/{{ $row['qty_ordered'] }}</span>
                        </p>
                    </div>
                    @unless ($done)
                        {{-- Untuk barang yang belum ada barcode-nya: ketuk sama dengan scan --}}
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" onclick="scanTap(@js(route('gudang.picking-batch.undo', $batch)), { sku: @js($row['sku']) })"
                                class="py-3 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 font-bold rounded-xl">−1</button>
                            <button type="button" onclick="scanTap(@js(route('gudang.picking-batch.scan', $batch)), { code: @js($row['sku']), via: 'ketuk' })"
                                class="col-span-2 py-3 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl">+1 Ambil</button>
                        </div>
                        @unless ($complete)
                            <form method="POST" action="{{ route('gudang.picking-batch.missing', $batch) }}"
                                onsubmit="return confirm(@js('Barang ' . $row['sku'] . ' benar-benar tidak ada di rak? Stok rak itu akan dinolkan, lalu sistem mencari rak lain atau menunda order-nya (Menunggu stok).'))">
                                @csrf
                                <input type="hidden" name="sku" value="{{ $row['sku'] }}">
                                <button type="submit" class="text-xs text-amber-400 hover:text-amber-300 underline">Barang tidak ada di rak</button>
                            </form>
                        @endunless
                    @endunless
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Keranjang per order --}}
        <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <h3 class="px-4 py-3 border-b border-slate-800 text-base font-semibold text-white">Keranjang</h3>
            <div class="divide-y divide-slate-800">
                @foreach ($batch->sessions as $session)
                    @if ($session->status === 'CANCELLED')
                        <div class="p-4 flex items-center gap-3 bg-red-900/10">
                            <span class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-xl font-black bg-red-500/20 text-red-300 border border-red-500/40">{{ $session->basket_no }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-white truncate">{{ $session->order?->order_number }}</p>
                                <p class="text-xs text-red-300">Order dibatalkan. Jangan dikemas; kembalikan isinya lewat <a href="{{ route('gudang.restock') }}" class="underline">Kembalikan ke Rak</a>.</p>
                            </div>
                        </div>
                        @continue
                    @endif
                    <div class="p-4 flex items-center gap-3">
                        <span class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-xl font-black {{ $session->status === 'COMPLETED' ? 'bg-emerald-500 text-slate-900' : 'bg-slate-800 text-white border border-slate-700' }}">{{ $session->basket_no }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-white truncate">{{ $session->order?->order_number }}</p>
                            <p class="text-xs text-slate-400 truncate">{{ $session->order?->customer_name }}{{ $session->order?->courier ? ' · ' . $session->order->courier : '' }}</p>
                        </div>
                        <p class="shrink-0 text-right text-sm text-slate-300">
                            <span class="font-bold text-white" data-scan-qty="basket-{{ $session->basket_no }}">{{ $session->items->sum('qty_picked') }}</span>/{{ $session->items->sum('qty_ordered') }} pcs
                            <br><span class="text-xs {{ $session->status === 'COMPLETED' ? 'text-emerald-400' : 'text-slate-500' }}">{{ $session->status === 'COMPLETED' ? 'Lengkap' : 'Belum lengkap' }}</span>
                        </p>
                    </div>
                @endforeach
            </div>
        </section>

        @unless ($done)
            <form method="POST" action="{{ route('gudang.picking-batch.destroy', $batch) }}" class="text-center"
                  onsubmit="return confirm('Batalkan keranjang yang belum lengkap? Barang yang sudah diambil untuk keranjang itu harus dikembalikan ke raknya.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-sm text-red-400 hover:text-red-300 underline">Batalkan keranjang yang belum lengkap</button>
                <p class="text-xs text-slate-500 mt-1">Order-nya kembali ke antrean picking. Keranjang yang sudah lengkap tetap lanjut ke packing.</p>
            </form>
        @endunless
    </div>
</x-app-layout>
