<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Packing') }}</h2>
                <p class="text-sm text-slate-400 mt-1">Proses pengemasan barang</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        {{-- Meja packing: scan label/no. order di keranjang untuk langsung mulai mengemas order itu --}}
        <x-scan-box :endpoint="route('gudang.command')" storage-key="packing-desk" :with-bin="false"
            hint="Scan no. order atau resi dari keranjang untuk langsung mulai packing order itu." />

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Belum mulai</p>
                <p class="text-2xl font-bold text-slate-400">{{ $stats['pending'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-amber-400 mb-1">Sedang dikerjakan</p>
                <p class="text-2xl font-bold text-amber-400">{{ $stats['in_progress'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-emerald-400 mb-1">Selesai</p>
                <p class="text-2xl font-bold text-emerald-400">{{ $stats['completed'] }}</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <form method="GET" action="{{ route('gudang.packing') }}" class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[250px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}" 
                           placeholder="Cari nomor session..." 
                           class="w-full pl-10 pr-4 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                </div>

                <select name="status" class="px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Status</option>
                    <option value="PENDING" {{ request('status') == 'PENDING' ? 'selected' : '' }}>Belum mulai</option>
                    <option value="IN_PROGRESS" {{ request('status') == 'IN_PROGRESS' ? 'selected' : '' }}>Sedang dikerjakan</option>
                    <option value="COMPLETED" {{ request('status') == 'COMPLETED' ? 'selected' : '' }}>Selesai</option>
                </select>

                <button type="submit" class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-on-accent font-semibold rounded-lg transition-all text-sm">Filter</button>
                <a href="{{ route('gudang.packing') }}" class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-lg border border-slate-700 transition-all text-sm">Reset</a>
                <a href="{{ route('gudang.packing.create') }}" class="px-4 py-2 ml-auto bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-semibold rounded-xl shadow-lg shadow-cyan-500/25 transition-all text-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Buat Packing Baru
                </a>
            </form>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-3 px-4 font-medium">No. Session</th>
                            <th class="text-left py-3 px-4 font-medium">Order</th>
                            <th class="text-left py-3 px-4 font-medium">Status</th>
                            <th class="text-left py-3 px-4 font-medium">User</th>
                            <th class="text-left py-3 px-4 font-medium">Tanggal</th>
                            <th class="text-right py-3 px-4 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($sessions as $session)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-4">
                                <span class="font-mono font-bold text-cyan-400 text-xs">{{ $session->session_number }}</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-xs text-slate-300">{{ $session->order?->order_number }}</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-xs px-2 py-1 rounded-full {{ $session->status_color }}">{{ $session->status_label }}</span>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-300">{{ $session->user?->name }}</td>
                            <td class="py-3 px-4 text-xs text-slate-300">{{ $session->created_at->format('d M Y H:i') }}</td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('gudang.packing.show', $session->id) }}" class="p-1.5 text-slate-400 hover:text-cyan-400 hover:bg-slate-800 rounded transition-colors" title="Detail">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </a>
                                    @if($session->status === 'PENDING')
                                        <form method="POST" action="{{ route('gudang.packing.destroy', $session->id) }}" class="inline" onsubmit="return confirm('Yakin hapus session ini?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded transition-colors" title="Hapus">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center py-12 text-slate-500">Belum ada packing session</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($sessions->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">
                    {{ $sessions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>