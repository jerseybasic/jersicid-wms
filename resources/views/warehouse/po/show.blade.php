<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Detail Purchase Order</h2>
                <p class="text-sm text-slate-400 mt-1 font-mono">{{ $order->po_number }}</p>
            </div>
            <a href="{{ route('po.index') }}" class="text-sm text-cyan-400 hover:text-cyan-300 transition-colors">←
                Kembali</a>
        </div>
    </x-slot>

    <div class="max-w-6xl mx-auto space-y-6">

        @if (session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl">
                {{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl">{{ session('error') }}</div>
        @endif

        {{-- Info Card --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Supplier</p>
                <p class="text-lg font-bold text-white">{{ $order->supplier_name }}</p>
                <span
                    class="inline-block mt-2 text-xs px-2 py-1 rounded-full {{ ['SUBLIMASI' => 'bg-purple-500/20 text-purple-400', 'KONVEKSI' => 'bg-pink-500/20 text-pink-400', 'UMUM' => 'bg-slate-500/20 text-slate-400'][$order->supplier_type] ?? 'bg-slate-500/20 text-slate-400' }}">
                    {{ $order->supplier_type }}
                </span>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Status</p>
                <span class="text-lg font-bold {{ $order->status_color }}">{{ $order->status }}</span>
                @if ($order->approved_at)
                    <p class="text-xs text-slate-500 mt-2">Approved: {{ $order->approved_at->format('d M Y H:i') }}</p>
                @endif
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Estimasi Tiba</p>
                <p class="text-lg font-bold text-white">{{ $order->expected_date->format('d M Y') }}</p>
                <p class="text-xs text-slate-500 mt-2">Dibuat: {{ $order->creator?->name }}
                    ({{ $order->creator?->role }})</p>
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Aksi Tersedia</h3>
            <div class="flex flex-wrap gap-2">

                {{-- Edit & Delete: OWNER, SUPERVISOR, STAFF_GUDANG (jika creator & status DRAFT/PENDING) --}}
                @if (in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR', 'STAFF_GUDANG']) &&
                        $order->canEditBy(auth()->user()) &&
                        in_array($order->status, ['DRAFT', 'PENDING']))
                    <a href="{{ route('po.edit', $order->id) }}"
                        class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-xl transition-all">✏️
                        Edit PO</a>
                    <form method="POST" action="{{ route('po.destroy', $order->id) }}" class="inline"
                        onsubmit="return confirm('Yakin hapus PO ini?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                            class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-sm font-semibold rounded-xl transition-all">🗑️
                            Hapus PO</button>
                    </form>
                @endif

                {{-- Approve: OWNER & SUPERVISOR --}}
                @if (auth()->user()->canApproveBy($order) && $order->status === 'PENDING')
                    <form method="POST" action="{{ route('po.approve', $order->id) }}" class="inline">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-xl transition-all"
                            onclick="return confirm('Approve PO ini?')">✅ Approve PO</button>
                    </form>
                @endif

                {{-- Update Stage: SUBLIM/KONVEKSI/OWNER/SUPERVISOR --}}
                @if ($order->canUpdateStageBy(auth()->user()))
                    @if ($order->status === 'APPROVED')
                        <form method="POST" action="{{ route('po.update-stage', $order->id) }}" class="inline">
                            @csrf
                            <input type="hidden" name="status" value="IN_PRODUCTION">
                            <button type="submit"
                                class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold rounded-xl transition-all">🏭
                                Mulai Produksi</button>
                        </form>
                    @elseif($order->status === 'IN_PRODUCTION')
                        <form method="POST" action="{{ route('po.update-stage', $order->id) }}" class="inline">
                            @csrf
                            <input type="hidden" name="status" value="READY">
                            <input type="text" name="production_notes" placeholder="Catatan produksi (opsional)"
                                class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm mr-2 focus:border-cyan-400 focus:outline-none">
                            <button type="submit"
                                class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-xl transition-all">📦
                                Tandai Ready</button>
                        </form>
                    @endif
                @endif

                {{-- Mark Received: OWNER/SUPERVISOR/STAFF_GUDANG --}}
                @if (in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR', 'STAFF_GUDANG']) && $order->status === 'READY')
                    <form method="POST" action="{{ route('po.received', $order->id) }}" class="inline">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-xl transition-all"
                            onclick="return confirm('Tandai PO sebagai DITERIMA? Stok akan otomatis ditambahkan.')">📥
                            Terima Barang</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Items Table --}}
        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800">
                <h3 class="text-lg font-semibold text-white">Item Pesanan</h3>
            </div>
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
                    @php $grandTotal = 0; @endphp
                    @foreach ($order->items as $item)
                        @php
                            $total = $item->qty_ordered * $item->price;
                            $grandTotal += $total;
                        @endphp
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-6 font-mono text-sm text-cyan-400">{{ $item->sku }}</td>
                            <td class="py-3 px-6 text-sm text-white">{{ $item->masterSku?->product_name ?? '-' }}</td>
                            <td class="py-3 px-6 text-sm text-slate-300">{{ $item->masterSku?->size ?? '-' }}</td>
                            <td class="py-3 px-6 text-sm text-white text-right font-semibold">
                                {{ number_format($item->qty_ordered) }}</td>
                            <td class="py-3 px-6 text-sm text-emerald-400 text-right font-semibold">
                                {{ number_format($item->qty_received) }}</td>
                            <td class="py-3 px-6 text-sm text-slate-300 text-right">Rp
                                {{ number_format($item->price) }}</td>
                            <td class="py-3 px-6 text-sm text-white text-right font-bold">Rp
                                {{ number_format($total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-800/50 border-t border-slate-700">
                    <tr>
                        <td colspan="6" class="py-3 px-6 text-right text-sm font-semibold text-white">Grand Total:
                        </td>
                        <td class="py-3 px-6 text-right text-lg font-bold text-cyan-400">Rp
                            {{ number_format($grandTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Notes --}}
        @if ($order->notes || $order->production_notes)
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-white mb-3">Catatan</h3>
                @if ($order->notes)
                    <div class="mb-3">
                        <p class="text-xs text-slate-400 mb-1">Catatan PO:</p>
                        <p class="text-sm text-slate-300">{{ $order->notes }}</p>
                    </div>
                @endif
                @if ($order->production_notes)
                    <div>
                        <p class="text-xs text-slate-400 mb-1">Catatan Produksi (SUBLIM/KONVEKSI):</p>
                        <p class="text-sm text-slate-300">{{ $order->production_notes }}</p>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
