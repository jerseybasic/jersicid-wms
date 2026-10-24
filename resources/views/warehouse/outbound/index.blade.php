<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-white">Manajemen Outbound</h2>
                <p class="text-sm text-slate-400 mt-1">Kelola order, picking, dan packing</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if(session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-slate-400">Total Order</p>
                <p class="text-xl font-bold text-white">{{ $stats['total'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-amber-400">Pending</p>
                <p class="text-xl font-bold text-white">{{ $stats['pending'] }}</p>
            </div>
            <a href="{{ route('gudang.outbound', ['status' => 'WAITING_STOCK']) }}" class="bg-slate-900 border {{ $stats['waiting'] ? 'border-orange-500/50' : 'border-slate-800' }} rounded-lg p-4 hover:bg-slate-800/60">
                <p class="text-xs text-orange-400">Menunggu stok</p>
                <p class="text-xl font-bold text-white">{{ $stats['waiting'] }}</p>
            </a>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-purple-400">Picking</p>
                <p class="text-xl font-bold text-white">{{ $stats['picking'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-cyan-400">Packing</p>
                <p class="text-xl font-bold text-white">{{ $stats['packing'] }}</p>
            </div>
        </div>

        @if ($needs->isNotEmpty())
            {{-- Kekurangan barang untuk order yang menunggu stok --}}
            <div class="bg-slate-900 border border-orange-500/40 rounded-lg p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-orange-300">Barang yang kurang untuk order menunggu stok</p>
                        <p class="text-xs text-slate-400">Order masuk antrean picking otomatis begitu barangnya diterima di Inbound.</p>
                    </div>
                    @if (in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR'], true) && $needs->sum('short') > 0)
                        <a href="{{ route('po.create', ['dari' => 'kebutuhan-order']) }}" class="px-4 py-2 bg-orange-500 hover:bg-orange-400 text-slate-900 text-sm font-bold rounded-lg">Buat PO untuk kekurangan</a>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-slate-400 text-left">
                            <tr><th class="py-1 pr-2">SKU</th><th class="py-1 px-1.5 text-right" title="Jumlah yang dipesan order menunggu stok">Butuh</th><th class="py-1 px-1.5 text-right" title="Stok yang belum dijatah ke order lain">Ada</th><th class="py-1 px-1.5 text-right" title="Sudah dipesan di PO yang belum diterima">Di PO</th><th class="py-1 pl-1.5 text-right">Kurang</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            @foreach ($needs as $need)
                                <tr>
                                    <td class="py-1.5 pr-2"><span class="font-mono text-white break-all">{{ $need['sku'] }}</span><span class="block text-xs text-slate-500">{{ $need['orders'] }} order</span></td>
                                    <td class="py-1.5 px-1.5 text-right text-slate-300">{{ $need['need'] }}</td>
                                    <td class="py-1.5 px-1.5 text-right text-slate-300">{{ $need['available'] }}</td>
                                    <td class="py-1.5 px-1.5 text-right text-slate-300">{{ $need['incoming'] }}</td>
                                    <td class="py-1.5 pl-1.5 text-right font-bold {{ $need['short'] ? 'text-orange-300' : 'text-emerald-400' }}">{{ $need['short'] ?: 'cukup' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- Filter & Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-lg shadow-xl overflow-hidden" x-data="bulkOrders(@js($orders->pluck('status', 'id')))">
            <div class="p-4 border-b border-slate-800 flex flex-wrap gap-3">
                <form method="GET" class="flex flex-wrap gap-2 flex-1">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari No. Order / Customer..." class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none flex-1 min-w-[200px]"/>
                    <select name="status" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <option value="">Semua Status</option>
                        <option value="PENDING" {{ request('status') == 'PENDING' ? 'selected' : '' }}>Pending</option>
                        <option value="WAITING_STOCK" {{ request('status') == 'WAITING_STOCK' ? 'selected' : '' }}>Menunggu stok</option>
                        <option value="CONFIRMED" {{ request('status') == 'CONFIRMED' ? 'selected' : '' }}>Confirmed</option>
                        <option value="PICKING" {{ request('status') == 'PICKING' ? 'selected' : '' }}>Picking</option>
                        <option value="PACKING" {{ request('status') == 'PACKING' ? 'selected' : '' }}>Packing</option>
                        <option value="READY_TO_SHIP" {{ request('status') == 'READY_TO_SHIP' ? 'selected' : '' }}>Siap Kirim</option>
                        <option value="SHIPPED" {{ request('status') == 'SHIPPED' ? 'selected' : '' }}>Shipped</option>
                    </select>
                    <select name="batas" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <option value="">Semua batas kirim</option>
                        <option value="terlambat" @selected(request('batas') === 'terlambat')>Terlambat</option>
                        <option value="hari-ini" @selected(request('batas') === 'hari-ini')>Kirim hari ini</option>
                    </select>
                    <select name="per_page" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm" title="Order per halaman">
                        @foreach ([15, 50, 100] as $size)
                            <option value="{{ $size }}" @selected((int) request('per_page', 15) === $size)>{{ $size }} / halaman</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg text-sm font-medium">Filter</button>
                    <a href="{{ route('gudang.outbound') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-sm font-medium border border-slate-700">Reset</a>
                    @if ($stats['ready'] > 0)
                        <a href="{{ route('gudang.label.shipping-bulk') }}" target="_blank" class="px-4 py-2 ml-2 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg transition-all text-sm flex items-center gap-2 border border-slate-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            Cetak {{ $stats['ready'] }} Label Siap Kirim
                        </a>
                    @endif
                    <a href="{{ route('gudang.outbound.import') }}" class="px-4 py-2 ml-2 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-lg transition-all text-sm flex items-center gap-2 border border-slate-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Import BigSeller
                    </a>
                    <a href="{{ route('gudang.outbound.create') }}" class="px-4 py-2 ml-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 text-slate-900 font-semibold rounded-lg shadow-lg shadow-cyan-500/25 transition-all text-sm flex items-center gap-2 border-2 border-slate-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        Buat Order
                    </a>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="py-3 pl-4 w-8">
                                <input type="checkbox" :checked="allChecked" @change="toggleAll($event.target.checked)" title="Pilih semua di halaman ini"
                                    class="rounded border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500">
                            </th>
                            <th class="text-left py-3 px-4">No. Order</th>
                            <th class="text-left py-3 px-4">Customer</th>
                            <th class="text-left py-3 px-4">Items</th>
                            <th class="text-left py-3 px-4">Status</th>
                            <th class="text-left py-3 px-4">Tanggal</th>
                            <th class="text-right py-3 px-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($orders as $order)
                        <tr class="hover:bg-slate-800/30 transition-colors" :class="selected.includes(@js($order->id)) && 'bg-cyan-500/5'">
                            <td class="py-3 pl-4">
                                <input type="checkbox" value="{{ $order->id }}" x-model="selected" aria-label="Pilih {{ $order->order_number }}"
                                    class="rounded border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500">
                            </td>
                            <td class="py-3 px-4">
                                <a href="{{ route('gudang.outbound.show', $order->id) }}" class="text-sm font-mono font-bold text-cyan-400 hover:underline">{{ $order->order_number }}</a>
                                @if($order->tracking_number)
                                    <p class="text-[10px] text-slate-500">Resi: {{ $order->tracking_number }}</p>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-sm text-white">{{ $order->customer_name }}</p>
                                <p class="text-xs text-slate-500">{{ $order->courier ?? '-' }}</p>
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-300">{{ $order->items->count() }} item</td>
                            <td class="py-3 px-4">
                                @php
                                    $statusColors = [
                                        'PENDING' => 'bg-amber-500/20 text-amber-400',
                                        'CONFIRMED' => 'bg-blue-500/20 text-blue-400',
                                        'PICKING' => 'bg-purple-500/20 text-purple-400',
                                        'PACKING' => 'bg-cyan-500/20 text-cyan-400',
                                        'READY_TO_SHIP' => 'bg-indigo-500/20 text-indigo-400',
                                        'SHIPPED' => 'bg-emerald-500/20 text-emerald-400',
                                        'COMPLETED' => 'bg-slate-500/20 text-slate-300',
                                        'CANCELLED' => 'bg-red-500/20 text-red-400',
                                    ];
                                @endphp
                                <span class="text-xs px-2 py-1 rounded-full {{ $order->isWaitingStock() ? 'bg-orange-500/20 text-orange-300' : ($statusColors[$order->status] ?? 'bg-slate-500/20 text-slate-400') }}">
                                    {{ $order->status_label }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-400">
                                {{ $order->created_at->format('d M Y') }}
                                <x-ship-badge :order="$order" class="block w-max mt-1" />
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex justify-end gap-1">
                                    @include('warehouse.outbound.partials.actions')
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center py-12 text-slate-500">Belum ada order</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($orders->hasPages())
                <div class="p-3 border-t border-slate-800">{{ $orders->links() }}</div>
            @endif

            {{-- Aksi massal untuk order yang dicentang --}}
            <div x-show="selected.length" x-cloak x-transition
                class="fixed bottom-4 inset-x-4 md:inset-x-auto md:right-6 z-40 bg-slate-800 border border-slate-600 rounded-2xl shadow-2xl p-3 flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-white px-1" x-text="selected.length + ' order dipilih'"></span>
                <form method="POST" action="{{ route('gudang.outbound.bulk-status') }}" class="flex flex-wrap gap-2">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <button name="status" value="CONFIRMED" x-show="count('PENDING')" class="px-3 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs rounded-lg font-semibold">
                        Konfirmasi (<span x-text="count('PENDING')"></span>)
                    </button>
                    <button name="status" value="COMPLETED" x-show="count('SHIPPED')" class="px-3 py-2 bg-slate-600 hover:bg-slate-500 text-white text-xs rounded-lg font-semibold">
                        Selesai (<span x-text="count('SHIPPED')"></span>)
                    </button>
                    <button name="status" value="CANCELLED" x-show="count('PENDING', 'CONFIRMED')"
                        @click="confirm('Batalkan ' + count('PENDING', 'CONFIRMED') + ' order?') || $event.preventDefault()"
                        class="px-3 py-2 bg-red-600/80 hover:bg-red-500 text-white text-xs rounded-lg font-semibold">
                        Batalkan (<span x-text="count('PENDING', 'CONFIRMED')"></span>)
                    </button>
                </form>
                <form method="GET" action="{{ route('gudang.label.shipping-bulk') }}" target="_blank" x-show="count('READY_TO_SHIP', 'SHIPPED', 'COMPLETED')">
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <button class="px-3 py-2 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded-lg font-semibold">
                        Cetak Label (<span x-text="count('READY_TO_SHIP', 'SHIPPED', 'COMPLETED')"></span>)
                    </button>
                </form>
                <button type="button" @click="selected = []" class="px-3 py-2 text-xs text-slate-300 hover:text-white">Batal pilih</button>
            </div>
        </div>
    </div>

    <script>
        function bulkOrders(statuses) {
            return {
                statuses,
                selected: [],
                get allChecked() {
                    const ids = Object.keys(this.statuses);
                    return ids.length > 0 && ids.every(id => this.selected.includes(id));
                },
                toggleAll(checked) {
                    this.selected = checked ? Object.keys(this.statuses) : [];
                },
                count(...wanted) {
                    return this.selected.filter(id => wanted.includes(this.statuses[id])).length;
                },
            };
        }
    </script>
</x-app-layout>