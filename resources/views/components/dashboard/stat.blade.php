@props([
    'label',
    'value',
    'sub' => null,
    'color' => 'cyan',
    'icon',
    'href' => null,
])

@php
    // Kelas ditulis lengkap supaya ikut ter-build oleh Tailwind
    $chip = [
        'cyan' => 'bg-cyan-500/15 text-cyan-400',
        'emerald' => 'bg-emerald-500/15 text-emerald-400',
        'red' => 'bg-red-500/15 text-red-400',
        'amber' => 'bg-amber-500/15 text-amber-400',
        'blue' => 'bg-blue-500/15 text-blue-400',
        'indigo' => 'bg-indigo-500/15 text-indigo-400',
        'purple' => 'bg-purple-500/15 text-purple-400',
        'pink' => 'bg-pink-500/15 text-pink-400',
    ][$color] ?? 'bg-cyan-500/15 text-cyan-400';
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card block p-4 lg:p-5' . ($href ? ' hover:border-cyan-500/40 transition-colors' : '')]) }}>
    {{-- Ikon sejajar judul, angka memakai lebar penuh kartu supaya tidak terpotong di HP --}}
    <div class="flex items-start justify-between gap-2 sm:gap-3">
        <p class="min-w-0 text-xs font-medium text-slate-400 leading-snug">{{ $label }}</p>
        <span class="-mt-1 w-8 h-8 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center flex-shrink-0 {{ $chip }}">
            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}" /></svg>
        </span>
    </div>
    <p class="mt-1 text-xl sm:text-2xl font-extrabold tracking-tight leading-tight text-white break-words">{{ $value }}</p>
    @if ($sub)
        <p class="mt-1 text-[11.5px] leading-snug text-slate-500">{{ $sub }}</p>
    @endif
</{{ $tag }}>
