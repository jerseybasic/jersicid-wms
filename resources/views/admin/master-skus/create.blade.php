<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('master-skus.index') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Tambah SKU Baru</h2>
                <p class="text-sm text-slate-400 mt-1">Masukkan data produk baru</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form method="POST" action="{{ route('master-skus.store') }}" class="space-y-4">
                @csrf

                @if($errors->any())
                    <div class="p-4 bg-red-900/30 border border-red-500/50 rounded-xl">
                        <ul class="text-sm text-red-400 space-y-1">
                            @foreach($errors->all() as $error)
                                <li>• {{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">SKU <span class="text-red-400">*</span></label>
                        <input type="text" name="sku" value="{{ old('sku') }}" required placeholder="JER-BASIC-L"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all font-mono"/>
                        <p class="text-xs text-slate-500 mt-1">Format: JER-[ARTIKEL]-[SIZE]</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Artikel <span class="text-red-400">*</span></label>
                        <input type="text" name="article" value="{{ old('article') }}" required placeholder="BASIC"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Nama Produk <span class="text-red-400">*</span></label>
                        <input type="text" name="product_name" value="{{ old('product_name') }}" required placeholder="Jersey Basic Premium"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Versi</label>
                        <input type="text" name="version" value="{{ old('version', '1.0') }}" placeholder="1.0"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Sub Versi</label>
                        <input type="text" name="sub_version" value="{{ old('sub_version') }}" placeholder="A (opsional)"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Kategori Size <span class="text-red-400">*</span></label>
                        <select name="size_category" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="">Pilih Kategori</option>
                            <option value="DEWASA" {{ old('size_category') == 'DEWASA' ? 'selected' : '' }}>DEWASA</option>
                            <option value="KIDS" {{ old('size_category') == 'KIDS' ? 'selected' : '' }}>KIDS</option>
                            <option value="TEENS" {{ old('size_category') == 'TEENS' ? 'selected' : '' }}>TEENS</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Size <span class="text-red-400">*</span></label>
                        <input type="text" name="size" value="{{ old('size') }}" required placeholder="L"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Size Token</label>
                        <input type="text" name="size_token" value="{{ old('size_token') }}" placeholder="L (opsional)"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Harga Jual (Rp) <span class="text-red-400">*</span></label>
                        <input type="number" name="price" value="{{ old('price', 0) }}" required min="0"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Harga Modal / HPP (Rp)</label>
                        <input type="number" name="cost_price" value="{{ old('cost_price', 0) }}" min="0" placeholder="Opsional"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all" />
                        <p class="text-xs text-slate-500 mt-1">Harga beli per pcs. Dipakai untuk nilai modal stok dan default harga di PO.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Berat Standar (gram)</label>
                        <input type="number" name="standard_weight_gram" value="{{ old('standard_weight_gram', 250) }}" min="0"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Min. Stok <span class="text-red-400">*</span></label>
                        <input type="number" name="min_stock" value="{{ old('min_stock', 0) }}" required min="0"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Status <span class="text-red-400">*</span></label>
                        <select name="status" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="AKTIF" {{ old('status', 'AKTIF') == 'AKTIF' ? 'selected' : '' }}>AKTIF</option>
                            <option value="NONAKTIF" {{ old('status') == 'NONAKTIF' ? 'selected' : '' }}>NONAKTIF</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-4 border-t border-slate-800">
                    <a href="{{ route('master-skus.index') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                    <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        ✨ Simpan SKU
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>