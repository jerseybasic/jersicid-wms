<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.tugas') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Tugas</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight truncate">Packing Gabungan</h2>
                <p class="text-xs sm:text-sm text-slate-400 mt-0.5 font-mono truncate">{{ $batch->batch_number }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $done = $batch->status === 'COMPLETED';
        $sessions = $batch->sessions->where('status', '!=', 'CANCELLED');
        $totalPacked = $sessions->sum(fn ($session) => $session->items->sum('qty_packed'));
        $totalOrdered = $sessions->sum(fn ($session) => $session->items->sum('qty_ordered'));
        $finished = $batch->sessions->where('status', 'COMPLETED')->count();
        $morePacking = $done && \App\Services\Packing::waitingOrders()->exists();
    @endphp

    <div class="max-w-3xl mx-auto space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-3 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-2xl font-bold text-white">{{ $batch->sessions->count() }}</p>
                <p class="text-xs text-slate-400">Keranjang</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-2xl font-bold {{ $done ? 'text-emerald-400' : 'text-white' }}">{{ $finished }}<span class="text-base text-slate-500">/{{ $sessions->count() }}</span></p>
                <p class="text-xs text-slate-400">Paket selesai</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                <p class="text-2xl font-bold {{ $done ? 'text-emerald-400' : 'text-amber-400' }}"><span data-scan-qty="total">{{ $totalPacked }}</span><span class="text-base text-slate-500">/{{ $totalOrdered }}</span></p>
                <p class="text-xs text-slate-400">pcs dikemas</p>
            </div>
        </div>

        @if ($done)
            {{-- Semua paket siap: cetak label sekaligus, lalu serahkan ke kurir --}}
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-300 rounded-xl text-sm space-y-3">
                <p>Semua keranjang sudah dikemas dan berstatus Siap Kirim. Tempel labelnya, taruh di rak Siap Kirim, lalu scan resinya saat kurir datang.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @if ($labelIds->isNotEmpty())
                        <a href="{{ route('gudang.label.shipping-bulk', ['ids' => $labelIds->all()]) }}" target="_blank" class="block py-3 text-center bg-slate-800 hover:bg-slate-700 border border-slate-600 text-white font-bold rounded-xl">Cetak {{ $labelIds->count() }} label</a>
                    @endif
                    <a href="{{ route('gudang.handover') }}" class="block py-3 text-center bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold rounded-xl">Serah terima kurir</a>
                </div>
                @if ($morePacking)
                    <form method="POST" action="{{ route('gudang.packing-batch.auto') }}">
                        @csrf
                        <button type="submit" class="w-full py-4 bg-purple-500 hover:bg-purple-400 text-white font-bold rounded-xl">Kemas keranjang berikutnya</button>
                    </form>
                @endif
            </div>
        @else
            <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-slate-900 border border-purple-500/50">
                <span class="text-xs font-bold uppercase text-purple-300 shrink-0">Sedang dikemas</span>
                <span class="ml-auto text-right font-bold text-white truncate" data-scan-qty="active">{{ $active && $active->status === 'IN_PROGRESS' ? 'Keranjang ' . $active->basket_no . ' · ' . $active->order?->order_number : 'Belum dipilih' }}</span>
            </div>
            <x-scan-box :endpoint="route('gudang.packing-batch.scan', $batch)" storage-key="packing-batch-{{ $batch->id }}" :with-bin="false"
                hint="Ambil satu keranjang, scan label/resinya (atau langsung scan barang), lalu scan setiap barang yang dimasukkan ke paket." />
            @if ($finished)
                <a href="{{ route('gudang.label.shipping-bulk', ['ids' => $labelIds->all()]) }}" target="_blank" class="block text-center text-sm text-cyan-400 hover:text-cyan-300">Cetak label {{ $finished }} paket yang sudah selesai</a>
            @endif
        @endif

        {{-- Satu kartu per keranjang: isi paket dan tombol +1/−1 untuk barang tanpa barcode --}}
        <section class="space-y-3">
            @foreach ($batch->sessions as $session)
                @php
                    $order = $session->order;
                    $complete = $session->status === 'COMPLETED';
                    $isActive = !$done && $active?->id === $session->id && !$complete;
                    $waitingReplacement = $session->status === 'IN_PROGRESS' && $order?->status === 'PICKING';
                @endphp
                @if ($session->status === 'CANCELLED')
                    <div class="p-4 flex items-center gap-3 bg-red-900/10 border border-red-500/40 rounded-xl">
                        <span class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-xl font-black bg-red-500/20 text-red-300 border border-red-500/40">{{ $session->basket_no }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-white truncate">{{ $order?->order_number }}</p>
                            <p class="text-xs text-red-300">Order dibatalkan. Jangan dikemas; kembalikan isinya lewat <a href="{{ route('gudang.restock') }}" class="underline">Kembalikan ke Rak</a>.</p>
                        </div>
                    </div>
                    @continue
                @endif
                <div class="bg-slate-900 border {{ $isActive ? 'border-purple-500' : ($complete ? 'border-emerald-500/40' : 'border-slate-800') }} rounded-xl overflow-hidden {{ $complete ? 'opacity-70' : '' }}">
                    <div class="p-4 flex items-start gap-3">
                        <span class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-xl font-black {{ $complete ? 'bg-emerald-500 text-slate-900' : 'bg-slate-800 text-white border border-slate-700' }}">{{ $session->basket_no }}</span>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-white truncate">{{ $order?->order_number }} @if ($order) <x-ship-badge :order="$order" class="align-middle ml-1" /> @endif</p>
                            <p class="text-xs text-slate-400 truncate">{{ $order?->customer_name }} · {{ $order?->courier ?: 'ekspedisi belum diisi' }}</p>
                            @if ($order?->tracking_number)
                                <p class="text-xs text-slate-400">Resi <span class="font-mono text-white">{{ $order->tracking_number }}</span></p>
                            @else
                                <p class="text-xs text-amber-400">Resi belum ada. <a href="{{ route('gudang.packing.show', $session->id) }}" class="underline font-semibold">Isi resi</a> supaya bisa diserahkan ke kurir.</p>
                            @endif
                            @if ($order?->notes)
                                <p class="mt-1 text-xs text-amber-200 whitespace-pre-line">{{ $order->notes }}</p>
                            @endif
                        </div>
                        <p class="shrink-0 text-right text-sm text-slate-300">
                            <span class="font-bold text-white" data-scan-qty="basket-{{ $session->basket_no }}">{{ $session->items->sum('qty_packed') }}</span>/{{ $session->items->sum('qty_ordered') }} pcs
                            <br><span class="text-xs {{ $complete ? 'text-emerald-400' : 'text-slate-500' }}">{{ $complete ? 'Siap kirim' : ($waitingReplacement ? 'Tunggu pengganti' : 'Belum lengkap') }}</span>
                        </p>
                    </div>

                    @if ($waitingReplacement)
                        <div class="mx-4 mb-4 p-3 rounded-lg bg-amber-900/20 border border-amber-500/40 text-sm text-amber-200">
                            Ada barang yang dilaporkan bermasalah. <a href="{{ route('gudang.packing.show', $session->id) }}" class="font-semibold underline">Buka keranjang ini</a> untuk mengambil penggantinya.
                        </div>
                    @endif

                    <div class="divide-y divide-slate-800 border-t border-slate-800">
                        @foreach ($session->items as $item)
                            @php $itemDone = $item->qty_packed >= $item->qty_ordered; @endphp
                            <div class="px-4 py-3 flex items-center gap-3 {{ $itemDone ? 'opacity-60' : '' }}">
                                <div class="flex-1 min-w-0">
                                    <p class="font-mono text-sm font-bold text-white break-all">{{ $item->sku }}</p>
                                    <p class="text-xs text-slate-400 truncate">{{ $item->masterSku?->product_name }}</p>
                                    @if ($item->masterSku?->size)
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded-md bg-white text-slate-900 text-xs font-black">Ukuran {{ $item->masterSku->size }}</span>
                                    @endif
                                </div>
                                <p class="shrink-0 text-right">
                                    <span class="text-xl font-bold {{ $itemDone ? 'text-emerald-400' : 'text-white' }}" data-scan-qty="{{ $item->id }}">{{ $item->qty_packed }}</span><span class="text-slate-500">/{{ $item->qty_ordered }}</span>
                                </p>
                                @if ($session->status === 'IN_PROGRESS' && !$waitingReplacement)
                                    <div class="shrink-0 flex gap-1.5">
                                        <button type="button" onclick="scanTap(@js(route('gudang.packing.tap', [$session->id, $item->id])), { delta: -1 })"
                                            class="w-11 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 font-bold rounded-lg">−1</button>
                                        <button type="button" onclick="scanTap(@js(route('gudang.packing.tap', [$session->id, $item->id])), { delta: 1 })"
                                            class="w-11 py-2 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-lg">+1</button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="px-4 py-2 border-t border-slate-800 flex flex-wrap justify-end gap-x-4 gap-y-1 text-xs">
                        @if ($complete)
                            <a href="{{ route('gudang.label.shipping', $order->id) }}" target="_blank" class="font-semibold text-cyan-400 hover:text-cyan-300">Cetak label</a>
                        @endif
                        <a href="{{ route('gudang.packing.show', $session->id) }}" class="text-slate-400 hover:text-white">{{ $complete ? 'Detail' : 'Ada masalah / buka sendiri' }}</a>
                    </div>
                </div>
            @endforeach
        </section>
    </div>
</x-app-layout>
