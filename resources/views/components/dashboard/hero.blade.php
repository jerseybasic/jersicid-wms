@props([
    'title',
    'subtitle' => null,
    // [['label' => ..., 'value' => ..., 'sub' => ..., 'href' => ?], ...] — maksimal 4 kotak kaca
    'tiles' => [],
])

{{-- Banner sambutan biru dengan kotak kaca (Opsi 2). Warnanya sama di tema terang dan gelap. --}}
<section {{ $attributes->merge(['class' => 'hero-banner relative overflow-hidden rounded-3xl p-5 lg:p-7']) }}>
    <div class="pointer-events-none absolute -top-24 -right-16 w-72 h-72 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-28 left-1/3 w-80 h-80 rounded-full bg-cyan-300/10 blur-3xl" aria-hidden="true"></div>

    <div class="relative flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="min-w-0">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/15 text-[11.5px] font-semibold text-on-accent">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                {{ now()->locale('id')->translatedFormat('l, j F Y') }}
            </span>
            <h2 class="mt-3 text-2xl lg:text-[1.75rem] font-extrabold tracking-tight text-on-accent">{{ $title }}</h2>
            @if ($subtitle)
                <p class="mt-1 text-sm" style="color:#CFE7F7">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap gap-2 flex-shrink-0">{{ $actions }}</div>
        @endisset
    </div>

    @if (count($tiles))
        <div class="relative mt-5 grid grid-cols-2 lg:grid-cols-4 gap-2.5 lg:gap-3">
            @foreach ($tiles as $tile)
                @php $tileHref = $tile['href'] ?? null; @endphp
                <a @if ($tileHref) href="{{ $tileHref }}" @endif
                    class="glass-tile block rounded-2xl p-3.5 lg:p-4 {{ $tileHref ? 'hover:bg-white/20 transition-colors' : 'cursor-default' }}">
                    <p class="text-[11.5px] font-semibold truncate" style="color:#CFE7F7">{{ $tile['label'] }}</p>
                    <p class="mt-1 text-2xl lg:text-[1.65rem] font-extrabold leading-none text-on-accent">{{ $tile['value'] }}</p>
                    @if (!empty($tile['sub']))
                        <p class="mt-1.5 text-[11px] truncate" style="color:#CFE7F7">{{ $tile['sub'] }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</section>
