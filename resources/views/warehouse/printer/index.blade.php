<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('dashboard.gudang') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Thermal Printer Management</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">Cetak QR master SKU, rak & bin, dan QR perintah langsung dari browser</p>
            </div>
        </div>
    </x-slot>

    @php
        $card = 'bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4';
        $field = 'px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none';
        $button = 'px-5 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold rounded-xl';
        $sizeOptions = ['stiker' => 'Stiker 50×30 mm', 'sedang' => 'Label 100×50 mm', 'besar' => 'Label 100×150 mm', 'a4' => 'Kertas A4 (printer biasa)'];
    @endphp

    <div class="max-w-5xl mx-auto space-y-6">
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        <div class="p-4 rounded-2xl border border-cyan-500/30 bg-cyan-900/20 text-sm text-slate-300 space-y-1">
            <p class="font-semibold text-cyan-300">Cara cetak ke printer thermal</p>
            <p>Klik tombol cetak, halaman label terbuka di tab baru dan dialog print muncul. Pilih printer thermal (misalnya Zebra ZT411), ukuran kertas sama dengan ukuran label, margin <b>None</b>, dan skala <b>100%</b>.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- QR master SKU --}}
            <form method="GET" action="{{ route('gudang.label.sku') }}" target="_blank" class="{{ $card }}"
                x-data="{ sku: '', qty: 1, add() { const code = this.sku.split(' ')[0].trim().toUpperCase(); if (!code) return; const list = this.$refs.list; list.value = (list.value.trim() ? list.value.trim() + '\n' : '') + code + (this.qty > 1 ? '*' + this.qty : ''); this.sku = ''; this.qty = 1; this.$refs.sku.focus(); } }">
                <div>
                    <h3 class="text-lg font-semibold text-white">QR Master SKU</h3>
                    <p class="text-sm text-slate-400">Isi QR = kode SKU, bisa langsung di-scan di picking, packing, inbound, dan cek barang.</p>
                </div>

                <div class="flex gap-2">
                    <input type="text" x-ref="sku" x-model="sku" list="sku-options" placeholder="Cari SKU..." @keydown.enter.prevent="add()" class="{{ $field }} flex-1 min-w-0 font-mono">
                    <input type="number" x-model.number="qty" min="1" max="300" class="{{ $field }} w-20 text-center" aria-label="Jumlah label">
                    <button type="button" @click="add()" class="px-4 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-xl">Tambah</button>
                </div>
                <datalist id="sku-options">
                    @foreach ($skus as $sku)
                        <option value="{{ $sku->sku }}">{{ $sku->product_name }} {{ $sku->size }}</option>
                    @endforeach
                </datalist>

                <label class="block text-sm text-slate-300">Daftar yang dicetak
                    <textarea name="daftar" x-ref="list" rows="5" required placeholder="JSC-HOME-L*3&#10;JSC-AWAY-M" class="{{ $field }} w-full mt-1 font-mono text-sm"></textarea>
                    <span class="text-xs text-slate-500">Satu SKU per baris, tambahkan *jumlah untuk lebih dari 1 label. Bisa tempel dari Excel.</span>
                </label>

                <div class="flex flex-wrap gap-2 items-center">
                    <select name="ukuran" class="{{ $field }} pr-10">
                        <option value="stiker">Stiker 50×30 mm</option>
                        <option value="a4">Kertas A4 (printer biasa)</option>
                    </select>
                    <button type="submit" class="{{ $button }}">Cetak QR SKU</button>
                </div>
            </form>

            {{-- QR rak & bin --}}
            <form method="GET" action="{{ route('gudang.label.bins') }}" target="_blank" class="{{ $card }}" x-data>
                <div>
                    <h3 class="text-lg font-semibold text-white">QR Rak & Bin</h3>
                    <p class="text-sm text-slate-400">Tempel di rak dan di setiap bin. Scan bin dulu sebelum scan barang saat inbound, picking, dan opname.</p>
                </div>

                @if ($racks->isNotEmpty())
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm text-slate-300">Rak</span>
                            <button type="button" class="text-xs text-cyan-400"
                                @click="$el.closest('form').querySelectorAll('input[name=\'rak[]\']').forEach(el => el.checked = true)">Pilih semua</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($racks as $rack)
                                <label class="flex items-center gap-2 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-sm text-white font-mono">
                                    <input type="checkbox" name="rak[]" value="{{ $rack }}" class="rounded text-cyan-500 bg-slate-900 border-slate-600">
                                    {{ $rack }}
                                </label>
                            @endforeach
                        </div>
                        <label class="mt-3 flex items-center gap-2 text-sm text-slate-300">
                            <input type="checkbox" name="dengan_bin" value="1" checked class="rounded text-cyan-500 bg-slate-900 border-slate-600">
                            Sertakan semua bin yang sudah dipakai di rak terpilih
                        </label>
                    </div>
                @endif

                <label class="block text-sm text-slate-300">Kode bin lain (opsional)
                    <textarea name="bin" rows="3" placeholder="A-01-01..A-01-10&#10;B-02-03" class="{{ $field }} w-full mt-1 font-mono text-sm uppercase"></textarea>
                    <span class="text-xs text-slate-500">Satu per baris. Pakai .. untuk rentang, misalnya A-01-01..A-01-10 untuk bin baru yang belum berisi stok.</span>
                </label>

                <div class="flex flex-wrap gap-2 items-center">
                    <select name="ukuran" class="{{ $field }} pr-10">
                        @foreach ($sizeOptions as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="{{ $button }}">Cetak QR Rak</button>
                </div>
            </form>
        </div>

        {{-- QR perintah --}}
        <form method="GET" action="{{ route('gudang.label.commands') }}" target="_blank" class="{{ $card }}">
            <div>
                <h3 class="text-lg font-semibold text-white">QR Perintah</h3>
                <p class="text-sm text-slate-400">Cetak sekali, tempel di meja picking, meja packing, area retur, dan pintu keluar. Bisa di-scan di kotak scan mana pun, termasuk layar Tugas Saya.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach (\App\Services\ScanCommand::COMMANDS as $code => $command)
                    <div class="p-4 bg-slate-800/60 border border-slate-700 rounded-xl">
                        <p class="font-bold text-white">{{ $command['title'] }}</p>
                        <p class="text-xs text-slate-400 mt-1">{{ $command['hint'] }}</p>
                        <p class="text-xs text-cyan-400 font-mono mt-2">{{ $code }}</p>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2 items-center">
                <select name="ukuran" class="{{ $field }} pr-10">
                    @foreach ($sizeOptions as $value => $name)
                        <option value="{{ $value }}" @selected($value === 'besar')>{{ $name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="{{ $button }}">Cetak QR Perintah</button>
            </div>
        </form>
    </div>
</x-app-layout>
