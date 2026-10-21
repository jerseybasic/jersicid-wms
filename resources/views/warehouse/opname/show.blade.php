<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('gudang.opname') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Kembali
                </a>
                <div>
                    <h2 class="font-semibold text-xl text-white leading-tight">Opname: {{ $opname->opname_number }}</h2>
                    <p class="text-sm text-slate-400 mt-1">{{ $opname->warehouse->name }} • {{ $opname->created_at->format('d M Y') }}</p>
                </div>
            </div>
            
            <!-- Status Badge -->
            <span class="px-3 py-1 rounded-full text-sm font-bold {{ $opname->status_color }}">
                {{ $opname->status }}
            </span>
        </div>
    </x-slot>

    <div class="space-y-6">
        
        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('error') }}
            </div>
        @endif

        <!-- Action Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm text-slate-400">
                Total Item: <span class="text-white font-bold">{{ $items->total() }}</span> | 
                Terhitung: <span class="text-emerald-400 font-bold">{{ $items->where('physical_qty', '!=', null)->count() }}</span> |
                Belum: <span class="text-amber-400 font-bold">{{ $items->where('physical_qty', null)->count() }}</span>
            </div>
            
            <div class="flex gap-2">
                @if($opname->status === 'DRAFT')
                    <form method="POST" action="{{ route('gudang.opname.start', $opname->id) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg text-sm transition-all">
                            ▶ Mulai Opname
                        </button>
                    </form>
                @endif

                @if($opname->status === 'IN_PROGRESS')
                    <form method="POST" action="{{ route('gudang.opname.update', $opname->id) }}" id="saveForm">
                        @csrf
                        <!-- Hidden inputs will be injected by JS if needed, but we use form inside table -->
                    </form>
                    
                    <button type="submit" form="mainOpnameForm" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-semibold rounded-lg text-sm transition-all">
                        💾 Simpan Sementara
                    </button>

                    <form method="POST" action="{{ route('gudang.opname.complete', $opname->id) }}" onsubmit="return confirm('Yakin ingin menyelesaikan Opname? Stok sistem akan disesuaikan otomatis berdasarkan selisih!')">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-lg text-sm shadow-lg shadow-emerald-500/20 transition-all">
                            ✅ Selesaikan & Adjust Stok
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if($opname->status === 'IN_PROGRESS')
            <x-scan-box storage-key="opname-bin-{{ $opname->id }}"
                hint="Scan lokasi bin, lalu scan setiap barang yang ada di bin itu. Setiap scan menambah 1 stok fisik. Klik Simpan Sementara secara berkala." />
        @endif

        <!-- Form Utama -->
        <form method="POST" action="{{ route('gudang.opname.update', $opname->id) }}" id="mainOpnameForm">
            @csrf
            
            <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                            <tr>
                                <th class="py-3 px-4">SKU / Produk</th>
                                <th class="py-3 px-4">Lokasi Bin</th>
                                <th class="py-3 px-4 text-center">Stok Sistem</th>
                                <th class="py-3 px-4 text-center w-32">Stok Fisik</th>
                                <th class="py-3 px-4 text-center">Selisih</th>
                                <th class="py-3 px-4">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @forelse($items as $index => $item)
                                <tr class="hover:bg-slate-800/30 transition-colors {{ $item->physical_qty !== null && $item->difference !== 0 ? 'bg-red-900/10' : '' }}">
                                    <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                                    
                                    <td class="py-3 px-4">
                                        <div class="font-mono text-cyan-400 font-bold">{{ $item->sku }}</div>
                                        <div class="text-xs text-slate-400">{{ $item->masterSku?->product_name ?? '-' }}</div>
                                    </td>
                                    <td class="py-3 px-4 font-mono text-slate-300">{{ $item->bin_code }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="font-bold text-white bg-slate-800 px-2 py-1 rounded">{{ $item->system_qty }}</span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input type="number" 
                                               name="items[{{ $index }}][physical_qty]" 
                                               value="{{ $item->physical_qty }}" 
                                               min="0"
                                               @if($opname->status === 'COMPLETED') disabled @endif
                                               class="w-full px-2 py-1 bg-slate-800 border border-slate-600 rounded text-white text-center font-bold focus:border-cyan-400 focus:outline-none physical-input"
                                               data-system="{{ $item->system_qty }}"
                                               data-sku="{{ $item->sku }}"
                                               data-bin="{{ strtoupper($item->bin_code) }}"
                                               data-index="{{ $index }}">
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="diff-display font-bold text-slate-500" id="diff-{{ $index }}">
                                            {{ $item->difference !== 0 ? ($item->difference > 0 ? '+' . $item->difference : $item->difference) : '-' }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input type="text" 
                                               name="items[{{ $index }}][notes]" 
                                               value="{{ $item->notes }}" 
                                               @if($opname->status === 'COMPLETED') disabled @endif
                                               placeholder="Catatan..." 
                                               class="w-full px-2 py-1 bg-slate-800 border border-slate-700 rounded text-xs text-white focus:border-cyan-400 focus:outline-none">
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-8 text-slate-500">Tidak ada item dalam opname ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                @if($items->hasPages())
                    <div class="p-4 border-t border-slate-800 bg-slate-800/20">
                        {{ $items->links() }}
                    </div>
                @endif
            </div>
        </form>
    </div>

    {{-- Script Kalkulasi Real-time --}}
    <script>
        // Scan barcode: kode bin memilih lokasi, kode SKU menambah 1 stok fisik di baris yang cocok
        window.addEventListener('scan-code', function (event) {
            const { code, bin, respond } = event.detail;
            const inputs = Array.from(document.querySelectorAll('.physical-input'));

            if (inputs.some(input => input.dataset.bin === code)) {
                return respond({ ok: true, type: 'bin', bin_code: code, message: 'Lokasi ' + code + ' dipilih. Sekarang scan barangnya.' });
            }

            const sameSku = inputs.filter(input => input.dataset.sku === code);
            if (sameSku.length === 0) {
                return respond({ ok: false, message: code + ' tidak ada di daftar opname halaman ini.' });
            }

            const target = bin ? sameSku.find(input => input.dataset.bin === bin) : (sameSku.length === 1 ? sameSku[0] : null);
            if (!target) {
                return respond({ ok: false, message: bin
                    ? code + ' tidak tercatat di bin ' + bin + '. Catat di keterangan atau pindahkan barangnya.'
                    : code + ' ada di beberapa bin. Scan lokasi bin dulu.' });
            }

            target.value = (parseInt(target.value) || 0) + 1;
            target.dispatchEvent(new Event('input'));
            respond({ ok: true, message: code + ' di ' + target.dataset.bin + ': fisik ' + target.value + ' (sistem ' + target.dataset.system + ')' });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.physical-input');
            
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    const index = this.getAttribute('data-index');
                    const systemQty = parseInt(this.getAttribute('data-system')) || 0;
                    const physicalQty = parseInt(this.value) || 0;
                    const diff = physicalQty - systemQty;
                    
                    const diffDisplay = document.getElementById('diff-' + index);
                    
                    if (diff === 0 && this.value !== '') {
                        diffDisplay.textContent = '0';
                        diffDisplay.className = 'diff-display font-bold text-emerald-400';
                        this.parentElement.parentElement.classList.remove('bg-red-900/10');
                    } else if (diff > 0) {
                        diffDisplay.textContent = '+' + diff;
                        diffDisplay.className = 'diff-display font-bold text-emerald-400';
                        this.parentElement.parentElement.classList.remove('bg-red-900/10');
                    } else if (diff < 0) {
                        diffDisplay.textContent = diff;
                        diffDisplay.className = 'diff-display font-bold text-red-400';
                        this.parentElement.parentElement.classList.add('bg-red-900/10');
                    } else {
                        diffDisplay.textContent = '-';
                        diffDisplay.className = 'diff-display font-bold text-slate-500';
                    }
                });
            });
        });
    </script>
</x-app-layout>