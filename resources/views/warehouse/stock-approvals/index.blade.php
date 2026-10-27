<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Persetujuan Stok</h2>
            <p class="text-sm text-slate-400 mt-1">Stok sistem baru berubah setelah disetujui di sini</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ $errors->first() }}</div>
        @endif

        {{-- Opname yang dikirim staf --}}
        <section class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                <h3 class="font-semibold text-white">Stock opname menunggu persetujuan</h3>
                <span class="text-xs text-slate-400">{{ $opnames->count() }} opname</span>
            </div>
            @forelse ($opnames as $opname)
                <div class="px-4 py-3 border-b border-slate-800 last:border-0 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('gudang.opname.show', $opname->id) }}" class="font-mono text-cyan-400 hover:text-cyan-300">{{ $opname->opname_number }}</a>
                        <p class="text-sm text-white">{{ $opname->scope_label }}</p>
                        <p class="text-xs text-slate-400">
                            Dikirim {{ $opname->submitter?->name ?? '-' }} · {{ $opname->submitted_at?->format('d M Y H:i') }}
                        </p>
                    </div>
                    <div class="text-sm text-slate-300">
                        {{ $opname->items_count }} lokasi ·
                        <span class="{{ $opname->diff_count ? 'text-red-400 font-bold' : 'text-slate-500' }}">{{ $opname->diff_count }} selisih</span>
                        @if ($opname->diff_count)
                            <span class="text-xs text-slate-400">(total {{ $opname->diff_total > 0 ? '+' : '' }}{{ (int) $opname->diff_total }} pcs)</span>
                        @endif
                    </div>
                    <a href="{{ route('gudang.opname.show', $opname->id) }}"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold rounded-lg text-center">Periksa & setujui</a>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-slate-500 text-center">Tidak ada opname yang menunggu persetujuan.</p>
            @endforelse
        </section>

        {{-- Laporan barang tidak ada di rak --}}
        <section class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-800">
                <h3 class="font-semibold text-white">Barang tidak ada di rak</h3>
                <p class="text-xs text-slate-400 mt-0.5">Dilaporkan picker. Selama belum diputuskan, stok di rak itu tidak dijatah ke picking dan tidak dihitung tersedia.</p>
            </div>
            @forelse ($reports as $report)
                @php $now = $currentQty[$report->sku . '|' . $report->bin_code] ?? 0; @endphp
                <div class="px-4 py-3 border-b border-slate-800 last:border-0 flex flex-col lg:flex-row lg:items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-white">{{ $report->sku }} <span class="text-slate-400">di</span> <span class="text-amber-300">{{ $report->bin_code }}</span></p>
                        <p class="text-xs text-slate-400 truncate">{{ $report->masterSku?->product_name }} {{ $report->masterSku?->size }}</p>
                        <p class="text-xs text-slate-400">
                            {{ $report->reporter?->name ?? '-' }} · {{ $report->created_at->format('d M H:i') }}
                            @if ($report->order)
                                · order <a href="{{ route('gudang.outbound.show', $report->order_id) }}" class="text-cyan-400 hover:text-cyan-300">{{ $report->order->order_number }}</a>
                            @endif
                        </p>
                    </div>
                    <div class="text-sm text-slate-300 lg:text-right">
                        Stok sistem: <span class="font-bold text-white">{{ $now }}</span> pcs<br>
                        <span class="text-xs text-slate-400">Akan dikurangi {{ min($report->qty, $now) }} pcs jika disetujui</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('gudang.stock-approvals.missing.approve', $report->id) }}"
                            onsubmit="return confirm('Barang memang tidak ada? Stok {{ $report->sku }} di {{ $report->bin_code }} akan dikurangi.')">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-lg">Setujui, nolkan stok</button>
                        </form>
                        <form method="POST" action="{{ route('gudang.stock-approvals.missing.reject', $report->id) }}" class="flex gap-2">
                            @csrf
                            <input type="text" name="note" maxlength="500" placeholder="Catatan (opsional)"
                                class="w-40 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm placeholder-slate-500">
                            <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Tolak, barang ada</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-slate-500 text-center">Tidak ada laporan yang menunggu.</p>
            @endforelse
        </section>

        @if ($decided->isNotEmpty())
            <section class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-800">
                    <h3 class="font-semibold text-white">Riwayat keputusan terakhir</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-800/50 text-xs text-slate-400">
                            <tr>
                                <th class="text-left py-2 px-4">SKU / rak</th>
                                <th class="text-left py-2 px-4">Keputusan</th>
                                <th class="text-left py-2 px-4">Dilaporkan</th>
                                <th class="text-left py-2 px-4">Diputuskan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($decided as $report)
                                <tr>
                                    <td class="py-2 px-4 font-mono text-white">{{ $report->sku }} <span class="text-slate-400">di</span> {{ $report->bin_code }}</td>
                                    <td class="py-2 px-4 {{ $report->status === 'APPROVED' ? 'text-red-400' : 'text-emerald-400' }}">
                                        {{ $report->status_label }}
                                        @if ($report->decision_note)
                                            <span class="block text-xs text-slate-400">{{ $report->decision_note }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-4 text-xs text-slate-400">{{ $report->reporter?->name ?? '-' }} · {{ $report->created_at->format('d M H:i') }}</td>
                                    <td class="py-2 px-4 text-xs text-slate-400">{{ $report->decider?->name ?? '-' }} · {{ $report->decided_at?->format('d M H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
