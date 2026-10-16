<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">
                {{ __('Dashboard Gudang') }}
            </h2>
            <p class="text-sm text-slate-400 mt-1">Overview operasional gudang hari ini</p>
        </div>
    </x-slot>

    <div class="space-y-6">

        <!-- Stats Cards Row 1 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Total Stok -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <p class="text-xs text-slate-400 mb-0.5">Total Stok</p>
                <p class="text-xl font-bold text-white">{{ number_format($totalStock ?? 0) }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Unit</p>
            </div>

            <!-- Nilai Stok -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-emerald-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="text-xs text-emerald-400 mb-0.5">Nilai Stok</p>
                <p class="text-xl font-bold text-emerald-400">
                    Rp {{ number_format(($stockValue ?? 0) / 1000000, 1) }}M
                </p>
            </div>

            <!-- Low Stock -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-red-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p class="text-xs text-red-400 mb-0.5">Low Stock</p>
                <p class="text-xl font-bold text-red-400">{{ $lowStock ?? 0 }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Alert</p>
            </div>

            <!-- Inbound Hari Ini -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-purple-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                    </svg>
                </div>
                <p class="text-xs text-purple-400 mb-0.5">Inbound Hari Ini</p>
                <p class="text-xl font-bold text-cyan-400">+{{ $inboundToday ?? 0 }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Unit</p>
            </div>
        </div>

        <!-- Stats Cards Row 2 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Picking Pending -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-amber-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <p class="text-xs text-amber-400 mb-0.5">Picking Pending</p>
                <p class="text-xl font-bold text-white">{{ $pickingPending ?? 0 }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Session</p>
            </div>

            <!-- Packing Pending -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-indigo-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <p class="text-xs text-indigo-400 mb-0.5">Packing Pending</p>
                {{-- ✅ FIX: Ganti $pickingPending → $packingPending --}}
                <p class="text-xl font-bold text-white">{{ $packingPending ?? 0 }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Session</p>
            </div>

            <!-- In Progress -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <p class="text-xs text-blue-400 mb-0.5">In Progress</p>
                <p class="text-xl font-bold text-white">{{ $inProgress ?? 0 }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Session</p>
            </div>

            <!-- Completed Today -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-emerald-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="text-xs text-emerald-400 mb-0.5">Selesai Hari Ini</p>
                <p class="text-xl font-bold text-white">{{ $completedToday ?? 0 }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Session</p>
            </div>
        </div>

        <!-- Quick Actions & Alerts -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Pending Tasks -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
                <h3 class="text-base font-semibold text-white mb-4">Tugas Menunggu</h3>
                <div class="space-y-3">
                    <a href="{{ route('po.index') }}"
                        class="flex items-center justify-between p-3 bg-slate-900/50 border border-slate-700/50 rounded-lg hover:border-cyan-500/50 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-amber-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">PO Siap Diterima</p>
                                <p class="text-xs text-slate-400">Barang ready di supplier</p>
                            </div>
                        </div>
                        {{-- ✅ FIX: $poPending → $poReady --}}
                        <span class="text-lg font-bold text-amber-400">{{ $poReady ?? 0 }}</span>
                    </a>

                    <a href="{{ route('gudang.picking') }}"
                        class="flex items-center justify-between p-3 bg-slate-900/50 border border-slate-700/50 rounded-lg hover:border-cyan-500/50 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-purple-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Order Perlu Picking</p>
                                <p class="text-xs text-slate-400">Ambil barang dari rak</p>
                            </div>
                        </div>
                        {{-- ✅ FIX: $pendingPick → $orderNeedPicking --}}
                        <span class="text-lg font-bold text-purple-400">{{ $orderNeedPicking ?? 0 }}</span>
                    </a>

                    <a href="{{ route('gudang.packing') }}"
                        class="flex items-center justify-between p-3 bg-slate-900/50 border border-slate-700/50 rounded-lg hover:border-cyan-500/50 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">Order Perlu Packing</p>
                                <p class="text-xs text-slate-400">Kemas untuk pengiriman</p>
                            </div>
                        </div>
                        {{-- ✅ FIX: $pendingPack → $orderNeedPacking --}}
                        <span class="text-lg font-bold text-cyan-400">{{ $orderNeedPacking ?? 0 }}</span>
                    </a>
                </div>
            </div>

            <!-- Low Stock Alerts -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
                <h3 class="text-base font-semibold text-white mb-4">Peringatan Stok Menipis</h3>
                <div class="space-y-2 max-h-72 overflow-y-auto custom-scrollbar">
                    {{-- ✅ FIX: $lowStockItems → $lowStockProducts --}}
                    @forelse($lowStockProducts ?? [] as $item)
                        <div class="flex items-center justify-between p-3 bg-red-900/20 border border-red-500/30 rounded-lg">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-white font-mono truncate">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ $item->product_name }}</p>
                            </div>
                            <div class="text-right ml-2">
                                {{-- ✅ FIX: current_qty → current_stock (computed column) --}}
                                <p class="text-sm font-bold text-red-400">{{ $item->current_stock ?? 0 }}</p>
                                <p class="text-[10px] text-slate-500">Min: {{ $item->min_stock }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <svg class="w-12 h-12 text-emerald-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-sm text-emerald-400">Semua stok aman!</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Recent Movements -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
                <h3 class="text-base font-semibold text-white mb-4">Pergerakan Terakhir</h3>
                <div class="space-y-2 max-h-72 overflow-y-auto custom-scrollbar">
                    @forelse($recentMovements ?? [] as $movement)
                        @php $isIn = in_array($movement->type, ['INBOUND', 'ADJUSTMENT_IN']); @endphp
                        <div class="flex items-center gap-3 p-3 bg-slate-900/50 border border-slate-700/50 rounded-lg">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 {{ $isIn ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' }} rounded-lg flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="{{ $isIn ? 'M7 16V4m0 0L3 8m4-4l4 4' : 'M17 8V20m0 0l4-4m-4 4l-4-4' }}" />
                                    </svg>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-mono text-white font-medium truncate">{{ $movement->sku }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ $movement->masterSku?->product_name ?? '-' }}</p>
                                <p class="text-[10px] text-slate-500">{{ $movement->user?->name ?? 'Sistem' }} • {{ $movement->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-bold {{ $isIn ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ $isIn ? '+' : '-' }}{{ number_format($movement->qty) }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <p class="text-sm text-slate-500">Belum ada pergerakan</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>