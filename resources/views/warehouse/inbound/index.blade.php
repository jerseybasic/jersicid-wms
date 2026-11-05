<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">
                {{ __('Inbound - Penerimaan Barang') }}
            </h2>
            <p class="text-sm text-slate-400 mt-1">Terima barang dari supplier & update stok gudang</p>
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

        <!-- Filter & Action Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl">
            <div class="flex flex-wrap items-center gap-3">
                <!-- Search -->
                <div class="flex-1 min-w-[250px]">
                    <form method="GET" action="{{ route('gudang.inbound') }}" class="flex flex-wrap gap-3">
                        <div class="relative flex-1 min-w-[200px]">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <input type="text" name="search" value="{{ request('search') }}" 
                                   placeholder="Cari No. Receipt, PO, supplier, SKU..." 
                                   class="w-full pl-10 pr-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                        </div>
                        
                        <!-- Status Filter -->
                        <select name="status" class="px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                            <option value="">Semua Status</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>

                        <!-- Filter Button -->
                        <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-semibold rounded-xl shadow-lg shadow-cyan-500/25 hover:shadow-cyan-500/40 transition-all duration-200 text-sm flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                            </svg>
                            Filter
                        </button>

                        <!-- Reset Button -->
                        <a href="{{ route('gudang.inbound') }}" class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-xl border border-slate-700 hover:border-slate-600 transition-all duration-200 text-sm flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Reset
                        </a>
                    </form>
                </div>

                <!-- Tombol Tambah (Di kanan) -->
                <a href="{{ route('gudang.inbound.create') }}" class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 hover:shadow-cyan-500/40 transition-all duration-200 text-sm flex items-center gap-2 whitespace-nowrap border-2 border-cyan-400/50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Terima Barang Baru
                </a>
            </div>
        </div>

        <!-- Table Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Riwayat Penerimaan Barang
                </h3>
                <span class="text-xs text-slate-400 bg-slate-800 px-3 py-1 rounded-full border border-slate-700">
                    {{ $inbounds->total() }} total
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="[&_td]:whitespace-nowrap w-full">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-3 px-4 font-medium">No. Receipt</th>
                            <th class="text-left py-3 px-4 font-medium">Supplier</th>
                            <th class="text-left py-3 px-4 font-medium">PO Terkait</th>
                            <th class="text-right py-3 px-4 font-medium">Diterima</th>
                            <th class="text-right py-3 px-4 font-medium">Pesanan</th>
                            <th class="text-left py-3 px-4 font-medium">Tanggal</th>
                            <th class="text-left py-3 px-4 font-medium">Status</th>
                            <th class="text-left py-3 px-4 font-medium">Diterima Oleh</th>
                            <th class="text-right py-3 px-4 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($inbounds as $inbound)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-4">
                                <a href="{{ route('gudang.inbound.show', $inbound->first_id) }}" class="text-xs font-mono font-bold text-cyan-400 hover:text-cyan-300">{{ $inbound->reference_no }}</a>
                                @if ($inbound->first?->notes)
                                    <p class="text-xs text-slate-500 mt-0.5 max-w-[14rem] truncate" title="{{ $inbound->first->notes }}">{{ $inbound->first->notes }}</p>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-sm text-white">{{ $inbound->supplier ?? '-' }}</td>
                            <td class="py-3 px-4 text-xs">
                                @if ($inbound->po)
                                    <a href="{{ route('po.show', $inbound->po->id) }}" class="font-mono text-cyan-400 hover:text-cyan-300 whitespace-nowrap">{{ $inbound->po->po_number }}</a>
                                @else
                                    <span class="text-slate-500">-</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-right text-sm whitespace-nowrap">
                                <span class="font-semibold text-emerald-400">+{{ number_format($inbound->total_qty) }} pcs</span>
                                <span class="block text-[11px] text-slate-500">{{ $inbound->sku_count }} SKU</span>
                            </td>
                            <td class="py-3 px-4 text-right text-xs whitespace-nowrap">
                                @if ($inbound->po)
                                    <span class="text-white font-semibold">{{ number_format($inbound->po->qty_received_total) }}/{{ number_format($inbound->po->qty_ordered_total) }} pcs</span>
                                    @if ($inbound->po->qty_remaining_total > 0)
                                        <span class="block text-[11px] text-orange-400">{{ number_format($inbound->po->qty_remaining_total) }} pcs masih diproses</span>
                                    @else
                                        <span class="block text-[11px] text-emerald-400">semua sudah datang</span>
                                    @endif
                                @elseif ($inbound->expected_lines > 0)
                                    <span class="text-white font-semibold">{{ number_format($inbound->total_qty) }}/{{ number_format($inbound->ordered_qty) }} pcs</span>
                                    @if ($inbound->pending_qty > 0)
                                        <span class="block text-[11px] text-orange-400">{{ number_format($inbound->pending_qty) }} pcs masih diproses</span>
                                    @else
                                        <span class="block text-[11px] text-emerald-400">semua sudah datang</span>
                                    @endif
                                @else
                                    <span class="text-slate-500">-</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-300 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($inbound->last_at)->format('d M Y') }}</td>
                            <td class="py-3 px-4">
                                @if ($inbound->receipt_status === 'BELUM_KOMPLIT')
                                    <span class="text-xs px-2 py-1 rounded-full bg-orange-500/20 text-orange-400 whitespace-nowrap">Belum Komplit</span>
                                    <span class="block mt-1 text-[11px] text-slate-500 whitespace-nowrap">baru sebagian jadi</span>
                                @else
                                    <span class="text-xs px-2 py-1 rounded-full bg-emerald-500/20 text-emerald-400">Komplit</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-300">{{ $inbound->first?->user?->name ?? '-' }}</td>
                            <td class="py-3 px-4 text-right">
                                <a href="{{ route('gudang.inbound.show', $inbound->first_id) }}" class="text-xs text-cyan-400 hover:text-cyan-300">Detail</a>
                                @if (in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR']))
                                    <a href="{{ route('gudang.inbound.edit', $inbound->first_id) }}" class="ml-2 text-xs text-amber-400 hover:text-amber-300">Edit</a>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center py-12 text-slate-500">Tidak ada data inbound</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($inbounds->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">
                    {{ $inbounds->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>