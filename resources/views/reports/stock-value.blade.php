<x-app-layout>
    @include('reports.partials.header', ['title' => 'Nilai Stok', 'subtitle' => 'Jumlah stok dikali harga jual dan harga modal (HPP) di master SKU', 'export' => route('reports.stock-value', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Nilai jual</p>
                <p class="text-xl sm:text-2xl font-bold text-emerald-400">Rp {{ number_format($total['value'], 0, ',', '.') }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Nilai modal (HPP)</p>
                <p class="text-xl sm:text-2xl font-bold text-cyan-400">Rp {{ number_format($total['cost_value'], 0, ',', '.') }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Potensi laba</p>
                <p class="text-xl sm:text-2xl font-bold text-white">Rp {{ number_format($total['profit'], 0, ',', '.') }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Stok</p>
                <p class="text-xl sm:text-2xl font-bold text-white">{{ number_format($total['qty']) }} pcs</p>
                <p class="text-xs text-slate-500">{{ number_format($total['skus']) }} SKU</p>
            </div>
        </div>

        @if ($total['without_cost'] > 0)
            <div class="p-4 bg-amber-500/10 border border-amber-500/30 rounded-xl text-sm text-amber-300">
                {{ number_format($total['without_cost']) }} SKU yang ada stoknya belum punya harga modal (HPP). Nilai modal dan potensi laba belum menghitung SKU tersebut. Isi HPP di Master SKU atau lewat kolom COST_PRICE saat import.
            </div>
        @endif

        @foreach (['Per kategori ukuran' => $categories, '20 produk dengan nilai terbesar' => $products] as $label => $rows)
            <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
                <div class="p-4 border-b border-slate-800"><h3 class="text-base font-semibold text-white">{{ $label }}</h3></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                        <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                            <tr>
                                <th class="py-3 px-4">Nama</th>
                                <th class="py-3 px-4 text-right">SKU</th>
                                <th class="py-3 px-4 text-right">Stok</th>
                                <th class="py-3 px-4 text-right">Nilai jual</th>
                                <th class="py-3 px-4 text-right">Nilai modal</th>
                                <th class="py-3 px-4 text-right">Potensi laba</th>
                                <th class="py-3 px-4 w-1/4">Porsi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse ($rows as $row)
                                @php($share = $total['value'] > 0 ? round($row->value / $total['value'] * 100, 1) : 0)
                                <tr class="hover:bg-slate-800/30">
                                    <td class="py-3 px-4 text-white font-medium">{{ $row->name }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">{{ number_format($row->skus) }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">{{ number_format($row->qty) }} pcs</td>
                                    <td class="py-3 px-4 text-right font-bold text-white">Rp {{ number_format($row->value, 0, ',', '.') }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">Rp {{ number_format($row->cost_value, 0, ',', '.') }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">Rp {{ number_format($row->profit, 0, ',', '.') }}</td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2 bg-slate-800 rounded-full overflow-hidden"><div class="h-full bg-emerald-500" style="width: {{ $share }}%"></div></div>
                                            <span class="text-xs text-slate-400 w-12 text-right">{{ $share }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center py-10 text-slate-500">Belum ada stok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
        <p class="text-xs text-slate-500">Porsi dihitung dari nilai jual. Potensi laba = nilai jual dikurangi nilai modal, hanya untuk SKU yang HPP-nya sudah diisi.</p>
    </div>
</x-app-layout>
