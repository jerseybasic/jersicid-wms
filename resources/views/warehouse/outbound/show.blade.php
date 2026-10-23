<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('gudang.outbound') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight font-mono truncate">{{ $order->order_number }}</h2>
                <p class="text-sm text-slate-400 mt-1">Detail order</p>
            </div>
        </div>
    </x-slot>

    @php
        $statusColors = [
            'PENDING' => 'bg-amber-500/20 text-amber-400',
            'CONFIRMED' => 'bg-blue-500/20 text-blue-400',
            'PICKING' => 'bg-purple-500/20 text-purple-400',
            'PACKING' => 'bg-cyan-500/20 text-cyan-400',
            'READY_TO_SHIP' => 'bg-indigo-500/20 text-indigo-400',
            'SHIPPED' => 'bg-emerald-500/20 text-emerald-400',
            'COMPLETED' => 'bg-slate-500/20 text-slate-300',
            'CANCELLED' => 'bg-red-500/20 text-red-400',
        ];
        $dotColors = [
            'slate' => 'bg-slate-400', 'blue' => 'bg-blue-400', 'red' => 'bg-red-400', 'emerald' => 'bg-emerald-400',
            'purple' => 'bg-purple-400', 'cyan' => 'bg-cyan-400', 'orange' => 'bg-orange-400', 'amber' => 'bg-amber-400',
        ];
        $total = $order->items->sum(fn ($item) => $item->qty_ordered * $item->price);
    @endphp

    <div class="space-y-4">
        @if(session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        {{-- Status & aksi --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm px-3 py-1 rounded-full font-semibold {{ $statusColors[$order->status] ?? 'bg-slate-500/20 text-slate-400' }}">{{ $order->status_label }}</span>
                <x-ship-badge :order="$order" class="!text-xs !px-3 !py-1" />
                @if ($order->ship_by)
                    <span class="text-xs text-slate-400">Batas kirim {{ $order->ship_by->translatedFormat('d M Y H:i') }}</span>
                @endif
            </div>
            <div class="flex flex-wrap justify-end gap-1">
                @if ($order->isEditable())
                    <a href="{{ route('gudang.outbound.edit', $order->id) }}" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Ubah
                    </a>
                @endif
                @include('warehouse.outbound.partials.actions')
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 space-y-4">
                {{-- Pembeli & pengiriman --}}
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-slate-500 mb-1">Penerima</p>
                        <p class="text-white font-semibold">{{ $order->customer_name }}</p>
                        @if ($order->customer_phone)
                            <p class="text-slate-400">{{ $order->customer_phone }}</p>
                        @endif
                        @if ($order->shipping_address)
                            <p class="text-slate-400 mt-1">{{ $order->shipping_address }}</p>
                        @endif
                    </div>
                    <div class="space-y-2">
                        <div>
                            <p class="text-xs text-slate-500">Kurir</p>
                            <p class="text-white">{{ $order->courier ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">No. resi</p>
                            <p class="text-white font-mono">{{ $order->tracking_number ?: '-' }}</p>
                        </div>
                    </div>
                    @if ($order->notes)
                        <div class="sm:col-span-2 pt-3 border-t border-slate-800">
                            <p class="text-xs text-slate-500 mb-1">Catatan</p>
                            <p class="text-slate-300 whitespace-pre-line">{{ $order->notes }}</p>
                        </div>
                    @endif
                </div>

                {{-- Item --}}
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-white">Item</h3>
                        <span class="text-xs text-slate-400 bg-slate-800 px-2 py-1 rounded">{{ $order->items->count() }} SKU · {{ $order->items->sum('qty_ordered') }} pcs</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                            <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                                <tr>
                                    <th class="py-3 px-4">SKU</th>
                                    <th class="py-3 px-4">Bin</th>
                                    <th class="py-3 px-4 text-right">Pesan</th>
                                    <th class="py-3 px-4 text-right">Diambil</th>
                                    <th class="py-3 px-4 text-right">Dikemas</th>
                                    <th class="py-3 px-4 text-right">Harga</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td class="py-3 px-4 !whitespace-normal min-w-[10rem]">
                                            <p class="font-mono font-semibold text-white break-all">{{ $item->sku }}</p>
                                            <p class="text-xs text-slate-500">{{ trim(($item->masterSku?->product_name ?? '') . ' ' . ($item->masterSku?->size ?? '')) ?: 'SKU tidak ada di Master SKU' }}</p>
                                        </td>
                                        <td class="py-3 px-4 font-mono text-slate-300">{{ $bins[$item->id] ?? $item->bin_code ?? '-' }}</td>
                                        <td class="py-3 px-4 text-right font-bold text-white">{{ $item->qty_ordered }}</td>
                                        <td class="py-3 px-4 text-right {{ $item->qty_picked >= $item->qty_ordered ? 'text-emerald-400' : 'text-slate-400' }}">{{ $item->qty_picked }}</td>
                                        <td class="py-3 px-4 text-right {{ $item->qty_packed >= $item->qty_ordered ? 'text-emerald-400' : 'text-slate-400' }}">{{ $item->qty_packed }}</td>
                                        <td class="py-3 px-4 text-right text-slate-300">{{ $item->price ? 'Rp ' . number_format($item->price, 0, ',', '.') : '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($total > 0)
                                <tfoot class="border-t border-slate-700">
                                    <tr>
                                        <td colspan="5" class="py-3 px-4 text-right text-slate-400">Total</td>
                                        <td class="py-3 px-4 text-right font-bold text-white">Rp {{ number_format($total, 0, ',', '.') }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>

            {{-- Riwayat --}}
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <h3 class="text-base font-semibold text-white mb-4">Riwayat</h3>
                <ol class="relative border-l border-slate-700 ml-1.5 space-y-5">
                    @foreach ($timeline as $step)
                        <li class="ml-5">
                            <span class="absolute -left-[5px] mt-1.5 w-2.5 h-2.5 rounded-full {{ $dotColors[$step['color']] ?? 'bg-slate-400' }}"></span>
                            <p class="text-sm font-semibold text-white">
                                @if (!empty($step['url']))
                                    <a href="{{ $step['url'] }}" class="hover:underline">{{ $step['title'] }}</a>
                                @else
                                    {{ $step['title'] }}
                                @endif
                            </p>
                            @if ($step['detail'])
                                <p class="text-xs text-slate-400">{{ $step['detail'] }}</p>
                            @endif
                            <p class="text-xs text-slate-500">{{ $step['at']->translatedFormat('d M Y H:i') }}{{ $step['user'] ? ' · ' . $step['user'] : '' }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>
</x-app-layout>
