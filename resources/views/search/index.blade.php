<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Hasil Pencarian</h2>
            <p class="text-sm text-slate-400 mt-1">Order, resi, SKU, lokasi, PO, dan retur</p>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-4">
        <form method="GET" action="{{ route('search') }}" class="flex gap-2">
            <input type="search" name="q" value="{{ $query }}" autofocus placeholder="No. order, resi, nama pembeli, SKU, rak, PO, retur..."
                class="flex-1 px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none">
            <button class="px-5 py-3 bg-cyan-600 hover:bg-cyan-500 text-white font-semibold rounded-xl">Cari</button>
        </form>

        @if ($tooShort)
            <p class="text-sm text-slate-400">Ketik minimal {{ \App\Services\QuickSearch::MIN_LENGTH }} huruf.</p>
        @elseif (!$groups)
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-8 text-center text-slate-400">
                Tidak ada yang cocok dengan "<span class="text-white">{{ $query }}</span>".
            </div>
        @endif

        @foreach ($groups as $group)
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                <h3 class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-400 bg-slate-800/50 border-b border-slate-800">{{ $group['label'] }}</h3>
                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}" class="block px-4 py-3 border-b border-slate-800 last:border-0 hover:bg-slate-800/50">
                        <p class="font-mono font-semibold text-cyan-400">{{ $item['title'] }}</p>
                        <p class="text-sm text-slate-400">{{ $item['subtitle'] }}</p>
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
</x-app-layout>
