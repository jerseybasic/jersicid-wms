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
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium text-slate-400 truncate">{{ $label }}</p>
            <p class="mt-1.5 text-2xl font-extrabold tracking-tight text-white truncate">{{ $value }}</p>
            @if ($sub)
                <p class="mt-1 text-[11.5px] text-slate-500 truncate">{{ $sub }}</p>
            @endif
        </div>
        <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 {{ $chip }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}" /></svg>
        </span>
    </div>
</{{ $tag }}>
