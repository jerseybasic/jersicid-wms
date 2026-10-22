<x-app-layout>
    @include('reports.partials.header', ['title' => 'Akurasi Stock Opname', 'subtitle' => 'Seberapa sering hitungan fisik sama dengan catatan sistem', 'export' => route('reports.opname-accuracy', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        @include('reports.partials.period', ['action' => route('reports.opname-accuracy')])

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Akurasi</p>
                <p class="text-2xl font-bold {{ ($summary['accuracy'] ?? 100) >= 95 ? 'text-emerald-400' : 'text-amber-400' }}">{{ $summary['accuracy'] !== null ? $summary['accuracy'] . '%' : '-' }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Lokasi dihitung</p>
                <p class="text-2xl font-bold text-white">{{ number_format($summary['counted']) }}</p>
                <p class="text-xs text-slate-500">dari {{ $summary['opnames'] }} opname</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Kelebihan fisik</p>
                <p class="text-2xl font-bold text-emerald-400">{{ $summary['plus'] > 0 ? '+' : '' }}{{ number_format($summary['plus']) }} pcs</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Kekurangan fisik</p>
                <p class="text-2xl font-bold text-red-400">{{ $summary['minus'] > 0 ? '-' : '' }}{{ number_format($summary['minus']) }} pcs</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
                <div class="p-4 border-b border-slate-800"><h3 class="text-base font-semibold text-white">Opname selesai</h3></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                        <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                            <tr>
                                <th class="py-3 px-4">No. Opname</th>
                                <th class="py-3 px-4">Cakupan</th>
                                <th class="py-3 px-4">Selesai</th>
                                <th class="py-3 px-4 text-right">Cocok</th>
                                <th class="py-3 px-4 text-right">Akurasi</th>
                                <th class="py-3 px-4 text-right">Selisih</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse ($opnames as $opname)
                                <tr class="hover:bg-slate-800/30">
                                    <td class="py-3 px-4"><a href="{{ route('gudang.opname.show', $opname->id) }}" class="font-mono font-bold text-cyan-400 hover:underline">{{ $opname->opname_number }}</a></td>
                                    <td class="py-3 px-4 text-slate-300">{{ $opname->scope_label }}</td>
                                    <td class="py-3 px-4 text-xs text-slate-300">{{ $opname->completed_at?->format('d M Y') }}</td>
                                    <td class="py-3 px-4 text-right text-slate-300">{{ $opname->match_count }}/{{ $opname->counted_count }}</td>
                                    <td class="py-3 px-4 text-right font-bold {{ ($opname->accuracy ?? 100) >= 95 ? 'text-emerald-400' : 'text-amber-400' }}">{{ $opname->accuracy !== null ? $opname->accuracy . '%' : '-' }}</td>
                                    <td class="py-3 px-4 text-right text-xs"><span class="text-emerald-400">+{{ (int) $opname->plus_qty }}</span> / <span class="text-red-400">{{ (int) $opname->minus_qty }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center py-10 text-slate-500">Belum ada opname yang selesai di periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
                <div class="p-4 border-b border-slate-800"><h3 class="text-base font-semibold text-white">SKU paling sering selisih</h3></div>
                <ul class="divide-y divide-slate-800">
                    @forelse ($problemSkus as $row)
                        <li class="px-4 py-3 flex items-center justify-between text-sm">
                            <span class="font-mono text-cyan-400">{{ $row->sku }}</span>
                            <span class="text-slate-300">{{ $row->times }}x · <span class="{{ $row->net < 0 ? 'text-red-400' : 'text-emerald-400' }}">{{ $row->net > 0 ? '+' : '' }}{{ (int) $row->net }} pcs</span></span>
                        </li>
                    @empty
                        <li class="px-4 py-10 text-center text-slate-500 text-sm">Tidak ada selisih.</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <p class="text-xs text-slate-500">Akurasi = lokasi yang hitungan fisiknya sama persis dengan sistem, dibagi semua lokasi yang dihitung.</p>
    </div>
</x-app-layout>
