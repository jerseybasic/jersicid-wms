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

        @php
            // Paket ekspedisi ini yang masih tertinggal (dihitung ulang dari layar, karena scan tidak memuat ulang halaman)
            $total = $orders->filter(fn ($order) => filled($order->tracking_number))->count();
        @endphp
        <form method="POST" action="{{ route('gudang.handover.finish', $handover) }}" enctype="multipart/form-data" class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3"
              onsubmit="const left = {{ $total }} - parseInt(document.querySelector('[data-scan-qty=count]').textContent || '0', 10);
                        return confirm((left > 0 ? 'Masih ada ' + left + ' paket ' + @js($handover->courier) + ' yang belum di-scan. Paket itu tetap Siap Kirim untuk penjemputan berikutnya.\n\n' : '') + 'Semua paket yang sudah di-scan akan berstatus Dikirim. Lanjutkan?')">
            @csrf
            <div>
                <label for="driver_name" class="block text-xs font-medium text-slate-400 mb-1">Nama kurir yang menerima (opsional)</label>
                <input id="driver_name" type="text" name="driver_name" maxlength="100" placeholder="Contoh: Pak Andi SPX"
                       class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:ring-cyan-500">
            </div>
            {{-- Bukti terima: tanda tangan kurir di layar dan/atau foto tumpukan paket --}}
            <div x-data="signaturePad()" class="space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-400">Tanda tangan kurir (opsional)</span>
                    <button type="button" x-show="signed" x-cloak @click="clear()" class="text-xs text-slate-400 hover:text-red-400">Hapus</button>
                </div>
                <canvas x-ref="canvas" class="w-full h-36 bg-white rounded-lg touch-none"
                    @pointerdown="start($event)" @pointermove="move($event)" @pointerup="end()" @pointerleave="end()"></canvas>
                <input type="hidden" name="signature" x-ref="input">
                <p class="text-[11px] text-slate-500" x-show="!signed">Minta kurir tanda tangan di kotak putih.</p>
            </div>
            <div>
                <label for="photo" class="block text-xs font-medium text-slate-400 mb-1">Foto tumpukan paket (opsional)</label>
                <input id="photo" type="file" name="photo" accept="image/*" capture="environment"
                       class="w-full text-sm text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-slate-700 file:text-white">
            </div>
            @error('signature') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
            @error('photo') <p class="text-xs text-red-400">{{ $message }}</p> @enderror
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

    <script>
        /** Kotak tanda tangan: goresan jari/pena disimpan sebagai PNG di input tersembunyi */
        function signaturePad() {
            return {
                signed: false,
                drawing: false,
                ctx: null,
                // Ukuran kanvas mengikuti lebar layar saat mulai menggores (bisa 0 saat halaman baru dimuat)
                setup() {
                    const canvas = this.$refs.canvas;
                    const ratio = window.devicePixelRatio || 1;
                    if (this.ctx && canvas.width === Math.round(canvas.offsetWidth * ratio)) {
                        return;
                    }
                    canvas.width = canvas.offsetWidth * ratio;
                    canvas.height = canvas.offsetHeight * ratio;
                    this.ctx = canvas.getContext('2d');
                    this.ctx.scale(ratio, ratio);
                    this.ctx.lineWidth = 2.5;
                    this.ctx.lineCap = 'round';
                    this.ctx.lineJoin = 'round';
                    this.ctx.strokeStyle = '#0f172a';
                },
                point(event) {
                    const rect = this.$refs.canvas.getBoundingClientRect();
                    return [event.clientX - rect.left, event.clientY - rect.top];
                },
                start(event) {
                    this.setup();
                    this.drawing = true;
                    this.$refs.canvas.setPointerCapture(event.pointerId);
                    this.ctx.beginPath();
                    this.ctx.moveTo(...this.point(event));
                },
                move(event) {
                    if (!this.drawing) {
                        return;
                    }
                    this.ctx.lineTo(...this.point(event));
                    this.ctx.stroke();
                    this.signed = true;
                },
                end() {
                    if (!this.drawing) {
                        return;
                    }
                    this.drawing = false;
                    if (this.signed) {
                        this.$refs.input.value = this.$refs.canvas.toDataURL('image/png');
                    }
                },
                clear() {
                    const canvas = this.$refs.canvas;
                    this.ctx?.clearRect(0, 0, canvas.width, canvas.height);
                    this.$refs.input.value = '';
                    this.signed = false;
                },
            };
        }
    </script>
</x-app-layout>
