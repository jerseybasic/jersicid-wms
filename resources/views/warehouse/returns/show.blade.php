<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.retur') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl border border-slate-700">Kembali</a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Retur {{ $return->return_number }}</h2>
                <p class="text-sm text-slate-400 mt-1">{{ $return->created_at->format('d M Y H:i') }} • {{ $return->receiver?->name }}</p>
            </div>
        </div>
    </x-slot>

    @php $good = $return->items->sum('qty_good'); @endphp

    <div class="max-w-4xl mx-auto space-y-6">
        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif

        @if ($good > 0)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row md:items-center gap-3">
                <p class="flex-1 text-sm text-slate-300">Tempel ulang label SKU jika labelnya lepas atau rusak, lalu simpan barang di rak yang tertulis.</p>
                <a href="{{ route('gudang.label.sku', ['ref' => $return->return_number]) }}" target="_blank"
                    class="px-4 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 text-sm font-bold rounded-xl text-center">Cetak label SKU ({{ $good }})</a>
            </div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-slate-400">Order</p>
                <p class="font-mono font-bold text-cyan-400">
                    @if ($return->order && !$return->order->trashed())
                        <a href="{{ route('gudang.outbound.show', $return->order->id) }}" class="hover:underline">{{ $return->order->order_number }}</a>
                    @else
                        {{ $return->order?->order_number }}
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-slate-400">Pelanggan</p>
                <p class="text-white">{{ $return->order?->customer_name }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-400">Alasan</p>
                <p class="text-white">{{ $return->reason_label }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-400">Bagus / Rusak</p>
                <p class="font-bold"><span class="text-emerald-400">{{ $good }}</span> / <span class="text-red-400">{{ $return->items->sum('qty_damaged') }}</span></p>
            </div>
            @if ($return->notes)
                <div class="col-span-2 md:col-span-4">
                    <p class="text-xs text-slate-400">Catatan</p>
                    <p class="text-sm text-slate-300 whitespace-pre-line">{{ $return->notes }}</p>
                </div>
            @endif
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50 text-xs text-slate-400">
                    <tr>
                        <th class="text-left py-3 px-4">SKU</th>
                        <th class="text-right py-3 px-4">Bagus</th>
                        <th class="text-left py-3 px-4">Masuk ke rak</th>
                        <th class="text-right py-3 px-4">Rusak</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($return->items as $item)
                        <tr>
                            <td class="py-3 px-4">
                                <p class="font-mono text-white">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400">{{ $item->masterSku?->product_name }} {{ $item->masterSku?->size }}</p>
                            </td>
                            <td class="py-3 px-4 text-right font-bold text-emerald-400">{{ $item->qty_good }}</td>
                            <td class="py-3 px-4 font-mono text-cyan-400">{{ $item->bin_code ?? '-' }}</td>
                            <td class="py-3 px-4 text-right font-bold {{ $item->qty_damaged ? 'text-red-400' : 'text-slate-500' }}">{{ $item->qty_damaged }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
