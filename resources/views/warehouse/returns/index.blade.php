<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Retur Barang</h2>
            <p class="text-sm text-slate-400 mt-1">Paket yang dikembalikan pelanggan: barang bagus masuk lagi ke rak, yang rusak dicatat</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row gap-3">
            <form method="GET" action="{{ route('gudang.retur') }}" class="flex-1 flex gap-2">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari no. retur, no. order, atau nama pelanggan..."
                    class="flex-1 min-w-0 px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
                <button type="submit" class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl">Cari</button>
            </form>
            <a href="{{ route('gudang.retur.create') }}"
                class="px-5 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl text-sm text-center">
                Terima Retur
            </a>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-800/50 text-xs text-slate-400">
                        <tr>
                            <th class="text-left py-3 px-4">No. Retur</th>
                            <th class="text-left py-3 px-4">Order</th>
                            <th class="text-left py-3 px-4">Alasan</th>
                            <th class="text-right py-3 px-4">Bagus</th>
                            <th class="text-right py-3 px-4">Rusak</th>
                            <th class="text-left py-3 px-4">Diterima</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($returns as $return)
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4">
                                    <a href="{{ route('gudang.retur.show', $return->id) }}" class="font-mono text-cyan-400 hover:text-cyan-300">{{ $return->return_number }}</a>
                                </td>
                                <td class="py-3 px-4">
                                    <p class="font-mono text-white">{{ $return->order?->order_number }}</p>
                                    <p class="text-xs text-slate-400">{{ $return->order?->customer_name }}</p>
                                </td>
                                <td class="py-3 px-4 text-slate-300">{{ $return->reason_label }}</td>
                                <td class="py-3 px-4 text-right font-bold text-emerald-400">{{ (int) $return->items_sum_qty_good }}</td>
                                <td class="py-3 px-4 text-right font-bold {{ $return->items_sum_qty_damaged ? 'text-red-400' : 'text-slate-500' }}">{{ (int) $return->items_sum_qty_damaged }}</td>
                                <td class="py-3 px-4 text-xs text-slate-400">
                                    {{ $return->created_at->format('d M Y H:i') }}
                                    <p>{{ $return->receiver?->name }}</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center text-slate-500">Belum ada retur.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($returns->hasPages())
                <div class="p-4 border-t border-slate-800">{{ $returns->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
