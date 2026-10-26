<x-app-layout>
    @include('reports.partials.header', ['title' => 'Kinerja Picker & Packer', 'subtitle' => 'Order dan pcs yang selesai diambil atau dikemas per orang', 'export' => route('reports.performance', array_merge(request()->query(), ['export' => 'excel']))])

    <div class="space-y-6">
        @include('reports.partials.period', ['action' => route('reports.performance')])

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            @foreach (['Picking (ambil barang)' => $picking, 'Packing (kemas)' => $packing] as $label => $rows)
                <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
                    <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-white">{{ $label }}</h3>
                        <span class="text-xs text-slate-400 bg-slate-800 px-2 py-1 rounded">{{ $rows->sum('orders') }} order · {{ number_format($rows->sum('pcs')) }} pcs</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                            <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                                <tr>
                                    <th class="py-3 px-4">Nama</th>
                                    <th class="py-3 px-4 text-right">Order</th>
                                    <th class="py-3 px-4 text-right">Pcs</th>
                                    <th class="py-3 px-4 text-right">Rata-rata / order</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                @forelse ($rows as $row)
                                    <tr class="hover:bg-slate-800/30">
                                        <td class="py-3 px-4 text-white font-medium">{{ $row['name'] }}</td>
                                        <td class="py-3 px-4 text-right text-slate-300">{{ number_format($row['orders']) }}</td>
                                        <td class="py-3 px-4 text-right font-bold text-cyan-400">{{ number_format($row['pcs']) }}</td>
                                        <td class="py-3 px-4 text-right text-slate-300">{{ $row['avg_minutes'] !== null ? $row['avg_minutes'] . ' menit' : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center py-10 text-slate-500">Belum ada sesi yang selesai di periode ini.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-slate-500">Rata-rata dihitung dari tombol mulai sampai sesi selesai. Sesi yang ditinggal lama di tengah jalan ikut membuat angkanya naik.</p>

        {{-- Catatan per staf: hal-hal yang perlu dicek, bukan hanya jumlah --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Catatan per staf</h3>
                <p class="text-xs text-slate-400 mt-1">Ketuk/input manual = barang dihitung tanpa scan barcode. Angka yang tinggi bisa berarti barcode rusak atau belum ditempel.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">Nama</th>
                            <th class="py-3 px-4 text-right">Paket diserahkan</th>
                            <th class="py-3 px-4 text-right">Ketuk/input manual</th>
                            <th class="py-3 px-4 text-right">Koreksi −1</th>
                            <th class="py-3 px-4 text-right">Barang tidak ada</th>
                            <th class="py-3 px-4 text-right">Masalah packing</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($notes as $row)
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 text-white font-medium">{{ $row['name'] }}</td>
                                <td class="py-3 px-4 text-right font-bold text-emerald-400">{{ number_format($row['handed']) }}</td>
                                <td class="py-3 px-4 text-right {{ $row['manual'] ? 'text-amber-400 font-semibold' : 'text-slate-500' }}">{{ number_format($row['manual']) }}</td>
                                <td class="py-3 px-4 text-right {{ $row['undo'] ? 'text-amber-400 font-semibold' : 'text-slate-500' }}">{{ number_format($row['undo']) }}</td>
                                <td class="py-3 px-4 text-right {{ $row['missing'] ? 'text-red-400 font-semibold' : 'text-slate-500' }}">{{ number_format($row['missing']) }}</td>
                                <td class="py-3 px-4 text-right {{ $row['problem'] ? 'text-red-400 font-semibold' : 'text-slate-500' }}">{{ number_format($row['problem']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-10 text-slate-500">Belum ada catatan di periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Hasil kerja gudang per hari --}}
        <div class="bg-slate-900 border border-slate-800 rounded-xl shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800">
                <h3 class="text-base font-semibold text-white">Per hari</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left [&_td]:whitespace-nowrap">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-3 px-4">Tanggal</th>
                            <th class="py-3 px-4 text-right">Dipicking</th>
                            <th class="py-3 px-4 text-right">Dikemas</th>
                            <th class="py-3 px-4 text-right">Diserahkan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($daily as $row)
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-3 px-4 text-white">{{ $row['date']->translatedFormat('D, d M Y') }}</td>
                                <td class="py-3 px-4 text-right text-slate-300">{{ number_format($row['picked_orders']) }} order · <span class="font-bold text-cyan-400">{{ number_format($row['picked_pcs']) }} pcs</span></td>
                                <td class="py-3 px-4 text-right text-slate-300">{{ number_format($row['packed_orders']) }} order · <span class="font-bold text-purple-400">{{ number_format($row['packed_pcs']) }} pcs</span></td>
                                <td class="py-3 px-4 text-right font-bold text-emerald-400">{{ number_format($row['handed']) }} paket</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center py-10 text-slate-500">Belum ada pekerjaan yang selesai di periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
