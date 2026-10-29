<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="font-semibold text-xl text-white leading-tight">SKU Terhambat</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">Kode yang belum terdaftar di Master SKU, dan SKU yang belum punya lokasi rak</p>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <a href="#belum-terdaftar" class="bg-slate-900 border {{ $unregistered->isNotEmpty() ? 'border-amber-500/40' : 'border-slate-800' }} rounded-xl p-4">
                <p class="text-2xl font-bold {{ $unregistered->isNotEmpty() ? 'text-amber-300' : 'text-white' }}">{{ $unregistered->count() }}</p>
                <p class="text-sm text-slate-300">Belum terdaftar</p>
                <p class="text-xs text-slate-500">{{ $unregistered->sum('orders_count') }} pesanan tertahan</p>
            </a>
            <a href="#belum-ada-rak" class="bg-slate-900 border {{ $unplaced->isNotEmpty() ? 'border-amber-500/40' : 'border-slate-800' }} rounded-xl p-4">
                <p class="text-2xl font-bold {{ $unplaced->isNotEmpty() ? 'text-amber-300' : 'text-white' }}">{{ $unplaced->count() }}</p>
                <p class="text-sm text-slate-300">Belum punya rak</p>
                <p class="text-xs text-slate-500">{{ $unplaced->where('stock', '>', 0)->count() }} di antaranya sudah ada stok</p>
            </a>
        </div>

        <form method="GET" action="{{ route('blocked-skus.index') }}" class="flex gap-2">
            <label for="q" class="sr-only">Cari</label>
            <input id="q" type="text" name="q" value="{{ $search }}" placeholder="Cari kode atau nama produk..."
                   class="flex-1 min-w-0 bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
            <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Cari</button>
        </form>

        {{-- 1. Belum terdaftar di Master SKU --}}
        <section id="belum-terdaftar" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden scroll-mt-20">
            <div class="p-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Belum terdaftar di Master SKU ({{ $unregistered->count() }})</h3>
                <p class="text-sm text-slate-400 mt-1">Kode dari import pesanan yang tidak dikenali, sehingga pesanannya dilewati. Buat SKU baru jika produknya memang belum ada, atau petakan jika produknya sudah ada dengan kode lain.</p>
            </div>

            @if ($unregistered->isEmpty())
                <p class="p-6 text-center text-sm text-slate-400">{{ $search !== '' ? 'Tidak ada yang cocok.' : 'Tidak ada. Semua kode dari import sudah dikenali.' }}</p>
            @else
                <datalist id="sku-options">
                    @foreach ($skuOptions as $option)
                        <option value="{{ $option->sku }} — {{ $option->product_name }} {{ $option->size }}"></option>
                    @endforeach
                </datalist>
                <ul class="divide-y divide-slate-800">
                    @foreach ($unregistered as $row)
                        <li class="p-4 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] gap-3 lg:items-center">
                            <div class="min-w-0">
                                <p class="font-mono text-amber-300 break-all">{{ $row->code }}</p>
                                @if ($row->product_name)
                                    <p class="text-sm text-slate-300 truncate">{{ $row->product_name }}</p>
                                @endif
                                <p class="text-xs text-slate-500 mt-0.5">
                                    <span class="text-amber-400 font-semibold">{{ $row->orders_count }} pesanan tertahan</span>
                                    · {{ $row->source_label }}{{ $row->source_ref ? ' (' . $row->source_ref . ')' : '' }}
                                    · terakhir {{ $row->last_seen_at?->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                                @if ($canCreateSku)
                                    <a href="{{ route('master-skus.create', ['kode' => $row->code, 'nama' => $row->product_name, 'kembali' => route('blocked-skus.index', [], false)]) }}"
                                       class="shrink-0 text-center px-3 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg">Buat SKU baru</a>
                                @endif
                                <form method="POST" action="{{ route('blocked-skus.map') }}" class="flex gap-2 flex-1 min-w-0">
                                    @csrf
                                    <input type="hidden" name="alias" value="{{ $row->code }}">
                                    <label for="map-{{ $row->id }}" class="sr-only">SKU gudang untuk {{ $row->code }}</label>
                                    <input id="map-{{ $row->id }}" type="text" name="sku" list="sku-options" required autocomplete="off" placeholder="Atau pilih SKU yang sama"
                                           class="flex-1 min-w-0 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
                                    <button type="submit" class="shrink-0 px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Petakan</button>
                                </form>
                                <form method="POST" action="{{ route('blocked-skus.ignore', $row) }}"
                                      data-confirm="Sembunyikan {{ $row->code }}? Pakai ini untuk kode yang bukan barang gudang (mis. ongkir atau bonus)." onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf
                                    <button type="submit" class="w-full sm:w-auto px-3 py-2 text-xs text-slate-400 hover:text-white border border-slate-700 rounded-lg">Abaikan</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @unless ($canCreateSku)
                    <p class="px-4 pb-4 text-xs text-slate-500">Membuat SKU baru hanya bisa oleh Owner dan Supervisor. Admin Gudang bisa memetakan ke SKU yang sudah ada.</p>
                @endunless
            @endif
        </section>

        {{-- 2. Sudah terdaftar tapi belum punya lokasi rak --}}
        <section id="belum-ada-rak" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden scroll-mt-20">
            <div class="p-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Belum punya lokasi rak ({{ $unplaced->count() }})</h3>
                <p class="text-sm text-slate-400 mt-1">SKU aktif yang belum ditetapkan ke rak dan stoknya tidak ada di lokasi rak mana pun, jadi tidak bisa dicari saat picking. Tetapkan raknya di Denah Gudang.</p>
            </div>

            @if ($unplaced->isEmpty())
                <p class="p-6 text-center text-sm text-slate-400">{{ $search !== '' ? 'Tidak ada yang cocok.' : 'Tidak ada. Semua SKU aktif sudah punya rak.' }}</p>
            @else
                <ul class="divide-y divide-slate-800">
                    @foreach ($unplaced->take(200) as $sku)
                        <li class="p-4 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-cyan-400 break-all">{{ $sku->sku }}</p>
                                <p class="text-sm text-slate-300 truncate">{{ $sku->product_name }} {{ $sku->size }}</p>
                            </div>
                            <div class="text-sm sm:text-right shrink-0">
                                @if ($sku->stock > 0)
                                    <p class="text-amber-300 font-semibold">Stok {{ $sku->stock }} di luar rak</p>
                                    <p class="text-xs text-slate-500 font-mono">{{ implode(', ', array_slice($sku->bins, 0, 3)) }}{{ count($sku->bins) > 3 ? ', …' : '' }}</p>
                                @else
                                    <p class="text-slate-500">Belum ada stok</p>
                                @endif
                            </div>
                            <a href="{{ route('gudang.mapping') }}" class="shrink-0 text-center px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Pilih rak</a>
                        </li>
                    @endforeach
                </ul>
                @if ($unplaced->count() > 200)
                    <p class="p-4 text-xs text-slate-500 border-t border-slate-800">Menampilkan 200 dari {{ $unplaced->count() }}. Pakai pencarian untuk menyaring.</p>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
