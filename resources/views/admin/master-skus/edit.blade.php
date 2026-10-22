<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('master-skus.index') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Edit SKU: {{ $sku->sku }}</h2>
                <p class="text-sm text-slate-400 mt-1">Perbarui informasi produk</p>
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
            <div class="p-4 bg-red-900/30 border border-red-500/50 rounded-xl">
                <ul class="text-sm text-red-400 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>• {{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Stock Info Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs text-slate-400 mb-1">Stok Saat Ini</p>
                <p class="text-2xl font-bold text-emerald-400">{{ number_format($currentStock) }}</p>
                <p class="text-xs text-slate-500 mt-1">Unit</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs text-slate-400 mb-1">Min. Stock</p>
                <p class="text-2xl font-bold text-white">{{ number_format($sku->min_stock) }}</p>
                <p class="text-xs text-slate-500 mt-1">Alert threshold</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5">
                <p class="text-xs text-slate-400 mb-1">Status</p>
                <p class="text-2xl font-bold {{ $sku->status === 'AKTIF' ? 'text-emerald-400' : 'text-slate-400' }}">
                    {{ $sku->status }}</p>
                <p class="text-xs text-slate-500 mt-1">
                    {{ $sku->status === 'AKTIF' ? 'Produk aktif' : 'Produk nonaktif' }}</p>
            </div>
        </div>

        <!-- Form Edit -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Informasi Produk
            </h3>

            <form method="POST" action="{{ route('master-skus.update', $sku->id) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">SKU <span
                                class="text-red-400">*</span></label>
                        <input type="text" name="sku" value="{{ old('sku', $sku->sku) }}" required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white font-mono focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Article <span
                                class="text-red-400">*</span></label>
                        <input type="text" name="article" value="{{ old('article', $sku->article) }}" required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Nama Produk <span
                                class="text-red-400">*</span></label>
                        <input type="text" name="product_name" value="{{ old('product_name', $sku->product_name) }}"
                            required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Version <span
                                class="text-red-400">*</span></label>
                        <input type="text" name="version" value="{{ old('version', $sku->version) }}" required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Sub Version</label>
                        <input type="text" name="sub_version" value="{{ old('sub_version', $sku->sub_version) }}"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Kategori Size <span
                                class="text-red-400">*</span></label>
                        <select name="size_category" required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="DEWASA"
                                {{ old('size_category', $sku->size_category) == 'DEWASA' ? 'selected' : '' }}>Dewasa
                            </option>
                            <option value="KIDS"
                                {{ old('size_category', $sku->size_category) == 'KIDS' ? 'selected' : '' }}>Kids
                            </option>
                            <option value="TEENS"
                                {{ old('size_category', $sku->size_category) == 'TEENS' ? 'selected' : '' }}>Teens
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Size <span
                                class="text-red-400">*</span></label>
                        <input type="text" name="size" value="{{ old('size', $sku->size) }}" required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Size Token</label>
                        <input type="text" name="size_token" value="{{ old('size_token', $sku->size_token) }}"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Harga Jual (Rp) <span
                                class="text-red-400">*</span></label>
                        <input type="number" name="price" value="{{ old('price', $sku->price) }}" required
                            min="0"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Harga Modal / HPP (Rp)</label>
                        <input type="number" name="cost_price" value="{{ old('cost_price', $sku->cost_price) }}" min="0" placeholder="Opsional"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all" />
                        <p class="text-xs text-slate-500 mt-1">Harga beli per pcs. Dipakai untuk nilai modal stok dan default harga di PO.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Berat Standar (gram) <span
                                class="text-red-400">*</span></label>
                        <input type="number" name="standard_weight_gram"
                            value="{{ old('standard_weight_gram', $sku->standard_weight_gram) }}" required
                            min="0"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Min. Stock <span
                                class="text-red-400">*</span></label>
                        <input type="number" name="min_stock" value="{{ old('min_stock', $sku->min_stock) }}"
                            required min="0"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Status <span
                                class="text-red-400">*</span></label>
                        <select name="status" required
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="AKTIF" {{ old('status', $sku->status) == 'AKTIF' ? 'selected' : '' }}>
                                Aktif</option>
                            <option value="NONAKTIF"
                                {{ old('status', $sku->status) == 'NONAKTIF' ? 'selected' : '' }}>Nonaktif</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-4 border-t border-slate-800">
                    <a href="{{ route('master-skus.index') }}"
                        class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                    <button type="submit"
                        class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

        <!-- Related Variants -->
        @if (isset($relatedVariants) && $relatedVariants->count() > 0)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    Varian Terkait (Article: {{ $sku->article }})
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach ($relatedVariants as $variant)
                        <a href="{{ route('master-skus.edit', $variant->id) }}"
                            class="p-3 bg-slate-800/50 border border-slate-700 rounded-xl hover:border-cyan-500/50 transition-all group">
                            <p class="text-xs font-mono font-bold text-cyan-400 group-hover:text-cyan-300">
                                {{ $variant->sku }}</p>
                            <p class="text-[10px] text-slate-400 mt-1">Size: {{ $variant->size }}</p>
                            <p class="text-[10px] text-slate-500">{{ $variant->size_category }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Stock Locations -->
        @if (isset($stockLocations) && $stockLocations->count() > 0)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Lokasi Stok ({{ $stockLocations->count() }} lokasi)
                </h3>
                <div class="space-y-2">
                    @foreach ($stockLocations as $location)
                        <div
                            class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg border border-slate-700/50">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-cyan-500/20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-mono font-semibold text-white">{{ $location->bin_code }}
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        @if ($location->batch_no)
                                            Batch: {{ $location->batch_no }} •
                                        @endif
                                        Terakhir gerak:
                                        {{ $location->last_movement_at?->diffForHumans() ?? 'Belum ada' }}
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-emerald-400">{{ number_format($location->qty) }}</p>
                                <p class="text-xs text-slate-500">unit</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
