<form method="GET" action="{{ $action }}" class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs text-slate-400 mb-1">Dari</label>
        <input type="date" name="start_date" value="{{ $start->toDateString() }}" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
    </div>
    <div>
        <label class="block text-xs text-slate-400 mb-1">Sampai</label>
        <input type="date" name="end_date" value="{{ $end->toDateString() }}" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none">
    </div>
    <button type="submit" class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">Tampilkan</button>
    <a href="{{ $action }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium rounded-lg border border-slate-700 transition-all">Reset</a>
</form>
