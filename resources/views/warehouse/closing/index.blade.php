<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Tutup Kiriman Hari Ini</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">Tutup setelah semua kurir hari ini datang dan serah terimanya selesai</p>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        @if ($closing)
            <div class="p-4 rounded-2xl border border-emerald-500/50 bg-emerald-900/30">
                <p class="text-lg font-bold text-emerald-400">Kiriman hari ini sudah ditutup</p>
                <p class="text-sm text-slate-300">
                    {{ $closing->closed_at->format('H:i') }} oleh {{ $closing->user?->name }} · {{ $closing->shipped_count }} paket diserahkan{{ $closing->pending_count ? " · {$closing->pending_count} order kembali Pending" : '' }}
                </p>
                @if ($shipped->sum() !== $closing->shipped_count)
                    <p class="text-sm text-amber-400 mt-1">Sejak ditutup ada perubahan: sekarang {{ $shipped->sum() }} paket diserahkan. Tutup ulang untuk memperbarui catatan.</p>
                @endif
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-center">
                <p class="text-3xl font-bold text-emerald-400">{{ $shipped->sum() }}</p>
                <p class="text-xs text-slate-400 mt-1">Paket diserahkan hari ini</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-center">
                <p class="text-3xl font-bold {{ $left->sum() ? 'text-amber-400' : 'text-slate-500' }}">{{ $left->sum() }}</p>
                <p class="text-xs text-slate-400 mt-1">Siap kirim, tertinggal untuk besok</p>
            </div>
        </div>

        <section class="bg-slate-900 border border-slate-800 rounded-2xl divide-y divide-slate-800">
            <p class="p-4 text-sm font-semibold text-white">Per kurir</p>
            @forelse ($shipped->keys()->merge($left->keys())->unique()->sort() as $courier)
                <div class="flex items-center gap-3 px-4 py-3 text-sm">
                    <span class="flex-1 font-semibold text-white">{{ $courier }}</span>
                    <span class="text-emerald-400">{{ $shipped[$courier] ?? 0 }} diserahkan</span>
                    @if ($left[$courier] ?? 0)
                        <span class="text-amber-400">{{ $left[$courier] }} tertinggal</span>
                    @endif
                </div>
            @empty
                <p class="px-4 py-3 text-sm text-slate-500">Belum ada paket yang diserahkan hari ini.</p>
            @endforelse
        </section>

        @if ($openHandovers->isNotEmpty())
            <div class="p-4 rounded-2xl border border-red-500/50 bg-red-900/30 space-y-2">
                <p class="font-semibold text-red-400">Selesaikan dulu serah terima ini sebelum menutup:</p>
                @foreach ($openHandovers as $handover)
                    <a href="{{ route('gudang.handover.show', $handover) }}" class="block text-sm text-white underline">
                        {{ $handover->courier }} · {{ $handover->items_count }} paket sudah di-scan
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Order yang belum diproses hari ini: kembali Pending supaya besok dikonfirmasi ulang (stoknya dicek lagi) --}}
        <section class="bg-slate-900 border {{ $unprocessed->isNotEmpty() ? 'border-amber-500/50' : 'border-slate-800' }} rounded-2xl divide-y divide-slate-800">
            <div class="p-4">
                <p class="text-sm font-semibold text-white">Belum diproses: <span class="{{ $unprocessed->isNotEmpty() ? 'text-amber-400' : 'text-slate-500' }}">{{ $unprocessed->count() }} order</span></p>
                <p class="text-xs text-slate-400 mt-1">
                    @if ($unprocessed->isNotEmpty())
                        Saat kiriman ditutup, order ini kembali ke status <span class="font-semibold text-amber-300">Pending</span>. Besok centang di Outbound lalu tekan Picking gabungan (stoknya dicek lagi).
                    @else
                        Semua order yang dikonfirmasi hari ini sudah mulai diproses.
                    @endif
                    @if ($inProcess)
                        {{ $inProcess }} order yang barangnya sudah mulai diambil atau dikemas tidak diubah; selesaikan besok.
                    @endif
                </p>
            </div>
            @foreach ($unprocessed->take(20) as $order)
                <a href="{{ route('gudang.outbound.show', $order->id) }}" class="flex items-center gap-3 px-4 py-2 text-sm hover:bg-slate-800/40">
                    <span class="font-mono text-cyan-400">{{ $order->order_number }}</span>
                    <span class="flex-1 truncate text-slate-300">{{ $order->customer_name }}</span>
                    <span class="text-xs text-slate-400">{{ $order->status_label }}</span>
                </a>
            @endforeach
            @if ($unprocessed->count() > 20)
                <p class="px-4 py-2 text-xs text-slate-500">dan {{ $unprocessed->count() - 20 }} order lainnya</p>
            @endif
        </section>

        <form method="POST" action="{{ route('gudang.closing.store') }}" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 space-y-3"
            @if ($unprocessed->isNotEmpty()) onsubmit="return confirm(@js('Tutup kiriman hari ini? ' . $unprocessed->count() . ' order yang belum diproses akan kembali ke Pending.'))" @endif>
            @csrf
            <label class="block text-sm text-slate-300">Catatan (opsional)
                <input type="text" name="notes" maxlength="500" placeholder="Contoh: JNE tidak datang"
                    class="mt-1 w-full px-4 py-3 bg-slate-800 border border-slate-600 rounded-xl text-white">
            </label>
            <button type="submit" @disabled($openHandovers->isNotEmpty())
                class="w-full py-4 rounded-xl text-base font-bold bg-emerald-500 hover:bg-emerald-400 text-slate-900 disabled:opacity-40">
                {{ $closing ? 'Tutup ulang kiriman hari ini' : 'Tutup kiriman hari ini' }}
            </button>
            <a href="{{ route('gudang.handover') }}" class="block text-center text-sm text-cyan-400">Buka Serah Terima Kurir</a>
        </form>

        @if ($history->isNotEmpty())
            <section class="space-y-3">
                <h3 class="text-lg font-semibold text-white">Riwayat tutup kiriman</h3>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl divide-y divide-slate-800">
                    @foreach ($history as $row)
                        <div class="p-4 text-sm">
                            <p class="font-semibold text-white">{{ $row->closing_date->format('d/m/Y') }} · {{ $row->shipped_count }} paket diserahkan{{ $row->left_count ? ", {$row->left_count} tertinggal" : '' }}{{ $row->pending_count ? ", {$row->pending_count} kembali Pending" : '' }}</p>
                            <p class="text-xs text-slate-400">
                                {{ collect($row->couriers ?? [])->map(fn ($count, $courier) => "$courier $count")->implode(' · ') ?: '-' }}
                                · ditutup {{ $row->closed_at->format('H:i') }} oleh {{ $row->user?->name }}
                            </p>
                            @if ($row->notes)
                                <p class="text-xs text-slate-300 mt-1">{{ $row->notes }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
