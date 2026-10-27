<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Dashboard Owner') }}</h2>
            <p class="text-sm text-slate-400 mt-1">Overview sistem JERSIC.ID-WMS</p>
        </div>
    </x-slot>

    <div class="space-y-6">

        <x-work-queue :items="$workItems" />

        <!-- Stats Cards - Row 1 (4 kolom) -->
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
                <p class="text-xl font-bold text-white">{{ number_format($totalStock) }}</p>
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
                <p class="text-xl font-bold text-emerald-400">Rp {{ number_format($stockValue / 1000000, 1) }}M</p>
                <p class="text-[10px] text-slate-500 mt-0.5">IDR</p>
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
                <p class="text-xl font-bold text-red-400">{{ $lowStockCount }}</p>
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
                <p class="text-xl font-bold text-white">+{{ number_format($todayInbound) }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Unit</p>
            </div>
        </div>

        <!-- Stats Cards Row 2 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Outbound Hari Ini -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-pink-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 8V20m0 0l4-4m-4 4l-4-4M7 16V4m0 0L3 8m4-4l4 4" />
                    </svg>
                </div>
                <p class="text-xs text-pink-400 mb-0.5">Outbound Hari Ini</p>
                <p class="text-xl font-bold text-white">-{{ number_format($todayOutbound) }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Unit</p>
            </div>

            <!-- In Production -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-amber-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                    </svg>
                </div>
                <p class="text-xs text-amber-400 mb-0.5">In Production</p>
                <p class="text-xl font-bold text-white">{{ $inProduction }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">PO</p>
            </div>

            <!-- Supplier Aktif -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-blue-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <p class="text-xs text-blue-400 mb-0.5">Supplier</p>
                <p class="text-xl font-bold text-white">{{ $activeSuppliers }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Aktif</p>
            </div>

            <!-- Order Aktif -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-indigo-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <p class="text-xs text-indigo-400 mb-0.5">Order Aktif</p>
                <p class="text-xl font-bold text-white">{{ $activeOrders }}</p>
                <p class="text-[10px] text-slate-500 mt-0.5">Belum dikirim</p>
            </div>
        </div>

        <!-- Charts Row (2 kolom: 2/3 + 1/3) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Chart Pergerakan Stok (2/3 width) -->
            <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-base font-semibold text-white">Pergerakan Stok (7 Hari)</h3>
                        <p class="text-xs text-slate-400 mt-1">Inbound vs Outbound</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                            <span class="text-xs text-slate-400">Inbound</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-red-400"></div>
                            <span class="text-xs text-slate-400">Outbound</span>
                        </div>
                    </div>
                </div>
                <div class="relative" style="height: 300px;">
                    <canvas id="movementChart"></canvas>
                </div>
            </div>

            <!-- Chart Distribusi Kategori (1/3 width) -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="mb-6">
                    <h3 class="text-base font-semibold text-white">Distribusi Stok per Kategori</h3>
                    <p class="text-xs text-slate-400 mt-1">Berdasarkan jumlah unit</p>
                </div>
                <div class="relative" style="height: 200px;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div class="mt-6 space-y-3">
                    @foreach ($categoryDistribution as $index => $cat)
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-3 rounded-full"
                                    style="background: {{ ['#06b6d4', '#ec4899', '#8b5cf6'][$index] }}"></div>
                                <span class="text-slate-400">{{ $cat->size_category }}</span>
                            </div>
                            <span class="font-semibold text-white">{{ number_format($cat->total_qty) }}
                                unit</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Bottom Row (2 kolom) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Top 5 SKU Stok Terbanyak -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-white">Top 5 SKU Stok Terbanyak</h3>
                    <a href="{{ route('reports.stock') }}"
                        class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Lihat Semua →</a>
                </div>
                <div class="space-y-3">
                    @forelse($topSkus as $index => $sku)
                        <div
                            class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg hover:bg-slate-800 transition-colors">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center text-xs font-bold text-cyan-400">
                                    {{ $index + 1 }}
                                </div>
                                <div>
                                    <p class="text-sm font-mono font-semibold text-white">{{ $sku->sku }}</p>
                                    <p class="text-xs text-slate-400 truncate max-w-[200px]">{{ $sku->product_name }}
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-emerald-400">{{ number_format($sku->current_stock) }}
                                </p>
                                <p class="text-[10px] text-slate-500">unit</p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8 text-slate-500">
                            <p class="text-sm">Belum ada data stok</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Top 5 Low Stock -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-white">⚠️ Peringatan Low Stock</h3>
                    <a href="{{ route('master-skus.index') }}"
                        class="text-xs text-red-400 hover:text-red-300 transition-colors">Lihat Semua →</a>
                </div>
                <div class="space-y-3">
                    @forelse($lowStockItems as $index => $sku)
                        @php
                            $percentage = $sku->limit_qty > 0 ? ($sku->current_stock / $sku->limit_qty) * 100 : 0;
                            $barColor =
                                $percentage <= 25
                                    ? 'bg-red-500'
                                    : ($percentage <= 50
                                        ? 'bg-amber-500'
                                        : 'bg-emerald-500');
                        @endphp
                        <div class="p-3 bg-slate-800/50 rounded-lg hover:bg-slate-800 transition-colors">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-mono font-semibold text-white truncate">{{ $sku->sku }}
                                    </p>
                                    <p class="text-xs text-slate-400 truncate">{{ $sku->product_name }}</p>
                                </div>
                                <div class="text-right ml-3">
                                    <p class="text-sm font-bold text-red-400">{{ $sku->current_stock }} /
                                        {{ $sku->limit_qty }}</p>
                                    <p class="text-[10px] text-slate-500">Sisa / Min</p>
                                </div>
                            </div>
                            <div class="w-full bg-slate-700 rounded-full h-1.5">
                                <div class="{{ $barColor }} h-1.5 rounded-full transition-all"
                                    style="width: {{ min($percentage, 100) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <div
                                class="w-12 h-12 bg-emerald-500/20 rounded-xl flex items-center justify-center mx-auto mb-2">
                                <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <p class="text-sm text-emerald-400 font-medium">Semua stok aman!</p>
                            <p class="text-xs text-slate-500 mt-1">Tidak ada SKU dengan stok menipis</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Recent Activities (Full Width) -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-white">Aktivitas Terakhir</h3>
                <a href="{{ route('reports.movement') }}"
                    class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Lihat Semua →</a>
            </div>
            <div class="space-y-3">
                @forelse($recentActivities as $activity)
                    <div
                        class="flex items-center gap-4 p-3 bg-slate-800/30 rounded-lg hover:bg-slate-800/50 transition-colors">
                        <div
                            class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0
                            {{ $activity->type === 'INBOUND'
                                ? 'bg-emerald-500/20 text-emerald-400'
                                : ($activity->type === 'OUTBOUND'
                                    ? 'bg-red-500/20 text-red-400'
                                    : 'bg-cyan-500/20 text-cyan-400') }}">
                            @if ($activity->type === 'INBOUND')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M7 16V4m0 0L3 8m4-4l4 4" />
                                </svg>
                            @elseif($activity->type === 'OUTBOUND')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 8V20m0 0l4-4m-4 4l-4-4" />
                                </svg>
                            @else
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span
                                    class="text-xs font-mono font-semibold text-cyan-400">{{ $activity->sku }}</span>
                                <span
                                    class="text-xs px-2 py-0.5 rounded-full 
                                    {{ $activity->type === 'INBOUND'
                                        ? 'bg-emerald-500/20 text-emerald-400'
                                        : ($activity->type === 'OUTBOUND'
                                            ? 'bg-red-500/20 text-red-400'
                                            : 'bg-cyan-500/20 text-cyan-400') }}">
                                    {{ $activity->type }}
                                </span>
                            </div>
                            <p class="text-xs text-slate-400 truncate">
                                {{ $activity->masterSku?->product_name ?? '-' }}</p>
                        </div>
                        <div class="text-right">
                            <p
                                class="text-sm font-bold {{ $activity->type === 'INBOUND' ? 'text-emerald-400' : 'text-red-400' }}">
                                {{ $activity->type === 'INBOUND' ? '+' : '-' }}{{ number_format($activity->qty) }}
                            </p>
                            <p class="text-[10px] text-slate-500">{{ $activity->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-slate-500">
                        <p class="text-sm">Belum ada aktivitas</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
        // Chart Pergerakan Stok
        const movementCtx = document.getElementById('movementChart').getContext('2d');
        new Chart(movementCtx, {
            type: 'line',
            data: {
                labels: @json($last7Days),
                datasets: [{
                        label: 'Inbound',
                        data: @json($inboundData),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    },
                    {
                        label: 'Outbound',
                        data: @json($outboundData),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                        borderColor: '#334155',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(51, 65, 85, 0.3)'
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(51, 65, 85, 0.3)'
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: {
                                size: 11
                            },
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Chart Distribusi Kategori
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: @json($categoryDistribution->pluck('size_category')),
                datasets: [{
                    data: @json($categoryDistribution->pluck('total_qty')),
                    backgroundColor: ['#06b6d4', '#ec4899', '#8b5cf6'],
                    borderColor: '#0f172a',
                    borderWidth: 3,
                    hoverOffset: 10,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                        borderColor: '#334155',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed.toLocaleString() + ' unit (' +
                                    percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    </script>
</x-app-layout>
