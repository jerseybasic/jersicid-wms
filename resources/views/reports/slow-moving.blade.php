<x-app-layout>
    @include('reports.partials.header', ['title' => 'Barang Lambat Laku', 'subtitle' => 'SKU yang masih ada stoknya tapi lama tidak keluar gudang', 'export' => route('reports.slow-moving', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        <form method="GET" action="{{ route('reports.slow-moving') }}" class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs text-slate-400 mb-1">Tidak keluar selama</label>
                <select name="hari" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                    @foreach ([30, 60, 90, 180] as $option)
                        <option value="{{ $option }}" @selected($days == $option)>{{ $option }} hari</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[12rem]">
                <label class="block text-xs text-slate-400 mb-1">Cari SKU / produk</label>
                <input type="text" name="search" value="{{ request('search') }}" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
            </div>
            <button type="submit" class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg transition-all">Tampilkan</button>
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">SKU lambat laku</p>
                <p class="text-2xl font-bold text-amber-400">{{ number_format($summary['skus']) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Total stok tertahan</p>
                <p class="text-2xl font-bold text-white">{{ number_format($summary['qty']) }} pcs</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Nilai (harga jual)</p>
                <p class="text-2xl font-bold text-white">Rp {{ number_format($summary['value'], 0, ',', '.') }}</p>
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">SKU</th>
                            <th class="py-3 px-4">Produk</th>
                            <th class="py-3 px-4 text-right">Stok</th>
                            <th class="py-3 px-4 text-right">Nilai</th>
                            <th class="py-3 px-4">Terakhir keluar</th>
                            <th class="py-3 px-4 text-right">Diam</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($items as $row)
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 font-mono text-cyan-400">{{ $row['sku'] }}</td>
                                <td class="py-3 px-4 text-white">{{ $row['product'] }} <span class="text-xs text-slate-500">{{ $row['category'] }}</span></td>
                                <td class="py-3 px-4 text-right font-bold text-white">{{ number_format($row['qty']) }}</td>
                                <td class="py-3 px-4 text-right text-slate-300">Rp {{ number_format($row['value'], 0, ',', '.') }}</td>
                                <td class="py-3 px-4 text-slate-300">{{ $row['last_out_at']?->format('d M Y') ?? 'Belum pernah' }}</td>
                                <td class="py-3 px-4 text-right font-semibold text-amber-400">{{ $row['idle_days'] }} hari</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-10 text-slate-500">Tidak ada SKU yang diam lebih dari {{ $days }} hari.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($items->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">{{ $items->links() }}</div>
            @endif
        </div>
        <p class="text-xs text-slate-500">SKU yang belum pernah keluar dihitung sejak stoknya pertama kali tercatat, jadi barang yang baru masuk tidak ikut muncul.</p>
    </div>
</x-app-layout>
