<x-app-layout>
    @php
        $editing = isset($order);
        $itemsEditable = !$editing || $order->itemsEditable(auth()->user());
        $itemsInProcess = $editing && $order->itemsInProcess();
        $initialItems = old('items', $editing ? $order->items->map(fn ($item) => ['sku' => $item->sku, 'qty' => $item->qty_ordered])->values()->all() : []);
        $value = fn ($field) => old($field, $editing ? $order->{$field} : null);
    @endphp
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ $editing ? route('gudang.outbound.show', $order->id) : route('gudang.outbound') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight truncate">{{ $editing ? 'Ubah Order' : 'Buat Order Baru' }}</h2>
                <p class="text-sm text-slate-400 mt-1 truncate">{!! $editing ? '<span class="font-mono">' . e($order->order_number) . '</span><span class="hidden sm:inline"> · ' . e($order->status_label) . '</span>' : 'Input data pengiriman keluar' !!}</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6" x-data="outboundForm()" x-init="setup(@js($skus), @js($initialItems))">

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl text-sm">{{ session('error') }}</div>
        @endif

        @if($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">
                <ul class="text-sm text-red-400 space-y-1 list-disc list-inside">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $editing ? route('gudang.outbound.update', $order->id) : route('gudang.outbound.store') }}" class="space-y-6">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <!-- Informasi Pengiriman -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-white mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Informasi Pengiriman
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Nama Customer *</label>
                        <input type="text" name="customer_name" required value="{{ $value('customer_name') }}"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">No. HP</label>
                        <input type="text" name="customer_phone" value="{{ $value('customer_phone') }}"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Alamat Pengiriman *</label>
                        <textarea name="shipping_address" rows="2" required
                                  class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all resize-none">{{ $value('shipping_address') }}</textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Kurir</label>
                        <input type="text" name="courier" value="{{ $value('courier') }}" placeholder="JNE / J&T / SiCepat"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Catatan</label>
                        <input type="text" name="notes" value="{{ $value('notes') }}"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                    </div>
                    @if ($editing)
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">No. Resi</label>
                            <input type="text" name="tracking_number" value="{{ $value('tracking_number') }}"
                                   class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white font-mono focus:border-cyan-400 focus:outline-none transition-all"/>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Batas Kirim</label>
                            <input type="datetime-local" name="ship_by" value="{{ old('ship_by', $order->ship_by?->format('Y-m-d\TH:i')) }}"
                                   class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all [color-scheme:dark]"/>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Item Order -->
            @if (!$itemsEditable)
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-white mb-2">Item Order</h3>
                    <p class="text-sm text-amber-400 mb-4">{{ in_array($order->status, \App\Services\OrderItemChange::STATUSES, true)
                        ? 'Order sudah mulai di-picking. Item hanya bisa diganti oleh Owner, Supervisor, atau Admin Gudang.'
                        : 'Item tidak bisa diganti karena paket sudah dikirim. Pakai retur jika barangnya kembali.' }}</p>
                    <div class="divide-y divide-slate-800 text-sm">
                        @foreach ($order->items as $item)
                            <div class="py-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono font-semibold text-white break-all">{{ $item->sku }}</p>
                                    <p class="text-xs text-slate-500">{{ trim(($item->masterSku?->product_name ?? '') . ' ' . ($item->masterSku?->size ?? '')) }}</p>
                                </div>
                                <span class="font-bold text-white shrink-0">{{ $item->qty_ordered }} pcs</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
            <div id="item-order" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        Item Order
                    </h3>
                    <button type="button" @click="addItem()" class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-semibold rounded-xl shadow-lg shadow-cyan-500/25 transition-all text-sm flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Tambah Item
                    </button>
                </div>

                @if ($itemsInProcess)
                    <div class="mb-5 p-4 bg-amber-900/20 border border-amber-500/40 rounded-xl text-sm text-amber-200 space-y-1">
                        <p class="font-semibold text-amber-300">Order ini sudah mulai diproses ({{ $order->status_label }}).</p>
                        <p>Jika item diganti: barang yang masih dipakai tetap di keranjang, barang yang tidak dipakai lagi masuk daftar <span class="font-semibold">Kembalikan ke Rak</span>, item baru ditambahkan ke sesi picking yang sama, dan paket dikemas ulang.</p>
                    </div>
                @endif

                <!-- Summary -->
                <div class="grid grid-cols-2 gap-3 mb-6">
                    <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700/50">
                        <p class="text-xs text-slate-400 mb-1">Total Item</p>
                        <p class="text-2xl font-bold text-cyan-400" x-text="items.length">0</p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700/50">
                        <p class="text-xs text-slate-400 mb-1">Total Qty</p>
                        <p class="text-2xl font-bold text-emerald-400" x-text="totalQty">0</p>
                    </div>
                </div>

                <!-- Items -->
                <div class="space-y-3">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="bg-slate-800/50 border border-slate-700 rounded-xl p-4 relative">
                            <!-- Tombol Hapus -->
                            <button type="button" @click="removeItem(index)" 
                                    x-show="items.length > 1"
                                    class="absolute top-3 right-3 p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-colors"
                                    title="Hapus item">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>

                            <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                                <!-- SKU -->
                                <div class="md:col-span-5">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">SKU *</label>
                                    <select x-model="item.sku" @change="onSkuChange(index)"
                                            class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                                        <option value="">Pilih SKU</option>
                                        <template x-for="s in skus" :key="s.sku">
                                            <option :value="s.sku" :selected="s.sku === item.sku" x-text="s.sku + ' - ' + s.product_name + ' (' + s.size + ')'"></option>
                                        </template>
                                    </select>
                                </div>

                                <!-- Product Info -->
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">Produk</label>
                                    <div class="px-3 py-2.5 bg-slate-900/50 border border-slate-700/50 rounded-lg text-sm h-[42px] flex items-center">
                                        <p class="text-white font-medium truncate" x-text="item.product_name || '-'"></p>
                                    </div>
                                </div>

                                <!-- Qty -->
                                <div class="md:col-span-3">
                                    <label class="block text-xs font-medium text-slate-400 mb-2">Qty *</label>
                                    <input type="number" x-model="item.qty" min="1" required
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Hidden Inputs -->
                <template x-for="(item, index) in items" :key="'hidden-'+index">
                    <div>
                        <input type="hidden" :name="'items['+index+'][sku]'" :value="item.sku">
                        <input type="hidden" :name="'items['+index+'][qty]'" :value="item.qty">
                    </div>
                </template>
            </div>
            @endif

            <!-- Action Buttons -->
            <div class="flex items-center gap-3">
                <a href="{{ $editing ? route('gudang.outbound.show', $order->id) : route('gudang.outbound') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Batal
                </a>
                <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-on-accent font-bold rounded-xl shadow-lg shadow-emerald-500/25 transition-all flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    {{ $editing ? 'Simpan Perubahan' : 'Simpan Order' }}
                </button>
            </div>
        </form>
    </div>

    <script>
        function outboundForm() {
            return {
                items: [],
                skus: [],

                // Bukan init(): Alpine sudah memanggil init() otomatis tanpa argumen, jadi item kosong ikut tertambah
                setup(skus, initial) {
                    this.skus = skus;
                    (initial || []).forEach(row => {
                        this.items.push({ sku: row.sku, qty: row.qty, product_name: '' });
                        this.onSkuChange(this.items.length - 1);
                    });
                    if (!this.items.length) this.addItem();
                },

                addItem() {
                    this.items.push({
                        sku: '',
                        qty: 1,
                        product_name: ''
                    });
                },

                removeItem(index) {
                    if (this.items.length > 1) {
                        this.items.splice(index, 1);
                    }
                },

                onSkuChange(index) {
                    const item = this.items[index];
                    const skuData = this.skus.find(s => s.sku === item.sku);
                    if (skuData) {
                        item.product_name = skuData.product_name + ' (' + skuData.size + ')';
                    } else {
                        item.product_name = '';
                    }
                },

                get totalQty() {
                    return this.items.reduce((sum, item) => sum + parseInt(item.qty || 0), 0);
                }
            };
        }
    </script>
</x-app-layout>