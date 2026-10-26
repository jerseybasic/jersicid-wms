<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Tugas Saya</h2>
            <p class="text-sm text-slate-400 mt-1">Ambil tugas, lalu scan barang di layar berikutnya</p>
        </div>
    </x-slot>

    @php
        $pill = 'inline-flex items-center justify-center min-w-[1.75rem] px-2 py-0.5 rounded-full text-xs font-bold';
        $card = 'bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col gap-3';
        $bigButton = 'w-full py-4 rounded-xl text-base font-bold text-center transition-all active:scale-[0.98]';
    @endphp

    <div class="max-w-3xl mx-auto space-y-8">

        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif

        {{-- QR perintah yang ditempel di meja: Mulai Picking, Mulai Packing, Cek Retur, Tutup Kiriman --}}
        <x-scan-box :endpoint="route('gudang.command')" storage-key="tugas" :with-bin="false"
            hint="Scan QR perintah (Mulai Picking, Mulai Packing, Cek Retur, Tutup Kiriman), atau scan no. order/resi di keranjang untuk langsung mulai packing." />

        {{-- Ringkasan --}}
        <div class="grid grid-cols-3 gap-3">
            <a href="#ambil" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-center">
                <p class="text-3xl font-bold text-amber-400">{{ $toPick->count() }}</p>
                <p class="text-xs text-slate-400 mt-1">Perlu diambil</p>
            </a>
            <a href="#kemas" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-center">
                <p class="text-3xl font-bold text-purple-400">{{ $toPack->count() }}</p>
                <p class="text-xs text-slate-400 mt-1">Perlu dikemas</p>
            </a>
            <a href="#kirim" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-center">
                <p class="text-3xl font-bold text-emerald-400">{{ $toShip->count() }}</p>
                <p class="text-xs text-slate-400 mt-1">Siap kirim</p>
            </a>
        </div>

        @php
            $late = $deadlines[\App\Services\ShipDeadline::LATE] ?? 0;
            $dueToday = $deadlines[\App\Services\ShipDeadline::TODAY] ?? 0;
        @endphp
        @if ($late || $dueToday)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 bg-slate-900 border {{ $late ? 'border-red-500/40' : 'border-amber-500/40' }} rounded-2xl p-4 text-sm text-slate-300">
                @if ($late)
                    <a href="{{ route('gudang.outbound', ['batas' => 'terlambat']) }}"><span class="text-lg font-bold text-red-400">{{ $late }}</span> order terlambat dikirim</a>
                @endif
                @if ($dueToday)
                    <a href="{{ route('gudang.outbound', ['batas' => 'hari-ini']) }}"><span class="text-lg font-bold text-amber-400">{{ $dueToday }}</span> order harus dikirim hari ini</a>
                @endif
                <span class="text-xs text-slate-500 w-full">Daftar di bawah sudah diurutkan dari batas kirim paling dekat.</span>
            </div>
        @endif

        @if ($toRestock > 0)
            <a href="{{ route('gudang.restock') }}" class="flex items-center justify-between gap-3 bg-slate-900 border border-red-500/40 rounded-2xl p-4">
                <span class="text-sm text-slate-300"><span class="text-lg font-bold text-red-400">{{ $toRestock }}</span> pcs dari order batal belum dikembalikan ke rak</span>
                <span class="text-sm font-bold text-red-400 shrink-0">Kembalikan</span>
            </a>
        @endif

        @if ($waitingStock > 0)
            <a href="{{ route('gudang.outbound', ['status' => 'WAITING_STOCK']) }}" class="flex items-center justify-between gap-3 bg-slate-900 border border-amber-500/40 rounded-2xl p-4">
                <span class="text-sm text-slate-300"><span class="text-lg font-bold text-amber-400">{{ $waitingStock }}</span> order menunggu stok, otomatis masuk antrean saat barangnya diterima</span>
                <span class="text-sm font-bold text-amber-400 shrink-0">Lihat</span>
            </a>
        @endif

        @if ($toRefill > 0)
            <a href="{{ route('gudang.replenishment') }}" class="flex items-center justify-between gap-3 bg-slate-900 border border-cyan-500/40 rounded-2xl p-4">
                <span class="text-sm text-slate-300"><span class="text-lg font-bold text-cyan-400">{{ $toRefill }}</span> SKU di rak hampir habis, cadangannya ada di bin lain</span>
                <span class="text-sm font-bold text-cyan-400 shrink-0">Isi ulang rak</span>
            </a>
        @endif

        {{-- Sedang berjalan --}}
        @if ($activePicking->isNotEmpty() || $activeBatches->isNotEmpty() || $activePacking->isNotEmpty())
            <section class="space-y-3">
                <h3 class="text-lg font-semibold text-white">Pekerjaan saya</h3>
                @foreach ($activeBatches as $batch)
                    @php $batchItems = $batch->sessions->flatMap->items; @endphp
                    <div class="{{ $card }} border-cyan-500/40">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold text-cyan-400">PICKING GABUNGAN</p>
                                <p class="text-base font-bold text-white">{{ $batch->sessions->count() }} order · {{ $batch->sessions->where('status', 'COMPLETED')->count() }} keranjang lengkap</p>
                                <p class="text-sm text-slate-400 font-mono">{{ $batch->batch_number }}</p>
                            </div>
                            <p class="text-right text-sm text-slate-300">
                                <span class="text-xl font-bold text-white">{{ $batchItems->sum('qty_picked') }}</span>/{{ $batchItems->sum('qty_ordered') }} pcs
                            </p>
                        </div>
                        <a href="{{ route('gudang.picking-batch.show', $batch) }}" class="{{ $bigButton }} bg-cyan-500 text-slate-900">Lanjutkan picking gabungan</a>
                    </div>
                @endforeach
                @foreach ($activePicking as $session)
                    <div class="{{ $card }} border-amber-500/40">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold text-amber-400">PICKING</p>
                                <p class="text-base font-bold text-white">{{ $session->order?->order_number }}</p>
                                <p class="text-sm text-slate-400">{{ $session->order?->customer_name }}</p>
                            </div>
                            <p class="text-right text-sm text-slate-300">
                                <span class="text-xl font-bold text-white">{{ $session->items->sum('qty_picked') }}</span>/{{ $session->items->sum('qty_ordered') }} pcs
                            </p>
                        </div>
                        <p class="text-xs text-slate-400">Rute bin: <span class="font-mono text-cyan-400">{{ $session->items->pluck('bin_code')->unique()->sort()->implode(' → ') }}</span></p>
                        <a href="{{ route('gudang.picking.show', $session->id) }}" class="{{ $bigButton }} bg-amber-500 text-slate-900">
                            {{ $session->status === 'PENDING' ? 'Buka & mulai' : 'Lanjutkan picking' }}
                        </a>
                    </div>
                @endforeach
                @foreach ($activePacking as $session)
                    <div class="{{ $card }} border-purple-500/40">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold text-purple-400">PACKING</p>
                                <p class="text-base font-bold text-white">{{ $session->order?->order_number }}</p>
                                <p class="text-sm text-slate-400">{{ $session->order?->customer_name }}</p>
                            </div>
                            <p class="text-right text-sm text-slate-300">
                                <span class="text-xl font-bold text-white">{{ $session->items->sum('qty_packed') }}</span>/{{ $session->items->sum('qty_ordered') }} pcs
                            </p>
                        </div>
                        <a href="{{ route('gudang.packing.show', $session->id) }}" class="{{ $bigButton }} bg-purple-500 text-white">
                            {{ $session->status === 'PENDING' ? 'Buka & mulai' : 'Lanjutkan packing' }}
                        </a>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($othersWork->isNotEmpty())
            <section class="space-y-2">
                <h3 class="text-sm font-semibold text-slate-400">Sedang dikerjakan staf lain</h3>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl divide-y divide-slate-800">
                    @foreach ($othersWork as $work)
                        <a href="{{ $work['url'] }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-slate-800/40">
                            <span class="text-slate-300"><span class="text-xs font-bold text-slate-500 uppercase">{{ $work['type'] }}</span> {{ $work['title'] }}</span>
                            <span class="text-slate-400 shrink-0">{{ $work['user'] ?? '-' }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Perlu diambil --}}
        <section id="ambil" class="space-y-3">
            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                Perlu diambil (picking) <span class="{{ $pill }} bg-amber-500/20 text-amber-400">{{ $toPick->count() }}</span>
            </h3>
            @if ($toPick->count() >= 2)
                {{-- Gabung otomatis: order dengan batas kirim terdekat, tanpa centang satu per satu --}}
                <form method="POST" action="{{ route('gudang.picking-batch.auto') }}">
                    @csrf
                    <button type="submit" class="{{ $bigButton }} bg-cyan-500 text-slate-900">
                        Ambil {{ min($toPick->count(), \App\Models\PickingBatch::AUTO_ORDERS) }} order sekaligus
                    </button>
                </form>
                <a href="{{ route('gudang.picking-batch.create') }}" class="block text-center text-sm text-cyan-400 hover:text-cyan-300">Pilih sendiri order yang digabung</a>
            @endif
            @forelse ($toPick as $order)
                <div class="{{ $card }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-base font-bold text-white"><a href="{{ route('gudang.outbound.show', $order->id) }}" class="hover:underline">{{ $order->order_number }}</a> <x-ship-badge :order="$order" class="align-middle ml-1" /></p>
                            <p class="text-sm text-slate-400">{{ $order->customer_name }} · {{ $order->courier ?: 'kurir belum diisi' }}</p>
                        </div>
                        <p class="text-right text-sm text-slate-300">{{ $order->items_count }} SKU<br><span class="font-bold text-white">{{ (int) $order->items_sum_qty_ordered }} pcs</span></p>
                    </div>
                    <form method="POST" action="{{ route('gudang.picking.store') }}">
                        @csrf
                        <input type="hidden" name="order_id" value="{{ $order->id }}">
                        <input type="hidden" name="start_now" value="1">
                        <button type="submit" class="{{ $bigButton }} bg-slate-800 border border-cyan-500/50 text-cyan-300">Ambil order ini saja</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-slate-500">Tidak ada order yang menunggu picking.</p>
            @endforelse
        </section>

        {{-- Perlu dikemas --}}
        <section id="kemas" class="space-y-3">
            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                Perlu dikemas (packing) <span class="{{ $pill }} bg-purple-500/20 text-purple-400">{{ $toPack->count() }}</span>
            </h3>
            @forelse ($toPack as $order)
                <div class="{{ $card }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            @if ($order->pickingSession?->basket_no)
                                <p class="text-xs font-bold text-cyan-400">KERANJANG {{ $order->pickingSession->basket_no }} · {{ $order->pickingSession->batch?->batch_number }}</p>
                            @endif
                            <p class="text-base font-bold text-white"><a href="{{ route('gudang.outbound.show', $order->id) }}" class="hover:underline">{{ $order->order_number }}</a> <x-ship-badge :order="$order" class="align-middle ml-1" /></p>
                            <p class="text-sm text-slate-400">{{ $order->customer_name }} · {{ $order->courier ?: 'kurir belum diisi' }}</p>
                        </div>
                        <p class="text-right text-sm text-slate-300">{{ $order->items_count }} SKU<br><span class="font-bold text-white">{{ (int) $order->items_sum_qty_ordered }} pcs</span></p>
                    </div>
                    <form method="POST" action="{{ route('gudang.packing.store') }}">
                        @csrf
                        <input type="hidden" name="order_id" value="{{ $order->id }}">
                        <input type="hidden" name="courier" value="{{ $order->courier }}">
                        <input type="hidden" name="start_now" value="1">
                        <button type="submit" class="{{ $bigButton }} bg-purple-500 text-white">Ambil & mulai packing</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-slate-500">Tidak ada order yang menunggu packing.</p>
            @endforelse
        </section>

        {{-- Siap kirim --}}
        <section id="kirim" class="space-y-3">
            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                Siap kirim <span class="{{ $pill }} bg-emerald-500/20 text-emerald-400">{{ $toShip->count() }}</span>
            </h3>
            @if ($toShip->isNotEmpty())
                <a href="{{ route('gudang.handover') }}" class="{{ $bigButton }} block bg-slate-800 border border-emerald-500/50 text-emerald-300">
                    Serahkan ke kurir (scan resi)
                </a>
            @endif
            @forelse ($toShip as $order)
                <div class="{{ $card }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-base font-bold text-white"><a href="{{ route('gudang.outbound.show', $order->id) }}" class="hover:underline">{{ $order->order_number }}</a> <x-ship-badge :order="$order" class="align-middle ml-1" /></p>
                            <p class="text-sm text-slate-400">{{ $order->customer_name }} · {{ $order->courier ?: 'kurir belum diisi' }}</p>
                        </div>
                        <p class="text-right text-sm font-bold text-white">{{ (int) $order->items_sum_qty_ordered }} pcs</p>
                    </div>
                    @if ($order->tracking_number)
                        <p class="text-sm text-slate-400">Resi <span class="font-mono text-white">{{ $order->tracking_number }}</span></p>
                    @else
                        <p class="text-sm text-amber-400">Belum ada resi. Cek di BigSeller lalu <a href="{{ route('gudang.outbound.edit', $order->id) }}" class="underline font-semibold">isi resinya</a>.</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Belum ada paket yang siap dikirim.</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
