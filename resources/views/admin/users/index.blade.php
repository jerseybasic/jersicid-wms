<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">
                {{ __('Kelola User') }}
            </h2>
            <p class="text-sm text-slate-400 mt-1">Manajemen pengguna sistem</p>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-5">
                <p class="text-xs text-slate-400 mb-1">Total User</p>
                <p class="text-2xl font-bold text-white">{{ \App\Models\User::count() }}</p>
            </div>
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-5">
                <p class="text-xs text-emerald-400 mb-1">Aktif</p>
                <p class="text-2xl font-bold text-white">{{ \App\Models\User::where('status', 'AKTIF')->count() }}</p>
            </div>
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-5">
                <p class="text-xs text-amber-400 mb-1">Pending</p>
                <p class="text-2xl font-bold text-white">{{ \App\Models\User::where('status', 'NONAKTIF')->count() }}</p>
            </div>
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-5">
                <p class="text-xs text-cyan-400 mb-1">Owner</p>
                <p class="text-2xl font-bold text-white">{{ \App\Models\User::where('role', 'OWNER')->count() }}</p>
            </div>
        </div>

        <!-- User Table -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl shadow-xl">
            <div class="flex items-center justify-between p-6 border-b border-slate-700/50">
                <h3 class="text-lg font-semibold text-white">Daftar Pengguna</h3>
                <a href="{{ route('users.create') }}" class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 hover:shadow-cyan-500/40 transform hover:scale-[1.02] transition-all duration-200 text-sm">
                    + Tambah User
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-700/50">
                            <th class="text-left py-3 px-6">User</th>
                            <th class="text-left py-3 px-6">Role</th>
                            <th class="text-left py-3 px-6">Workspace</th>
                            <th class="text-left py-3 px-6">Status</th>
                            <th class="text-left py-3 px-6">Dibuat</th>
                            <th class="text-right py-3 px-6">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr class="border-b border-slate-700/30 hover:bg-slate-800/50 transition-colors">
                                <td class="py-4 px-6">
                                    <div class="flex items-center gap-3">
                                        <x-avatar :user="$user" size="w-10 h-10 text-sm" />
                                        <div>
                                            <p class="text-sm font-medium text-white">{{ $user->name }}</p>
                                            <p class="text-xs text-slate-500">{{ $user->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="text-xs px-2 py-1 rounded-full bg-cyan-500/20 text-cyan-400">
                                        {{ $user->role }}
                                    </span>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="text-xs text-slate-300">{{ $user->workspace_default }}</span>
                                </td>
                                <td class="py-4 px-6">
                                    <form method="POST" action="{{ route('users.toggle-status', $user->id) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-xs px-3 py-1 rounded-full font-medium transition-colors {{ $user->status === 'AKTIF' ? 'bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30' : 'bg-amber-500/20 text-amber-400 hover:bg-amber-500/30' }}">
                                            {{ $user->status }}
                                        </button>
                                    </form>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="text-xs text-slate-400">{{ $user->created_at->format('d M Y') }}</span>
                                </td>
                                <td class="py-4 px-6 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('users.edit', $user->id) }}" class="p-2 text-slate-400 hover:text-cyan-400 hover:bg-slate-800 rounded-lg transition-colors" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </a>
                                        <form method="POST" action="{{ route('users.destroy', $user->id) }}" class="inline" onsubmit="return confirm('Yakin ingin menghapus user ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="p-2 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded-lg transition-colors" title="Hapus">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-12 text-sm text-slate-500">
                                    Belum ada user
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($users->hasPages())
                <div class="p-4 border-t border-slate-700/50">
                    {{ $users->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>