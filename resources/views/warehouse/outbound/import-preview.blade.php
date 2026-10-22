<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.outbound.import') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Ganti file</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Pratinjau Import</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1 truncate">{{ $fileName }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $ready = collect($orders)->where('importable', true);
        $blocked = collect($orders)->where('importable', false);
    @endphp

    <div class="space-y-6">
        @if (!empty($flash['success']))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ $flash['success'] }}</div>
        @endif
        @if (!empty($flash['error']))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $flash['error'] }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Pesanan di file</p>
                <p class="text-2xl font-bold text-white">{{ count($orders) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-emerald-400 mb-1">Siap diimpor</p>
                <p class="text-2xl font-bold text-emerald-400">{{ $ready->count() }}</p>
                <p class="text-xs text-slate-500">{{ number_format($ready->sum(fn ($o) => collect($o['items'])->sum('qty'))) }} pcs</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-red-400 mb-1">Dilewati</p>
                <p class="text-2xl font-bold {{ $blocked->count() ? 'text-red-400' : 'text-white' }}">{{ $blocked->count() }}</p>
            </div>
        </div>

        @if ($ready->isNotEmpty())
            <form method="POST" action="{{ route('gudang.outbound.import.store') }}"
                  class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-4 sm:justify-between">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="flex items-start gap-3 text-sm text-slate-300">
                    <input type="checkbox" name="confirm_now" value="1" checked class="mt-0.5 rounded border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500">
                    <span>Langsung konfirmasi, jadi pesanan masuk ke daftar picking.<br><span class="text-xs text-slate-500">Hapus centang jika ingin memeriksanya dulu di Outbound.</span></span>
                </label>
                <button type="submit" class="shrink-0 px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg transition-all">
                    Import {{ $ready->count() }} pesanan
                </button>
            </form>
        @else
            <div class="p-4 bg-amber-900/20 border border-amber-500/40 text-amber-300 rounded-xl text-sm">
                Tidak ada pesanan yang bisa diimpor. Periksa alasan di bawah, perbaiki datanya, lalu upload ulang.
            </div>
        @endif

        @if ($unknown->isNotEmpty())
            <div class="bg-slate-900 border border-amber-500/40 rounded-xl p-4 sm:p-5 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-white">SKU belum dikenal ({{ $unknown->count() }})</h3>
                    <p class="text-sm text-slate-400 mt-1">
                        @if ($canMap)
                            Kode SKU di marketplace berbeda dengan Master SKU? Pilih SKU gudang yang sama, lalu simpan. Pemetaan diingat, jadi import berikutnya langsung dikenali.
                        @else
                            Minta Owner, Supervisor, atau Admin Gudang memetakan SKU ini ke SKU gudang, atau menambahkannya di Master SKU.
                        @endif
                    </p>
                </div>

                @if ($canMap)
                    <form method="POST" action="{{ route('gudang.outbound.import.map') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <datalist id="sku-options">
                            @foreach ($skuOptions as $option)
                                <option value="{{ $option->sku }} — {{ $option->product_name }} {{ $option->size }}"></option>
                            @endforeach
                        </datalist>
                        <div class="divide-y divide-slate-800">
                            @foreach ($unknown as $i => $item)
                                <div class="py-3 grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-4 sm:items-center">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm text-amber-300 break-all">{{ $item['sku'] }}</p>
                                        <p class="text-xs text-slate-500 truncate">{{ $item['name'] }}</p>
                                    </div>
                                    <div>
                                        <input type="hidden" name="map[{{ $i }}][alias]" value="{{ $item['sku'] }}">
                                        <label for="map-{{ $i }}" class="sr-only">SKU gudang untuk {{ $item['sku'] }}</label>
                                        <input id="map-{{ $i }}" type="text" name="map[{{ $i }}][sku]" list="sku-options" value="{{ $item['suggest'] }}"
                                               placeholder="Ketik kode atau nama produk" autocomplete="off"
                                               class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
                                        @if ($item['suggest'])
                                            <p class="text-xs text-cyan-400 mt-1">Saran otomatis, periksa dulu sebelum menyimpan.</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:justify-between">
                            <p class="text-xs text-slate-500">Yang dikosongkan dilewati. Owner dan Supervisor bisa mengubahnya di Master Data → Pemetaan SKU.</p>
                            <button type="submit" class="shrink-0 px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg transition-all">Simpan pemetaan dan periksa ulang</button>
                        </div>
                    </form>
                @endif
            </div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">Pesanan</th>
                            <th class="py-3 px-4">Penerima</th>
                            <th class="py-3 px-4">Barang</th>
                            <th class="py-3 px-4">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach (collect($orders)->sortBy('importable') as $order)
                            <tr class="align-top {{ $order['importable'] ? '' : 'bg-red-950/20' }}">
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <p class="font-mono font-bold {{ $order['importable'] ? 'text-cyan-400' : 'text-slate-400' }}">{{ $order['order_number'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $order['marketplace'] }}</p>
                                    @if ($order['tracking_number'])
                                        <p class="text-xs text-slate-500">{{ $order['courier'] }} · {{ $order['tracking_number'] }}</p>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-slate-300 min-w-[10rem]">
                                    <p>{{ $order['customer_name'] }}</p>
                                    @if ($order['buyer'])
                                        <p class="text-xs text-slate-500">{{ $order['buyer'] }}</p>
                                    @endif
                                </td>
                                <td class="py-3 px-4 min-w-[14rem]">
                                    @foreach ($order['items'] as $item)
                                        <p class="text-slate-300"><span class="font-mono text-xs">{{ $item['sku'] }}</span> <span class="font-bold text-white">×{{ $item['qty'] }}</span></p>
                                        @if (!empty($item['mapped_from']))
                                            <p class="text-xs text-slate-500">dari {{ implode(', ', array_unique($item['mapped_from'])) }}</p>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="py-3 px-4 min-w-[12rem] text-xs space-y-1">
                                    @forelse ($order['problems'] as $problem)
                                        <p class="text-red-400">{{ $problem }}</p>
                                    @empty
                                        <p class="text-emerald-400">Siap diimpor</p>
                                    @endforelse
                                    @foreach ($order['warnings'] as $warning)
                                        <p class="text-amber-400">{{ $warning }}</p>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
