<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.handover') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight truncate">Serah Terima {{ $handover->courier }}</h2>
                <p class="text-xs sm:text-sm text-slate-400 mt-0.5 font-mono truncate">{{ $handover->handover_number }}</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-3xl font-bold text-cyan-400" data-scan-qty="count">{{ $scannedIds->count() }}</p>
                <p class="text-xs text-slate-400">Paket di-scan</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-3xl font-bold text-white">{{ $orders->count() }}</p>
                <p class="text-xs text-slate-400">Paket {{ $handover->courier }} siap kirim</p>
            </div>
        </div>

        <x-scan-box :endpoint="route('gudang.handover.scan', $handover)" :with-bin="false"
            hint="Scan resi di label setiap paket yang diserahkan ke kurir." />

        <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <h3 class="px-4 py-3 border-b border-slate-800 text-base font-semibold text-white">Paket {{ $handover->courier }}</h3>
            @if ($orders->isEmpty())
                <p class="p-6 text-center text-sm text-slate-500">Tidak ada paket Siap Kirim untuk kurir ini.</p>
            @else
                <div class="divide-y divide-slate-800">
                    @foreach ($orders as $order)
                        @php $scanned = isset($scannedIds[$order->id]); @endphp
                        <div class="p-4 flex items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-white break-all">{{ $order->tracking_number ?: 'Belum ada resi' }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ $order->order_number }} · {{ $order->customer_name }} · {{ (int) $order->items_sum_qty_ordered }} pcs</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-semibold {{ $scanned ? 'text-emerald-400' : 'text-amber-400' }}" data-scan-qty="row-{{ $order->id }}">{{ $scanned ? 'Sudah di-scan' : 'Belum di-scan' }}</p>
                                @if ($scanned)
                                    <form method="POST" action="{{ route('gudang.handover.remove', [$handover, $order]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-slate-400 hover:text-red-400 underline">keluarkan</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <form method="POST" action="{{ route('gudang.handover.finish', $handover) }}" class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3"
              onsubmit="return confirm('Semua paket yang sudah di-scan akan berstatus Dikirim. Lanjutkan?')">
            @csrf
            <div>
                <label for="driver_name" class="block text-xs font-medium text-slate-400 mb-1">Nama kurir yang menerima (opsional)</label>
                <input id="driver_name" type="text" name="driver_name" maxlength="100" placeholder="Contoh: Pak Andi SPX"
                       class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
            </div>
            <button type="submit" class="w-full py-3 bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold rounded-xl">Serahkan dan cetak manifest</button>
            <p class="text-xs text-slate-500">Paket yang belum di-scan tetap Siap Kirim dan bisa diserahkan di serah terima berikutnya.</p>
        </form>

        <form method="POST" action="{{ route('gudang.handover.destroy', $handover) }}" class="text-center"
              onsubmit="return confirm('Batalkan serah terima ini? Status paket tidak berubah.')">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm text-red-400 hover:text-red-300 underline">Batalkan serah terima</button>
        </form>
    </div>
</x-app-layout>
