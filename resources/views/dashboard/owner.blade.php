<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>
    <x-slot name="subheader">Ringkasan gudang JERSIC.ID</x-slot>

    @php
        $icons = [
            'box' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
            'money' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'alert' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
            'building' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        ];
    @endphp

    <div class="space-y-5 lg:space-y-6">

        <x-dashboard.hero :title="'Selamat datang, ' . auth()->user()->name" subtitle="Ringkasan stok, order, dan produksi hari ini."
            :tiles="[
                ['label' => 'Order Aktif', 'value' => number_format($activeOrders), 'sub' => 'Belum dikirim', 'href' => route('gudang.outbound')],
                ['label' => 'Inbound Hari Ini', 'value' => '+' . number_format($todayInbound), 'sub' => 'Unit masuk', 'href' => route('gudang.inbound')],
                ['label' => 'Outbound Hari Ini', 'value' => '-' . number_format($todayOutbound), 'sub' => 'Unit keluar', 'href' => route('gudang.outbound')],
                ['label' => 'Dalam Produksi', 'value' => number_format($inProduction), 'sub' => 'PO di supplier', 'href' => route('po.index')],
            ]">
            <x-slot name="actions">
                <a href="{{ route('reports.index') }}"
                    class="inline-flex items-center gap-2 h-10 px-4 rounded-xl bg-white text-[#0B3B66] text-sm font-bold shadow hover:bg-sky-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    Laporan
                </a>
            </x-slot>
        </x-dashboard.hero>

        <!-- Angka utama -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4">
            <x-dashboard.stat label="Total Stok" :value="number_format($totalStock)" sub="Unit di semua rak" color="cyan" :icon="$icons['box']" :href="route('reports.stock')" />
            <x-dashboard.stat label="Nilai Stok" :value="'Rp ' . number_format($stockValue / 1000000, 1) . 'M'" sub="Harga modal (IDR)" color="emerald" :icon="$icons['money']" />
            <x-dashboard.stat label="Stok Menipis" :value="number_format($lowStockCount)" sub="SKU di bawah minimum" color="red" :icon="$icons['alert']" :href="route('master-skus.index')" />
            <x-dashboard.stat label="Supplier" :value="number_format($activeSuppliers)" sub="Aktif" color="blue" :icon="$icons['building']" :href="route('suppliers.index')" />
        </div>

        <x-work-queue :items="$workItems" />

        <!-- Charts Row (2 kolom: 2/3 + 1/3) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 lg:gap-6">

            <!-- Chart Pergerakan Stok (2/3 width) -->
            <div class="lg:col-span-2 card p-5 lg:p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-base font-bold text-white">Pergerakan Stok (7 Hari)</h3>
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

            <x-dashboard.quick-actions />
        </div>

        <!-- Bottom Row (3 kolom) -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 lg:gap-6">

            <!-- Chart Distribusi Kategori (1/3 width) -->
            <div class="card p-5 lg:p-6">
                <div class="mb-6">
                    <h3 class="text-base font-bold text-white">Distribusi Stok per Kategori</h3>
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
                                    style="background: {{ ['#06b6d4', '#ec4899', '#8b5cf6', '#f59e0b', '#10b981'][$index % 5] }}"></div>
                                <span class="text-slate-400">{{ $cat->size_category }}</span>
                            </div>
                            <span class="font-semibold text-white">{{ number_format($cat->total_qty) }}
                                unit</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Top 5 SKU Stok Terbanyak -->
            <div class="card p-5 lg:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-white">Top 5 SKU Stok Terbanyak</h3>
                    <a href="{{ route('reports.stock') }}"
                        class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Lihat Semua →</a>
                </div>
                <div class="space-y-3">
                    @forelse($topSkus as $index => $sku)
                        <div
                            class="flex items-center justify-between p-3 bg-slate-800/50 rounded-xl hover:bg-slate-800 transition-colors">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center text-xs font-bold text-cyan-400">
                                    {{ $index + 1 }}
                                </div>
                                <div>
                                    <p class="text-sm font-mono font-semibold text-white">{{ $sku->sku }}</p>
                                    <p class="text-xs text-slate-400 truncate max-w-[160px]">{{ $sku->product_name }}
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
            <div class="card p-5 lg:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-white">Peringatan Stok Menipis</h3>
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
                        <div class="p-3 bg-slate-800/50 rounded-xl hover:bg-slate-800 transition-colors">
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
        <div class="card p-5 lg:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-white">Aktivitas Terakhir</h3>
                <a href="{{ route('reports.movement') }}"
                    class="text-xs text-cyan-400 hover:text-cyan-300 transition-colors">Lihat Semua →</a>
            </div>
            <div class="space-y-3">
                @forelse($recentActivities as $activity)
                    <div
                        class="flex items-center gap-4 p-3 bg-slate-800/40 rounded-xl hover:bg-slate-800/70 transition-colors">
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
        (function () {
            // Warna grafik dibaca dari tema yang aktif, lalu diperbarui saat tema diganti
            const cssVar = (name) => 'rgb(' + getComputedStyle(document.documentElement).getPropertyValue(name).trim() + ')';
            const cssVarA = (name, a) => 'rgb(' + getComputedStyle(document.documentElement).getPropertyValue(name).trim() + ' / ' + a + ')';
            const palette = () => ({
                grid: cssVarA('--b-slate-700', 0.35),
                tick: cssVar('--c-slate-400'),
                surface: cssVar('--c-slate-900'),
                tooltipBg: cssVar('--c-slate-800'),
                tooltipTitle: cssVar('--c-slate-100'),
                tooltipBody: cssVar('--c-slate-300'),
                tooltipBorder: cssVar('--b-slate-700'),
            });
            const tooltip = (p) => ({
                backgroundColor: p.tooltipBg,
                titleColor: p.tooltipTitle,
                bodyColor: p.tooltipBody,
                borderColor: p.tooltipBorder,
                borderWidth: 1,
                padding: 12,
                cornerRadius: 10,
            });
            let p = palette();

            const movementChart = new Chart(document.getElementById('movementChart'), {
                type: 'line',
                data: {
                    labels: @json($last7Days),
                    datasets: [{
                            label: 'Inbound',
                            data: @json($inboundData),
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.12)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                        },
                        {
                            label: 'Outbound',
                            data: @json($outboundData),
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.10)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false }, tooltip: tooltip(p) },
                    scales: {
                        x: { grid: { color: p.grid }, ticks: { color: p.tick, font: { size: 11 } } },
                        y: {
                            beginAtZero: true,
                            grid: { color: p.grid },
                            ticks: { color: p.tick, font: { size: 11 }, callback: (value) => value.toLocaleString('id-ID') }
                        }
                    }
                }
            });

            const categoryChart = new Chart(document.getElementById('categoryChart'), {
                type: 'doughnut',
                data: {
                    labels: @json($categoryDistribution->pluck('size_category')),
                    datasets: [{
                        data: @json($categoryDistribution->pluck('total_qty')),
                        backgroundColor: ['#06b6d4', '#ec4899', '#8b5cf6', '#f59e0b', '#10b981'],
                        borderColor: p.surface,
                        borderWidth: 3,
                        hoverOffset: 10,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign(tooltip(p), {
                            callbacks: {
                                label: function (context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.parsed.toLocaleString('id-ID') + ' unit (' + percentage + '%)';
                                }
                            }
                        })
                    }
                }
            });

            window.addEventListener('themechange', () => {
                p = palette();
                for (const axis of ['x', 'y']) {
                    movementChart.options.scales[axis].grid.color = p.grid;
                    movementChart.options.scales[axis].ticks.color = p.tick;
                }
                Object.assign(movementChart.options.plugins.tooltip, tooltip(p));
                Object.assign(categoryChart.options.plugins.tooltip, tooltip(p));
                categoryChart.data.datasets[0].borderColor = p.surface;
                movementChart.update('none');
                categoryChart.update('none');
            });
        })();
    </script>
</x-app-layout>
