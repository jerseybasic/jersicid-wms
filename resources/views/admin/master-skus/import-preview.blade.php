<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('master-skus.index') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Batal</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Pratinjau Import Master SKU</h2>
                <p class="text-sm text-slate-400 mt-1 truncate">{{ $fileName }} · sheet {{ $sheet }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $labels = \App\Services\MasterSkuSync::LABELS;
        $kindOf = fn ($row) => $row['kind'] === 'same' && $row['changes'] ? 'changed' : $row['kind'];
        $cards = [
            ['key' => 'new', 'label' => 'SKU baru', 'count' => $summary['new'], 'note' => number_format($summary['new_qty']) . ' pcs', 'color' => 'text-cyan-400'],
            ['key' => 'up', 'label' => 'Stok bertambah', 'count' => $summary['up'], 'note' => '+' . number_format($summary['up_qty']) . ' pcs', 'color' => 'text-emerald-400'],
            ['key' => 'down', 'label' => 'Stok berkurang', 'count' => $summary['down'], 'note' => '−' . number_format($summary['down_qty']) . ' pcs', 'color' => 'text-red-400'],
            ['key' => 'changed', 'label' => 'Data produk berubah', 'count' => $summary['changed'], 'note' => 'nama, harga, status, dll.', 'color' => 'text-amber-400'],
            ['key' => 'same', 'label' => 'Stok tetap', 'count' => $summary['same'], 'note' => 'tidak ada perubahan stok', 'color' => 'text-slate-300'],
            ['key' => 'skip', 'label' => 'Dilewati', 'count' => $summary['skip'], 'note' => 'perlu diperbaiki di file', 'color' => 'text-slate-400'],
        ];
    @endphp

    <div class="space-y-6" x-data="{ filter: 'changes' }">
        @if (!$hasStock)
            <div class="p-4 bg-amber-900/20 border border-amber-500/40 text-amber-300 rounded-xl text-sm">
                File ini tidak punya kolom stok (CURRENT_STOCK atau Total Stok Saat Ini), jadi stok tidak diubah. Hanya data produk yang dibandingkan.
            </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
            @foreach ($cards as $card)
                <button type="button" @click="filter = '{{ $card['key'] }}'"
                        :class="filter === '{{ $card['key'] }}' ? 'border-cyan-500' : 'border-slate-800'"
                        class="text-left bg-slate-900 border rounded-xl p-4 hover:border-slate-600 transition-colors">
                    <p class="text-xs {{ $card['color'] }} mb-1">{{ $card['label'] }}</p>
                    <p class="text-2xl font-bold {{ $card['count'] ? $card['color'] : 'text-white' }}">{{ number_format($card['count']) }}</p>
                    <p class="text-xs text-slate-500">{{ $card['note'] }}</p>
                </button>
            @endforeach
        </div>

        @if ($hasWork)
            <form method="POST" action="{{ route('master-skus.import.store') }}"
                  class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-4 sm:justify-between">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="text-sm text-slate-300 space-y-1">
                    <p>Stok tiap SKU akan disamakan dengan angka di file. Selisihnya dicatat di Laporan Pergerakan Stok sebagai penyesuaian (ADJUSTMENT).</p>
                    <p class="text-xs text-slate-500">
                        Tambahan stok masuk ke lokasi SKU yang stoknya paling banyak, atau ke {{ \App\Services\MasterSkuSync::DEFAULT_BIN }} untuk SKU yang belum punya lokasi (tata ke rak lewat Denah Gudang).
                        @if ($notInFile)
                            {{ number_format($notInFile) }} SKU aktif yang tidak ada di file dibiarkan apa adanya.
                        @endif
                    </p>
                </div>
                <button type="submit" class="shrink-0 px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg transition-all">
                    Simpan perubahan
                </button>
            </form>
        @else
            <div class="p-4 bg-slate-900 border border-slate-700 text-slate-300 rounded-xl text-sm">
                Tidak ada yang berubah: semua SKU di file sudah sama dengan data saat ini.
                @if ($summary['skip'])
                    {{ $summary['skip'] }} baris dilewati, lihat alasannya di bawah.
                @endif
            </div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3 flex-wrap">
                <p class="text-sm text-slate-300">
                    <span x-show="filter === 'changes'">Semua baris yang berubah atau dilewati</span>
                    @foreach ($cards as $card)
                        <span x-show="filter === '{{ $card['key'] }}'" x-cloak>{{ $card['label'] }}</span>
                    @endforeach
                </p>
                <button type="button" x-show="filter !== 'changes'" x-cloak @click="filter = 'changes'" class="text-xs text-cyan-400 hover:text-cyan-300">Tampilkan semua perubahan</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">SKU</th>
                            <th class="py-3 px-4 text-right">Stok sekarang</th>
                            <th class="py-3 px-4 text-right">Stok di file</th>
                            <th class="py-3 px-4">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach ($rows as $row)
                            @php
                                $kind = $kindOf($row);
                                $diff = $row['after'] === null ? 0 : $row['after'] - $row['before'];
                                $show = $kind === 'same' ? "filter === 'same'" : "filter === 'changes' || filter === '{$kind}'" . ($row['changes'] && $kind !== 'changed' ? " || filter === 'changed'" : '');
                            @endphp
                            <tr class="align-top {{ $kind === 'skip' ? 'bg-red-950/20' : '' }}" x-show="{{ $show }}" @if ($kind === 'same') x-cloak @endif>
                                <td class="py-3 px-4 min-w-[12rem]">
                                    <p class="font-mono font-bold {{ $kind === 'skip' ? 'text-slate-400' : 'text-cyan-400' }}">{{ $row['sku'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $row['name'] }} {{ $row['size'] }} · baris {{ $row['row'] }}</p>
                                </td>
                                <td class="py-3 px-4 text-right font-mono text-slate-300 whitespace-nowrap">{{ $row['create'] ? '—' : number_format($row['before']) }}</td>
                                <td class="py-3 px-4 text-right font-mono whitespace-nowrap">
                                    @if ($row['after'] === null)
                                        <span class="text-slate-500">kosong</span>
                                    @else
                                        <span class="text-white">{{ number_format($row['after']) }}</span>
                                        @if ($diff && $kind !== 'skip')
                                            <p class="text-xs {{ $diff > 0 ? 'text-emerald-400' : 'text-red-400' }}">{{ $diff > 0 ? '+' : '−' }}{{ number_format(abs($diff)) }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-3 px-4 min-w-[14rem] text-xs space-y-1">
                                    @if ($kind === 'new')
                                        <p class="text-cyan-400">SKU baru, ditambahkan ke Master SKU</p>
                                    @elseif ($kind === 'up')
                                        <p class="text-emerald-400">Stok bertambah</p>
                                    @elseif ($kind === 'down')
                                        <p class="text-red-400">Stok berkurang</p>
                                    @elseif ($kind === 'same')
                                        <p class="text-slate-500">Tidak berubah</p>
                                    @endif
                                    @foreach ($row['changes'] as $field => $change)
                                        <p class="text-amber-300">{{ $labels[$field] ?? $field }}: <span class="text-slate-500 line-through">{{ $change['old'] ?? 'kosong' }}</span> → {{ $change['new'] ?? 'kosong' }}</p>
                                    @endforeach
                                    @foreach ($row['problems'] as $problem)
                                        <p class="text-red-400">{{ $problem }}</p>
                                    @endforeach
                                    @foreach ($row['warnings'] as $warning)
                                        <p class="text-amber-400">{{ $warning }}</p>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
