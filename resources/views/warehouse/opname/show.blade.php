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
                    <p class="text-sm text-slate-400 mt-1">{{ $opname->scope_label }} • {{ $opname->warehouse?->name }} • {{ $opname->created_at->format('d M Y') }}</p>
                </div>
            </div>
            
            <!-- Status Badge -->
            <span class="px-3 py-1 rounded-full text-sm font-bold {{ $opname->status_color }}">
                {{ $opname->status_label }}
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
                Lokasi: <span class="text-white font-bold">{{ $summary['total'] }}</span> |
                Terhitung: <span class="text-emerald-400 font-bold">{{ $summary['counted'] }}</span> |
                Belum: <span class="text-amber-400 font-bold">{{ $summary['total'] - $summary['counted'] }}</span> |
                Selisih: <span class="{{ $summary['diff'] ? 'text-red-400' : 'text-slate-500' }} font-bold">{{ $summary['diff'] }}</span>
            </div>
            
            <div class="flex gap-2">
                @if($opname->status === 'DRAFT')
                    <form method="POST" action="{{ route('gudang.opname.start', $opname->id) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-on-accent font-semibold rounded-lg text-sm transition-all">
                            ▶ Mulai Opname
                        </button>
                    </form>
                @endif

                @if($opname->status === 'IN_PROGRESS')
                    <form method="POST" action="{{ route('gudang.opname.update', $opname->id) }}" id="saveForm">
                        @csrf
                        <!-- Hidden inputs will be injected by JS if needed, but we use form inside table -->
                    </form>
                    
                    <button type="submit" form="mainOpnameForm" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent font-semibold rounded-lg text-sm transition-all">
                        💾 Simpan Sementara
                    </button>

                    @php $isManager = in_array(auth()->user()->role, \App\Http\Controllers\Warehouse\StockOpnameController::MANAGER_ROLES, true); @endphp
                    <form method="POST" action="{{ route('gudang.opname.complete', $opname->id) }}"
                        onsubmit="return confirm('{{ $isManager ? 'Selesaikan opname? Stok sistem akan disesuaikan sesuai selisih.' : 'Kirim opname untuk disetujui? Stok sistem baru berubah setelah Owner/Supervisor menyetujui.' }}')">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-on-accent font-bold rounded-lg text-sm shadow-lg shadow-emerald-500/20 transition-all">
                            {{ $isManager ? '✅ Selesaikan & Sesuaikan Stok' : '📨 Kirim untuk Disetujui' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if ($opname->status === 'IN_PROGRESS' && $opname->rejection_note)
            <div class="p-4 bg-amber-900/30 border border-amber-500/50 text-amber-300 rounded-xl text-sm">
                <p class="font-semibold">Dikembalikan untuk dihitung ulang</p>
                <p class="mt-1">{{ $opname->rejection_note }}</p>
            </div>
        @endif

        @if ($opname->status === 'SUBMITTED')
            <div class="p-4 bg-blue-900/20 border border-blue-500/40 rounded-xl space-y-3">
                <p class="text-sm text-blue-200">
                    Dikirim {{ $opname->submitter?->name ?? '-' }} pada {{ $opname->submitted_at?->format('d M Y H:i') }}.
                    Stok sistem belum berubah. Periksa baris yang selisih di bawah sebelum memutuskan.
                </p>
                @if (in_array(auth()->user()->role, \App\Http\Controllers\Warehouse\StockOpnameController::MANAGER_ROLES, true))
                    <div class="flex flex-col md:flex-row gap-2">
                        <form method="POST" action="{{ route('gudang.opname.approve', $opname->id) }}"
                            onsubmit="return confirm('Setujui opname ini? Stok sistem akan disesuaikan sesuai selisih.')">
                            @csrf
                            <button type="submit" class="w-full md:w-auto px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-on-accent font-bold rounded-lg text-sm">✅ Setujui & Sesuaikan Stok</button>
                        </form>
                        <form method="POST" action="{{ route('gudang.opname.reject', $opname->id) }}" class="flex-1 flex flex-col sm:flex-row gap-2">
                            @csrf
                            <input type="text" name="reason" required maxlength="500" placeholder="Alasan dikembalikan, mis. rak A-02 dihitung ulang"
                                class="flex-1 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm placeholder-slate-500">
                            <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-lg text-sm">Kembalikan untuk dihitung ulang</button>
                        </form>
                    </div>
                @else
                    <p class="text-xs text-slate-400">Menunggu persetujuan Owner/Supervisor.</p>
                @endif
            </div>
        @endif

        @if ($opname->status === 'COMPLETED' && $opname->approver)
            <p class="text-xs text-slate-400">Disetujui {{ $opname->approver->name }} pada {{ $opname->completed_at?->format('d M Y H:i') }}.</p>
        @endif

        @if($opname->status === 'IN_PROGRESS')
            <x-scan-box storage-key="opname-bin-{{ $opname->id }}"
                hint="Scan lokasi bin, lalu scan setiap barang yang ada di bin itu. Setiap scan menambah 1 stok fisik. Klik Simpan Sementara secara berkala." />
        @endif

        @if ($racksInOpname->count() > 1)
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs text-slate-400">Tampilkan rak:</span>
                <a href="{{ route('gudang.opname.show', $opname->id) }}"
                    class="px-3 py-1.5 rounded-lg text-sm font-semibold {{ $rackFilter === '' ? 'bg-cyan-500 text-slate-900' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' }}">Semua</a>
                @foreach ($racksInOpname as $rack)
                    <a href="{{ route('gudang.opname.show', ['id' => $opname->id, 'rak' => $rack]) }}"
                        class="px-3 py-1.5 rounded-lg text-sm font-semibold {{ $rackFilter === $rack ? 'bg-cyan-500 text-slate-900' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' }}">{{ $rack }}</a>
                @endforeach
            </div>
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
                                               @if($opname->status !== 'IN_PROGRESS') disabled @endif
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
                                               @if($opname->status !== 'IN_PROGRESS') disabled @endif
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

        @if ($opname->status === 'IN_PROGRESS')
            <form method="POST" action="{{ route('gudang.opname.add-item', $opname->id) }}" id="addItemForm"
                class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col sm:flex-row sm:items-end gap-3">
                @csrf
                <div class="flex-1">
                    <p class="text-sm font-semibold text-white">Barang ada di rak tapi tidak ada di daftar?</p>
                    <p class="text-xs text-slate-400">Simpan Sementara dulu, lalu tambahkan di sini dan isi stok fisiknya.</p>
                </div>
                <input type="text" name="sku" id="addItemSku" required placeholder="SKU"
                    class="sm:w-48 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm font-mono uppercase focus:border-cyan-400 focus:outline-none">
                <input type="text" name="bin_code" id="addItemBin" required placeholder="Lokasi, mis. A-01-01"
                    class="sm:w-40 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm font-mono uppercase focus:border-cyan-400 focus:outline-none">
                <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-lg">Tambah barang</button>
            </form>
        @endif
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
                // Siapkan form "Tambah barang" supaya staf cukup klik tombolnya
                const skuField = document.getElementById('addItemSku');
                if (skuField) {
                    skuField.value = code;
                    document.getElementById('addItemBin').value = bin || '';
                }
                return respond({ ok: false, message: code + ' tidak ada di daftar halaman ini. Jika barangnya memang ada di rak, pakai "Tambah barang" di bawah.' });
            }

            const target = bin ? sameSku.find(input => input.dataset.bin === bin) : (sameSku.length === 1 ? sameSku[0] : null);
            if (!target) {
                return respond({ ok: false, message: bin
                    ? code + ' tidak tercatat di bin ' + bin + '. Catat di keterangan atau pindahkan barangnya.'
                    : code + ' ada di beberapa bin. Scan lokasi bin dulu.' });
            }

            if (target.disabled) {
                return respond({ ok: false, message: 'Klik Mulai Opname dulu.' });
            }
            target.value = (parseInt(target.value) || 0) + 1;
            target.dispatchEvent(new Event('input'));
            respond({ ok: true, message: code + ' di ' + target.dataset.bin + ': fisik ' + target.value + ' (sistem ' + target.dataset.system + ')' });
        });

        // Ingatkan jika pindah halaman/rak sebelum hitungan disimpan
        let opnameDirty = false;
        window.addEventListener('beforeunload', function (event) {
            if (opnameDirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.physical-input');
            const mainForm = document.getElementById('mainOpnameForm');
            mainForm.addEventListener('input', () => { opnameDirty = true; });
            mainForm.addEventListener('submit', () => { opnameDirty = false; });
            
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