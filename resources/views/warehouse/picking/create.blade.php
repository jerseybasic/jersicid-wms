<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.picking') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Buat Picking Session Baru</h2>
                <p class="text-sm text-slate-400 mt-1">Pilih order yang akan dipicking</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            @if($availableOrders->count() === 0)
                <div class="text-center py-12">
                    <svg class="w-16 h-16 text-slate-700 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p class="text-lg text-slate-400 font-medium">Tidak ada order yang siap dipicking</p>
                    <p class="text-sm text-slate-500 mt-2">Order harus berstatus CONFIRMED untuk bisa dipicking</p>
                </div>
            @else
                <form method="POST" action="{{ route('gudang.picking.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Pilih Order <span class="text-red-400">*</span></label>
                        <select name="order_id" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="">-- Pilih Order --</option>
                            @foreach($availableOrders as $order)
                                <option value="{{ $order->id }}">
                                    {{ $order->order_number }} - {{ $order->customer_name }} ({{ $order->items->count() }} items)
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Catatan</label>
                        <textarea name="notes" rows="3" placeholder="Catatan untuk picker (opsional)"
                                  class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"></textarea>
                    </div>

                    <div class="flex items-center gap-3 pt-4 border-t border-slate-800">
                        <a href="{{ route('gudang.picking') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                        <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-400 hover:to-purple-500 text-on-accent font-bold rounded-xl shadow-lg shadow-purple-500/25 transition-all">
                            Buat Picking Session
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>