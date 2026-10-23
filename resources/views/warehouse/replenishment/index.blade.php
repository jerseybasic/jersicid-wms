<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Isi Ulang Rak</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">SKU yang stok di raknya menipis, sementara cadangannya ada di bin lain</p>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-slate-400 mr-1">Tampilkan rak yang terisi</span>
            @foreach (\App\Http\Controllers\Warehouse\ReplenishmentController::LEVELS as $option)
                <a href="{{ route('gudang.replenishment', ['batas' => $option]) }}"
                    class="px-3 py-2 rounded-lg text-sm font-semibold {{ $level === $option ? 'bg-cyan-500 text-slate-900' : 'bg-slate-800 text-slate-300' }}">≤ {{ $option }}%</a>
            @endforeach
        </div>

        <section class="space-y-3">
            <h3 class="text-lg font-semibold text-white">Perlu diisi ulang <span class="text-cyan-400">({{ $refill->count() }})</span></h3>
            @forelse ($refill as $row)
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="font-mono font-semibold text-cyan-400">{{ $row->sku }}</p>
                        <p class="text-xs text-slate-400 truncate">{{ $row->name }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <div class="flex-1 h-2 rounded-full bg-slate-800 overflow-hidden">
                                <div class="h-full {{ $row->percent <= 25 ? 'bg-red-500' : 'bg-amber-400' }}" style="width: {{ min(100, $row->percent) }}%"></div>
                            </div>
                            <span class="text-xs text-slate-300 whitespace-nowrap">Rak {{ $row->rack }}: {{ $row->in_rack }}/{{ $row->capacity }}</span>
                        </div>
                    </div>
                    <div class="sm:text-right text-sm">
                        <p class="text-slate-300">
                            Ambil <span class="font-bold text-white">{{ $row->suggest }} pcs</span>
                            dari <span class="font-mono text-amber-400">{{ $row->source->bin }}</span>
                            ke <span class="font-mono text-emerald-400">{{ $row->target }}</span>
                        </p>
                        <p class="text-xs text-slate-500">cadangan di {{ $row->source->bin }}: {{ $row->source->qty }} pcs</p>
                    </div>
                    <a href="{{ route('gudang.transfer', ['dari' => $row->source->bin, 'sku' => $row->sku, 'jumlah' => $row->suggest, 'ke' => $row->target]) }}"
                        class="shrink-0 px-4 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold rounded-xl text-center">Pindahkan</a>
                </div>
            @empty
                <p class="text-sm text-slate-500 bg-slate-900 border border-slate-800 rounded-2xl p-6 text-center">
                    Semua rak masih terisi di atas {{ $level }}% atau tidak ada cadangan di bin lain.
                </p>
            @endforelse
        </section>

        @if ($empty->isNotEmpty())
            <section class="space-y-3">
                <h3 class="text-lg font-semibold text-white">Habis di semua lokasi <span class="text-red-400">({{ $empty->count() }})</span></h3>
                <p class="text-xs text-slate-400">Tidak ada stok sama sekali, jadi perlu pembelian atau produksi ulang.</p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl divide-y divide-slate-800">
                    @foreach ($empty as $row)
                        <div class="flex items-center gap-3 px-4 py-3 text-sm">
                            <span class="font-mono text-white flex-1 truncate">{{ $row->sku }}</span>
                            <span class="text-xs text-slate-400">rak {{ $row->rack }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($over->isNotEmpty())
            <section class="space-y-3">
                <h3 class="text-lg font-semibold text-white">Melebihi kapasitas <span class="text-amber-400">({{ $over->count() }})</span></h3>
                <p class="text-xs text-slate-400">Isi rak lebih banyak dari kapasitas yang ditetapkan. Pindahkan kelebihannya, atau perbarui kapasitas di Denah Gudang.</p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl divide-y divide-slate-800">
                    @foreach ($over as $row)
                        <a href="{{ route('gudang.mapping.show', $row->rack) }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-slate-800/40">
                            <span class="font-mono text-white flex-1 truncate">{{ $row->sku }}</span>
                            <span class="text-amber-400">{{ $row->in_rack }}/{{ $row->capacity }}</span>
                            <span class="text-xs text-slate-400">rak {{ $row->rack }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-xs text-slate-500">Hanya SKU yang sudah ditetapkan ke rak beserta kapasitasnya di Denah Gudang yang dihitung di sini.</p>
    </div>
</x-app-layout>
