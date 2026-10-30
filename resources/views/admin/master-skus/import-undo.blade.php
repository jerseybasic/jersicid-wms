<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('master-skus.index') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Batalkan Import Master SKU</h2>
                <p class="text-sm text-slate-400 mt-1 truncate">
                    {{ $plan['file'] ?? 'Import' }} · {{ $import->created_at->format('d M Y H:i') }} · {{ $import->user?->name ?? '-' }} · <span class="font-mono">{{ $plan['reference'] }}</span>
                </p>
            </div>
        </div>
    </x-slot>

    @php
        $labels = \App\Services\MasterSkuSync::LABELS;
        $cards = [
            ['label' => 'SKU baru dihapus', 'count' => count($plan['remove']), 'note' => number_format(array_sum($plan['remove'])) . ' pcs stoknya dinolkan', 'color' => 'text-red-400'],
            ['label' => 'Stok dikembalikan', 'count' => collect($plan['stock'])->unique('sku')->count(), 'note' => 'ke angka sebelum import', 'color' => 'text-amber-400'],
            ['label' => 'Data produk dikembalikan', 'count' => count($plan['fields']), 'note' => 'nama, harga, status, dll.', 'color' => 'text-cyan-400'],
            ['label' => 'Tidak bisa dihapus', 'count' => count($plan['kept']), 'note' => 'SKU baru yang sudah dipakai', 'color' => 'text-slate-300'],
        ];
    @endphp

    <div class="space-y-6">
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl text-sm">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ($cards as $card)
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                    <p class="text-xs {{ $card['color'] }} mb-1">{{ $card['label'] }}</p>
                    <p class="text-2xl font-bold {{ $card['count'] ? $card['color'] : 'text-white' }}">{{ number_format($card['count']) }}</p>
                    <p class="text-xs text-slate-500">{{ $card['note'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($undone)
            <div class="p-4 bg-slate-900 border border-slate-700 text-slate-300 rounded-xl text-sm">Import ini sudah dibatalkan.</div>
        @elseif ($hasWork)
            <form method="POST" action="{{ route('master-skus.import.undo.store', $import) }}"
                  onsubmit="return confirm('Batalkan import {{ $plan['reference'] }}? Langkah ini tidak bisa diulang.')"
                  class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-4 sm:justify-between">
                @csrf
                <div class="text-sm text-slate-300 space-y-1">
                    <p>SKU baru dari import ini dihapus permanen beserta stok dan lokasi raknya, jadi kodenya bisa dipakai lagi. Perubahan stok SKU lain dibalik dengan selisih yang sama.</p>
                    <p class="text-xs text-slate-500">Semua penyesuaian tercatat di Laporan Pergerakan Stok dengan referensi {{ $plan['reference'] }}. Setelah dibatalkan, upload lagi file yang benar lewat Import.</p>
                </div>
                <button type="submit" class="shrink-0 px-6 py-2.5 bg-red-600 hover:bg-red-500 text-on-accent text-sm font-semibold rounded-lg transition-all">
                    Batalkan import
                </button>
            </form>
        @else
            <div class="p-4 bg-slate-900 border border-slate-700 text-slate-300 rounded-xl text-sm">
                Tidak ada lagi yang bisa dibatalkan dari import ini.
            </div>
        @endif

        @if ($plan['remove'] || $plan['kept'])
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 text-sm text-slate-300">SKU baru dari import ini</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                            <tr>
                                <th class="py-3 px-4">SKU</th>
                                <th class="py-3 px-4 text-right">Stok</th>
                                <th class="py-3 px-4">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($plan['remove'] as $sku => $qty)
                                <tr>
                                    <td class="py-2.5 px-4 font-mono font-bold text-cyan-400 whitespace-nowrap">{{ $sku }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-300">{{ number_format($qty) }}</td>
                                    <td class="py-2.5 px-4 text-xs text-red-400">Dihapus</td>
                                </tr>
                            @endforeach
                            @foreach ($plan['kept'] as $sku => $reason)
                                <tr class="bg-amber-950/20">
                                    <td class="py-2.5 px-4 font-mono font-bold text-slate-300 whitespace-nowrap">{{ $sku }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-500">—</td>
                                    <td class="py-2.5 px-4 text-xs text-amber-300">Tidak dihapus: {{ $reason }}. Ubah statusnya jadi NONAKTIF kalau tidak dipakai.</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($plan['stock'])
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 text-sm text-slate-300">Stok yang dikembalikan</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                            <tr>
                                <th class="py-3 px-4">SKU</th>
                                <th class="py-3 px-4">Lokasi</th>
                                <th class="py-3 px-4 text-right">Sekarang</th>
                                <th class="py-3 px-4 text-right">Menjadi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($plan['stock'] as $row)
                                <tr>
                                    <td class="py-2.5 px-4 font-mono font-bold text-cyan-400 whitespace-nowrap">{{ $row['sku'] }}</td>
                                    <td class="py-2.5 px-4 font-mono text-slate-300">{{ $row['bin'] }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono text-slate-300">{{ number_format($row['now']) }}</td>
                                    <td class="py-2.5 px-4 text-right font-mono whitespace-nowrap">
                                        <span class="text-white">{{ number_format($row['to']) }}</span>
                                        <p class="text-xs {{ $row['diff'] > 0 ? 'text-emerald-400' : 'text-red-400' }}">{{ $row['diff'] > 0 ? '+' : '−' }}{{ number_format(abs($row['diff'])) }}</p>
                                        @if ($row['short'])
                                            <p class="text-xs text-amber-300">{{ $row['short'] }} pcs sudah keluar, berhenti di 0</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($plan['fields'])
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800 text-sm text-slate-300">Data produk yang dikembalikan</div>
                <div class="divide-y divide-slate-800">
                    @foreach ($plan['fields'] as $sku => $fields)
                        <div class="px-4 py-2.5">
                            <p class="font-mono font-bold text-cyan-400 text-sm">{{ $sku }}</p>
                            @foreach ($fields as $field => [$now, $before])
                                <p class="text-xs text-slate-400">{{ $labels[$field] ?? $field }}: <span class="text-slate-500 line-through">{{ $now ?? '-' }}</span> → <span class="text-white">{{ $before ?? '-' }}</span></p>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
