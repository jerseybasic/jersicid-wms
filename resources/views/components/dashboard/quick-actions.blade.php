{{-- Tombol pintas pekerjaan yang paling sering dipakai, disaring sesuai role --}}
@php
    $role = auth()->user()->role;
    $isManager = in_array($role, ['OWNER', 'SUPERVISOR']);
    $actions = array_values(array_filter([
        ['label' => 'Terima Barang', 'route' => 'gudang.inbound.create', 'bg' => 'bg-emerald-500', 'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
        ['label' => 'Order Baru', 'route' => 'gudang.outbound.create', 'bg' => 'bg-blue-500', 'icon' => 'M12 4v16m8-8H4'],
        $isManager ? ['label' => 'Buat PO', 'route' => 'po.create', 'bg' => 'bg-amber-500', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'] : null,
        ['label' => 'Picking', 'route' => 'gudang.picking', 'bg' => 'bg-purple-500', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['label' => 'Cek Barang', 'route' => 'gudang.scanner', 'bg' => 'bg-cyan-500', 'icon' => 'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z'],
        ['label' => 'Stock Opname', 'route' => 'gudang.opname', 'bg' => 'bg-indigo-500', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
        $isManager ? null : ['label' => 'Serah Kurir', 'route' => 'gudang.handover', 'bg' => 'bg-pink-500', 'icon' => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0'],
    ]));
@endphp

<div {{ $attributes->merge(['class' => 'card p-4 lg:p-5']) }}>
    <h3 class="text-base font-bold text-white">Aksi Cepat</h3>
    <p class="text-xs text-slate-400 mt-0.5">Pekerjaan yang paling sering dipakai</p>
    <div class="mt-4 grid grid-cols-3 gap-2.5">
        @foreach ($actions as $action)
            <a href="{{ route($action['route']) }}"
                class="{{ $action['bg'] }} group rounded-2xl p-3 flex flex-col items-center justify-center gap-2 text-center min-h-[5.5rem] text-on-accent hover:brightness-110 hover:-translate-y-0.5 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $action['icon'] }}" /></svg>
                <span class="text-[12px] font-semibold leading-tight">{{ $action['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
