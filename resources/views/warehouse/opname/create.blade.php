<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.opname') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Buat Stock Opname Baru') }}</h2>
                <p class="text-sm text-slate-400 mt-1">Pilih rak yang mau dihitung, atau kosongkan untuk seluruh gudang</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto">
        @if (session('error'))
            <div class="mb-4 p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ $errors->first() }}</div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form method="POST" action="{{ route('gudang.opname.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Pilih Gudang <span
                            class="text-red-400">*</span></label>
                    <select name="warehouse_id" required
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                        <option value="">-- Pilih Gudang --</option>
                        @foreach($warehouses as $wh)
                            <option value="{{ $wh->id }}" @selected(old('warehouse_id', $warehouses->count() === 1 ? $wh->id : null) == $wh->id)>{{ $wh->name }} ({{ $wh->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <p class="block text-sm font-medium text-slate-300 mb-1">Rak yang dihitung</p>
                    <p class="text-xs text-slate-500 mb-3">Opname per rak lebih cepat dan gudang tetap bisa jalan di rak lain. Tidak memilih rak = seluruh gudang.</p>
                    @if ($racks->isEmpty())
                        <p class="text-sm text-slate-500">Belum ada rak atau stok.</p>
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                            @foreach ($racks as $rack)
                                <label class="flex flex-col items-center justify-center gap-1 p-3 rounded-xl border cursor-pointer select-none
                                    {{ $rack['busy'] ? 'border-slate-800 bg-slate-900 opacity-50 cursor-not-allowed' : 'border-slate-700 bg-slate-800 has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/10' }}">
                                    <input type="checkbox" name="racks[]" value="{{ $rack['code'] }}" class="sr-only"
                                        @checked(in_array($rack['code'], old('racks', []))) @disabled($rack['busy'])>
                                    <span class="text-lg font-bold text-white">Rak {{ $rack['code'] }}</span>
                                    <span class="text-xs {{ $rack['busy'] ? 'text-amber-400' : 'text-slate-400' }}">
                                        {{ $rack['busy'] ? 'sedang diopname' : $rack['bins'] . ' lokasi berisi' }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Catatan / Periode</label>
                    <textarea name="notes" rows="2" placeholder="Contoh: Cek rutin rak A minggu ini"
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">{{ old('notes') }}</textarea>
                </div>

                <div class="pt-4 flex items-center gap-3">
                    <a href="{{ route('gudang.opname') }}"
                        class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                    <button type="submit"
                        class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Buat Daftar Hitung
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>