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

        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl text-sm">{{ $errors->first() }}</div>
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
                                    <p class="text-[10px] text-amber-400" x-show="sku.unplaced_stock > 0" x-text="sku.unplaced_stock + ' belum di rak'"></p>
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
                        @if (in_array(auth()->user()->role, \App\Http\Controllers\Admin\BlockedSkuController::CREATE_SKU_ROLES, true))
                            <p class="text-xs text-slate-500 mt-1">Produknya belum terdaftar?</p>
                            <a :href="@js(route('master-skus.create')) + '?' + new URLSearchParams({ kode: searchQuery.trim(), kembali: @js(route('gudang.mapping.show', $rack->code, false)) })"
                               class="inline-block mt-3 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg">
                                Buat SKU baru "<span x-text="searchQuery.trim().toUpperCase()"></span>"
                            </a>
                        @else
                            <p class="text-xs text-slate-500 mt-1">Coba kata kunci lain. Jika produknya belum terdaftar, minta Owner/Supervisor membuat SKU baru.</p>
                        @endif
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
                                    <p class="text-[10px] text-amber-400" x-show="item.unplaced_stock > 0" x-text="item.unplaced_stock + ' belum di rak'"></p>
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
                    <div class="mb-4 p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl space-y-3"
                        x-show="cart.some(item => item.unplaced_stock > 0)">
                        <label class="flex items-start gap-3 text-sm text-slate-200">
                            <input type="checkbox" name="move_unplaced" value="1" checked
                                class="mt-0.5 rounded border-slate-600 bg-slate-800 text-amber-500 focus:ring-amber-500">
                            <span>Pindahkan stok yang belum punya rak ke rak ini
                                (<span x-text="cart.reduce((n, item) => n + (item.unplaced_stock || 0), 0)"></span> pcs).
                                <span class="block text-xs text-slate-400">Stok ini tercatat di lokasi seperti DEFAULT-01 atau GUDANG-UTAMA, jadi belum muncul di denah.</span></span>
                        </label>
                        <div class="flex items-center gap-2">
                            <label for="bin_code" class="text-xs text-slate-400 shrink-0">Ke lokasi</label>
                            <input id="bin_code" type="text" name="bin_code" value="{{ old('bin_code', $rack->code . '-01-01') }}"
                                class="w-36 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-white text-sm font-mono uppercase focus:border-cyan-400 focus:outline-none">
                        </div>
                    </div>
                    <button type="submit"
                        class="w-full px-6 py-4 border-2 border-slate-700 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-on-accent font-bold rounded-xl shadow-lg shadow-emerald-500/25 transition-all flex items-center justify-center gap-2">
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
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl" x-data="rackEditor(@js($rackProducts->values()))">
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
                {{-- Pilih SKU yang salah lalu edit atau hapus; atau sekaligus semua --}}
                <div class="flex flex-wrap items-center gap-2 mb-3 p-3 bg-slate-800/40 border border-slate-700/50 rounded-xl">
                    <label class="flex items-center gap-2 text-sm text-slate-300 mr-auto">
                        <input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)"
                            class="rounded border-slate-600 bg-slate-900 text-cyan-500">
                        Pilih semua
                        <span class="text-cyan-400 font-semibold" x-show="selected.length" x-text="'(' + selected.length + ' dipilih)'"></span>
                    </label>
                    <button type="button" @click="openEdit(selected)" :disabled="!selected.length"
                        class="px-3 py-2 text-sm font-semibold rounded-lg bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 disabled:opacity-40">Edit terpilih</button>
                    <button type="button" @click="remove(selected)" :disabled="!selected.length"
                        class="px-3 py-2 text-sm font-semibold rounded-lg bg-red-500/20 text-red-300 border border-red-500/30 disabled:opacity-40">Hapus terpilih</button>
                    <span class="hidden sm:block w-px h-6 bg-slate-700"></span>
                    <button type="button" @click="openEdit(allSkus)"
                        class="px-3 py-2 text-sm font-semibold rounded-lg bg-slate-700 text-white">Edit semua</button>
                    <button type="button" @click="remove(allSkus)"
                        class="px-3 py-2 text-sm font-semibold rounded-lg bg-slate-700 text-red-300">Hapus semua</button>
                </div>

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

                                <input type="checkbox" @click.stop :checked="groupSelected(skus)" @change="toggleGroup(skus, $event.target.checked)"
                                    class="rounded border-slate-600 bg-slate-900 text-cyan-500 flex-shrink-0" title="Pilih semua size">

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
                                                <input type="checkbox" :value="sku.sku" x-model="selected"
                                                    class="rounded border-slate-600 bg-slate-900 text-cyan-500 flex-shrink-0">
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
                                                <button type="button" @click="openEdit([sku.sku])"
                                                    class="p-1.5 text-slate-400 hover:text-cyan-400 hover:bg-cyan-500/10 rounded transition-colors"
                                                    title="Edit">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                    </svg>
                                                </button>
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

                {{-- Hapus terpilih/semua: lepas SKU dari rak, stok di bin tidak berubah --}}
                <form x-ref="deleteForm" method="POST" action="{{ route('gudang.mapping.bulk-delete', $rack->code) }}" class="hidden">
                    @csrf
                    <template x-for="code in deleting" :key="code">
                        <input type="hidden" name="skus[]" :value="code">
                    </template>
                </form>

                {{-- Edit terpilih/semua --}}
                <div x-show="editing.length" x-cloak class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4" @keydown.escape.window="editing = []">
                    <form method="POST" action="{{ route('gudang.mapping.bulk-update', $rack->code) }}" @click.outside="editing = []"
                        class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-3xl max-h-[90vh] flex flex-col">
                        @csrf
                        <div class="p-5 border-b border-slate-800">
                            <h3 class="text-lg font-semibold text-white">Edit <span x-text="editing.length"></span> SKU di rak {{ $rack->code }}</h3>
                            <p class="text-xs text-slate-400 mt-1">Kosongkan kolom yang tidak ingin diubah. Lokasi bin baru memindahkan semua stok SKU itu dari rak ini ke bin tersebut (tercatat sebagai transfer).</p>
                            <div class="mt-3 flex flex-wrap items-end gap-2">
                                <label class="text-xs text-slate-400">Kapasitas untuk semua
                                    <input type="number" min="1" x-model="fillCapacity" class="mt-1 block w-28 px-2 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                                </label>
                                <label class="text-xs text-slate-400">Lokasi bin untuk semua
                                    <input type="text" x-model="fillBin" placeholder="{{ $rack->code }}-01-01" class="mt-1 block w-36 px-2 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm font-mono uppercase">
                                </label>
                                <button type="button" @click="applyFill()" class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Terapkan ke semua baris</button>
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto divide-y divide-slate-800">
                            <template x-for="(row, index) in editing" :key="row.sku">
                                <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                                    <input type="hidden" :name="'items[' + index + '][sku]'" :value="row.sku">
                                    <div class="flex-1 min-w-[10rem]">
                                        <p class="text-sm font-mono font-semibold text-cyan-400" x-text="row.sku"></p>
                                        <p class="text-xs text-slate-400" x-text="row.current_qty + ' pcs · ' + (row.bin_location ? 'Bin ' + row.bin_location : 'belum ada stok')"></p>
                                    </div>
                                    <label class="text-xs text-slate-400">Kapasitas
                                        <input type="number" min="1" :name="'items[' + index + '][max_capacity]'" x-model="row.max_capacity"
                                            class="mt-1 block w-24 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm text-right">
                                    </label>
                                    <label class="text-xs text-slate-400">Pindah ke bin
                                        <input type="text" :name="'items[' + index + '][bin_code]'" x-model="row.bin_code" :placeholder="row.bin_location || '{{ $rack->code }}-01-01'"
                                            class="mt-1 block w-32 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm font-mono uppercase">
                                    </label>
                                </div>
                            </template>
                        </div>
                        <div class="p-4 border-t border-slate-800 flex justify-end gap-2">
                            <button type="button" @click="editing = []" class="px-4 py-2 bg-slate-700 text-white rounded-lg">Batal</button>
                            <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold rounded-lg">Simpan perubahan</button>
                        </div>
                    </form>
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
        function rackEditor(products) {
            return {
                products: products,
                allSkus: products.map((p) => p.sku),
                selected: [],
                editing: [],
                deleting: [],
                fillCapacity: '',
                fillBin: '',

                get allSelected() {
                    return this.selected.length > 0 && this.selected.length === this.allSkus.length;
                },

                toggleAll(on) {
                    this.selected = on ? [...this.allSkus] : [];
                },

                groupSelected(skus) {
                    return skus.every((s) => this.selected.includes(s.sku));
                },

                toggleGroup(skus, on) {
                    const codes = skus.map((s) => s.sku);
                    this.selected = on
                        ? [...new Set([...this.selected, ...codes])]
                        : this.selected.filter((code) => !codes.includes(code));
                },

                openEdit(codes) {
                    this.fillCapacity = '';
                    this.fillBin = '';
                    this.editing = this.products
                        .filter((p) => codes.includes(p.sku))
                        .map((p) => ({ ...p, max_capacity: p.max_capacity || '', bin_code: '' }));
                },

                applyFill() {
                    this.editing.forEach((row) => {
                        if (this.fillCapacity) row.max_capacity = this.fillCapacity;
                        if (this.fillBin) row.bin_code = this.fillBin.toUpperCase();
                    });
                },

                remove(codes) {
                    if (!codes.length) return;
                    const label = codes.length === this.allSkus.length ? 'SEMUA ' + codes.length : codes.length;
                    if (!confirm('Lepas ' + label + ' SKU dari rak ini? Stok di bin tidak berubah.')) return;
                    this.deleting = [...codes];
                    this.$nextTick(() => this.$refs.deleteForm.submit());
                },
            };
        }

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

                    // Tetap terbuka walau kosong, supaya saran "Buat SKU baru" terlihat
                    this.showDropdown = true;
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
                        unplaced_stock: sku.unplaced_stock,
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
