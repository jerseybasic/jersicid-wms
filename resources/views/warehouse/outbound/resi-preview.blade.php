<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.outbound.resi') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Cek Resi BigSeller</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">Periksa dulu sebelum stok dipotong</p>
            </div>
        </div>
    </x-slot>

    @php
        $badge = [
            'ship' => ['Potong stok & kirim', 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40'],
            'mark' => ['Tandai dikirim', 'bg-cyan-500/15 text-cyan-300 border-cyan-500/40'],
            'skip' => ['Dilewati', 'bg-amber-500/15 text-amber-300 border-amber-500/40'],
            'missing' => ['Tidak ditemukan', 'bg-red-500/15 text-red-300 border-red-500/40'],
        ];
        $toProcess = ($counts['ship'] ?? 0) + ($counts['mark'] ?? 0);
    @endphp

    <div class="max-w-4xl mx-auto space-y-4">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach ($badge as $key => [$label, $class])
                <div class="p-3 rounded-xl border {{ $class }}">
                    <p class="text-xs">{{ $label }}</p>
                    <p class="text-xl font-bold">{{ $counts[$key] ?? 0 }}</p>
                </div>
            @endforeach
        </div>

        @if ($repeated)
            <div class="p-3 bg-slate-800/60 border border-slate-700 text-slate-300 rounded-lg text-sm">
                {{ count($repeated) }} resi ditempel lebih dari sekali dan hanya dihitung sekali: <span class="font-mono">{{ implode(', ', array_slice($repeated, 0, 10)) }}{{ count($repeated) > 10 ? ', ...' : '' }}</span>
            </div>
        @endif
        @if ($counts['missing'] ?? 0)
            <div class="p-3 bg-red-900/20 border border-red-500/40 text-red-300 rounded-lg text-sm">
                Resi yang tidak ditemukan belum punya order di aplikasi. Ekspor pesanannya dari BigSeller, upload di
                <a href="{{ route('gudang.outbound.import') }}" class="underline">Import BigSeller</a>, lalu input lagi resinya di sini.
            </div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-xl divide-y divide-slate-800">
            @foreach ($rows as $row)
                <div class="p-3 sm:p-4 flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4">
                    <div class="sm:w-56 shrink-0 min-w-0">
                        <p class="font-mono text-sm text-white break-all">{{ $row['code'] }}</p>
                        @if ($row['order'])
                            <a href="{{ route('gudang.outbound.show', $row['order']->id) }}" target="_blank" class="text-xs text-cyan-400 hover:underline break-all">{{ $row['order']->order_number }}</a>
                            <p class="text-xs text-slate-500">{{ $row['order']->status_label }} · {{ $row['order']->customer_name }}</p>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0 space-y-1">
                        <span class="inline-block text-[11px] px-2 py-0.5 rounded-full border {{ $badge[$row['result']][1] }}">{{ $badge[$row['result']][0] }}</span>
                        <p class="text-xs text-slate-400">{{ $row['reason'] }}</p>
                        @if ($row['items'])
                            <ul class="text-xs text-slate-300 space-y-0.5">
                                @foreach ($row['items'] as $item)
                                    <li><span class="font-mono">{{ $item['sku'] }}</span> ×{{ $item['qty'] }} <span class="text-slate-500">(stok sistem {{ $item['available'] }})</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('gudang.outbound.resi.store') }}" class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3"
              x-data="{ sending: false }" @submit="if (sending) { $event.preventDefault(); return; } sending = true">
            @csrf
            <textarea name="resi" class="hidden">{{ $text }}</textarea>
            <p class="flex-1 text-sm text-slate-300">
                @if ($toProcess)
                    {{ $toProcess }} order akan ditandai <strong>Dikirim</strong>{{ ($counts['ship'] ?? 0) ? ', stok ' . $counts['ship'] . ' order di antaranya dipotong dari rak' : '' }}. Yang lain tidak diubah.
                @else
                    Tidak ada order yang bisa diproses dari daftar ini.
                @endif
            </p>
            <a href="{{ route('gudang.outbound.resi') }}" class="px-4 py-2.5 text-center bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-lg border border-slate-700">Ubah daftar</a>
            @if ($toProcess)
                <button type="submit" :disabled="sending" class="px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all disabled:opacity-60">
                    <span x-text="sending ? 'Memproses...' : 'Proses {{ $toProcess }} order'">Proses {{ $toProcess }} order</span>
                </button>
            @endif
        </form>
    </div>
</x-app-layout>
