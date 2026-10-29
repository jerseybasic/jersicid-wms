<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Purchase Order') }}</h2>
                <p class="text-sm text-slate-400 mt-1">Kelola pesanan pembelian ke supplier</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Total PO</p>
                <p class="text-2xl font-bold text-white">{{ $stats['total'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-amber-400 mb-1">Pending</p>
                <p class="text-2xl font-bold text-amber-400">{{ $stats['pending'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-emerald-400 mb-1">Approved</p>
                <p class="text-2xl font-bold text-emerald-400">{{ $stats['approved'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-cyan-400 mb-1">In Production</p>
                <p class="text-2xl font-bold text-cyan-400">{{ $stats['in_production'] }}</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <form method="GET" action="{{ route('po.index') }}" class="flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-[250px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari nomor PO..."
                        class="w-full pl-10 pr-4 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all" />
                </div>

                <select name="status" class="px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Status</option>
                    @foreach ($statusOptions as $status => $label)
                        <option value="{{ $status }}" {{ request('status') == $status ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="supplier_id" class="px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Supplier</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" {{ request('supplier_id') == $supplier->id ? 'selected' : '' }}>{{ $supplier->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white font-semibold rounded-lg transition-all text-sm">Filter</button>
                <a href="{{ route('po.index') }}" class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-lg border border-slate-700 transition-all text-sm">Reset</a>
                @if (in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR']))
                    <a href="{{ route('po.create') }}" class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all text-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Buat PO Baru
                    </a>
                    <a href="{{ route('po.create', ['dari' => 'stok-menipis']) }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-amber-300 font-semibold rounded-xl border border-amber-500/40 transition-all text-sm">
                        PO dari Stok Menipis
                    </a>
                @endif
            </form>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-3 px-4 font-medium">No. PO</th>
                            <th class="text-left py-3 px-4 font-medium">Supplier</th>
                            <th class="text-left py-3 px-4 font-medium">Tipe</th>
                            <th class="text-right py-3 px-4 font-medium">Total</th>
                            <th class="text-left py-3 px-4 font-medium">Status</th>
                            <th class="text-left py-3 px-4 font-medium">Tanggal</th>
                            <th class="text-right py-3 px-4 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($purchaseOrders as $po)
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-4">
                                    <a href="{{ route('po.show', $po->id) }}" class="font-mono font-bold text-cyan-400 text-xs hover:text-cyan-300 transition-colors">
                                        {{ $po->po_number }}
                                    </a>
                                </td>
                                <td class="py-3 px-4 text-xs text-slate-300">{{ $po->supplier_name ?? '-' }}</td>
                                <td class="py-3 px-4">
                                    <span class="text-xs px-2 py-1 bg-slate-800 text-slate-300 rounded-lg font-mono">{{ $po->supplier_type }}</span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <span class="text-xs font-semibold text-emerald-400">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</span>
                                </td>
                                <td class="py-3 px-4">
<span class="text-xs px-2 py-1 rounded-full {{ $po->status_color }}">{{ $po->status_label }}</span>
                                </td>
                                <td class="py-3 px-4 text-xs text-slate-300">{{ $po->created_at->format('d M Y') }}</td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <!-- Detail -->
                                        <a href="{{ route('po.show', $po->id) }}" class="p-1.5 text-slate-400 hover:text-cyan-400 hover:bg-slate-800 rounded transition-colors" title="Detail">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </a>

                                        <!-- Edit (belum diterima/dibatalkan, OWNER/SUPERVISOR) -->
                                        @if ($po->isEditable() && in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR']))
                                            <a href="{{ route('po.edit', $po->id) }}" class="p-1.5 text-slate-400 hover:text-amber-400 hover:bg-slate-800 rounded transition-colors" title="Edit">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </a>
                                        @endif

                                        <!-- Delete (DRAFT only, OWNER only) -->
                                        @if ($po->status === 'DRAFT' && auth()->user()->role === 'OWNER')
                                            <form method="POST" action="{{ route('po.destroy', $po->id) }}" class="inline" onsubmit="return confirm('Yakin hapus PO ini?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded transition-colors" title="Hapus">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-12 text-slate-500">
                                    <div class="w-16 h-16 bg-slate-800/50 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                        <svg class="w-8 h-8 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </div>
                                    <p class="text-sm">Belum ada Purchase Order</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($purchaseOrders->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">
                    {{ $purchaseOrders->links() }}
                </div>
            @endif
        </div>
    </div>

</x-app-layout>