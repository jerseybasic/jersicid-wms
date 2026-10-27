<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>
    <x-slot name="subheader">Operasional gudang hari ini</x-slot>

    @php
        $icons = [
            'box' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'alert' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
            'bolt' => 'M13 10V3L4 14h7v7l9-11h-7z',
            'check' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        ];
    @endphp

    <div class="space-y-5 lg:space-y-6">

        <x-dashboard.hero :title="'Selamat datang, ' . auth()->user()->name" subtitle="Kerjakan yang paling mendesak dulu, lalu lanjutkan antrean."
            :tiles="[
                ['label' => 'Inbound Hari Ini', 'value' => '+' . number_format($inboundToday ?? 0), 'sub' => 'Unit masuk', 'href' => route('gudang.inbound')],
                ['label' => 'Outbound Hari Ini', 'value' => '-' . number_format($outboundToday ?? 0), 'sub' => 'Unit keluar', 'href' => route('gudang.outbound')],
                ['label' => 'Picking Pending', 'value' => number_format($pickingPending ?? 0), 'sub' => 'Sesi menunggu', 'href' => route('gudang.picking')],
                ['label' => 'Packing Pending', 'value' => number_format($packingPending ?? 0), 'sub' => 'Sesi menunggu', 'href' => route('gudang.packing')],
            ]">
            <x-slot name="actions">
                <a href="{{ route('gudang.tugas') }}"
                    class="inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-white text-[#0B3B66] text-sm font-bold shadow hover:bg-sky-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
                    Tugas Saya
                </a>
            </x-slot>
        </x-dashboard.hero>

        <!-- Angka utama -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4">
            <x-dashboard.stat label="Total Stok" :value="number_format($totalStock ?? 0)" :sub="'Nilai Rp ' . number_format(($stockValue ?? 0) / 1000000, 1) . 'M'" color="cyan" :icon="$icons['box']" />
            <x-dashboard.stat label="Stok Menipis" :value="number_format($lowStock ?? 0)" sub="SKU di bawah minimum" color="red" :icon="$icons['alert']" />
            <x-dashboard.stat label="Sedang Dikerjakan" :value="number_format($inProgress ?? 0)" sub="Sesi picking/packing" color="blue" :icon="$icons['bolt']" />
            <x-dashboard.stat label="Selesai Hari Ini" :value="number_format($completedToday ?? 0)" sub="Sesi" color="emerald" :icon="$icons['check']" />
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 lg:gap-6">
            <x-work-queue :items="$workItems" class="xl:col-span-2" />
            <x-dashboard.quick-actions />
        </div>

        <!-- Stok menipis & pergerakan terakhir -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 lg:gap-6">

            <!-- Low Stock Alerts -->
            <div class="card p-5 lg:p-6">
                <h3 class="text-base font-bold text-white mb-4">Peringatan Stok Menipis</h3>
                <div class="space-y-2 max-h-72 overflow-y-auto custom-scrollbar">
                    {{-- ✅ FIX: $lowStockItems → $lowStockProducts --}}
                    @forelse($lowStockProducts ?? [] as $item)
                        <div class="flex items-center justify-between p-3 bg-red-500/10 border border-red-500/25 rounded-xl">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-white font-mono truncate">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ $item->product_name }}</p>
                            </div>
                            <div class="text-right ml-2">
                                {{-- ✅ FIX: current_qty → current_stock (computed column) --}}
                                <p class="text-sm font-bold text-red-400">{{ $item->current_stock ?? 0 }}</p>
                                <p class="text-[10px] text-slate-500">Min: {{ $item->limit_qty }}</p>
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
            <div class="card p-5 lg:p-6">
                <h3 class="text-base font-bold text-white mb-4">Pergerakan Terakhir</h3>
                <div class="space-y-2 max-h-72 overflow-y-auto custom-scrollbar">
                    @forelse($recentMovements ?? [] as $movement)
                        @php $isIn = in_array($movement->type, ['INBOUND', 'ADJUSTMENT_IN']); @endphp
                        <div class="flex items-center gap-3 p-3 bg-slate-800/40 border border-slate-800 rounded-xl">
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