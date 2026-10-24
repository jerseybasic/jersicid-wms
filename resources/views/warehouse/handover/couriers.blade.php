<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.handover') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Serah Terima</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Nama Ekspedisi</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">Nama jasa kirim dari marketplace dan ekspedisi yang menjemput paketnya</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-sm text-slate-300 space-y-1">
            <p>Paket dikelompokkan per ekspedisi di Serah Terima Kurir. Kalau dua nama sebenarnya diambil kurir yang sama (mis. "Shopee Xpress" dan "SPX Standard"), isi dengan nama ekspedisi yang sama.</p>
            <p class="text-slate-400">Kosongkan untuk kembali ke aturan bawaan.</p>
        </div>

        @if ($names->isEmpty())
            <p class="text-sm text-slate-500 bg-slate-900 border border-slate-800 rounded-2xl p-6 text-center">Belum ada order dengan nama jasa kirim.</p>
        @else
            <form method="POST" action="{{ route('gudang.handover.couriers.save') }}" class="space-y-4">
                @csrf
                <datalist id="brands">
                    @foreach ($brands as $brand)
                        <option value="{{ $brand }}">
                    @endforeach
                </datalist>
                <div class="bg-slate-900 border border-slate-800 rounded-xl divide-y divide-slate-800">
                    @foreach ($names as $i => $row)
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-white break-words">{{ $row['name'] }}</p>
                                <p class="text-xs {{ $row['custom'] ? 'text-cyan-400' : 'text-slate-500' }}">{{ $row['custom'] ? 'Diatur manual' : 'Aturan bawaan' }}</p>
                            </div>
                            <input type="hidden" name="map[{{ $i }}][name]" value="{{ $row['name'] }}">
                            <input type="text" name="map[{{ $i }}][courier]" value="{{ $row['courier'] }}" list="brands" maxlength="50"
                                   class="sm:w-48 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white uppercase"
                                   aria-label="Ekspedisi untuk {{ $row['name'] }}">
                        </div>
                    @endforeach
                </div>
                <button type="submit" class="w-full py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold rounded-xl">Simpan</button>
            </form>
        @endif
    </div>
</x-app-layout>
