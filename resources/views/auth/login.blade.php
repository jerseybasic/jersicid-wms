<x-guest-layout>
<div class="login-page min-h-screen bg-slate-950 flex flex-col lg:items-center lg:justify-center lg:p-8 overflow-x-hidden">

    <div class="relative w-full flex-1 lg:flex-none flex flex-col lg:flex-row lg:w-[1120px] lg:max-w-full lg:h-[640px] lg:rounded-3xl"
        style="box-shadow: var(--login-shadow)">

        <!-- Ganti tema -->
        <button type="button" x-data="themeSwitch" @click="toggle()"
            :aria-label="current === 'dark' ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'"
            :title="current === 'dark' ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'"
            class="absolute z-30 top-5 right-4 lg:right-5 w-10 h-10 rounded-xl flex items-center justify-center border transition-colors text-on-accent bg-white/10 border-white/25 lg:text-slate-400 lg:bg-slate-950 lg:border-slate-700 lg:hover:text-white">
            <svg x-show="current === 'dark'" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
            <svg x-show="current !== 'dark'" x-cloak class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
        </button>

        <!-- ==================== PANEL BIRU: ILUSTRASI GUDANG ==================== -->
        <section class="app-sidebar-bg relative flex-none h-[410px] lg:h-auto lg:w-1/2 overflow-hidden lg:rounded-l-3xl text-on-accent">
            <div class="absolute -right-16 -top-24 w-60 h-60 rounded-full bg-white/[0.05] pointer-events-none" aria-hidden="true"></div>

            <div class="relative px-5 pt-5 lg:px-12 lg:pt-11">
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 lg:w-11 lg:h-11 rounded-xl lg:rounded-2xl bg-white flex items-center justify-center p-1">
                        <img src="{{ asset('images/logo-jersic.png') }}" alt="JERSIC.ID" class="w-full h-full object-contain" width="40" height="40" fetchpriority="high">
                    </span>
                    <div>
                        <p class="font-extrabold text-[15px] lg:text-[17px] tracking-wide">JERSIC.ID</p>
                        <p class="text-[10.5px] lg:text-[11.5px] font-semibold" style="color:#9CD3F0">Warehouse Management System</p>
                    </div>
                </div>
                <h1 class="mt-5 lg:mt-9 text-[22px] lg:text-[34px] leading-tight font-extrabold">Gudang rapi,<br>order terkirim tepat waktu</h1>
                <p class="hidden lg:block mt-2.5 text-sm" style="color:#CFE7F7">Stok per rak, picking, packing, sampai serah kurir.</p>
            </div>

            <x-login-art part="scene" class="absolute left-0 right-0 bottom-[72px] lg:bottom-[150px] w-full lg:w-[calc(100%+2rem)]" />
            <x-login-art part="clouds-row" class="lg:hidden absolute left-0 bottom-0 w-full h-[76px]" />
        </section>

        <!-- Awan pembatas (desktop), sedikit menembus atas & bawah kartu -->
        <x-login-art part="clouds-vertical" class="hidden lg:block absolute z-10 pointer-events-none w-[190px] h-[calc(100%+120px)] -top-[60px] left-[calc(50%-90px)]" />

        <!-- ==================== FORM ==================== -->
        <section class="relative z-20 flex-1 bg-slate-900 lg:rounded-r-3xl flex flex-col justify-start lg:justify-center px-5 pt-2 pb-6 lg:pl-24 lg:pr-14 lg:py-10">

            <div class="w-full max-w-[400px] mx-auto lg:mx-0 flex-1 lg:flex-none flex flex-col">
                <p class="text-[11px] lg:text-xs font-extrabold tracking-[0.16em] text-cyan-400">LOGIN PENGGUNA</p>
                <h2 class="mt-1 lg:mt-1.5 text-[22px] lg:text-[28px] font-extrabold text-white">Masuk ke gudang</h2>
                <p class="hidden lg:block mt-1.5 text-sm text-slate-400">Gunakan email dan password yang diberikan Owner.</p>

                @if (session('success'))
                    <div class="mt-4 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-400">{{ session('success') }}</div>
                @endif

                @if ($errors->any())
                    <div class="mt-4 p-3 rounded-xl bg-red-500/10 border border-red-500/30" role="alert">
                        <ul class="text-sm text-red-400 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="mt-5 lg:mt-7 flex-1 lg:flex-none flex flex-col" x-data="{ show: false, forgot: false }">
                    @csrf

                    <div class="space-y-3 lg:space-y-3.5">
                        <label class="login-field cursor-text">
                            <svg class="w-5 h-5 flex-shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6" /></svg>
                            <span class="flex-1 min-w-0">
                                <span class="block text-xs font-semibold text-slate-300 mb-0.5">Email</span>
                                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="nama@jersic.id">
                            </span>
                        </label>

                        <label class="login-field cursor-text">
                            <svg class="w-5 h-5 flex-shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 018 0v4" /></svg>
                            <span class="flex-1 min-w-0">
                                <span class="block text-xs font-semibold text-slate-300 mb-0.5">Password</span>
                                <input :type="show ? 'text' : 'password'" type="password" name="password" id="login_password" required autocomplete="current-password" placeholder="Masukkan password">
                            </span>
                            <button type="button" name="eyes-btn" @click="show = !show" :aria-label="show ? 'Sembunyikan password' : 'Lihat password'"
                                class="flex-shrink-0 text-slate-400 hover:text-cyan-400 transition-colors">
                                <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7S2.5 12 2.5 12z" /><circle cx="12" cy="12" r="3" /></svg>
                                <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a3 3 0 004.2 4.2M9.9 5.2A9.9 9.9 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-3.2 4.1M6.6 6.6A17 17 0 002.5 12S6 19 12 19a9.6 9.6 0 004.4-1" /></svg>
                            </button>
                        </label>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-[13px]">
                        <label class="flex items-center gap-2 text-slate-400 cursor-pointer">
                            <input type="checkbox" name="remember" class="w-[18px] h-[18px] rounded-md border-slate-600 bg-slate-950 text-cyan-600 focus:ring-cyan-500/30">
                            Ingat saya
                        </label>
                        <button type="button" @click="forgot = !forgot" class="font-semibold text-cyan-400 hover:text-cyan-300">Lupa password?</button>
                    </div>
                    <p x-show="forgot" x-cloak x-transition.opacity class="mt-2 text-xs text-slate-400">Hubungi Owner untuk mengatur ulang password Anda.</p>

                    <button type="submit"
                        class="relative mt-5 lg:mt-6 h-[52px] lg:h-[54px] rounded-2xl text-on-accent font-bold text-[15.5px] flex items-center justify-center hover:brightness-110 active:scale-[0.99] transition"
                        style="background: linear-gradient(90deg, #0B3B66 0%, #0B5A85 55%, #0E7490 100%); box-shadow: 0 10px 24px rgb(11 59 102 / 0.30)">
                        Masuk
                        <svg class="absolute right-5 w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" /></svg>
                    </button>

                    <p class="mt-auto lg:mt-7 pt-4 border-t border-slate-800 text-center text-xs lg:text-[12.5px] text-slate-400">
                        Belum punya akun? <span class="font-semibold text-cyan-400">Minta ke Owner</span> untuk dibuatkan.
                    </p>
                </form>
            </div>
        </section>
    </div>
</div>
</x-guest-layout>
