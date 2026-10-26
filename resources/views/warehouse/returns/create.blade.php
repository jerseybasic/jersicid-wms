<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.retur') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl border border-slate-700">Kembali</a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Terima Retur</h2>
                <p class="text-sm text-slate-400 mt-1">Cari order, lalu isi jumlah barang yang kembali</p>
            </div>
        </div>
    </x-slot>

    @php
        $input = 'w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none';
    @endphp

    <div class="max-w-4xl mx-auto space-y-6">
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">
                <ul class="space-y-1 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="GET" action="{{ route('gudang.retur.create') }}" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col sm:flex-row gap-3">
            <input type="text" id="returnOrderCode" name="order" value="{{ $order?->order_number ?? old('order') }}" required autofocus
                placeholder="Scan label kirim, atau ketik no. order / no. resi"
                class="flex-1 px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white font-mono uppercase placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
            <x-camera-button for="returnOrderCode" />
            <button type="submit" class="px-5 py-3 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-xl">Cari order</button>
        </form>

        @if ($order)
            <form method="POST" action="{{ route('gudang.retur.store') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="order_id" value="{{ $order->id }}">

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <p class="text-xs text-slate-400">Order</p>
                        <p class="font-mono font-bold text-cyan-400">{{ $order->order_number }}</p>
                        <p class="text-xs text-slate-500">{{ $order->status_label }} • {{ $order->shipped_at?->format('d M Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Pelanggan</p>
                        <p class="text-white">{{ $order->customer_name }}</p>
                        <p class="text-xs text-slate-500">{{ $order->courier }} {{ $order->tracking_number }}</p>
                    </div>
                    <div>
                        <label for="reason" class="text-xs text-slate-400">Alasan retur</label>
                        <select id="reason" name="reason" required class="{{ $input }} mt-1">
                            @foreach ($reasons as $value => $label)
                                <option value="{{ $value }}" @selected(old('reason') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach ($lines as $line)
                        @php $id = $line['order_item_id']; @endphp
                        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 {{ $line['returnable'] === 0 ? 'opacity-50' : '' }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                                <div>
                                    <p class="font-mono font-bold text-white">{{ $line['sku'] }}</p>
                                    <p class="text-xs text-slate-400">{{ $line['product'] }}</p>
                                </div>
                                <p class="text-xs text-slate-400">
                                    Dikirim {{ $line['sent'] }} pcs
                                    @if ($line['returned'])
                                        • sudah diretur {{ $line['returned'] }}
                                    @endif
                                    • bisa diretur <span class="font-bold text-white">{{ $line['returnable'] }}</span>
                                </p>
                            </div>
                            @if ($line['returnable'] > 0)
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-xs text-emerald-400 mb-1">Bagus, masuk rak</label>
                                        <input type="number" name="items[{{ $id }}][qty_good]" min="0" max="{{ $line['returnable'] }}"
                                            value="{{ old("items.$id.qty_good", 0) }}" class="{{ $input }}">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-red-400 mb-1">Rusak</label>
                                        <input type="number" name="items[{{ $id }}][qty_damaged]" min="0" max="{{ $line['returnable'] }}"
                                            value="{{ old("items.$id.qty_damaged", 0) }}" class="{{ $input }}">
                                    </div>
                                    <div class="col-span-2 sm:col-span-1">
                                        <label class="block text-xs text-slate-400 mb-1">Lokasi rak</label>
                                        <input type="text" name="items[{{ $id }}][bin_code]" placeholder="A-01-01"
                                            value="{{ old("items.$id.bin_code", $line['suggested_bin']) }}" class="{{ $input }} font-mono uppercase">
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                    <label for="notes" class="block text-xs text-slate-400 mb-1">Catatan (opsional)</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="1000" placeholder="Mis. minta tukar ke ukuran XL"
                        class="{{ $input }}">{{ old('notes') }}</textarea>
                </div>

                <button type="submit"
                    class="w-full py-4 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl">
                    Simpan Retur
                </button>
            </form>
        @endif
    </div>
</x-app-layout>
