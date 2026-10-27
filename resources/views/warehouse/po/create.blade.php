<x-app-layout>
    @php
        $isEdit = $po->exists;
        $rows = old('items') ?? ($isEdit
            ? $po->items->map(fn ($i) => ['sku' => $i->sku, 'qty' => $i->qty_ordered, 'price' => $i->price])->all()
            : (!empty($prefillRows) ? $prefillRows : [['sku' => '', 'qty' => 1, 'price' => 0]]));
    @endphp
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('po.index') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">{{ $isEdit ? 'Edit Purchase Order ' . $po->po_number : 'Buat Purchase Order Baru' }}</h2>
                <p class="text-sm text-slate-400 mt-1">Tambahkan item yang akan dipesan</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form method="POST" action="{{ $isEdit ? route('po.update', $po->id) : route('po.store') }}" class="space-y-6">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif

                @isset($prefillRows)
                    <div class="p-4 rounded-xl border {{ count($prefillRows) ? 'bg-amber-900/20 border-amber-500/40 text-amber-300' : 'bg-emerald-900/20 border-emerald-500/40 text-emerald-300' }} text-sm">
                        @if (count($prefillRows))
                            {{ count($prefillRows) }} {{ $prefillLabel }}
                        @else
                            {{ $prefillEmpty }}
                        @endif
                    </div>
                @endisset

                @if($errors->any())
                    <div class="p-4 bg-red-900/30 border border-red-500/50 rounded-xl">
                        <ul class="text-sm text-red-400 space-y-1">
                            @foreach($errors->all() as $error)
                                <li>• {{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <!-- Info PO -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Supplier -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Supplier *</label>
                        <select name="supplier_id" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="">-- Pilih Supplier --</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" {{ old('supplier_id', $po->supplier_id) == $supplier->id ? 'selected' : '' }}>
                                    {{ $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Tipe Supplier -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Tipe Supplier *</label>
                        <select name="supplier_type" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all">
                            <option value="UMUM" {{ old('supplier_type', $po->supplier_type ?? 'UMUM') == 'UMUM' ? 'selected' : '' }}>Umum</option>
                            <option value="SUBLIMASI" {{ old('supplier_type', $po->supplier_type ?? 'UMUM') == 'SUBLIMASI' ? 'selected' : '' }}>Sublimasi</option>
                            <option value="KONVEKSI" {{ old('supplier_type', $po->supplier_type ?? 'UMUM') == 'KONVEKSI' ? 'selected' : '' }}>Konveksi</option>
                        </select>
                        <p class="text-xs text-slate-500 mt-1">PO Sublimasi/Konveksi terlihat tim terkait</p>
                    </div>

                    <!-- Tanggal Estimasi Tiba -->
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Tanggal Estimasi Tiba *</label>
                        <input type="date" name="expected_date" value="{{ old('expected_date', $po->expected_date?->format('Y-m-d')) }}" required 
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                </div>

                <!-- Catatan -->
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Catatan</label>
                    <textarea name="notes" rows="2" placeholder="Catatan tambahan (opsional)"
                              class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all">{{ old('notes', $po->notes ?? (!empty($prefillRows) ? $prefillNotes : '')) }}</textarea>
                </div>

                <!-- Items Section -->
                <div class="border-t border-slate-800 pt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            Item Pesanan
                        </h3>
                        <button type="button" id="add-item" class="px-4 py-2 mt-1 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            Tambah Item
                        </button>
                    </div>

                    <!-- Header Kolom -->
                    <div class="hidden md:grid grid-cols-12 gap-3 mb-2 px-1">
                        <div class="col-span-6 text-xs text-slate-400 font-medium">SKU / Produk</div>
                        <div class="col-span-2 text-xs text-slate-400 font-medium text-center">Qty</div>
                        <div class="col-span-3 text-xs text-slate-400 font-medium text-right">Harga Satuan</div>
                        <div class="col-span-1"></div>
                    </div>

                    <datalist id="sku-options">
                        @foreach($skus as $sku)
                            <option value="{{ $sku->sku }}" data-price="{{ $sku->cost_price ?: $sku->price }}">{{ $sku->product_name }} ({{ $sku->size }})</option>
                        @endforeach
                    </datalist>

                    <div id="items-container" class="space-y-3">
                        @foreach ($rows as $index => $row)
                        <div class="grid grid-cols-12 gap-3 item-row bg-slate-800/30 p-3 rounded-xl border border-slate-700/50">
                            <div class="col-span-12 md:col-span-6">
                                {{-- Bisa diketik: cari kode SKU, nama produk, atau ukuran --}}
                                <input type="text" name="items[{{ $index }}][sku]" required list="sku-options" autocomplete="off"
                                       value="{{ $row['sku'] ?? '' }}" placeholder="Ketik SKU atau nama produk..."
                                       class="sku-input w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm font-mono uppercase focus:border-cyan-400 focus:outline-none transition-all"/>
                                <p class="sku-label text-xs text-slate-400 mt-1 truncate"></p>
                                @isset($row['note'])
                                    <p class="text-xs text-red-400 mt-1">{{ $row['note'] }}</p>
                                @endisset
                            </div>
                            <div class="col-span-6 md:col-span-2">
                                <input type="number" name="items[{{ $index }}][qty]" placeholder="Qty" required min="1" value="{{ $row['qty'] ?? 1 }}"
                                       class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div class="col-span-6 md:col-span-3">
                                <input type="number" name="items[{{ $index }}][price]" placeholder="Harga" required min="0" value="{{ $row['price'] ?? 0 }}"
                                       class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div class="col-span-12 md:col-span-1 flex items-center justify-center">
                                <button type="button" class="text-red-400 hover:text-red-300 hover:bg-red-500/10 p-2 rounded-lg transition-colors remove-btn" style="{{ $loop->first ? 'display:none;' : '' }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <!-- Total Estimasi -->
                    <div class="mt-4 p-4 bg-slate-800/50 rounded-xl border border-slate-700/50 flex items-center justify-between">
                        <span class="text-sm text-slate-400">Total Estimasi:</span>
                        <span class="text-lg font-bold text-emerald-400" id="total-estimate">Rp 0</span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3 pt-4 border-t border-slate-800">
                    <a href="{{ route('po.index') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                    <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Purchase Order' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let itemCount = {{ max(array_keys($rows)) + 1 }};

        // Tambah Item
        document.getElementById('add-item').addEventListener('click', function() {
            const container = document.getElementById('items-container');
            const firstRow = container.querySelector('.item-row').cloneNode(true);
            
            // Reset values
            firstRow.querySelectorAll('input').forEach(el => {
                if (el.name.includes('[sku]')) el.value = '';
                else if (el.name.includes('qty')) el.value = '1';
                else if (el.name.includes('price')) el.value = '0';
            });
            firstRow.querySelector('.sku-label').textContent = '';
            firstRow.querySelectorAll('.text-red-400').forEach(el => el.remove());
            
            // Update name index
            firstRow.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/\[\d+\]/, `[${itemCount}]`);
            });
            
            // Show remove button
            firstRow.querySelector('.remove-btn').style.display = 'block';
            container.appendChild(firstRow);
            itemCount++;
            updateTotal();
        });

        // Hapus Item
        document.addEventListener('click', function(e) {
            const removeBtn = e.target.closest('.remove-btn');
            if (removeBtn) {
                removeBtn.closest('.item-row').remove();
                updateTotal();
            }
        });

        // Data SKU untuk kolom yang bisa diketik: nama produk dan harga modal per kode SKU
        const skuData = @js($skus->mapWithKeys(fn ($sku) => [$sku->sku => ['name' => $sku->product_name . ' (' . $sku->size . ')', 'price' => $sku->cost_price ?: $sku->price]]));

        // Saat SKU dipilih/diketik: tampilkan nama produk, isi harga, dan tolak kode yang tidak ada
        function checkSku(input, fillPrice) {
            const code = input.value.trim().toUpperCase();
            input.value = code;
            const sku = skuData[code];
            const row = input.closest('.item-row');
            row.querySelector('.sku-label').textContent = sku ? sku.name : (code ? 'SKU tidak ditemukan, pilih dari daftar' : '');
            row.querySelector('.sku-label').classList.toggle('text-amber-400', !sku && code !== '');
            input.setCustomValidity(sku || !code ? '' : 'SKU tidak ditemukan. Pilih dari daftar.');
            const priceInput = row.querySelector('[name*="[price]"]');
            if (sku && fillPrice && priceInput && sku.price > 0) {
                priceInput.value = sku.price;
                updateTotal();
            }
        }

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('sku-input')) {
                checkSku(e.target, true);
            }
        });
        document.querySelectorAll('.sku-input').forEach(input => checkSku(input, false));

        // Update qty/price untuk hitung total
        document.addEventListener('input', function(e) {
            if (e.target.name.includes('qty') || e.target.name.includes('price')) {
                updateTotal();
            }
        });

        // Hitung total estimasi
        document.addEventListener('DOMContentLoaded', updateTotal);
        function updateTotal() {
            let total = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const qty = parseInt(row.querySelector('[name*="[qty]"]')?.value || 0);
                const price = parseInt(row.querySelector('[name*="[price]"]')?.value || 0);
                total += qty * price;
            });
            document.getElementById('total-estimate').textContent = 'Rp ' + total.toLocaleString('id-ID');
        }
    </script>
</x-app-layout>