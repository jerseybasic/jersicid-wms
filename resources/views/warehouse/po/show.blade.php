<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Detail Purchase Order</h2>
                <p class="text-sm text-slate-400 mt-1 font-mono">{{ $po->po_number }}</p>
            </div>
            <a href="{{ route('po.index') }}" class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors">←
                Kembali</a>
        </div>
    </x-slot>

    @php
        $role = auth()->user()->role;
        $isManager = in_array($role, ['OWNER', 'SUPERVISOR']);
        $canUpdateStage = in_array($role, ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG']);
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">

        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">
                {{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ $errors->first() }}</div>
        @endif

        {{-- Info Card --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Supplier</p>
                <p class="text-lg font-bold text-white">{{ $po->supplier_name }}</p>
                <span
                    class="inline-block mt-2 text-xs px-2 py-1 rounded-full {{ ['SUBLIMASI' => 'bg-purple-500/20 text-purple-400', 'KONVEKSI' => 'bg-pink-500/20 text-pink-400', 'UMUM' => 'bg-slate-500/20 text-slate-400'][$po->supplier_type] ?? 'bg-slate-500/20 text-slate-400' }}">
                    {{ $po->supplier_type }}
                </span>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Status</p>
                <span class="inline-block text-sm font-bold px-3 py-1 rounded-full {{ $po->status_color }}">{{ $po->status_label }}</span>
                @if ($po->approved_at)
                    <p class="text-xs text-slate-500 mt-2">Disetujui {{ $po->approver?->name }}:
                        {{ $po->approved_at->format('d M Y H:i') }}</p>
                @endif
                @if ($po->ready_at)
                    <p class="text-xs text-slate-500 mt-1">Siap diambil: {{ $po->ready_at->format('d M Y H:i') }}</p>
                @endif
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Estimasi Tiba</p>
                <p class="text-lg font-bold text-white">{{ $po->expected_date?->format('d M Y') }}</p>
                <p class="text-xs text-slate-500 mt-2">Dibuat: {{ $po->creator?->name }}
                    ({{ $po->creator?->role }})</p>
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Aksi Tersedia</h3>
            <div class="flex flex-wrap items-center gap-2">

                <a href="{{ route('po.print', $po->id) }}" target="_blank"
                    class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl transition-all">Cetak PO</a>

                @if ($isManager && $po->isEditable())
                    <a href="{{ route('po.edit', $po->id) }}"
                        class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-xl transition-all">Edit PO</a>
                @endif

                @if ($isManager && $po->status === 'DRAFT')
                    <form method="POST" action="{{ route('po.update-status', $po->id) }}" class="inline">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="PENDING">
                        <button type="submit"
                            class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded-xl transition-all">Ajukan untuk Approval</button>
                    </form>
                @endif

                @if ($role === 'OWNER' && $po->status === 'DRAFT')
                    <form method="POST" action="{{ route('po.destroy', $po->id) }}" class="inline"
                        onsubmit="return confirm('Yakin hapus PO ini?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                            class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl transition-all">Hapus PO</button>
                    </form>
                @endif

                @if ($isManager && $po->status === 'PENDING')
                    <form method="POST" action="{{ route('po.update-status', $po->id) }}" class="inline">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="APPROVED">
                        <button type="submit"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-xl transition-all"
                            onclick="return confirm('Approve PO ini?')">Approve PO</button>
                    </form>

                    <div x-data="{ open: false }" class="contents">
                        <button type="button" @click="open = !open" x-show="!open"
                            class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded-xl transition-all">Tolak</button>
                        <form x-show="open" x-cloak method="POST" action="{{ route('po.update-status', $po->id) }}"
                            class="flex flex-wrap items-center gap-2 w-full">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="DRAFT">
                            <input type="text" name="reason" required maxlength="500" placeholder="Alasan ditolak (wajib)"
                                class="flex-1 min-w-[12rem] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-amber-400 focus:outline-none">
                            <button type="submit"
                                class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold rounded-xl transition-all">Kembalikan ke Draft</button>
                            <button type="button" @click="open = false" class="text-sm text-slate-400 hover:text-white">Batal</button>
                        </form>
                    </div>
                @endif

                @if ($canUpdateStage && $po->status === 'APPROVED')
                    <form method="POST" action="{{ route('po.update-status', $po->id) }}" class="inline">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="IN_PRODUCTION">
                        <button type="submit"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold rounded-xl transition-all">Mulai Produksi</button>
                    </form>
                @endif

                @if ($canUpdateStage && $po->status === 'IN_PRODUCTION')
                    <form method="POST" action="{{ route('po.update-status', $po->id) }}" class="flex flex-wrap items-center gap-2">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="READY_FOR_PICKUP">
                        <input type="text" name="production_notes" placeholder="Catatan produksi (opsional)"
                            class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <input type="number" name="defect_count" min="0" placeholder="Jumlah defect"
                            class="w-36 px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <button type="submit"
                            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-xl transition-all">Tandai Siap Diambil</button>
                    </form>
                @endif

                @if (in_array($po->status, ['APPROVED', 'IN_PRODUCTION', 'READY_FOR_PICKUP', 'READY']))
                    <a href="{{ route('gudang.inbound.create', ['po_id' => $po->id]) }}"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-xl transition-all">Terima Barang (Inbound)</a>
                @endif

                @if ($isManager && $po->canTransitionTo('CANCELLED'))
                    <div x-data="{ open: false }" class="contents">
                        <button type="button" @click="open = !open" x-show="!open"
                            class="px-4 py-2 bg-slate-700 hover:bg-red-600 text-white text-sm font-semibold rounded-xl transition-all">Batalkan PO</button>
                        <form x-show="open" x-cloak method="POST" action="{{ route('po.update-status', $po->id) }}"
                            class="flex flex-wrap items-center gap-2 w-full p-3 rounded-xl border border-red-500/40 bg-red-900/10">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="CANCELLED">
                            @if ($po->items->sum('qty_received') > 0)
                                <p class="w-full text-xs text-amber-300">Sebagian barang sudah diterima. Barang itu tetap di stok; hanya sisa pesanan yang dibatalkan.</p>
                            @endif
                            <input type="text" name="reason" maxlength="500" placeholder="Alasan dibatalkan, mis. tidak jadi order"
                                class="flex-1 min-w-[12rem] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-red-400 focus:outline-none">
                            <button type="submit" onclick="return confirm('Batalkan PO {{ $po->po_number }}?')"
                                class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl transition-all">Ya, Batalkan</button>
                            <button type="button" @click="open = false" class="text-sm text-slate-400 hover:text-white">Tutup</button>
                        </form>
                    </div>
                @endif

                @if (in_array($po->status, ['RECEIVED', 'CANCELLED']))
                    <p class="text-sm text-slate-400">PO ini sudah {{ strtolower($po->status_label) }}.</p>
                @endif
            </div>

            {{-- Koreksi status: untuk membetulkan status yang terlanjur salah karena miskom --}}
            @if ($isManager && $po->status !== 'RECEIVED')
                <div x-data="{ open: {{ $errors->has('reason') && old('_correct') ? 'true' : 'false' }} }" class="mt-4 pt-4 border-t border-slate-800">
                    <button type="button" @click="open = !open" class="text-sm text-cyan-400 hover:text-cyan-300">
                        Status salah? <span class="underline">Koreksi status</span>
                    </button>
                    <form x-show="open" x-cloak method="POST" action="{{ route('po.correct-status', $po->id) }}"
                        class="mt-3 flex flex-wrap items-center gap-2">
                        @csrf @method('PATCH')
                        <input type="hidden" name="_correct" value="1">
                        <select name="status" required
                            class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                            <option value="">-- Status yang benar --</option>
                            @foreach (\App\Models\PurchaseOrder::CORRECTABLE_STATUSES as $status)
                                @continue($status === $po->status)
                                <option value="{{ $status }}" @selected(old('status') === $status)>{{ \App\Models\PurchaseOrder::STATUS_LABELS[$status] }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="reason" required maxlength="500" placeholder="Alasan koreksi (wajib), mis. salah klik, produksi belum mulai"
                            class="flex-1 min-w-[12rem] px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <button type="submit" onclick="return confirm('Ubah status PO ini?')"
                            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-xl transition-all">Simpan Status</button>
                        <p class="w-full text-xs text-slate-500">
                            @if ($po->status === 'CANCELLED')
                                PO yang dibatalkan bisa diaktifkan lagi dengan memilih statusnya sekarang.
                            @else
                                Dipakai jika status terakhir keliru. Alasan dicatat di catatan PO dan riwayat aktivitas.
                            @endif
                        </p>
                    </form>
                </div>
            @endif
        </div>

        {{-- Items Table --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800">
                <h3 class="text-lg font-semibold text-white">Item Pesanan</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-800/50 border-b border-slate-700">
                        <tr class="text-xs text-slate-400">
                            <th class="text-left py-3 px-6">SKU</th>
                            <th class="text-left py-3 px-6">Nama Produk</th>
                            <th class="text-left py-3 px-6">Size</th>
                            <th class="text-right py-3 px-6">Qty Order</th>
                            <th class="text-right py-3 px-6">Qty Diterima</th>
                            <th class="text-right py-3 px-6">Harga</th>
                            <th class="text-right py-3 px-6">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach ($po->items as $item)
                            <tr class="hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-6 font-mono text-sm text-cyan-400">{{ $item->sku }}</td>
                                <td class="py-3 px-6 text-sm text-white">{{ $item->masterSku?->product_name ?? '-' }}</td>
                                <td class="py-3 px-6 text-sm text-slate-300">{{ $item->masterSku?->size ?? '-' }}</td>
                                <td class="py-3 px-6 text-sm text-white text-right font-semibold">
                                    {{ number_format($item->qty_ordered) }}</td>
                                <td class="py-3 px-6 text-sm text-emerald-400 text-right font-semibold">
                                    {{ number_format($item->qty_received) }}</td>
                                <td class="py-3 px-6 text-sm text-slate-300 text-right">Rp
                                    {{ number_format($item->price, 0, ',', '.') }}</td>
                                <td class="py-3 px-6 text-sm text-white text-right font-bold">Rp
                                    {{ number_format($item->qty_ordered * $item->price, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-800/50 border-t border-slate-700">
                        <tr>
                            <td colspan="6" class="py-3 px-6 text-right text-sm font-semibold text-white">Grand Total:</td>
                            <td class="py-3 px-6 text-right text-lg font-bold text-cyan-400">Rp
                                {{ number_format($po->total_amount, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Notes --}}
        @if ($po->notes || $po->production_notes || $po->defect_count)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
                <h3 class="text-sm font-semibold text-white">Catatan</h3>
                @if ($po->notes)
                    <div>
                        <p class="text-xs text-slate-400 mb-1">Catatan PO:</p>
                        <p class="text-sm text-slate-300 whitespace-pre-line">{{ $po->notes }}</p>
                    </div>
                @endif
                @if ($po->production_notes)
                    <div>
                        <p class="text-xs text-slate-400 mb-1">Catatan Produksi:</p>
                        <p class="text-sm text-slate-300">{{ $po->production_notes }}</p>
                    </div>
                @endif
                @if ($po->defect_count)
                    <div>
                        <p class="text-xs text-slate-400 mb-1">Barang Defect:</p>
                        <p class="text-sm text-red-400">{{ $po->defect_count }} pcs {{ $po->defect_notes ? '— ' . $po->defect_notes : '' }}</p>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
