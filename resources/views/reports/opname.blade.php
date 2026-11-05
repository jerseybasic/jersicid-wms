<x-app-layout>
    @include('reports.partials.header', ['title' => 'Laporan Stock Opname', 'subtitle' => 'Rekap hasil pencocokan stok fisik vs sistem', 'export' => route('reports.opname', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <!-- Filter Bar -->
            <form method="GET" action="{{ route('reports.opname') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <option value="">Semua Status</option>
                        <option value="DRAFT" {{ request('status') == 'DRAFT' ? 'selected' : '' }}>Draft</option>
                        <option value="IN_PROGRESS" {{ request('status') == 'IN_PROGRESS' ? 'selected' : '' }}>In Progress</option>
                        <option value="COMPLETED" {{ request('status') == 'COMPLETED' ? 'selected' : '' }}>Completed</option>
                        <option value="CANCELLED" {{ request('status') == 'CANCELLED' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="{{ request('start_date') }}" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Tanggal Akhir</label>
                    <input type="date" name="end_date" value="{{ request('end_date') }}" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div class="md:col-span-4 flex justify-end gap-2 mt-2">
                    <a href="{{ route('reports.opname') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium rounded-lg border border-slate-700 transition-all">Reset</a>
                    <button type="submit" class="px-6 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">Terapkan Filter</button>
                </div>
            </form>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Total Opname</p>
                <p class="text-xl font-bold text-white">{{ $opnames->total() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-emerald-400 mb-1">Selesai</p>
                <p class="text-xl font-bold text-white">{{ (int) ($statusCounts['COMPLETED'] ?? 0) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-amber-400 mb-1">Sedang Berjalan</p>
                <p class="text-xl font-bold text-white">{{ (int) ($statusCounts['IN_PROGRESS'] ?? 0) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Draft</p>
                <p class="text-xl font-bold text-white">{{ (int) ($statusCounts['DRAFT'] ?? 0) }}</p>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                <h3 class="text-base font-semibold text-white">Daftar Stock Opname</h3>
                <span class="text-xs text-slate-400 bg-slate-800 px-2 py-1 rounded">
                    {{ $opnames->total() }} total
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="[&_td]:whitespace-nowrap w-full text-sm text-left">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">No. Opname</th>
                            <th class="py-3 px-4">Gudang</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Dibuat Oleh</th>
                            <th class="py-3 px-4">Disetujui Oleh</th>
                            <th class="py-3 px-4">Tanggal</th>
                            <th class="py-3 px-4 text-right">Total Item</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($opnames as $opname)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold text-cyan-400">{{ $opname->opname_number }}</td>
                            <td class="py-3 px-4 text-white">{{ $opname->warehouse?->name ?? '-' }}</td>
                            <td class="py-3 px-4">
                                <span class="text-xs px-2 py-1 rounded-full {{ $opname->status_color }}">{{ $opname->status }}</span>
                            </td>
                            <td class="py-3 px-4 text-slate-300">{{ $opname->creator?->name ?? '-' }}</td>
                            <td class="py-3 px-4 text-slate-300">{{ $opname->approver?->name ?? '-' }}</td>
                            <td class="py-3 px-4 text-xs text-slate-300">{{ $opname->created_at->format('d M Y H:i') }}</td>
                            <td class="py-3 px-4 text-right font-bold text-white">{{ $opname->items_count }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-slate-500">
                                <svg class="w-12 h-12 text-slate-700 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                </svg>
                                Tidak ada data opname.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            @if($opnames->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">
                    {{ $opnames->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>