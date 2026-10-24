<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.tugas') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Tugas</span>
            </a>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight truncate">Packing {{ $session->order?->order_number }}</h2>
                <p class="text-xs sm:text-sm text-slate-400 mt-0.5 font-mono truncate">{{ $session->session_number }}</p>
            </div>
            <span class="hidden sm:inline-block shrink-0 px-3 py-1 rounded-full text-sm font-bold {{ $session->status_color }}">{{ $session->status_label }}</span>
        </div>
    </x-slot>

    @php
        $running = $session->status === 'IN_PROGRESS';
        $order = $session->order;
        $canManual = in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'], true);
        // Setelah lapor masalah, order kembali ke picking sampai barang penggantinya diambil
        $waitingReplacement = $running && $order?->status === 'PICKING';
        $replacements = $waitingReplacement
            ? ($session->pickingSession?->items ?? collect())->filter(fn ($item) => $item->qty_picked < $item->qty_ordered)
            : collect();
        $problems = \App\Http\Controllers\Warehouse\PackingController::PROBLEMS;
    @endphp

    <div class="max-w-3xl mx-auto space-y-4">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        @if ($order)
            {{-- Yang perlu diketahui packer sebelum menutup paket --}}
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2 text-sm">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    @if ($session->pickingSession?->basket_no)
                        <span class="px-2 py-0.5 rounded-md bg-cyan-500 text-slate-900 font-black">Keranjang {{ $session->pickingSession->basket_no }}</span>
                    @endif
                    <span class="font-semibold text-white">{{ $order->courier ?: 'Ekspedisi belum diisi' }}</span>
                    <x-ship-badge :order="$order" />
                </div>
                <p class="text-slate-400">{{ $order->customer_name }} · Resi:
                    @if ($order->tracking_number)
                        <span class="font-mono text-white">{{ $order->tracking_number }}</span>
                    @else
                        <span class="text-amber-400 font-semibold">belum ada, cek di BigSeller</span>
                    @endif
                </p>
                @if ($order->notes)
                    <p class="p-2 rounded-lg bg-amber-500/10 border border-amber-500/40 text-amber-200 whitespace-pre-line">{{ $order->notes }}</p>
                @endif
            </div>
        @endif

        @if ($session->status === 'PENDING')
            <form method="POST" action="{{ route('gudang.packing.start', $session->id) }}">
                @csrf
                <button type="submit" class="w-full py-4 bg-purple-500 hover:bg-purple-400 text-white font-bold rounded-xl">Mulai packing</button>
            </form>
        @elseif ($session->status === 'CANCELLED')
            <div class="bg-red-900/20 border border-red-500/40 rounded-xl p-4 text-sm text-red-300">
                Order ini dibatalkan. Jangan dikemas; kembalikan barangnya lewat
                <a href="{{ route('gudang.restock') }}" class="font-semibold underline">Kembalikan ke Rak</a>.
            </div>
        @elseif ($waitingReplacement)
            <div class="bg-amber-900/20 border border-amber-500/40 rounded-xl p-4 space-y-3 text-sm text-amber-200">
                <p class="font-semibold">Menunggu barang pengganti</p>
                @foreach ($replacements as $item)
                    <p>Ambil <span class="font-bold text-white">{{ $item->qty_ordered - $item->qty_picked }} pcs {{ $item->sku }}</span> di rak <span class="font-mono font-bold text-cyan-300">{{ $item->bin_code }}</span></p>
                @endforeach
                <a href="{{ route('gudang.picking.show', $session->picking_session_id) }}" class="block w-full py-3 text-center bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold rounded-xl">Ambil pengganti</a>
                <p class="text-xs text-amber-300/80">Setelah penggantinya diambil, kembali ke halaman ini untuk melanjutkan packing.</p>
            </div>
        @elseif ($running)
            <x-scan-box :endpoint="route('gudang.packing.scan', $session->id)" storage-key="packing-{{ $session->id }}"
                :with-bin="false" hint="Scan setiap barang yang dimasukkan ke paket, atau ketuk +1 di barang yang belum ada barcode-nya." />
        @elseif ($session->status === 'COMPLETED')
            <div class="bg-emerald-900/20 border border-emerald-500/40 rounded-xl p-4 text-sm text-emerald-300">
                Packing selesai. Tempel label BigSeller, lalu paket masuk daftar Siap Kirim untuk diserahkan ke kurir.
            </div>
            {{-- Meja packing: langsung lanjut ke keranjang berikutnya --}}
            <x-scan-box :endpoint="route('gudang.command')" storage-key="packing-desk" :with-bin="false"
                hint="Scan no. order atau resi dari keranjang berikutnya untuk langsung mulai packing." />
        @endif

        <section class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
            <h3 class="px-4 py-3 border-b border-slate-800 text-base font-semibold text-white">Isi paket</h3>
            <div class="divide-y divide-slate-800">
                @foreach ($session->items as $item)
                    @php $complete = $item->qty_packed >= $item->qty_ordered; @endphp
                    <div class="p-4 space-y-3 {{ $complete ? 'opacity-60' : '' }}">
                        <div class="flex gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-white break-all">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400 truncate">{{ $item->masterSku?->product_name }}</p>
                                @if ($item->masterSku?->size)
                                    <span class="inline-block mt-1 px-2 py-0.5 rounded-md bg-slate-800 border border-slate-700 text-sm font-bold text-white">Ukuran {{ $item->masterSku->size }}</span>
                                @endif
                            </div>
                            <p class="shrink-0 text-right">
                                <span class="text-2xl font-bold {{ $complete ? 'text-emerald-400' : 'text-white' }}" data-scan-qty="{{ $item->id }}">{{ $item->qty_packed }}</span><span class="text-slate-500">/{{ $item->qty_ordered }}</span>
                            </p>
                        </div>

                        @if ($running && !$waitingReplacement)
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" onclick="scanTap(@js(route('gudang.packing.tap', [$session->id, $item->id])), { delta: -1 })"
                                    class="py-3 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 font-bold rounded-xl">−1</button>
                                <button type="button" onclick="scanTap(@js(route('gudang.packing.tap', [$session->id, $item->id])), { delta: 1 })"
                                    class="col-span-2 py-3 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-xl">+1 Masukkan</button>
                            </div>

                            <details class="text-xs text-slate-400">
                                <summary class="cursor-pointer select-none text-amber-400">Ada masalah dengan barang ini?</summary>
                                <form method="POST" action="{{ route('gudang.packing.problem', [$session->id, $item->id]) }}" class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    @csrf
                                    @foreach ($problems as $reason => $label)
                                        <button type="submit" name="reason" value="{{ $reason }}"
                                            onclick="return confirm(@js('Laporkan 1 pcs ' . $item->sku . ': ' . strtolower($label) . '? Barang penggantinya perlu diambil dari rak.'))"
                                            class="py-2 px-3 bg-slate-800 hover:bg-amber-600 border border-slate-700 text-slate-200 font-semibold rounded-lg">{{ $label }}</button>
                                    @endforeach
                                </form>
                            </details>

                            @if ($canManual && !$complete)
                                <details class="text-xs text-slate-400">
                                    <summary class="cursor-pointer select-none">Input jumlah manual</summary>
                                    <form method="POST" action="{{ route('gudang.packing.pack', [$session->id, $item->id]) }}" class="mt-2 flex items-end gap-2">
                                        @csrf
                                        <label class="flex-1">Jumlah dikemas
                                            <input type="number" name="qty_packed" value="{{ $item->qty_packed }}" min="0" max="{{ $item->qty_ordered }}" required
                                                class="mt-1 w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                                        </label>
                                        <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Simpan</button>
                                    </form>
                                </details>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
