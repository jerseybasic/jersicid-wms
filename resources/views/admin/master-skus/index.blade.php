<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Master SKU') }}</h2>
                <p class="text-sm text-slate-400 mt-1">Kelola data produk dan stok</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('master-skus.export') }}"
                    class="px-3 py-2 bg-emerald-600 hover:bg-emerald-500 text-on-accent text-xs font-semibold rounded-lg transition-all flex items-center gap-1.5 shadow-lg shadow-emerald-500/25">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Export
                </a>
                <button onclick="document.getElementById('importModal').classList.remove('hidden')"
                    class="px-3 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-xs font-semibold rounded-lg transition-all flex items-center gap-1.5 shadow-lg shadow-cyan-500/25">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Import
                </button>
                <a href="{{ route('master-skus.create') }}"
                    class="px-3 py-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-lg shadow-lg shadow-cyan-500/25 transition-all text-xs flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Tambah SKU
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4" x-data="masterSkuApp()">

        @if (session('success'))
            <div
                class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg flex items-start gap-2 text-sm">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="whitespace-pre-line">{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div
                class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg flex items-start gap-2 text-sm">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="whitespace-pre-line">{{ session('error') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 rounded-lg">
                <ul class="text-sm text-red-400 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>• {{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <p class="text-xs text-slate-400 mb-0.5">Total SKU</p>
                <p class="text-xl font-bold text-white">{{ number_format($stats['total']) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-emerald-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="text-xs text-emerald-400 mb-0.5">SKU Aktif</p>
                <p class="text-xl font-bold text-emerald-400">{{ number_format($stats['aktif']) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-slate-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="text-xs text-slate-400 mb-0.5">SKU Nonaktif</p>
                <p class="text-xl font-bold text-slate-400">{{ number_format($stats['nonaktif']) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <div class="w-8 h-8 bg-red-500/20 rounded-lg flex items-center justify-center mb-2">
                    <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p class="text-xs text-red-400 mb-0.5">Low Stock Alert</p>
                <p class="text-xl font-bold text-red-400">{{ number_format($stats['low_stock']) }}</p>
            </div>
        </div>

        @if ($recentImports->isNotEmpty())
            <!-- Riwayat Import -->
            <div class="bg-slate-900 border border-slate-800 rounded-lg">
                <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-2 flex-wrap">
                    <h3 class="text-sm font-semibold text-white">Import terakhir</h3>
                    <span class="text-xs text-slate-500">Salah import? Batalkan untuk menghapus SKU baru dan mengembalikan stoknya.</span>
                </div>
                <div class="divide-y divide-slate-800">
                    @foreach ($recentImports as $import)
                        @php $v = $import->new_values ?? []; @endphp
                        <div class="px-4 py-2.5 flex items-center gap-3 flex-wrap">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold text-white truncate">{{ $v['file'] ?? 'Import' }}</p>
                                <p class="text-[11px] text-slate-500">
                                    {{ $import->created_at->format('d M Y H:i') }} · {{ $import->user?->name ?? '-' }} · <span class="font-mono">{{ $v['reference'] ?? '' }}</span>
                                </p>
                                <p class="text-[11px] text-slate-400">
                                    {{ count($v['new'] ?? []) }} SKU baru · {{ count($v['stock_up'] ?? []) }} stok naik · {{ count($v['stock_down'] ?? []) }} stok turun · {{ count($v['changed'] ?? []) }} data berubah
                                </p>
                            </div>
                            @if ($import->undone)
                                <span class="text-[11px] px-2 py-1 rounded-full bg-slate-700/60 text-slate-300">Sudah dibatalkan</span>
                            @else
                                <a href="{{ route('master-skus.import.undo', $import) }}"
                                    class="px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 border border-red-500/40 text-red-300 text-xs font-semibold rounded-lg">Batalkan import</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Filter & Search -->
        <div class="bg-slate-900 border border-slate-800 rounded-lg p-3">
            <form method="GET" action="{{ route('master-skus.index') }}" class="flex flex-wrap gap-2">
                <div class="relative flex-1 min-w-[200px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}"
                        placeholder="Cari produk, artikel, atau version..."
                        class="w-full pl-9 pr-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all" />
                </div>
                <select name="status"
                    class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Status</option>
                    <option value="AKTIF" {{ request('status') == 'AKTIF' ? 'selected' : '' }}>Aktif</option>
                    <option value="NONAKTIF" {{ request('status') == 'NONAKTIF' ? 'selected' : '' }}>Nonaktif</option>
                </select>
                <select name="size_category"
                    class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Kategori</option>
                    <option value="DEWASA" {{ request('size_category') == 'DEWASA' ? 'selected' : '' }}>Dewasa
                    </option>
                    <option value="KIDS" {{ request('size_category') == 'KIDS' ? 'selected' : '' }}>Kids</option>
                    <option value="TEENS" {{ request('size_category') == 'TEENS' ? 'selected' : '' }}>Teens</option>
                </select>
                <button type="submit"
                    class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent font-semibold rounded-lg transition-all text-sm">Filter</button>
                <a href="{{ route('master-skus.index') }}"
                    class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-lg border border-slate-700 transition-all text-sm">Reset</a>
            </form>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">

            <!-- Daftar SKU (Grouped by Product Name + Version) -->
            <div class="xl:col-span-2 bg-slate-900 border border-slate-800 rounded-lg shadow-xl overflow-hidden">
                <div class="p-3 border-b border-slate-800 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-white">Daftar Produk</h3>
                    <span class="text-xs text-slate-400">{{ $articles->total() }} produk</span>
                </div>

                <div class="divide-y divide-slate-800">
                    @forelse($articles as $product)
                        <div x-data="{ expanded: false, skus: [], loading: false }" class="border-b border-slate-800 last:border-0">
                            <!-- Row Utama (Klik untuk expand) -->
                            <div @click="toggleProduct('{{ addslashes($product->product_name) }}', '{{ addslashes($product->version ?? '') }}', $data)"
                                class="flex items-center gap-3 p-3 hover:bg-slate-800/30 transition-colors cursor-pointer group">

                                <!-- Expand Icon -->
                                <svg class="w-4 h-4 text-slate-500 group-hover:text-cyan-400 transition-all flex-shrink-0"
                                    :class="expanded ? 'rotate-90' : ''" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7" />
                                </svg>

                                <!-- Product Icon -->
                                <div
                                    class="w-10 h-10 bg-gradient-to-br from-cyan-500/20 to-blue-600/20 rounded-lg flex items-center justify-center flex-shrink-0 border border-cyan-500/30">
                                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                </div>

                                <!-- Product Info -->
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-white truncate">{{ $product->product_name }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                        <span
                                            class="text-[10px] px-1.5 py-0.5 bg-slate-800 text-slate-300 rounded">{{ $product->size_category }}</span>
                                        @if ($product->version)
                                            <span class="text-[10px] text-cyan-400">{{ $product->version }}</span>
                                        @endif
                                        @if ($product->sub_version)
                                            <span
                                                class="text-[10px] text-purple-400">{{ $product->sub_version }}</span>
                                        @endif
                                        <span class="text-[10px] text-slate-500">{{ $product->size_count }}
                                            size</span>
                                    </div>
                                </div>

                                <!-- Total Stock -->
                                <div class="text-right flex-shrink-0">
                                    <p
                                        class="text-sm font-bold {{ $product->total_stock <= $product->min_stock ? 'text-red-400' : 'text-emerald-400' }}">
                                        {{ number_format($product->total_stock) }}
                                    </p>
                                    <p class="text-[9px] text-slate-500">Min: {{ $product->min_stock }}</p>
                                </div>
                            </div>

                            <!-- Expanded SKU Details -->
                            <div x-show="expanded" x-transition class="bg-slate-950/50 border-t border-slate-800">
                                <div x-show="loading" class="p-4 text-center">
                                    <div
                                        class="inline-block w-6 h-6 border-2 border-cyan-500/30 border-t-cyan-500 rounded-full animate-spin">
                                    </div>
                                    <p class="text-xs text-slate-400 mt-2">Memuat detail size...</p>
                                </div>

                                <div x-show="!loading && skus.length > 0" class="p-2 space-y-1">
                                    <div class="flex justify-end px-1">
                                        <button type="button" @click="deleteSkus(skus.map(s => s.sku), '{{ addslashes($product->product_name) }} (' + skus.length + ' size)')"
                                            class="text-[11px] text-red-400 hover:text-red-300">Hapus semua size produk ini</button>
                                    </div>
                                    <template x-for="sku in skus" :key="sku.sku">
                                        <div
                                            class="flex items-center justify-between p-2 bg-slate-900/50 rounded-lg border border-slate-800/50 hover:border-cyan-500/30 transition-all">
                                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                                <span
                                                    class="w-8 h-8 bg-cyan-500/10 text-cyan-400 text-[10px] font-bold rounded flex items-center justify-center flex-shrink-0 font-mono"
                                                    x-text="sku.size"></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-xs font-mono font-semibold text-cyan-400 truncate"
                                                        x-text="sku.sku"></p>
                                                    <p class="text-[10px] text-slate-500 truncate">
                                                        <span x-text="sku.article"></span> •
                                                        <span x-text="sku.version"></span>
                                                        <span x-show="sku.sub_version"> • <span
                                                                x-text="sku.sub_version"></span></span>
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <div class="text-right">
                                                    <p class="text-xs font-bold"
                                                        :class="sku.current_stock <= sku.min_stock ? 'text-red-400' :
                                                            'text-emerald-400'"
                                                        x-text="sku.current_stock"></p>
                                                    <p class="text-[9px] text-slate-500">Min: <span
                                                            x-text="sku.min_stock"></span></p>
                                                </div>
                                                <span class="text-[10px] px-1.5 py-0.5 rounded-full"
                                                    :class="sku.status === 'AKTIF' ? 'bg-emerald-500/20 text-emerald-400' :
                                                        'bg-slate-500/20 text-slate-400'"
                                                    x-text="sku.status"></span>
                                                <button type="button" @click="deleteSkus([sku.sku], sku.sku)" title="Hapus SKU"
                                                    class="p-1 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                                <a :href="'/master-skus/' + sku.id + '/edit'"
                                                    class="p-1 text-slate-400 hover:text-cyan-400 hover:bg-slate-800 rounded transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                        viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="!loading && skus.length === 0" class="p-4 text-center">
                                    <p class="text-xs text-slate-500">Tidak ada SKU untuk produk ini</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-12 text-slate-500">
                            <div
                                class="w-12 h-12 bg-slate-800/50 rounded-xl flex items-center justify-center mx-auto mb-2">
                                <svg class="w-6 h-6 text-slate-600" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                            <p class="text-xs">Belum ada data produk</p>
                        </div>
                    @endforelse
                </div>

                @if ($articles->hasPages())
                    <div class="p-3 border-t border-slate-800 bg-slate-800/20">
                        {{ $articles->links() }}
                    </div>
                @endif
            </div>

            <!-- Side Panels -->
            <div class="space-y-4">
                <!-- Top 10 Stok Terbanyak -->
                <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                        Top 10 Stok Terbanyak
                    </h3>
                    <div class="space-y-1.5">
                        @forelse($topProducts as $index => $product)
                            <div class="flex items-center justify-between p-2 bg-slate-800/50 rounded-lg">
                                <div class="flex items-center gap-2 min-w-0 flex-1">
                                    <span
                                        class="w-5 h-5 bg-cyan-500/20 text-cyan-400 text-[10px] font-bold rounded flex items-center justify-center flex-shrink-0">{{ $index + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[10px] font-semibold text-white truncate">
                                            {{ $product->product_name }}</p>
                                        <p class="text-[9px] text-slate-500 truncate">{{ $product->version }}</p>
                                    </div>
                                </div>
                                <span
                                    class="text-[11px] font-bold text-emerald-400 flex-shrink-0 ml-2">{{ number_format($product->total_stock) }}</span>
                            </div>
                        @empty
                            <p class="text-xs text-slate-500 text-center py-3">Belum ada data</p>
                        @endforelse
                    </div>
                </div>

                <!-- Stok Menipis -->
                <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            Stok Menipis
                        </h3>
                    </div>
                    <div class="space-y-1.5">
                        @forelse($lowStockProducts->take(5) as $product)
                            <div
                                class="flex items-center justify-between p-2 bg-red-900/20 border border-red-500/30 rounded-lg">
                                <div class="min-w-0 flex-1">
                                    <p class="text-[10px] font-semibold text-white truncate">
                                        {{ $product->product_name }}</p>
                                    <p class="text-[9px] text-slate-500 truncate">{{ $product->version }}</p>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <p class="text-[11px] font-bold text-red-400">
                                        {{ number_format($product->total_stock) }}</p>
                                    <p class="text-[9px] text-slate-500">Min: {{ $product->min_stock }}</p>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-3">
                                <svg class="w-8 h-8 text-emerald-400 mx-auto mb-1" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-[10px] text-emerald-400">Semua stok aman!</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl p-6 max-w-md w-full shadow-2xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-white">Import Master SKU</h3>
                <button onclick="document.getElementById('importModal').classList.add('hidden')"
                    class="text-slate-400 hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mb-4 p-3 bg-emerald-900/20 border border-emerald-500/30 rounded-lg">
                <p class="text-xs text-emerald-400 font-semibold mb-1">✓ Dicek dulu sebelum disimpan</p>
                <ul class="text-xs text-slate-300 space-y-1">
                    <li>• Muncul pratinjau: SKU baru, stok bertambah, stok berkurang, dan data yang berubah</li>
                    <li>• Stok disamakan dengan kolom CURRENT_STOCK / Total Stok Saat Ini, selisihnya tercatat di Pergerakan Stok</li>
                    <li>• Bisa pakai file Export di atas atau sheet sendiri dengan judul kolom bahasa Indonesia</li>
                    <li>• Data lama tidak dihapus. Kalau salah import, pakai <b>Batalkan import</b> di daftar Import terakhir</li>
                </ul>
            </div>
            <form method="POST" action="{{ route('master-skus.import') }}" enctype="multipart/form-data"
                class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Pilih File Excel</label>
                    <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-cyan-600 file:text-white hover:file:bg-cyan-500" />
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('importModal').classList.add('hidden')"
                        class="flex-1 px-4 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">Cek perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <form id="deleteSkusForm" method="POST" action="{{ route('master-skus.bulk-destroy') }}" class="hidden">
        @csrf
    </form>

    <script>
        function masterSkuApp() {
            return {
                deleteSkus(skus, label) {
                    if (!confirm(`Hapus ${label}?\n\nSKU yang sudah dipakai di order, PO, retur, atau pergerakan stok tidak akan dihapus. Stok SKU yang dihapus dinolkan dan tercatat di Pergerakan Stok.`)) {
                        return;
                    }
                    const form = document.getElementById('deleteSkusForm');
                    form.querySelectorAll('input[name="skus[]"]').forEach(el => el.remove());
                    skus.forEach(sku => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'skus[]';
                        input.value = sku;
                        form.appendChild(input);
                    });
                    form.submit();
                },

                async toggleProduct(productName, version, component) {
                    component.expanded = !component.expanded;

                    if (component.expanded && component.skus.length === 0) {
                        component.loading = true;
                        try {
                            const url =
                                `/master-skus/article/${encodeURIComponent(productName)}/${encodeURIComponent(version)}/skus`;
                            const response = await fetch(url);
                            const data = await response.json();
                            component.skus = data;
                        } catch (error) {
                            console.error('Error loading SKUs:', error);
                        } finally {
                            component.loading = false;
                        }
                    }
                }
            }
        }
    </script>
</x-app-layout>
