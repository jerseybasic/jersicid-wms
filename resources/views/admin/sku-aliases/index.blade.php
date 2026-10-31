<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-semibold text-xl text-white leading-tight">Pemetaan SKU</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">SKU di marketplace yang berbeda dengan kode Master SKU</p>
            </div>
            <a href="{{ route('blocked-skus.index') }}" class="shrink-0 px-3 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-lg border border-slate-700">
                SKU Terhambat @if ($blockedCount)<span class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-amber-500 text-slate-900">{{ $blockedCount }}</span>@endif
            </a>
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

        <form method="POST" action="{{ route('sku-aliases.store') }}" class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-5 space-y-3">
            @csrf
            <div>
                <h3 class="text-base font-semibold text-white">Tambah pemetaan</h3>
                <p class="text-sm text-slate-400 mt-1">Saat import pesanan BigSeller, SKU marketplace di kiri dibaca sebagai SKU gudang di kanan. Pemetaan juga bisa dibuat langsung dari pratinjau import.</p>
            </div>
            <datalist id="sku-options">
                @foreach ($skuOptions as $option)
                    <option value="{{ $option->sku }} — {{ $option->product_name }} {{ $option->size }}"></option>
                @endforeach
            </datalist>
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 sm:items-end">
                <div>
                    <label for="alias" class="block text-xs font-medium text-slate-400 mb-1">SKU marketplace</label>
                    <input id="alias" type="text" name="alias" value="{{ old('alias') }}" required maxlength="100" placeholder="Contoh: GOTHIC-BLK-M"
                           class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white font-mono placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
                </div>
                <div>
                    <label for="sku" class="block text-xs font-medium text-slate-400 mb-1">SKU gudang</label>
                    <input id="sku" type="text" name="sku" value="{{ old('sku') }}" required list="sku-options" autocomplete="off" placeholder="Ketik kode atau nama produk"
                           class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
                </div>
                <button type="submit" class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">Simpan</button>
            </div>
            <p class="text-xs text-slate-500">Jika SKU marketplace sudah dipetakan, pemetaannya diganti.</p>
        </form>

        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <form method="GET" action="{{ route('sku-aliases.index') }}" class="p-4 border-b border-slate-800 flex gap-2">
                <label for="q" class="sr-only">Cari</label>
                <input id="q" type="text" name="q" value="{{ $search }}" placeholder="Cari SKU marketplace atau SKU gudang..."
                       class="flex-1 min-w-0 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
                <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Cari</button>
            </form>

            @if ($aliases->isEmpty())
                <p class="p-6 text-center text-sm text-slate-400">
                    {{ $search !== '' ? 'Tidak ada pemetaan yang cocok.' : 'Belum ada pemetaan. SKU marketplace yang sama persis dengan Master SKU tidak perlu dipetakan.' }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                            <tr>
                                <th class="py-3 px-4">SKU marketplace</th>
                                <th class="py-3 px-4">SKU gudang</th>
                                <th class="py-3 px-4 hidden md:table-cell">Dibuat</th>
                                <th class="py-3 px-4"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($aliases as $alias)
                                <tr class="align-top">
                                    <td class="py-3 px-4 font-mono text-amber-300 break-all">{{ $alias->alias }}</td>
                                    <td class="py-3 px-4">
                                        <p class="font-mono text-cyan-400 break-all">{{ $alias->sku }}</p>
                                        @if ($alias->masterSku)
                                            <p class="text-xs text-slate-500">{{ $alias->masterSku->product_name }} {{ $alias->masterSku->size }}</p>
                                        @else
                                            <p class="text-xs text-red-400">SKU gudang sudah tidak ada di Master SKU</p>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 text-xs text-slate-500 hidden md:table-cell whitespace-nowrap">
                                        {{ $alias->created_at?->format('d/m/Y') }}{{ $alias->creator ? ' · ' . $alias->creator->name : '' }}
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" action="{{ route('sku-aliases.destroy', $alias) }}"
                                              data-confirm="Hapus pemetaan {{ $alias->alias }}?" onsubmit="return confirm(this.dataset.confirm)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-red-400 hover:text-white hover:bg-red-600 border border-red-500/40 rounded-lg transition-all">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($aliases->hasPages())
                    <div class="p-4 border-t border-slate-800">{{ $aliases->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
