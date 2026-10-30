<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'jersic.id WMS') }} - {{ $title ?? 'Dashboard' }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <style>
        [x-cloak] { display: none !important; }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(2); opacity: 0; }
        }
        .pulse-ring::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 9999px;
            background: currentColor;
            animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body class="font-sans antialiased bg-slate-950 text-white" x-data="{ sidebarOpen: true }">
    
    <div class="flex h-screen overflow-hidden">
        
        <!-- ==================== SIDEBAR ==================== -->
        @if(isset($menus))
        <aside 
            x-bind:class="sidebarOpen ? 'w-64' : 'w-20'"
            class="flex flex-col bg-slate-900 border-r border-slate-800 transition-all duration-300 ease-in-out relative"
        >
            <!-- Logo & Toggle -->
            <div class="flex items-center h-16 px-4 border-b border-slate-800 relative">
                <div class="flex items-center gap-3" x-show="sidebarOpen" x-transition>
                    <div class="w-8 h-8 flex items-center justify-center flex-shrink-0">
                        <img src="{{ asset('asset/images/logo-jersic.png') }}" alt="Logo" class="w-full h-full object-contain"> 
                    </div>
                    <span class="text-lg font-bold text-white tracking-wider">JERSIC<span class="text-cyan-400">.WMS</span></span>
                </div>
                
                <!-- Hamburger Button -->
                <button 
                    x-on:click="sidebarOpen = !sidebarOpen" 
                    class="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors flex-shrink-0"
                    x-bind:class="!sidebarOpen ? 'absolute left-1/2 -translate-x-1/2' : 'ml-auto'"
                    title="Toggle Sidebar"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>

            <!-- Navigation Menu -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                @foreach($menus as $menu)
                    @php
                        // Safe handling untuk array atau object
                        $menuName = is_array($menu) ? ($menu['name'] ?? '') : ($menu->name ?? '');
                        $menuRoute = is_array($menu) ? ($menu['route'] ?? '') : ($menu->route ?? '');
                        $menuIcon = is_array($menu) ? ($menu['icon'] ?? '') : ($menu->icon ?? '');
                        
                        $currentRoute = request()->route()->getName();
                        $isActive = $currentRoute === $menuRoute;
                        $activeClass = 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30';
                        $inactiveClass = 'text-slate-400 hover:bg-slate-800 hover:text-white border border-transparent';
                    @endphp

                    <a 
                        href="{{ route($menuRoute) }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all relative group {{ $isActive ? $activeClass : $inactiveClass }}"
                        title="{{ $menuName }}"
                    >
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $menuIcon }}"/>
                        </svg>
                        <span x-show="sidebarOpen" x-transition>{{ $menuName }}</span>
                        
                        <!-- Tooltip saat sidebar collapsed -->
                        <div x-show="!sidebarOpen" class="absolute left-full ml-2 px-2 py-1 bg-slate-800 text-white text-xs rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-50 border border-slate-700">
                            {{ $menuName }}
                        </div>
                    </a>
                @endforeach
            </nav>

            <!-- User Profile + Tanggal di Bottom Sidebar -->
            <div class="px-3 py-4 border-t border-slate-800">
                <!-- Info User -->
                <div class="flex items-center gap-3 w-full px-3 py-2" x-bind:class="sidebarOpen ? '' : 'justify-center'">
                    <x-avatar :user="auth()->user()" size="w-8 h-8 text-xs" />
                    <div class="flex-1 text-left min-w-0" x-show="sidebarOpen" x-transition>
                        <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-slate-500 truncate">{{ auth()->user()->role }}</p>
                    </div>
                </div>

                <!-- Tanggal & Jam Real-Time -->
                <div 
                    x-show="sidebarOpen" 
                    x-transition
                    class="mt-3 pt-3 border-t border-slate-800/50 px-3"
                >
                    <div 
                        x-data="{ date: '' }" 
                        x-init="
                            const updateDate = () => {
                                const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                                const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                const now = new Date();
                                date = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
                            };
                            updateDate();
                            setInterval(updateDate, 60000);
                        "
                        class="flex items-center gap-2 text-xs text-slate-500"
                    >
                        <svg class="w-3.5 h-3.5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span class="font-medium text-slate-400" x-text="date"></span>
                    </div>
                    <div 
                        x-data="{ time: '' }" 
                        x-init="
                            const updateTime = () => {
                                const now = new Date();
                                const hours = String(now.getHours()).padStart(2, '0');
                                const minutes = String(now.getMinutes()).padStart(2, '0');
                                const seconds = String(now.getSeconds()).padStart(2, '0');
                                time = `${hours}:${minutes}:${seconds} WIB`;
                            };
                            updateTime();
                            setInterval(updateTime, 1000);
                        "
                        class="flex items-center gap-2 text-xs text-slate-500 mt-1"
                    >
                        <svg class="w-3.5 h-3.5 text-cyan-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="font-medium text-slate-400 font-mono tracking-wider" x-text="time"></span>
                    </div>
                </div>
            </div>
        </aside>
        @endif

        <!-- ==================== MAIN CONTENT ==================== -->
        <div class="flex-1 flex flex-col overflow-hidden">
            
            <!-- Top Header -->
            <header class="flex items-center justify-between h-16 px-6 bg-slate-900/50 border-b border-slate-800 backdrop-blur-sm">
                <div class="flex items-center gap-4">
                    <!-- Mobile Toggle -->
                    <button x-on:click="sidebarOpen = !sidebarOpen" class="text-slate-400 hover:text-white transition-colors lg:hidden">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    
                    @if(isset($header))
                        <div>
                            {{ $header }}
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    <!-- Notifikasi Bell -->
                    <button class="relative p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors" title="Notifikasi">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                    </button>

                    <!-- Dropdown Profile di Header (Kanan) -->
                    <div class="relative" x-data="{ profileOpen: false }">
                        <button 
                            x-on:click="profileOpen = !profileOpen"
                            class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-slate-800 transition-colors"
                        >
                            <div class="text-right">
                                <p class="text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                                <p class="text-xs text-slate-500">{{ auth()->user()->role }}</p>
                            </div>
                            <x-avatar :user="auth()->user()" size="w-8 h-8 text-xs" />
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Dropdown Profile -->
                        <div 
                            x-show="profileOpen" 
                            x-cloak
                            x-on:click.away="profileOpen = false"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute right-0 top-full mt-2 w-64 bg-slate-800 border border-slate-700 rounded-xl shadow-2xl overflow-hidden z-50"
                        >
                            <div class="p-3 border-b border-slate-700 bg-slate-900/50">
                                <div class="flex items-center gap-3">
                                    <x-avatar :user="auth()->user()" size="w-10 h-10 text-sm" />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                                        <p class="text-xs text-slate-400 truncate">{{ auth()->user()->email }}</p>
                                    </div>
                                </div>
                                <span class="inline-block mt-2 px-2 py-0.5 text-[10px] font-semibold bg-cyan-500/20 text-cyan-400 rounded-full">
                                    {{ auth()->user()->role }}
                                </span>
                            </div>

                            <div class="py-1">
                                <a 
                                    href="{{ route('settings.update-profile') }}" 
                                    class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700 hover:text-white transition-colors"
                                >
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    Profile
                                </a>

                                @if(auth()->user()->role === 'OWNER')
                                <a 
                                    href="{{ route('settings.index') }}" 
                                    class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-700 hover:text-white transition-colors"
                                >
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    Pengaturan
                                </a>
                                @endif

                                <div class="border-t border-slate-700 my-1"></div>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button 
                                        type="submit" 
                                        class="flex items-center gap-3 w-full px-4 py-2.5 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-colors text-left"
                                    >
                                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                        </svg>
                                        Log Out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-y-auto bg-slate-950 p-6">
                {{ $slot }}
            </main>
        </div>
    </div>
    <x-sidebar-scroll />
</body>
</html>