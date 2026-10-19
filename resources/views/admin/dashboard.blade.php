<x-admin-layout title="Dashboard">
    <x-slot name="pageTitle">Overview</x-slot>

    <!-- KPI Cards (Presisi & Clean) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        @php
        $kpiCards = [
        ['label' => 'Total Stok', 'value' => number_format($totalStock), 'sub' => 'Unit tersedia', 'iconClass' => 'bg-indigo-500/10 text-indigo-400', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['label' => 'Penjualan Hari Ini', 'value' => format_rupiah($todaySales), 'sub' => 'Realtime', 'iconClass' => 'bg-emerald-500/10 text-emerald-400', 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['label' => 'Stok Menipis', 'value' => $lowStockItems, 'sub' => 'Perlu restock', 'iconClass' => 'bg-amber-500/10 text-amber-400', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
        ['label' => 'Pending Orders', 'value' => $pendingOrders, 'sub' => 'Antrian picking', 'iconClass' => 'bg-zinc-500/10 text-zinc-400', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ];

        $salesSeries = $salesChart->pluck('net_sales')->map(fn ($value) => (float) $value)->values();
        $salesCategories = $salesChart->pluck('sale_date')
        ->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d M'))
        ->values();
        @endphp

        @foreach($kpiCards as $kpi)
        <div class="glass-panel p-6 hover:border-zinc-700 transition-colors duration-300">
            <div class="flex justify-between items-center mb-2">
                <p class="text-xs font-semibold text-muted uppercase tracking-wider">{{ $kpi['label'] }}</p>
                <div class="w-8 h-8 rounded-lg flex items-center justify-center {{ $kpi['iconClass'] }}">
                    <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $kpi['icon'] }}"></path>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-primary tracking-tight">{{ $kpi['value'] }}</p>
            <p class="text-xs text-muted mt-1">{{ $kpi['sub'] }}</p>
        </div>
        @endforeach
    </div>

    <!-- Charts & Data Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">
        <!-- Main Chart -->
        <div class="lg:col-span-2 glass-panel p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h3 class="text-sm font-semibold text-zinc-100">Tren Pendapatan</h3>
                    <p class="text-xs text-zinc-500 mt-0.5">30 Hari Terakhir</p>
                </div>
                <div class="flex gap-2">
                    <span class="flex items-center gap-1.5 text-[11px] text-zinc-400"><span class="w-2 h-2 rounded-full bg-indigo-500"></span> Revenue</span>
                </div>
            </div>
            <div id="salesChart" class="w-full h-72"></div>
        </div>

        <!-- Recent Activity / Transactions -->
        <div class="glass-panel p-6 flex flex-col">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-sm font-semibold text-zinc-100">Transaksi Terbaru</h3>
                <a href="#" class="text-[11px] text-indigo-400 hover:text-indigo-300 font-medium">Lihat Semua</a>
            </div>
            <div class="flex-1 overflow-y-auto space-y-4 pr-1">
                @forelse($recentTransactions as $trans)
                <div class="flex items-center justify-between py-2 border-b border-zinc-800/50 last:border-0">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-zinc-800 flex items-center justify-center text-zinc-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-zinc-200">{{ $trans->invoice_number }}</p>
                            <p class="text-[10px] text-zinc-500">{{ $trans->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    <span class="text-xs font-semibold text-zinc-100">{{ format_rupiah($trans->total) }}</span>
                </div>
                @empty
                <p class="text-xs text-zinc-500 text-center py-8">Belum ada transaksi.</p>
                @endforelse
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
    <script type="application/json" id="salesChartSeries">
        @json($salesSeries)
    </script>
    <script type="application/json" id="salesChartCategories">
        @json($salesCategories)
    </script>
    <script>
        const salesSeries = JSON.parse(document.getElementById('salesChartSeries').textContent);
        const salesCategories = JSON.parse(document.getElementById('salesChartCategories').textContent);

        // Chart yang sangat clean, tanpa grid lines yang mengganggu
        var options = {
            series: [{
                name: 'Revenue',
                data: salesSeries
            }],
            chart: {
                type: 'area',
                height: 280,
                background: 'transparent',
                toolbar: {
                    show: false
                },
                fontFamily: 'Inter, sans-serif'
            },
            colors: ['#6366f1'], // Indigo 500
            dataLabels: {
                enabled: false
            },
            stroke: {
                curve: 'smooth',
                width: 2
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.2,
                    opacityTo: 0,
                    stops: [0, 100]
                }
            },
            xaxis: {
                categories: salesCategories,
                labels: {
                    style: {
                        colors: '#71717a',
                        fontSize: '10px'
                    },
                    rotate: -45
                },
                axisBorder: {
                    show: false
                },
                axisTicks: {
                    show: false
                }
            },
            yaxis: {
                labels: {
                    style: {
                        colors: '#71717a',
                        fontSize: '10px'
                    },
                    formatter: (val) => (val / 1000000).toFixed(1) + 'jt'
                }
            },
            grid: {
                borderColor: '#27272a',
                strokeDashArray: 4,
                padding: {
                    top: 0,
                    bottom: 0
                }
            },
            theme: {
                mode: 'dark'
            }
        };
        new ApexCharts(document.querySelector("#salesChart"), options).render();
    </script>
    @endpush
</x-admin-layout>