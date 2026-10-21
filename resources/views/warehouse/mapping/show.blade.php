<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.mapping') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Rak {{ $rack->code }}</h2>
                <p class="text-sm text-slate-400 mt-1">Kelola produk di rak ini</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-6xl mx-auto space-y-6">

        @if (session('success'))
            <div
                class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Rack Info -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div>
                    <p class="text-xs text-slate-400 mb-1">Kode Rak</p>
                    <p class="text-2xl font-bold text-white">{{ $rack->code }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 mb-1">Warna</p>
                    <div class="flex items-center gap-2">
                        <div class="w-4 h-4 rounded" style="background-color: {{ $rack->color }}"></div>
                        <p class="text-lg font-semibold text-white uppercase">{{ $rack->color }}</p>
                    </div>
                </div>
                <div>
                    <p class="text-xs text-slate-400 mb-1">Jumlah Baris</p>
                    <p class="text-2xl font-bold text-white">{{ $rack->rows_count }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 mb-1">Produk Ter-mapping</p>
                    <p class="text-2xl font-bold text-cyan-400">{{ $totalSku ?? 0 }}</p>
                </div>
            </div>
        </div>

        <!-- Keranjang Produk (Shopping Cart) -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl" x-data="assignProductForm(@js($allSkus))">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    Keranjang Produk
                </h3>
                <div class="flex items-center gap-3">
                    <span
                        class="px-3 py-1 bg-cyan-500/20 text-cyan-400 text-sm font-semibold rounded-lg border border-cyan-500/30">
                        <span x-text="cart.length"></span> produk dipilih
                    </span>
                    <button @click="clearCart" x-show="cart.length > 0"
                        class="px-3 py-1 bg-red-500/20 text-red-400 text-sm font-semibold rounded-lg border border-red-500/30 hover:bg-red-500/30 transition-colors">
                        Kosongkan
                    </button>
                </div>
            </div>

            <!-- Search & Add Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="md:col-span-2 relative">
                    <label class="block text-sm font-medium text-slate-300 mb-2">
                        Cari Produk (SKU) <span class="text-red-400">*</span>
                    </label>

                    <div class="relative">
                        <input type="text" x-model="searchQuery" @input="onSearchInput" @focus="showDropdown = true"
                            @click.away="showDropdown = false" @keydown.enter.prevent="selectHighlighted"
                            @keydown.arrow-down.prevent="highlightNext" @keydown.arrow-up.prevent="highlightPrevious"
                            @keydown.escape="showDropdown = false"
                            placeholder="Ketik SKU atau nama produk untuk mencari..."
                            class="w-full px-4 py-3 bg-slate-800 border-2 border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all">

                        <button x-show="searchQuery" @click="clearSearch" type="button"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Dropdown Results -->
                    <div x-show="showDropdown && filteredSkus.length > 0"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute z-50 w-full mt-2 bg-slate-800 border border-slate-700 rounded-xl shadow-2xl overflow-hidden max-h-80 overflow-y-auto custom-scrollbar"
                        style="display: none;">
                        <div class="px-4 py-2 bg-slate-900/50 border-b border-slate-700">
                            <p class="text-xs text-slate-400">
                                Ditemukan <span class="text-cyan-400 font-semibold" x-text="filteredSkus.length"></span>
                                produk
                            </p>
                        </div>

                        <template x-for="(sku, index) in filteredSkus" :key="sku.sku">
                            <button type="button" @click="addToCart(sku)"
                                class="w-full px-4 py-3 text-left hover:bg-cyan-500/10 transition-colors border-b border-slate-700/50 last:border-0 flex items-center gap-3"
                                :class="highlightedIndex === index ? 'bg-cyan-500/10' : ''">
                                <div class="flex-shrink-0">
                                    <span
                                        class="px-2 py-1 bg-cyan-500/20 text-cyan-400 text-xs font-mono font-bold rounded-lg border border-cyan-500/30"
                                        x-text="sku.sku"></span>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-white font-medium truncate" x-text="sku.product_name"></p>
                                    <p class="text-xs text-slate-400">
                                        <span x-text="sku.article"></span> •
                                        <span x-text="sku.size"></span> •
                                        <span x-text="sku.size_category"></span>
                                    </p>
                                </div>

                                <div class="flex-shrink-0 text-right">
                                    <p class="text-xs text-slate-400">Stok:</p>
                                    <p class="text-sm font-bold text-emerald-400" x-text="sku.current_stock"></p>
                                </div>

                                <div class="flex-shrink-0">
                                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                </div>
                            </button>
                        </template>
                    </div>

                    <!-- No Results -->
                    <div x-show="showDropdown && searchQuery && filteredSkus.length === 0"
                        class="absolute z-50 w-full mt-2 bg-slate-800 border border-slate-700 rounded-xl shadow-2xl p-6 text-center"
                        style="display: none;">
                        <svg class="w-12 h-12 text-slate-600 mx-auto mb-2" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-sm text-slate-400">Tidak ada produk ditemukan</p>
                        <p class="text-xs text-slate-500 mt-1">Coba kata kunci lain</p>
                    </div>
                </div>

                <!-- Default Capacity -->
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Kapasitas Default</label>
                    <input type="number" x-model="defaultCapacity" min="1"
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                    <p class="text-xs text-slate-500 mt-1">Akan digunakan untuk semua produk di keranjang</p>
                </div>
            </div>

            <!-- Cart Items -->
            <div x-show="cart.length > 0" x-transition class="border-t border-slate-800 pt-4 mt-4">
                <h4 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Produk di Keranjang
                </h4>

                <div class="space-y-2 max-h-64 overflow-y-auto custom-scrollbar">
                    <template x-for="(item, index) in cart" :key="item.sku">
                        <div
                            class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg border border-slate-700/50">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <div
                                    class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <span class="text-xs font-bold text-cyan-400" x-text="index + 1"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-mono font-bold text-white truncate" x-text="item.sku"></p>
                                    <p class="text-xs text-slate-400 truncate" x-text="item.product_name"></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="text-right">
                                    <p class="text-xs text-slate-400">Stok total</p>
                                    <p class="w-20 px-2 py-1 text-emerald-400 text-sm font-semibold" x-text="item.current_stock"></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-slate-400">Kapasitas</p>
                                    <input type="number" x-model="item.max_capacity" min="1"
                                        class="w-20 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-white text-sm text-right focus:border-cyan-400 focus:outline-none">
                                </div>
                                <button type="button" @click="removeFromCart(index)"
                                    class="p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-colors"
                                    title="Hapus dari keranjang">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Submit Button -->
                <form method="POST" action="{{ route('gudang.mapping.assign', $rack->code) }}" class="mt-4">
                    @csrf
                    <template x-for="(item, index) in cart" :key="'hidden-' + index">
                        <div>
                            <input type="hidden" :name="'products[' + index + '][sku]'" :value="item.sku">
                            <input type="hidden" :name="'products[' + index + '][max_capacity]'"
                                :value="item.max_capacity">
                        </div>
                    </template>
                    <button type="submit"
                        class="w-full px-6 py-4 border-2 border-slate-700 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/25 transition-all flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M5 13l4 4L19 7" />
                        </svg>
                        Simpan <span x-text="cart.length"></span> Produk ke Rak
                    </button>
                </form>
            </div>

            <!-- Empty Cart Message -->
            <div x-show="cart.length === 0" class="border-t border-slate-800 pt-4 mt-4 text-center py-8">
                <svg class="w-16 h-16 text-slate-600 mx-auto mb-3" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <p class="text-sm text-slate-400 font-medium">Keranjang kosong</p>
                <p class="text-xs text-slate-500 mt-1">Cari dan klik produk untuk menambahkan ke keranjang</p>
            </div>
        </div>

        <!-- Produk di Rak (Grouped by Product Name + Version) -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    Produk di Rak {{ $rack->code }}
                </h3>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-400">{{ $totalSku ?? 0 }} SKU</span>
                    <span class="text-xs text-emerald-400 font-semibold">{{ number_format($totalQty ?? 0) }}
                        unit</span>
                </div>
            </div>

            @if (isset($groupedProducts) && $groupedProducts->count() > 0)
                <div class="space-y-2">
                    @foreach ($groupedProducts as $groupKey => $products)
                        @php
                            $parts = explode('|', $groupKey);
                            $productName = $parts[0];
                            $version = $parts[1] ?? '';
                            $firstProduct = $products->first();
                            $totalQtyInGroup = $products->sum('current_qty');
                            $sizeCount = $products->count();
                        @endphp

                        <div x-data="{ expanded: false, skus: @js($products->values()) }" class="border border-slate-800 rounded-xl overflow-hidden">
                            <!-- Product Header (Klik untuk expand) -->
                            <div @click="expanded = !expanded"
                                class="flex items-center gap-3 p-3 bg-slate-800/30 hover:bg-slate-800/50 transition-colors cursor-pointer group">

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
                                    <p class="text-sm font-semibold text-white truncate">{{ $productName }}</p>
                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                        <span
                                            class="text-[10px] px-1.5 py-0.5 bg-slate-800 text-slate-300 rounded">{{ $firstProduct->size_category }}</span>
                                        @if ($version)
                                            <span class="text-[10px] text-cyan-400">{{ $version }}</span>
                                        @endif
                                        @if ($firstProduct->sub_version)
                                            <span
                                                class="text-[10px] text-purple-400">{{ $firstProduct->sub_version }}</span>
                                        @endif
                                        <span class="text-[10px] text-slate-500">{{ $sizeCount }} size</span>
                                    </div>
                                </div>

                                <!-- Total Qty di Rak -->
                                <div class="text-right flex-shrink-0">
                                    <p class="text-sm font-bold text-emerald-400">
                                        {{ number_format($totalQtyInGroup) }}</p>
                                    <p class="text-[9px] text-slate-500">total di rak</p>
                                </div>
                            </div>

                            <!-- Expanded SKU List -->
                            <div x-show="expanded" x-transition class="bg-slate-950/50 border-t border-slate-800">
                                <div x-show="skus.length > 0" class="p-2 space-y-1">
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
                                                    <p class="text-[10px] font-mono text-slate-400 truncate"
                                                        x-text="sku.bin_location ? 'Bin: ' + sku.bin_location : 'Belum ada stok di rak ini'"></p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <div class="text-right">
                                                    <p class="text-xs font-bold text-emerald-400"
                                                        x-text="sku.current_qty"></p>
                                                    <p class="text-[9px] text-slate-500" x-show="sku.max_capacity">Max: <span
                                                            x-text="sku.max_capacity"></span></p>
                                                </div>
                                                <form :action="'/gudang/mapping/{{ $rack->code }}/' + sku.sku"
                                                    method="POST"
                                                    onsubmit="return confirm('Yakin hapus produk dari rak ini?')"
                                                    class="inline">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                        class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors"
                                                        title="Hapus">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="skus.length === 0" class="p-4 text-center">
                                    <p class="text-xs text-slate-500">Tidak ada SKU untuk produk ini</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-12">
                    <svg class="w-16 h-16 text-slate-600 mx-auto mb-3" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                    </svg>
                    <p class="text-sm text-slate-400 font-medium">Belum ada produk di rak ini</p>
                    <p class="text-xs text-slate-500 mt-1">Stok muncul di sini setelah barang masuk ke bin rak ini (contoh bin: {{ $rack->code }}-01-01)</p>
                </div>
            @endif
        </div>
    </div>

    <script>
        function assignProductForm(allSkus) {
            return {
                allSkus: allSkus,
                searchQuery: '',
                filteredSkus: [],
                showDropdown: false,
                highlightedIndex: -1,
                cart: [],
                defaultCapacity: 100,

                onSearchInput() {
                    if (!this.searchQuery) {
                        this.filteredSkus = [];
                        this.showDropdown = false;
                        return;
                    }

                    const query = this.searchQuery.toLowerCase().trim();

                    this.filteredSkus = this.allSkus.filter(sku => {
                        const alreadyInCart = this.cart.some(item => item.sku === sku.sku);
                        if (alreadyInCart) return false;

                        const skuMatch = sku.sku.toLowerCase().includes(query);
                        const nameMatch = sku.product_name.toLowerCase().includes(query);
                        const articleMatch = sku.article.toLowerCase().includes(query);
                        return skuMatch || nameMatch || articleMatch;
                    });

                    this.showDropdown = this.filteredSkus.length > 0;
                    this.highlightedIndex = -1;
                },

                addToCart(sku) {
                    const exists = this.cart.some(item => item.sku === sku.sku);
                    if (exists) {
                        alert('Produk sudah ada di keranjang!');
                        return;
                    }

                    this.cart.push({
                        sku: sku.sku,
                        product_name: sku.product_name,
                        article: sku.article,
                        size: sku.size,
                        current_stock: sku.current_stock,
                        max_capacity: this.defaultCapacity
                    });

                    this.searchQuery = '';
                    this.filteredSkus = [];
                    this.showDropdown = false;
                    this.highlightedIndex = -1;
                },

                removeFromCart(index) {
                    this.cart.splice(index, 1);
                },

                clearCart() {
                    if (confirm('Yakin kosongkan keranjang?')) {
                        this.cart = [];
                    }
                },

                clearSearch() {
                    this.searchQuery = '';
                    this.filteredSkus = [];
                    this.showDropdown = false;
                },

                highlightNext() {
                    if (this.highlightedIndex < this.filteredSkus.length - 1) {
                        this.highlightedIndex++;
                    }
                },

                highlightPrevious() {
                    if (this.highlightedIndex > 0) {
                        this.highlightedIndex--;
                    }
                },

                selectHighlighted() {
                    if (this.highlightedIndex >= 0 && this.highlightedIndex < this.filteredSkus.length) {
                        this.addToCart(this.filteredSkus[this.highlightedIndex]);
                    }
                }
            }
        }
    </script>
</x-app-layout>
