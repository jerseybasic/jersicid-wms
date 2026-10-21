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
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-slate-400">Total Order</p>
                <p class="text-xl font-bold text-white">{{ $stats['total'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-amber-400">Pending</p>
                <p class="text-xl font-bold text-white">{{ $stats['pending'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-purple-400">Picking</p>
                <p class="text-xl font-bold text-white">{{ $stats['picking'] }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-cyan-400">Packing</p>
                <p class="text-xl font-bold text-white">{{ $stats['packing'] }}</p>
            </div>
        </div>

        <!-- Filter & Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-lg shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex flex-wrap gap-3">
                <form method="GET" class="flex flex-wrap gap-2 flex-1">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari No. Order / Customer..." class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none flex-1 min-w-[200px]"/>
                    <select name="status" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
                        <option value="">Semua Status</option>
                        <option value="PENDING" {{ request('status') == 'PENDING' ? 'selected' : '' }}>Pending</option>
                        <option value="CONFIRMED" {{ request('status') == 'CONFIRMED' ? 'selected' : '' }}>Confirmed</option>
                        <option value="PICKING" {{ request('status') == 'PICKING' ? 'selected' : '' }}>Picking</option>
                        <option value="PACKING" {{ request('status') == 'PACKING' ? 'selected' : '' }}>Packing</option>
                        <option value="READY_TO_SHIP" {{ request('status') == 'READY_TO_SHIP' ? 'selected' : '' }}>Siap Kirim</option>
                        <option value="SHIPPED" {{ request('status') == 'SHIPPED' ? 'selected' : '' }}>Shipped</option>
                    </select>
                    <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-lg text-sm font-medium">Filter</button>
                    <a href="{{ route('gudang.outbound') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-sm font-medium border border-slate-700">Reset</a>
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
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-4">
                                <span class="text-sm font-mono font-bold text-cyan-400">{{ $order->order_number }}</span>
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
                                <span class="text-xs px-2 py-1 rounded-full {{ $statusColors[$order->status] ?? 'bg-slate-500/20 text-slate-400' }}">
                                    {{ $order->status_label }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-400">{{ $order->created_at->format('d M Y') }}</td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex justify-end gap-1">
                                    @if($order->status === 'PENDING')
                                        <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="CONFIRMED">
                                            <button class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white text-xs rounded font-medium">Konfirmasi</button>
                                        </form>
                                    @elseif($order->status === 'CONFIRMED')
                                        <a href="{{ route('gudang.picking.create') }}" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white text-xs rounded font-medium">Buat Picking</a>
                                    @elseif($order->status === 'PICKING')
                                        <a href="{{ route('gudang.picking') }}" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Lihat Picking</a>
                                    @elseif($order->status === 'PACKING')
                                        <a href="{{ route('gudang.packing') }}" class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 text-white text-xs rounded font-medium">Ke Packing</a>
                                    @elseif($order->status === 'READY_TO_SHIP')
                                        <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}" class="flex items-center gap-1">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="SHIPPED">
                                            <input type="text" name="tracking_number" required placeholder="No. resi"
                                                   class="w-32 px-2 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-xs font-mono focus:border-cyan-400 focus:outline-none">
                                            <button class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded font-medium">Kirim</button>
                                        </form>
                                    @elseif($order->status === 'SHIPPED')
                                        <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="COMPLETED">
                                            <button class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Selesai</button>
                                        </form>
                                    @endif
                                    @if(in_array($order->status, ['READY_TO_SHIP', 'SHIPPED', 'COMPLETED']))
                                        <a href="{{ route('gudang.label.shipping', $order->id) }}" target="_blank" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs rounded font-medium">Label</a>
                                    @endif
                                    @if(in_array($order->status, ['PENDING', 'CONFIRMED']))
                                        <form method="POST" action="{{ route('gudang.outbound.update-status', $order->id) }}" onsubmit="return confirm('Batalkan order ini?')">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="CANCELLED">
                                            <button class="px-3 py-1.5 bg-red-600/80 hover:bg-red-500 text-white text-xs rounded font-medium">Batal</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center py-12 text-slate-500">Belum ada order</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($orders->hasPages())
                <div class="p-3 border-t border-slate-800">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>