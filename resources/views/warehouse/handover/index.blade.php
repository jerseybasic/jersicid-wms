<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Serah Terima Kurir</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">Scan resi setiap paket yang diserahkan, lalu cetak manifest untuk ditandatangani kurir</p>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">
        <a href="{{ route('gudang.closing') }}" class="flex items-center justify-between bg-slate-900 border border-slate-800 rounded-2xl p-4 hover:bg-slate-800/60">
            <span class="text-sm text-slate-300">Semua kurir hari ini sudah datang?</span>
            <span class="text-sm font-bold text-emerald-400">Tutup kiriman hari ini</span>
        </a>
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        @if ($open->isNotEmpty())
            <section class="space-y-3">
                <h3 class="text-lg font-semibold text-white">Sedang berjalan</h3>
                @foreach ($open as $handover)
                    <a href="{{ route('gudang.handover.show', $handover) }}" class="flex items-center gap-4 bg-slate-900 border border-cyan-500/40 rounded-2xl p-4 hover:bg-slate-800/60">
                        <div class="flex-1 min-w-0">
                            <p class="text-lg font-bold text-white">{{ $handover->courier }}</p>
                            <p class="text-xs text-slate-400 font-mono">{{ $handover->handover_number }} · {{ $handover->user?->name }}</p>
                        </div>
                        <p class="text-right"><span class="text-2xl font-bold text-cyan-400">{{ $handover->items_count }}</span><br><span class="text-xs text-slate-400">paket di-scan</span></p>
                    </a>
                @endforeach
            </section>
        @endif

        <section class="space-y-3">
            <h3 class="text-lg font-semibold text-white">Mulai serah terima</h3>
            @forelse ($couriers as $courier => $count)
                <form method="POST" action="{{ route('gudang.handover.store') }}" class="flex items-center gap-4 bg-slate-900 border border-slate-800 rounded-2xl p-4">
                    @csrf
                    <input type="hidden" name="courier" value="{{ $courier }}">
                    <div class="flex-1 min-w-0">
                        <p class="text-lg font-bold text-white">{{ $courier }}</p>
                        <p class="text-sm text-slate-400">{{ $count }} paket siap kirim</p>
                    </div>
                    <button type="submit" class="shrink-0 px-5 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold rounded-xl">Mulai scan</button>
                </form>
            @empty
                <p class="text-sm text-slate-500 bg-slate-900 border border-slate-800 rounded-2xl p-6 text-center">Belum ada paket Siap Kirim yang menunggu kurir.</p>
            @endforelse
        </section>

        <section class="space-y-3">
            <h3 class="text-lg font-semibold text-white">Riwayat</h3>
            @if ($history->isEmpty())
                <p class="text-sm text-slate-500">Belum ada serah terima.</p>
            @else
                <div class="bg-slate-900 border border-slate-800 rounded-2xl divide-y divide-slate-800">
                    @foreach ($history as $handover)
                        <a href="{{ route('gudang.handover.print', $handover) }}" class="flex items-center gap-3 p-4 hover:bg-slate-800/40">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-white">{{ $handover->courier }} · {{ $handover->items_count }} paket</p>
                                <p class="text-xs text-slate-400 truncate">
                                    {{ $handover->completed_at?->format('d/m/Y H:i') }} · {{ $handover->user?->name }}{{ $handover->driver_name ? ' → ' . $handover->driver_name : '' }}
                                </p>
                            </div>
                            <span class="text-xs text-cyan-400 shrink-0">Manifest</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
