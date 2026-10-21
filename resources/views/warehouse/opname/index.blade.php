<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Stock Opname</h2>
            <p class="text-sm text-slate-400 mt-1">Hitung fisik per rak atau seluruh gudang, lalu sesuaikan stok sistem</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row gap-3">
            <form method="GET" action="{{ route('gudang.opname') }}" class="flex-1 flex flex-wrap gap-2">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari no. opname..."
                    class="flex-1 min-w-[10rem] px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
                <select name="status" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm">
                    <option value="">Semua status</option>
                    @foreach (\App\Models\StockOpname::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl">Cari</button>
            </form>
            <a href="{{ route('gudang.opname.create') }}"
                class="px-5 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl text-sm text-center">
                Buat Opname
            </a>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-800/50 text-xs text-slate-400">
                        <tr>
                            <th class="text-left py-3 px-4">No. Opname</th>
                            <th class="text-left py-3 px-4">Cakupan</th>
                            <th class="text-left py-3 px-4">Status</th>
                            <th class="text-right py-3 px-4">Terhitung</th>
                            <th class="text-right py-3 px-4">Selisih</th>
                            <th class="text-left py-3 px-4">Dibuat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($opnames as $opname)
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4">
                                    <a href="{{ route('gudang.opname.show', $opname->id) }}" class="font-mono text-cyan-400 hover:text-cyan-300">{{ $opname->opname_number }}</a>
                                </td>
                                <td class="py-3 px-4 text-white">{{ $opname->scope_label }}</td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $opname->status_color }}">{{ $opname->status_label }}</span>
                                </td>
                                <td class="py-3 px-4 text-right text-slate-300">{{ $opname->counted_count }} / {{ $opname->items_count }}</td>
                                <td class="py-3 px-4 text-right font-bold {{ $opname->diff_count ? 'text-red-400' : 'text-slate-500' }}">{{ $opname->diff_count }}</td>
                                <td class="py-3 px-4 text-xs text-slate-400">
                                    {{ $opname->created_at->format('d M Y H:i') }}
                                    <p>{{ $opname->creator?->name }}</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center text-slate-500">Belum ada stock opname.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($opnames->hasPages())
                <div class="p-4 border-t border-slate-800">{{ $opnames->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
