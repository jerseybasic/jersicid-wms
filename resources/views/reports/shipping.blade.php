<x-app-layout>
    @include('reports.partials.header', ['title' => 'Ketepatan Kirim', 'subtitle' => 'Order yang dikirim sebelum atau sesudah batas kirimnya', 'export' => route('reports.shipping', array_merge(request()->query(), ['export' => 'excel']))])

    @php
        $rateColor = fn ($rate) => $rate === null ? 'text-slate-500' : ($rate >= 95 ? 'text-emerald-400' : ($rate >= 80 ? 'text-amber-400' : 'text-red-400'));
    @endphp

    <div class="space-y-6">
        @include('reports.partials.period', ['action' => route('reports.shipping')])

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Tepat waktu</p>
                <p class="text-2xl font-bold {{ $rateColor($summary['rate']) }}">{{ $summary['rate'] !== null ? $summary['rate'] . '%' : '-' }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Order dikirim</p>
                <p class="text-2xl font-bold text-white">{{ number_format($summary['shipped']) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Dikirim terlambat</p>
                <p class="text-2xl font-bold text-red-400">{{ number_format($summary['late']) }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Rata-rata order → kirim</p>
                <p class="text-2xl font-bold text-cyan-400">{{ $summary['avg_hours'] !== null ? $summary['avg_hours'] . ' jam' : '-' }}</p>
            </div>
            <a href="{{ route('gudang.outbound', ['batas' => 'terlambat']) }}" class="col-span-2 lg:col-span-1 bg-slate-900 border {{ $summary['open_late'] ? 'border-red-500/40' : 'border-slate-800' }} rounded-xl p-4">
                <p class="text-xs text-slate-400 mb-1">Belum dikirim & sudah lewat batas</p>
                <p class="text-2xl font-bold {{ $summary['open_late'] ? 'text-red-400' : 'text-white' }}">{{ number_format($summary['open_late']) }}</p>
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach (['Per hari kirim' => $daily, 'Per kurir' => $couriers] as $label => $rows)
                <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
                    <div class="p-4 border-b border-slate-800">
                        <h3 class="text-base font-semibold text-white">{{ $label }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                            <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                                <tr>
                                    <th class="py-3 px-4">{{ $loop->first ? 'Tanggal' : 'Kurir' }}</th>
                                    <th class="py-3 px-4 text-right">Dikirim</th>
                                    <th class="py-3 px-4 text-right">Terlambat</th>
                                    <th class="py-3 px-4 text-right">Tepat waktu</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                @forelse ($rows as $key => $row)
                                    <tr class="hover:bg-slate-800/30">
                                        <td class="py-3 px-4 text-white font-medium">{{ $loop->parent->first ? \Carbon\Carbon::parse($key)->translatedFormat('D, d M Y') : $key }}</td>
                                        <td class="py-3 px-4 text-right text-slate-300">{{ number_format($row['shipped']) }}</td>
                                        <td class="py-3 px-4 text-right {{ $row['late'] ? 'text-red-400 font-semibold' : 'text-slate-500' }}">{{ number_format($row['late']) }}</td>
                                        <td class="py-3 px-4 text-right font-bold {{ $rateColor($row['rate']) }}">{{ $row['rate'] }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center py-10 text-slate-500">Belum ada order yang dikirim di periode ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                <h3 class="text-base font-semibold text-white">Order yang dikirim terlambat</h3>
                <span class="text-xs text-slate-400 bg-slate-800 px-2 py-1 rounded">{{ $late->count() }} order</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">No. Order</th>
                            <th class="py-3 px-4">Kurir</th>
                            <th class="py-3 px-4">Batas kirim</th>
                            <th class="py-3 px-4">Dikirim</th>
                            <th class="py-3 px-4 text-right">Lewat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($late->take(50) as $order)
                            @php
                                $hours = (int) ceil($order->deadline->diffInMinutes($order->shipped_at) / 60);
                            @endphp
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4">
                                    <a href="{{ route('gudang.outbound.show', $order->id) }}" class="font-mono font-bold text-cyan-400">{{ $order->order_number }}</a>
                                    <p class="text-xs text-slate-500">{{ $order->customer_name }}</p>
                                </td>
                                <td class="py-3 px-4 text-slate-300">{{ $order->courier ?: '-' }}</td>
                                <td class="py-3 px-4 text-slate-300">{{ $order->deadline->format('d M H:i') }}</td>
                                <td class="py-3 px-4 text-slate-300">{{ $order->shipped_at->format('d M H:i') }}</td>
                                <td class="py-3 px-4 text-right font-semibold text-red-400">{{ $hours >= 24 ? floor($hours / 24) . ' hari ' . ($hours % 24) . ' jam' : $hours . ' jam' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-10 text-slate-500">Semua order di periode ini dikirim tepat waktu.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($late->count() > 50)
                <p class="p-3 border-t border-slate-800 text-xs text-slate-500">Menampilkan 50 yang paling lama terlambat. Daftar lengkap ada di Export Excel.</p>
            @endif
        </div>
        <p class="text-xs text-slate-500">Batas kirim diambil dari file marketplace, atau dihitung dari jam potong di Pengaturan. Order dianggap dikirim saat statusnya diubah menjadi Shipped.</p>
    </div>
</x-app-layout>
