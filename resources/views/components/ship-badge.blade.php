@props(['order'])
{{-- Tanda batas kirim untuk order yang belum dikirim --}}
@if (in_array($order->status, \App\Services\ShipDeadline::OPEN_STATUSES, true))
    @php
        $deadline = $order->shipDeadline();
    @endphp
    @switch($order->shipStatus())
        @case(\App\Services\ShipDeadline::LATE)
            <span {{ $attributes->merge(['class' => 'inline-block px-2 py-0.5 rounded-md text-[11px] font-bold bg-red-500/20 text-red-400']) }} title="Batas kirim {{ $deadline->format('d M Y') }}">Terlambat {{ max(1, (int) $deadline->copy()->startOfDay()->diffInDays(today())) }} hari</span>
            @break
        @case(\App\Services\ShipDeadline::TODAY)
            <span {{ $attributes->merge(['class' => 'inline-block px-2 py-0.5 rounded-md text-[11px] font-bold bg-amber-500/20 text-amber-400']) }}>Kirim hari ini</span>
            @break
        @case(\App\Services\ShipDeadline::TOMORROW)
            <span {{ $attributes->merge(['class' => 'inline-block px-2 py-0.5 rounded-md text-[11px] font-semibold bg-slate-700 text-slate-300']) }}>Kirim besok</span>
            @break
        @default
            <span {{ $attributes->merge(['class' => 'inline-block px-2 py-0.5 rounded-md text-[11px] font-semibold bg-slate-800 text-slate-400']) }}>Kirim {{ $deadline->translatedFormat('d M') }}</span>
    @endswitch
@endif
