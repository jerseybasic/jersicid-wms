<x-app-layout>
    @include('reports.partials.header', ['title' => 'Laporan Pergerakan Stok', 'subtitle' => 'Histori mutasi stok gudang', 'export' => route('reports.movement', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        <!-- Filter Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <form method="GET" action="{{ route('reports.movement') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-400 mb-1">Cari No. Ref / SKU</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Contoh: JJ001 atau JER-001" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="{{ request('start_date') }}" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Tanggal Akhir</label>
                    <input type="date" name="end_date" value="{{ request('end_date') }}" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Tipe Mutasi</label>
                    <select name="type" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <option value="">Semua Tipe</option>
                        <option value="INBOUND" {{ request('type') == 'INBOUND' ? 'selected' : '' }}>Inbound (Masuk)</option>
                        <option value="OUTBOUND" {{ request('type') == 'OUTBOUND' ? 'selected' : '' }}>Outbound (Keluar)</option>
                        <option value="TRANSFER" {{ request('type') == 'TRANSFER' ? 'selected' : '' }}>Transfer</option>
                        <option value="ADJUSTMENT" {{ request('type') == 'ADJUSTMENT' ? 'selected' : '' }}>Adjustment (Opname)</option>
                    </select>
                </div>
                <div class="md:col-span-5 flex justify-end gap-2">
                    <a href="{{ route('reports.movement') }}" class="px-4 py-4 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium rounded-lg border border-slate-700 transition-all">Reset</a>
                    <button type="submit" class="px-6 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">Terapkan Filter</button>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="[&_td]:whitespace-nowrap w-full text-sm text-left">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">Waktu</th>
                            <th class="py-3 px-4">No. Referensi</th>
                            <th class="py-3 px-4">Tipe</th>
                            <th class="py-3 px-4">SKU / Produk</th>
                            <th class="py-3 px-4">Bin</th>
                            <th class="py-3 px-4 text-center">Qty</th>
                            <th class="py-3 px-4">User</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($movements as $m)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-4 text-xs text-slate-300 whitespace-nowrap">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                            <td class="py-3 px-4 font-mono text-cyan-400 font-bold">{{ $m->reference_no }}</td>
                            <td class="py-3 px-4">
                                @php
                                    $typeColors = [
                                        'INBOUND' => 'bg-emerald-500/20 text-emerald-400',
                                        'OUTBOUND' => 'bg-red-500/20 text-red-400',
                                        'TRANSFER' => 'bg-purple-500/20 text-purple-400',
                                        'ADJUSTMENT' => 'bg-amber-500/20 text-amber-400',
                                    ];
                                @endphp
                                <span class="text-xs px-2 py-1 rounded-full {{ $typeColors[$m->type] ?? 'bg-slate-500/20 text-slate-400' }}">{{ $m->type }}</span>
                            </td>
                            <td class="py-3 px-4">
                                <div class="font-mono text-white">{{ $m->sku }}</div>
                                <div class="text-xs text-slate-500">{{ $m->product_name }}</div>
                            </td>
                            <td class="py-3 px-4 font-mono text-slate-300">{{ $m->bin_code }}</td>
                            <td class="py-3 px-4 text-center">
                                @php $isIn = in_array($m->type, ['INBOUND', 'ADJUSTMENT']) && $m->qty_after > $m->qty_before; @endphp
                                <span class="font-bold {{ $isIn ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ $isIn ? '+' : '-' }}{{ $m->qty }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-300">{{ $m->user_name }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-500">Tidak ada data pergerakan stok pada periode ini.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($movements->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">{{ $movements->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>