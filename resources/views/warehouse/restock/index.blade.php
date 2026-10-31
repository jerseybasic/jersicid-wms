<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Kembalikan ke Rak</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">Barang dari order yang dibatalkan atau itemnya diganti saat sedang diproses. Stok bertambah setelah barangnya ditaruh kembali.</p>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        @if ($groups->isEmpty())
            <p class="text-sm text-slate-400 bg-slate-900 border border-slate-800 rounded-2xl p-6 text-center">
                Tidak ada barang yang perlu dikembalikan ke rak.
            </p>
        @else
            <x-scan-box :endpoint="route('gudang.restock.scan')" storage-key="restock-bin"
                hint="Scan rak tujuan lalu scan barangnya, atau ketuk +1 untuk mengembalikan ke rak asalnya." />

            @foreach ($groups as $items)
                @php $first = $items->first(); $order = $first['order']; @endphp
                <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-800">
                        <a href="{{ route('gudang.outbound.show', $order->id) }}" class="font-semibold text-white hover:underline">{{ $order->order_number }}</a>
                        <p class="text-xs text-slate-400">{{ $order->customer_name }} · {{ $first['why'] }} {{ $first['at']->translatedFormat('d M H:i') }}</p>
                    </div>
                    <div class="divide-y divide-slate-800">
                        @foreach ($items as $item)
                            <div class="p-4 space-y-3">
                                <div class="flex gap-3">
                                    <div class="shrink-0 w-20">
                                        <p class="text-[11px] text-slate-500 uppercase">Rak asal</p>
                                        <p class="font-mono text-sm font-bold text-cyan-400 break-all">{{ $item['bin_code'] ?: '-' }}</p>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-mono text-sm font-bold text-white break-all">{{ $item['sku'] }}</p>
                                        <p class="text-xs text-slate-400 truncate">{{ trim(($item['masterSku']?->product_name ?? '') . ' ' . ($item['masterSku']?->size ?? '')) }}</p>
                                    </div>
                                    <p class="shrink-0 text-right">
                                        <span class="text-2xl font-bold text-amber-400" data-scan-qty="{{ $item['id'] }}">{{ $item['qty'] }}</span>
                                        <span class="block text-[11px] text-slate-500">pcs belum kembali</span>
                                    </p>
                                </div>
                                @if ($item['bin_code'])
                                    <button type="button" onclick="scanTap(@js($item['tap']), {})"
                                        class="w-full py-3 bg-cyan-600 hover:bg-cyan-500 text-on-accent font-bold rounded-xl">+1 Sudah ditaruh di {{ $item['bin_code'] }}</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>
</x-app-layout>
