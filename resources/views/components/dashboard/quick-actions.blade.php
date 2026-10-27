{{-- Tombol pintas untuk tablet & HP (di desktop semua menu sudah terlihat di sidebar).
     Nama dan ikon sama dengan menu di sidebar, disaring sesuai role. --}}
@php
    $isManager = in_array(auth()->user()->role, ['OWNER', 'SUPERVISOR', 'ADMIN_GUDANG']);
    $actions = array_values(array_filter([
        ['label' => 'Inbound', 'route' => 'gudang.inbound', 'chip' => 'bg-emerald-500/15 text-emerald-400', 'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
        ['label' => 'Outbound', 'route' => 'gudang.outbound', 'chip' => 'bg-blue-500/15 text-blue-400', 'icon' => 'M17 8V20m0 0l4-4m-4 4l-4-4M7 16V4m0 0L3 8m4-4l4 4'],
        ['label' => 'Picking', 'route' => 'gudang.picking', 'chip' => 'bg-purple-500/15 text-purple-400', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['label' => 'Packing', 'route' => 'gudang.packing', 'chip' => 'bg-cyan-500/15 text-cyan-400', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['label' => 'Serah Terima Kurir', 'route' => 'gudang.handover', 'chip' => 'bg-pink-500/15 text-pink-400', 'icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1'],
        ['label' => 'Cek Barang & Lokasi', 'route' => 'gudang.scanner', 'chip' => 'bg-teal-500/15 text-teal-400', 'icon' => 'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z'],
        ['label' => 'Stock Opname', 'route' => 'gudang.opname', 'chip' => 'bg-indigo-500/15 text-indigo-400', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
        $isManager
            ? ['label' => 'Purchase Order', 'route' => 'po.index', 'chip' => 'bg-amber-500/15 text-amber-400', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z']
            : ['label' => 'Transfer Stok', 'route' => 'gudang.transfer', 'chip' => 'bg-amber-500/15 text-amber-400', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
    ]));
@endphp

<div {{ $attributes->merge(['class' => 'card p-4 lg:hidden']) }}>
    <h3 class="text-base font-bold text-white">Aksi Cepat</h3>
    <div class="mt-3 grid grid-cols-4 gap-2">
        @foreach ($actions as $action)
            <a href="{{ route($action['route']) }}"
                class="flex flex-col items-center gap-1.5 p-2 rounded-xl text-center hover:bg-slate-800/60 transition-colors">
                <span class="w-11 h-11 rounded-xl flex items-center justify-center {{ $action['chip'] }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $action['icon'] }}" /></svg>
                </span>
                <span class="text-[11px] font-medium leading-tight text-slate-300">{{ $action['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
