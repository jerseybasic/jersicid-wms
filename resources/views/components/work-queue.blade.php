@props([
    // Daftar dari App\Services\WorkQueue::for()
    'items' => [],
])

@php
    // Kelas ditulis lengkap supaya ikut ter-build oleh Tailwind
    $colors = [
        'amber' => ['card' => 'bg-amber-500/10 border-amber-500/25', 'num' => 'text-amber-400', 'btn' => 'bg-amber-500'],
        'blue' => ['card' => 'bg-blue-500/10 border-blue-500/25', 'num' => 'text-blue-400', 'btn' => 'bg-blue-500'],
        'purple' => ['card' => 'bg-purple-500/10 border-purple-500/25', 'num' => 'text-purple-400', 'btn' => 'bg-purple-500'],
        'cyan' => ['card' => 'bg-cyan-500/10 border-cyan-500/25', 'num' => 'text-cyan-400', 'btn' => 'bg-cyan-600'],
        'emerald' => ['card' => 'bg-emerald-500/10 border-emerald-500/25', 'num' => 'text-emerald-400', 'btn' => 'bg-emerald-600'],
        'teal' => ['card' => 'bg-teal-500/10 border-teal-500/25', 'num' => 'text-teal-400', 'btn' => 'bg-teal-600'],
        'slate' => ['card' => 'bg-slate-800/60 border-slate-700', 'num' => 'text-slate-300', 'btn' => 'bg-slate-600'],
        'red' => ['card' => 'bg-red-500/10 border-red-500/25', 'num' => 'text-red-400', 'btn' => 'bg-red-500'],
    ];
    $waiting = collect($items)->where('count', '>', 0);
@endphp

<div {{ $attributes->merge(['class' => 'card p-4 lg:p-5']) }}>
    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <h3 class="text-base font-bold text-white">Tugas Hari Ini</h3>
            <p class="text-xs text-slate-400 mt-0.5">
                {{ $waiting->isEmpty() ? 'Tidak ada yang menunggu' : $waiting->sum('count') . ' pekerjaan menunggu tindakan' }}
            </p>
        </div>
        <a href="{{ route('gudang.tugas') }}" class="text-xs font-semibold text-cyan-400 hover:text-cyan-300 whitespace-nowrap">Buka Tugas Saya</a>
    </div>

    @if ($waiting->isEmpty())
        <div class="flex items-center gap-3 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/25">
            <span class="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </span>
            <p class="text-sm text-emerald-400 font-medium">Semua beres, tidak ada pekerjaan yang menunggu.</p>
        </div>
    @else
        <div class="grid gap-3 grid-cols-[repeat(auto-fill,minmax(14rem,1fr))]">
            @foreach ($waiting as $item)
                @php $c = $colors[$item['color']] ?? $colors['slate']; @endphp
                <div data-work-item="{{ $item['key'] }}" class="flex flex-col justify-between gap-3 p-4 rounded-2xl border {{ $c['card'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white leading-snug">{{ $item['label'] }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $item['hint'] }}</p>
                        </div>
                        <span class="text-2xl font-extrabold leading-none {{ $c['num'] }}">{{ $item['count'] }}</span>
                    </div>
                    <a href="{{ $item['url'] }}"
                        class="self-start inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold text-on-accent {{ $c['btn'] }} hover:brightness-110">
                        Kerjakan
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
