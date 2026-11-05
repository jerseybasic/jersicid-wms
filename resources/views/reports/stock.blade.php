<x-app-layout>
    @include('reports.partials.header', ['title' => 'Laporan Stok Saat Ini', 'subtitle' => 'Snapshot stok aktif per lokasi bin', 'export' => route('reports.stock', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        <!-- Filter Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <form method="GET" action="{{ route('reports.stock') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-2">
                    <label class="block text-xs text-slate-400 mb-1">Cari SKU / Nama Produk</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Contoh: JER-001 atau Jersey Basic" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Kategori Size</label>
                    <select name="category" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <option value="">Semua Kategori</option>
                        <option value="DEWASA" {{ request('category') == 'DEWASA' ? 'selected' : '' }}>DEWASA</option>
                        <option value="KIDS" {{ request('category') == 'KIDS' ? 'selected' : '' }}>KIDS</option>
                        <option value="TEENS" {{ request('category') == 'TEENS' ? 'selected' : '' }}>TEENS</option>
                    </select>
                </div>
                <div class="md:col-span-4 flex justify-end gap-2 mt-2">
                    <a href="{{ route('reports.stock') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium rounded-lg border border-slate-700 transition-all">Reset</a>
                    <button type="submit" class="px-6 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">Terapkan Filter</button>
                </div>
            </form>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Total SKU</p>
                <p class="text-xl font-bold text-white">{{ $stocks->total() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-emerald-400 mb-1">Total Stok</p>
                <p class="text-xl font-bold text-white">{{ number_format($stocks->sum('qty')) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-amber-400 mb-1">Low Stock</p>
                <p class="text-xl font-bold text-white">{{ $stocks->filter(fn($s) => $s->qty <= $s->min_stock)->count() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-cyan-400 mb-1">Nilai Stok</p>
                <p class="text-lg font-bold text-white">Rp {{ number_format($stocks->sum(fn($s) => $s->qty * ($s->price ?? 0)) / 1000000, 1) }}M</p>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="[&_td]:whitespace-nowrap w-full text-sm text-left">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">SKU</th>
                            <th class="py-3 px-4">Produk</th>
                            <th class="py-3 px-4">Kategori</th>
                            <th class="py-3 px-4">Size</th>
                            <th class="py-3 px-4">Lokasi Bin</th>
                            <th class="py-3 px-4 text-center">Qty</th>
                            <th class="py-3 px-4 text-center">Min</th>
                            <th class="py-3 px-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($stocks as $stock)
                        <tr class="hover:bg-slate-800/30 transition-colors {{ $stock->qty <= $stock->min_stock ? 'bg-red-900/10' : '' }}">
                            <td class="py-3 px-4 font-mono font-bold text-cyan-400">{{ $stock->sku }}</td>
                            <td class="py-3 px-4">
                                <div class="text-white font-medium">{{ $stock->product_name }}</div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-xs px-2 py-1 rounded-full bg-purple-500/20 text-purple-400">{{ $stock->size_category }}</span>
                            </td>
                            <td class="py-3 px-4 text-slate-300">{{ $stock->size }}</td>
                            <td class="py-3 px-4 font-mono text-slate-300">{{ $stock->bin_code }}</td>
                            <td class="py-3 px-4 text-center">
                                <span class="font-bold {{ $stock->qty <= $stock->min_stock ? 'text-red-400' : 'text-emerald-400' }}">{{ number_format($stock->qty) }}</span>
                            </td>
                            <td class="py-3 px-4 text-center text-slate-500">{{ $stock->min_stock }}</td>
                            <td class="py-3 px-4">
                                <span class="text-xs px-2 py-1 rounded-full {{ $stock->sku_status === 'AKTIF' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-500/20 text-slate-400' }}">
                                    {{ $stock->sku_status }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-12 text-slate-500">Tidak ada data stok.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($stocks->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">{{ $stocks->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>