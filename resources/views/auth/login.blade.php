<x-guest-layout>
<div class="h-screen w-screen overflow-hidden bg-slate-950 relative">
    
    <!-- Background Ambient -->
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-cyan-500/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-cyan-600/10 rounded-full blur-3xl"></div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="relative h-full w-full flex">
        
        <!-- ==================== LEFT PANEL: LOGIN FORM ==================== -->
        <div class="w-full md:w-1/2 h-full relative overflow-hidden bg-slate-900">
            
            <div class="h-full flex flex-col justify-center p-12">
                <div class="w-full max-w-md mx-auto">
                    <!-- Logo -->
                    <div class="flex items-center gap-3 mb-8">
                        <div class="w-10 h-10 bg-gradient-to-br from-transparent-400 to-transparent-600 rounded-xl flex items-center justify-center shadow-lg shadow-transparent-500/30">
                            <img src="{{ asset('images/icon-jersic.webp') }}" alt="JERSIC.ID" 
                                 class="w-full h-full object-contain"
                                 width="40"
                                 height="40"
                                 loading="eager"
                                 fetchpriority="high">
                        </div>
                        <h1 class="text-2xl font-bold text-white tracking-wider">JERSIC.ID<span class="text-cyan-400">-WMS</span></h1>
                    </div>

                    @if(session('success'))
                        <div class="mb-4 p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl text-sm flex items-start gap-3 animate-fade-in">
                            <div class="w-10 h-10 flex items-center justify-center flex-shrink-0">
                                <img src="{{ asset('images/logo-jersic.png') }}" alt="Logo" class="w-full h-full object-contain">
                            </div>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif

                    @if(session('register_error'))
                        <div class="mb-4 p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl text-sm flex items-start gap-3 animate-fade-in">
                            <div class="w-20 h-20 bg-gradient-to-br from-transparent-400 to-transparent-600 rounded-xl flex items-center justify-center shadow-lg shadow-transparent-500/30">
                                <img src="{{ asset('images/icon-logo-jersic.webp') }}" alt="JERSIC.ID WMS" 
                                     class="w-full h-full object-contain"
                                     width="80"
                                     height="80"
                                     loading="eager">
                            </div>
                            <span>{{ session('register_error') }}</span>
                        </div>
                    @endif

                    <div class="mb-8">
                        <h2 class="text-3xl font-bold text-white mb-2">Selamat Datang</h2>
                        <p class="text-slate-300">Masuk ke akun Anda</p>
                    </div>

                    @if($errors->any())
                        <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-xl">
                            <ul class="text-sm text-red-400 space-y-1">
                                @foreach($errors->all() as $error)
                                    <li>• {{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="space-y-4">
                        @csrf
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-slate-300 mb-2">Email</label>
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                                    </svg>
                                </div>
                                <input type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="nama@jersic.id" class="w-full pl-12 pr-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                </div>
                                <input 
                                    type="password" 
                                    name="password" 
                                    id="login_password"
                                    required 
                                    placeholder="••••••••"
                                    class="w-full pl-12 pr-12 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"
                                />
                                <button 
                                    type="button" name="eyes-btn" 
                                    onclick="toggleLoginPassword()"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-cyan-400 transition-colors focus:outline-none"
                                >
                                    <svg id="login-eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-sm">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="remember" class="rounded border-slate-700 bg-slate-800 text-cyan-500 focus:ring-cyan-500/20">
                                <span class="ml-2 text-slate-400">Ingat saya</span>
                            </label>
                            @if(Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-cyan-400 hover:text-cyan-300 transition-colors">Lupa password?</a>
                            @endif
                        </div>

                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 hover:shadow-cyan-500/40 transform hover:scale-[1.02] transition-all duration-200">
                            Masuk
                        </button>

                        <!-- ✅ Link Register - Hanya muncul di Mobile -->
                        <div class="md:hidden text-center mt-4 pt-4 border-t border-slate-800">
                            <p class="text-sm text-slate-400 mb-2">Belum punya akun?</p>
                            <a href="{{ route('register') }}" 
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/40 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 transition-all shadow-lg hover:shadow-cyan-500/10">
                                <span>Daftar Akun Baru</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ==================== RIGHT PANEL: INFO ==================== -->
        <div class="hidden md:flex w-1/2 h-full flex-col justify-center items-center p-12 bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 relative overflow-hidden">
            
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-cyan-500/5 rounded-full blur-3xl"></div>
            </div>

            <div class="max-w-md relative z-10 text-center">
                <div class="flex justify-center mb-6">
                    <div class="w-20 h-20 bg-gradient from-transparent-400 to-transparent-600 rounded-xl flex items-center justify-center shadow-lg shadow-transparent-500/30">
                        <img src="{{ asset('images/icon-logo-jersic.webp') }}" alt="Logo" class="w-full h-full object-contain">
                    </div>
                </div>

                <h2 class="text-3xl font-bold text-white text-center mb-3 leading-tight">
                    Warehouse Management<br>System
                </h2>
                
                <p class="text-slate-400 text-center mb-6 leading-relaxed text-sm">
                    Kelola inventaris, pelacakan stok, dan operasional gudang Anda dengan sistem yang modern dan efisien.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-800/50 border border-slate-700/50 backdrop-blur-sm">
                        <div class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="text-left">
                            <h3 class="text-xs font-semibold text-white mb-0.5">Real-time Tracking</h3>
                            <p class="text-[11px] text-slate-400">Pantau stok langsung</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-800/50 border border-slate-700/50 backdrop-blur-sm">
                        <div class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                        <div class="text-left">
                            <h3 class="text-xs font-semibold text-white mb-0.5">Secure Access</h3>
                            <p class="text-[11px] text-slate-400">Keamanan terjamin</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-800/50 border border-slate-700/50 backdrop-blur-sm">
                        <div class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                        </div>
                        <div class="text-left">
                            <h3 class="text-xs font-semibold text-white mb-0.5">Analytics</h3>
                            <p class="text-[11px] text-slate-400">Laporan lengkap</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-800/50 border border-slate-700/50 backdrop-blur-sm">
                        <div class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <div class="text-left">
                            <h3 class="text-xs font-semibold text-white mb-0.5">Fast Performance</h3>
                            <p class="text-[11px] text-slate-400">Sistem cepat</p>
                        </div>
                    </div>
                </div>

                <a href="{{ route('register') }}" 
                   class="mt-6 inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-semibold border border-cyan-500/40 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 transition-all shadow-lg hover:shadow-cyan-500/10">
                    <span>Belum punya akun? Daftar Baru →</span>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.animate-fade-in {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
<script>
// Toggle Login Password Visibility
function toggleLoginPassword() {
    const passwordInput = document.getElementById('login_password');
    const eyeIcon = document.getElementById('login-eye-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
        `;
    } else {
        passwordInput.type = 'password';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        `;
    }
}
</script>
</x-guest-layout>
