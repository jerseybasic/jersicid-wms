<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.retur') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl border border-slate-700">Kembali</a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Retur {{ $return->return_number }}</h2>
                <p class="text-sm text-slate-400 mt-1">{{ $return->created_at->format('d M Y H:i') }} • {{ $return->receiver?->name }}</p>
            </div>
        </div>
    </x-slot>

    @php $good = $return->items->sum('qty_good'); @endphp

    <div class="max-w-4xl mx-auto space-y-6">
        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif

        @if (in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG'], true))
            {{-- Perbaiki retur yang salah: ubah alasan/catatan, atau hapus lalu buat ulang jika jumlahnya salah --}}
            <details class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
                <summary class="cursor-pointer select-none text-sm font-semibold text-cyan-400">Ubah atau hapus retur ini</summary>
                <form method="POST" action="{{ route('gudang.retur.update', $return->id) }}" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    @csrf @method('PUT')
                    <label class="text-xs text-slate-400">Alasan
                        <select name="reason" class="mt-1 w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                            @foreach (\App\Models\OrderReturn::REASONS as $value => $label)
                                <option value="{{ $value }}" @selected($return->reason === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs text-slate-400 md:col-span-2">Catatan
                        <input type="text" name="notes" value="{{ $return->notes }}" class="mt-1 w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </label>
                    <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg md:col-start-3">Simpan</button>
                </form>
                <form method="POST" action="{{ route('gudang.retur.destroy', $return->id) }}" class="mt-4 pt-4 border-t border-slate-800"
                    onsubmit="return confirm(@js('Hapus retur ' . $return->return_number . '? ' . ($good ? $good . ' pcs barang bagus yang masuk rak akan dikeluarkan lagi dari stok.' : '')))">
                    @csrf @method('DELETE')
                    <p class="text-xs text-slate-400 mb-2">Jumlah atau raknya salah? Hapus retur ini (stoknya dikoreksi), lalu buat retur baru.</p>
                    <button type="submit" class="px-4 py-2 bg-red-600/80 hover:bg-red-500 text-white text-sm font-semibold rounded-lg">Hapus retur</button>
                </form>
            </details>
        @endif

        @if ($good > 0)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row md:items-center gap-3">
                <p class="flex-1 text-sm text-slate-300">Tempel ulang label SKU jika labelnya lepas atau rusak, lalu simpan barang di rak yang tertulis.</p>
                <a href="{{ route('gudang.label.sku', ['ref' => $return->return_number]) }}" target="_blank"
                    class="px-4 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 text-sm font-bold rounded-xl text-center">Cetak label SKU ({{ $good }})</a>
            </div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-slate-400">Order</p>
                <p class="font-mono font-bold text-cyan-400">
                    @if ($return->order && !$return->order->trashed())
                        <a href="{{ route('gudang.outbound.show', $return->order->id) }}" class="hover:underline">{{ $return->order->order_number }}</a>
                    @else
                        {{ $return->order?->order_number }}
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-slate-400">Pelanggan</p>
                <p class="text-white">{{ $return->order?->customer_name }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-400">Alasan</p>
                <p class="text-white">{{ $return->reason_label }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-400">Bagus / Rusak</p>
                <p class="font-bold"><span class="text-emerald-400">{{ $good }}</span> / <span class="text-red-400">{{ $return->items->sum('qty_damaged') }}</span></p>
            </div>
            @if ($return->notes)
                <div class="col-span-2 md:col-span-4">
                    <p class="text-xs text-slate-400">Catatan</p>
                    <p class="text-sm text-slate-300 whitespace-pre-line">{{ $return->notes }}</p>
                </div>
            @endif
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-800/50 text-xs text-slate-400">
                    <tr>
                        <th class="text-left py-3 px-4">SKU</th>
                        <th class="text-right py-3 px-4">Bagus</th>
                        <th class="text-left py-3 px-4">Masuk ke rak</th>
                        <th class="text-right py-3 px-4">Rusak</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach ($return->items as $item)
                        <tr>
                            <td class="py-3 px-4">
                                <p class="font-mono text-white">{{ $item->sku }}</p>
                                <p class="text-xs text-slate-400">{{ $item->masterSku?->product_name }} {{ $item->masterSku?->size }}</p>
                            </td>
                            <td class="py-3 px-4 text-right font-bold text-emerald-400">{{ $item->qty_good }}</td>
                            <td class="py-3 px-4 font-mono text-cyan-400">{{ $item->bin_code ?? '-' }}</td>
                            <td class="py-3 px-4 text-right font-bold {{ $item->qty_damaged ? 'text-red-400' : 'text-slate-500' }}">{{ $item->qty_damaged }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
