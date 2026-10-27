<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Riwayat Aktivitas</h2>
            <p class="text-sm text-slate-400 mt-1">Siapa melakukan apa, kapan, dan apa yang berubah</p>
        </div>
    </x-slot>

    <div class="space-y-4">
        <form method="GET" action="{{ route('audit-trails.index') }}"
            class="bg-slate-900 border border-slate-800 rounded-2xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <select name="user" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm">
                <option value="">Semua user</option>
                <option value="system" @selected(($filters['user'] ?? '') === 'system')>Sistem</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(($filters['user'] ?? '') === $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
            <select name="module" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm">
                <option value="">Semua modul</option>
                @foreach ($modules as $module)
                    <option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ \App\Models\AuditTrail::MODULE_LABELS[$module] ?? $module }}</option>
                @endforeach
            </select>
            <select name="action" class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm">
                <option value="">Semua aksi</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ \App\Models\AuditTrail::ACTION_LABELS[$action] ?? $action }}</option>
                @endforeach
            </select>
            <div class="flex gap-2 lg:col-span-2">
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="Dari tanggal"
                    class="flex-1 min-w-0 px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm">
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="Sampai tanggal"
                    class="flex-1 min-w-0 px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm">
            </div>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari SKU, no. order, nama..."
                class="px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white text-sm placeholder-slate-500">
            <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-6">
                <button type="submit" class="px-4 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-xl">Terapkan filter</button>
                <a href="{{ route('audit-trails.index') }}" class="px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold rounded-xl">Reset</a>
                <a href="{{ route('audit-trails.index', array_filter($filters) + ['export' => 1]) }}"
                    class="px-4 py-2.5 bg-emerald-700 hover:bg-emerald-600 text-on-accent text-sm font-semibold rounded-xl">Export Excel</a>
                <span class="self-center text-xs text-slate-400 ml-auto">{{ $activities->total() }} aktivitas</span>
            </div>
        </form>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-3 px-4 font-medium">Waktu</th>
                            <th class="text-left py-3 px-4 font-medium">User</th>
                            <th class="text-left py-3 px-4 font-medium">Aktivitas</th>
                            <th class="text-left py-3 px-4 font-medium">Detail</th>
                            <th class="text-left py-3 px-4 font-medium">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($activities as $activity)
                            @php
                                $old = $activity->valuesOf('old_values');
                                $new = $activity->valuesOf('new_values');
                                $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
                            @endphp
                            <tr class="hover:bg-slate-800/30 transition-colors align-top">
                                <td class="py-3 px-4 text-xs text-slate-300 whitespace-nowrap">
                                    {{ $activity->created_at->format('d M Y H:i') }}
                                    <p class="text-[10px] text-slate-500">{{ $activity->created_at->diffForHumans() }}</p>
                                </td>
                                <td class="py-3 px-4 text-sm text-white whitespace-nowrap">{{ $activity->user?->name ?? 'Sistem' }}</td>
                                <td class="py-3 px-4">
                                    <span class="text-xs px-2 py-1 rounded bg-cyan-500/20 text-cyan-300">{{ $activity->action_label }}</span>
                                    <p class="text-xs text-slate-400 mt-1">{{ $activity->module_label }}</p>
                                </td>
                                <td class="py-3 px-4 text-xs">
                                    @if ($keys)
                                        <dl class="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 max-w-xl">
                                            @foreach (array_slice($keys, 0, 8) as $key)
                                                <dt class="text-slate-500">{{ $key }}</dt>
                                                <dd class="text-slate-200 break-words">
                                                    @if (array_key_exists($key, $old))
                                                        <span class="text-red-300 line-through">{{ \Illuminate\Support\Str::limit(\App\Models\AuditTrail::formatValue($old[$key]), 80) }}</span>
                                                        <span class="text-slate-500">→</span>
                                                    @endif
                                                    <span class="{{ array_key_exists($key, $old) ? 'text-emerald-300' : '' }}">{{ \Illuminate\Support\Str::limit(\App\Models\AuditTrail::formatValue($new[$key] ?? null), 120) }}</span>
                                                </dd>
                                            @endforeach
                                        </dl>
                                        @if (count($keys) > 8)
                                            <p class="text-slate-500 mt-1">+{{ count($keys) - 8 }} lainnya (lihat Export Excel)</p>
                                        @endif
                                    @else
                                        <span class="text-slate-600">-</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-xs text-slate-500 font-mono">{{ $activity->ip_address ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-12 text-slate-500">Tidak ada aktivitas yang cocok dengan filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($activities->hasPages())
                <div class="p-4 border-t border-slate-800 bg-slate-800/20">
                    {{ $activities->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
