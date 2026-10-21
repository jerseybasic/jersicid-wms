@props([
    // Daftar dari App\Services\WorkQueue::for()
    'items' => [],
])

@php
    // Kelas ditulis lengkap supaya ikut ter-build oleh Tailwind
    $colors = [
        'amber' => 'text-amber-400 bg-amber-500/15',
        'blue' => 'text-blue-400 bg-blue-500/15',
        'purple' => 'text-purple-400 bg-purple-500/15',
        'cyan' => 'text-cyan-400 bg-cyan-500/15',
        'emerald' => 'text-emerald-400 bg-emerald-500/15',
        'teal' => 'text-teal-400 bg-teal-500/15',
        'slate' => 'text-slate-300 bg-slate-500/15',
        'red' => 'text-red-400 bg-red-500/15',
    ];
    $waiting = collect($items)->where('count', '>', 0);
@endphp

<div {{ $attributes->merge(['class' => 'bg-slate-900 border border-slate-800 rounded-2xl p-4 lg:p-5']) }}>
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-base font-semibold text-white">Tugas Hari Ini</h3>
        <a href="{{ route('gudang.tugas') }}" class="text-xs text-cyan-400 hover:text-cyan-300">Buka Tugas Saya</a>
    </div>

    @if ($waiting->isEmpty())
        <p class="text-sm text-emerald-400 py-2">Semua beres, tidak ada pekerjaan yang menunggu.</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
            @foreach ($waiting as $item)
                <a href="{{ $item['url'] }}" data-work-item="{{ $item['key'] }}"
                    class="flex items-center gap-3 p-3 rounded-xl bg-slate-800/60 border border-slate-700/60 hover:border-cyan-500/50 transition-colors">
                    <span class="min-w-[2.5rem] h-10 px-2 rounded-lg flex items-center justify-center text-lg font-bold {{ $colors[$item['color']] ?? $colors['slate'] }}">
                        {{ $item['count'] }}
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-white truncate">{{ $item['label'] }}</span>
                        <span class="block text-xs text-slate-400 truncate">{{ $item['hint'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
