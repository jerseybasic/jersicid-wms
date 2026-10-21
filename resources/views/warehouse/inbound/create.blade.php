<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('gudang.inbound') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Form Penerimaan Barang</h2>
                <p class="text-sm text-slate-400 mt-1">Input barang yang diterima dari supplier</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-6" x-data="inboundForm()" x-init="init(@js($skus), @js($racks), @js($poData), @js($suggestedBins), @js((string) old('po_id', request('po_id'))), @js((string) old('supplier_name')))"
        @scan-code.window="onScan($event.detail)">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 rounded-xl">
                <ul class="text-sm text-red-400 space-y-1">
                    @foreach($errors->all() as $error)
                        <li class="flex items-center gap-2">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ $error }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('gudang.inbound.store') }}">
            @csrf

            <!-- Informasi Penerimaan -->
            <div class="mb-2 bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Informasi Penerimaan
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            No. Surat Jalan <span class="text-red-400">*</span>
                        </label>
                        <input type="text" name="receipt_number" value="{{ old('receipt_number') }}" required
                               placeholder="Contoh: JJ001"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all font-mono"/>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Nama Supplier <span class="text-red-400">*</span>
                        </label>
                        <input type="text" name="supplier_name" x-model="supplierName" required
                               placeholder="Nama supplier"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Tanggal Terima <span class="text-red-400">*</span>
                        </label>
                        <input type="date" name="received_date" value="{{ old('received_date', date('Y-m-d')) }}" required
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">PO Terkait</label>
                        <select name="po_id" x-model="poId" @change="selectPo()" class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="">-- Tidak Ada --</option>
                            @if(isset($purchaseOrders))
                                @foreach($purchaseOrders as $po)
                                    <option value="{{ $po->id }}">
                                        {{ $po->po_number }} - {{ $po->supplier_name }}
                                    </option>
                                @endforeach
                            @endif
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">
                            Gudang <span class="text-red-400">*</span>
                        </label>
                        <select name="warehouse_id" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="">Pilih Gudang</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" {{ old('warehouse_id') == $warehouse->id ? 'selected' : '' }}>
                                    {{ $warehouse->name }} ({{ $warehouse->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <!-- Item Barang Diterima -->
            <div class="mb-2 bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        Item Barang Diterima
                    </h3>
                    <button type="button" @click="addItem()" class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-semibold rounded-xl shadow-lg shadow-cyan-500/25 transition-all text-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Tambah Item
                    </button>
                </div>

                <div class="mb-6">
                    <x-scan-box storage-key="inbound-bin"
                        hint="Scan lokasi rak tujuan, lalu scan setiap barang yang diterima. Setiap scan menambah 1 qty diterima." />
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700/50">
                        <p class="text-xs text-slate-400 mb-1">Total Item</p>
                        <p class="text-2xl font-bold text-cyan-400" x-text="items.length">0</p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700/50">
                        <p class="text-xs text-slate-400 mb-1">Total Qty Diterima</p>
                        <p class="text-2xl font-bold text-emerald-400" x-text="totalQty">0</p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700/50">
                        <p class="text-xs text-slate-400 mb-1">Total Qty Expected</p>
                        <p class="text-2xl font-bold text-blue-400" x-text="totalExpected">0</p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700/50">
                        <p class="text-xs text-slate-400 mb-1">Total Qty Rejected</p>
                        <p class="text-2xl font-bold text-red-400" x-text="totalRejected">0</p>
                    </div>
                </div>

                <!-- Items -->
                <div class="space-y-4">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="bg-slate-800/50 border border-slate-700 rounded-xl p-5 relative">
                            <!-- Tombol Hapus - Posisi Benar di pojok kanan atas -->
                            <button type="button" @click="removeItem(index)" 
                                    x-show="items.length > 1"
                                    class="absolute top-3 right-3 p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-colors z-10"
                                    title="Hapus item">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>

                            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                                <!-- SKU -->
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">SKU *</label>
                                    <select x-model="item.sku" @change="onSkuChange(index)" 
                                            class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                                        <option value="">Pilih SKU</option>
                                        <template x-for="sku in skus" :key="sku.sku">
                                            <option :value="sku.sku" x-text="sku.sku + ' - ' + sku.product_name"></option>
                                        </template>
                                    </select>
                                </div>

                                <!-- Product Info -->
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">Produk</label>
                                    <div class="px-3 py-2.5 bg-slate-900/50 border border-slate-700/50 rounded-lg text-sm h-[42px] flex items-center">
                                        <div>
                                            <p class="text-white font-medium" x-text="item.product_name || '-'"></p>
                                            <p class="text-xs text-slate-500" x-text="'Size: ' + (item.size || '-')"></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bin Code -->
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">Lokasi Bin * <span class="text-slate-500 font-normal">(RAK-BARIS-SLOT)</span></label>
                                    <input type="text" x-model="item.bin_code" 
                                           placeholder="A-01-01"
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm font-mono placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                                </div>

                                <!-- Qty Expected -->
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-slate-400 mb-2" x-text="poId ? 'Sisa PO' : 'Order'">Order</label>
                                    <input type="number" x-model="item.qty_expected" min="0"
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                                </div>

                                <!-- Qty Received -->
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-emerald-400 mb-2">Diterima *</label>
                                    <input type="number" x-model="item.qty_received" required min="0"
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-emerald-500/30 rounded-lg text-white text-sm focus:border-emerald-400 focus:outline-none transition-all"/>
                                </div>

                                <!-- Qty Rejected -->
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-red-400 mb-2">Reject</label>
                                    <input type="number" x-model="item.qty_rejected" min="0"
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-red-500/30 rounded-lg text-white text-sm focus:border-red-400 focus:outline-none transition-all"/>
                                </div>

                                <!-- Batch No -->
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">No. Batch</label>
                                    <input type="text" x-model="item.batch_no" 
                                           placeholder="Opsional"
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm font-mono placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Hidden Inputs -->
                <template x-for="(item, index) in submitItems" :key="'hidden-'+index">
                    <div>
                        <input type="hidden" :name="'items['+index+'][sku]'" :value="item.sku">
                        <input type="hidden" :name="'items['+index+'][qty_expected]'" :value="item.qty_expected">
                        <input type="hidden" :name="'items['+index+'][qty_received]'" :value="item.qty_received">
                        <input type="hidden" :name="'items['+index+'][qty_rejected]'" :value="item.qty_rejected">
                        <input type="hidden" :name="'items['+index+'][bin_code]'" :value="item.bin_code">
                        <input type="hidden" :name="'items['+index+'][batch_no]'" :value="item.batch_no">
                    </div>
                </template>
            </div>

            <!-- Catatan Penerimaan -->
            <div class="mb-2 bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Catatan Penerimaan
                </h3>
                <textarea name="notes" rows="3" placeholder="Catatan tambahan (opsional)..."
                          class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all">{{ old('notes') }}</textarea>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-3">
                <a href="{{ route('gudang.inbound') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Batal
                </a>
                <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-white font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Proses Penerimaan Barang
                </button>
            </div>
        </form>
    </div>

    <script>
        function inboundForm() {
            return {
                items: [],
                skus: [],
                racks: [],
                poData: {},
                suggestedBins: {},
                poId: '',
                supplierName: '',

                init(skus, racks, poData, suggestedBins, poId, supplierName) {
                    this.skus = skus;
                    this.racks = racks;
                    this.poData = poData || {};
                    this.suggestedBins = suggestedBins || {};
                    this.supplierName = supplierName || '';
                    this.poId = poId && this.poData[poId] ? poId : '';
                    if (this.poId) {
                        this.selectPo();
                    } else {
                        this.addItem();
                    }
                },

                blankItem(overrides = {}) {
                    return Object.assign({
                        sku: '',
                        qty_expected: 0,
                        qty_received: 0,
                        qty_rejected: 0,
                        bin_code: '',
                        batch_no: '',
                        product_name: '',
                        size: '',
                        price: 0
                    }, overrides);
                },

                addItem() {
                    this.items.push(this.blankItem({ qty_expected: 1 }));
                },

                removeItem(index) {
                    if (this.items.length > 1) {
                        this.items.splice(index, 1);
                    }
                },

                // Pilih PO: isi supplier dan semua item yang belum diterima penuh
                selectPo() {
                    const po = this.poData[this.poId];
                    if (!po) {
                        this.items = [];
                        this.addItem();
                        return;
                    }
                    this.supplierName = po.supplier_name;
                    this.items = po.items.map((item) => this.blankItem({
                        sku: item.sku,
                        qty_expected: item.remaining,
                        bin_code: item.bin_code,
                        product_name: item.product_name,
                        size: item.size,
                        price: item.price
                    }));
                    if (this.items.length === 0) {
                        this.addItem();
                    }
                },

                onSkuChange(index) {
                    const item = this.items[index];
                    const skuData = this.skus.find(s => s.sku === item.sku);
                    if (skuData) {
                        item.product_name = skuData.product_name;
                        item.size = skuData.size;
                        item.price = skuData.price;
                        if (!item.bin_code && this.suggestedBins[item.sku]) {
                            item.bin_code = this.suggestedBins[item.sku];
                        }
                    } else {
                        item.product_name = '';
                        item.size = '';
                        item.price = 0;
                    }
                },

                rackCodeFromBin(code) {
                    const bin = code.startsWith('RAK-') ? code.slice(4) : code;
                    return bin.split('-')[0];
                },

                onScan({ code, bin, respond }) {
                    const sku = this.skus.find(s => s.sku === code);

                    if (!sku) {
                        if (this.racks.some(r => r.code === this.rackCodeFromBin(code))) {
                            return respond({ ok: true, type: 'bin', bin_code: code, message: 'Lokasi ' + code + ' dipilih. Sekarang scan barangnya.' });
                        }
                        return respond({ ok: false, message: 'Kode ' + code + ' tidak dikenal sebagai SKU maupun lokasi rak.' });
                    }

                    if (this.poId && !this.items.some(i => i.sku === code)) {
                        return respond({ ok: false, message: code + ' tidak ada di PO ini. Pisahkan barangnya.' });
                    }

                    let row = this.items.find(i => i.sku === code && (!bin || !i.bin_code || i.bin_code === bin));
                    if (!row) {
                        row = this.items.find(i => i.sku === '');
                        const fields = { sku: code, product_name: sku.product_name, size: sku.size, price: sku.price, bin_code: bin || this.suggestedBins[code] || '' };
                        if (row) {
                            Object.assign(row, fields);
                        } else {
                            this.items.push(this.blankItem(fields));
                            row = this.items[this.items.length - 1];
                        }
                    }

                    if (!row.bin_code) {
                        row.bin_code = bin || this.suggestedBins[code] || '';
                    }
                    row.qty_received = parseInt(row.qty_received || 0) + 1;

                    const expected = this.poId ? parseInt(row.qty_expected || 0) : 0;
                    let message = code + ': ' + row.qty_received + (expected ? '/' + expected : '') + ' pcs di ' + (row.bin_code || '(bin belum diisi)');
                    if (expected && row.qty_received > expected) {
                        message += '. Melebihi jumlah PO, cek lagi.';
                    }
                    respond({ ok: true, message: message });
                },

                // Hanya item yang benar-benar diterima yang dikirim
                get submitItems() {
                    return this.items.filter(item => item.sku && parseInt(item.qty_received || 0) > 0);
                },

                get totalQty() {
                    return this.items.reduce((sum, item) => sum + parseInt(item.qty_received || 0), 0);
                },

                get totalExpected() {
                    return this.items.reduce((sum, item) => sum + parseInt(item.qty_expected || 0), 0);
                },

                get totalRejected() {
                    return this.items.reduce((sum, item) => sum + parseInt(item.qty_rejected || 0), 0);
                }
            };
        }
    </script>
</x-app-layout>