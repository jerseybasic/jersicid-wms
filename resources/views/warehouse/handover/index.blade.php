<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Serah Terima Kurir</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">Paket hasil packing per ekspedisi: mana yang belum di-scan, sudah di-scan, dan sudah diserahkan</p>
        </div>
    </x-slot>

    @php
        $stateLabel = ['waiting' => 'Belum di-scan', 'scanned' => 'Di-scan', 'shipped' => 'Diserahkan'];
        $stateClass = ['waiting' => 'text-amber-400', 'scanned' => 'text-cyan-400', 'shipped' => 'text-emerald-400'];
        $isToday = $date->isToday();
    @endphp

    <div class="max-w-3xl mx-auto space-y-6">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        {{-- Scan resi apa pun: paket otomatis masuk ke daftar ekspedisinya --}}
        <x-scan-box :endpoint="route('gudang.handover.scan-any')" storage-key="handover" :with-bin="false"
            hint="Scan resi setiap paket yang diserahkan. Paket otomatis masuk ke daftar ekspedisinya." />

        <form method="GET" action="{{ route('gudang.handover') }}" class="flex flex-wrap items-center gap-2 text-sm">
            <label for="tanggal" class="text-slate-400">Paket diserahkan pada</label>
            <input id="tanggal" type="date" name="tanggal" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}"
                   class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white" onchange="this.form.submit()">
            @unless ($isToday)
                <a href="{{ route('gudang.handover') }}" class="text-cyan-400 hover:text-cyan-300">Hari ini</a>
            @endunless
        </form>

        @if ($noResi->isNotEmpty())
            <section class="bg-slate-900 border border-amber-500/40 rounded-2xl p-4 space-y-2">
                <p class="text-sm font-semibold text-amber-300">{{ $noResi->count() }} paket siap kirim belum punya resi, jadi belum bisa di-scan</p>
                <p class="text-xs text-slate-400">Cek resinya di BigSeller (atau cetak ulang dari Shopee/TikTok), lalu isi di detail order.</p>
                <div class="divide-y divide-slate-800">
                    @foreach ($noResi as $order)
                        <div class="py-2 flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate text-white">{{ $order->order_number }} <span class="text-slate-400">· {{ $order->courier ?: 'ekspedisi kosong' }}</span></span>
                            <a href="{{ route('gudang.outbound.edit', $order->id) }}" class="shrink-0 font-semibold text-amber-400 hover:text-amber-300">Isi resi</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-white">Per ekspedisi</h3>
                @if ($canMap)
                    <a href="{{ route('gudang.handover.couriers') }}" class="text-sm text-cyan-400 hover:text-cyan-300">Atur nama ekspedisi</a>
                @endif
            </div>

            @forelse ($groups as $group)
                @php $c = $group['counts']; @endphp
                <div class="bg-slate-900 border {{ $c['waiting'] + $c['scanned'] ? 'border-slate-700' : 'border-slate-800' }} rounded-2xl p-4 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-lg font-bold text-white">{{ $group['courier'] }}</p>
                            @if ($group['open'])
                                <p class="text-xs text-slate-400 font-mono">{{ $group['open']->handover_number }} · {{ $group['open']->user?->name }}</p>
                            @endif
                        </div>
                        @if ($c['waiting'] + $c['scanned'])
                            <form method="POST" action="{{ route('gudang.handover.store') }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="courier" value="{{ $group['courier'] }}">
                                <button type="submit" class="px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-slate-900 text-sm font-bold rounded-xl">
                                    Serahkan
                                </button>
                            </form>
                        @endif
                    </div>

                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="bg-slate-800/60 rounded-xl py-2">
                            <p class="text-2xl font-bold text-amber-400" data-scan-qty="waiting-{{ $group['key'] }}">{{ $c['waiting'] }}</p>
                            <p class="text-[11px] text-slate-400">Belum di-scan</p>
                        </div>
                        <div class="bg-slate-800/60 rounded-xl py-2">
                            <p class="text-2xl font-bold text-cyan-400" data-scan-qty="scanned-{{ $group['key'] }}">{{ $c['scanned'] }}</p>
                            <p class="text-[11px] text-slate-400">Di-scan, belum diserahkan</p>
                        </div>
                        <div class="bg-slate-800/60 rounded-xl py-2">
                            <p class="text-2xl font-bold text-emerald-400">{{ $c['shipped'] }}</p>
                            <p class="text-[11px] text-slate-400">Diserahkan {{ $isToday ? 'hari ini' : $date->translatedFormat('d M') }}</p>
                        </div>
                    </div>

                    <details>
                        <summary class="cursor-pointer select-none text-sm text-cyan-400">Lihat {{ $group['orders']->count() }} paket</summary>
                        <div class="mt-2 divide-y divide-slate-800">
                            @foreach ($group['orders'] as ['order' => $order, 'state' => $state])
                                <div class="py-2 flex items-center gap-3">
                                    <div class="flex-1 min-w-0">
                                        <p class="font-mono text-sm font-bold text-white break-all">{{ $order->tracking_number }}</p>
                                        <p class="text-xs text-slate-400 truncate">
                                            <a href="{{ route('gudang.outbound.show', $order->id) }}" class="hover:underline">{{ $order->order_number }}</a>
                                            · {{ $order->customer_name }} · {{ (int) $order->items_sum_qty_ordered }} pcs
                                            @if ($state === 'waiting' && $order->packed_at && $order->packed_at->lt(today()))
                                                · <span class="text-red-400">dikemas {{ $order->packed_at->translatedFormat('d M') }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="shrink-0 text-right text-xs">
                                        <p class="font-semibold {{ $stateClass[$state] }}" data-scan-qty="pkg-{{ $order->id }}">{{ $stateLabel[$state] }}</p>
                                        @if ($state === 'shipped')
                                            <p class="text-slate-400">
                                                {{ $order->shipped_at?->format('H:i') }}
                                                @if ($order->handoverItem?->handover)
                                                    · <a href="{{ route('gudang.handover.print', $order->handoverItem->handover) }}" class="text-cyan-400 hover:underline">manifest</a>
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                </div>
            @empty
                <p class="text-sm text-slate-500 bg-slate-900 border border-slate-800 rounded-2xl p-6 text-center">
                    Belum ada paket Siap Kirim{{ $isToday ? '' : ' atau paket yang diserahkan pada tanggal ini' }}.
                </p>
            @endforelse
        </section>

        <a href="{{ route('gudang.closing') }}" class="flex items-center justify-between bg-slate-900 border border-slate-800 rounded-2xl p-4 hover:bg-slate-800/60">
            <span class="text-sm text-slate-300">Semua kurir hari ini sudah datang?</span>
            <span class="text-sm font-bold text-emerald-400">Tutup kiriman hari ini</span>
        </a>

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
