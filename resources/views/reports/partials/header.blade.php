<x-slot name="header">
    <div class="flex items-center gap-3 sm:gap-4 min-w-0">
        <a href="{{ route('reports.index') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span class="hidden sm:inline">Kembali</span>
        </a>
        <div class="min-w-0">
            <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">{{ $title }}</h2>
            <p class="hidden sm:block text-sm text-slate-400 mt-1">{{ $subtitle }}</p>
        </div>
    </div>
</x-slot>
