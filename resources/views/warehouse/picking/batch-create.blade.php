<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.tugas') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Picking Gabungan</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">Ambil barang untuk beberapa order dalam satu jalan</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4"
         x-data="{ selected: [], max: {{ $max }}, ids: @js($orders->pluck('id')), oldest(n) { this.selected = this.ids.slice(0, n); } }">
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-sm text-slate-300 space-y-1">
            <p>Pilih beberapa order. Daftar ambilnya digabung per SKU dan diurutkan per rak, jadi setiap rak cukup didatangi sekali.</p>
            <p class="text-slate-400">Siapkan keranjang bernomor. Setiap kali barang di-scan, layar menunjukkan nomor keranjangnya. Order yang lengkap langsung bisa dikemas.</p>
        </div>

        @if ($orders->count() < 2)
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-8 text-center text-slate-400">
                {{ $orders->isEmpty() ? 'Tidak ada order yang menunggu picking.' : 'Hanya ada 1 order yang menunggu picking. Ambil dari Tugas Saya.' }}
            </div>
        @else
            <form method="POST" action="{{ route('gudang.picking-batch.store') }}" class="space-y-4">
                @csrf
                <div class="flex flex-wrap gap-2">
                    @foreach ([5, 10, $max] as $n)
                        @if ($n <= $orders->count() || $loop->first)
                            <button type="button" @click="oldest({{ min($n, $orders->count()) }})"
                                    class="px-3 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-sm font-semibold rounded-lg">
                                {{ min($n, $orders->count()) }} terlama
                            </button>
                        @endif
                    @endforeach
                    <button type="button" @click="selected = []" class="px-3 py-2 text-sm text-slate-400 hover:text-white">Kosongkan</button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl divide-y divide-slate-800">
                    @foreach ($orders as $order)
                        <label class="flex items-center gap-3 p-4 cursor-pointer hover:bg-slate-800/40"
                               :class="selected.includes(@js($order->id)) ? 'bg-cyan-900/20' : ''">
                            <input type="checkbox" name="order_ids[]" value="{{ $order->id }}" x-model="selected"
                                   :disabled="!selected.includes(@js($order->id)) && selected.length >= max"
                                   class="w-5 h-5 rounded border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500">
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-white truncate">{{ $order->order_number }}</p>
                                <p class="text-sm text-slate-400 truncate">{{ $order->customer_name }} · {{ $order->courier ?: 'kurir belum diisi' }}</p>
                            </div>
                            <p class="text-right text-sm text-slate-300 shrink-0">{{ $order->items_count }} SKU<br><span class="font-bold text-white">{{ (int) $order->items_sum_qty_ordered }} pcs</span></p>
                        </label>
                    @endforeach
                </div>

                <div class="sticky bottom-0 -mx-4 px-4 py-3 bg-slate-950/95 border-t border-slate-800 flex items-center gap-3">
                    <p class="text-sm text-slate-300 flex-1"><span class="text-xl font-bold text-white" x-text="selected.length"></span> order dipilih <span class="text-slate-500">(maks. {{ $max }})</span></p>
                    <button type="submit" :disabled="selected.length < 2"
                            class="px-5 py-3 bg-cyan-500 hover:bg-cyan-400 disabled:opacity-40 disabled:cursor-not-allowed text-slate-900 font-bold rounded-xl">
                        Mulai picking gabungan
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
