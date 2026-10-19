<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">{{ __('Pengaturan Sistem') }}</h2>
            <p class="text-sm text-slate-400 mt-1">Konfigurasi aplikasi dan informasi sistem</p>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <!-- System Info Card -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Informasi Sistem
            </h3>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">Laravel Version</p>
                    <p class="text-lg font-bold text-cyan-400">{{ $stats['laravel_version'] }}</p>
                </div>
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">PHP Version</p>
                    <p class="text-lg font-bold text-cyan-400">{{ $stats['php_version'] }}</p>
                </div>
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">Total Users</p>
                    <p class="text-lg font-bold text-white">{{ $stats['total_users'] }}</p>
                </div>
                <div class="bg-slate-900/50 rounded-xl p-4 border border-slate-700/50">
                    <p class="text-xs text-slate-400 mb-1">Active SKUs</p>
                    <p class="text-lg font-bold text-white">{{ number_format($stats['active_skus']) }}</p>
                </div>
            </div>
        </div>

        <!-- App Configuration -->
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Konfigurasi Aplikasi
            </h3>

            <form method="POST" action="{{ route('settings.update-profile') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Nama Aplikasi</label>
                        <input type="text" value="JERSIC.ID WMS" disabled class="w-full px-4 py-3 bg-slate-900/50 border border-slate-700 rounded-xl text-slate-500 cursor-not-allowed"/>
                        <p class="text-xs text-slate-500 mt-1">Hardcoded di layout.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Timezone</label>
                        <input type="text" value="{{ config('app.timezone') }}" disabled class="w-full px-4 py-3 bg-slate-900/50 border border-slate-700 rounded-xl text-slate-500 cursor-not-allowed"/>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-700/50">
                    <h4 class="text-sm font-semibold text-white mb-3">Informasi Admin Utama</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Nama Admin</label>
                            <input type="text" name="name" value="{{ auth()->user()->name }}" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Email Admin</label>
                            <input type="email" name="email" value="{{ auth()->user()->email }}" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all"/>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4">
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

        <!-- System Maintenance -->
        <div class="bg-gradient-to-br from-red-900/20 to-slate-900 border border-red-500/30 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                Zona Berbahaya
            </h3>
            <p class="text-sm text-slate-400 mb-4">Tindakan di bawah ini tidak dapat dibatalkan. Harap berhati-hati.</p>
            <div class="flex gap-3">
                <form method="POST" action="{{ route('settings.clear-cache') }}" onsubmit="return confirm('Yakin ingin clear cache?')">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-lg border border-slate-700 transition-all">
                        Clear Cache
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>