<x-guest-layout>
<div class="h-screen w-screen overflow-hidden bg-slate-950 relative">
    
    <!-- Background Ambient -->
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-40 -left-40 w-96 h-96 bg-cyan-500/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 -right-40 w-96 h-96 bg-cyan-600/10 rounded-full blur-3xl"></div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="relative h-full w-full flex">
        
        <!-- ==================== LEFT PANEL: INFO ==================== -->
        <div class="hidden md:flex w-1/2 h-full flex-col justify-center items-center p-12 bg-gradient-to-br from-slate-800 via-slate-900 to-slate-950 relative overflow-hidden">
            
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-cyan-500/5 rounded-full blur-3xl"></div>
            </div>

            <div class="max-w-md relative z-10 text-center">
                <div class="flex justify-center mb-6">
                    <div class="w-20 h-20 bg-gradient-br from-transparent-400 to-transparent-600 rounded-xl flex items-center justify-center shadow-lg shadow-transparent-500/30">
                        <img src="{{ asset('images/icon-logo-jersic.webp') }}" alt="JERSIC.ID WMS" 
                            class="w-full h-full object-contain"
                            width="80"
                            height="80"
                            loading="eager">
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

                <a href="{{ route('login') }}" 
                   class="mt-6 inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-semibold border border-cyan-500/40 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 transition-all shadow-lg hover:shadow-cyan-500/10">
                    <span>← Sudah punya akun? Masuk</span>
                </a>
            </div>
        </div>

        <!-- ==================== RIGHT PANEL: REGISTER FORM (WITH SCROLL) ==================== -->
        <div class="w-full md:w-1/2 h-full relative overflow-hidden bg-slate-900">
            
            <div class="h-full overflow-y-auto p-12">
                <div class="w-full max-w-md mx-auto pb-8">
                    <!-- Logo -->
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-transparent-400 to-transparent-600 rounded-xl flex items-center justify-center shadow-lg shadow-transparent-500/30">
                            <img src="{{ asset('images/icon-jersic.webp') }}" alt="JERSIC.ID" 
                                 class="w-full h-full object-contain"
                                 width="40"
                                 height="40"
                                 loading="eager"
                                 fetchpriority="high">
                        </div>
                        <h1 class="text-xl font-bold text-white tracking-wider">JERSIC.ID<span class="text-cyan-400">-WMS</span></h1>
                    </div>

                    <div class="mb-6">
                        <h2 class="text-2xl font-bold text-white mb-1">Buat Akun Baru</h2>
                        <p class="text-sm text-slate-400">Daftar untuk mulai menggunakan sistem</p>
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

                    <form method="POST" action="{{ route('register') }}" class="space-y-4">
                        @csrf
                        
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1">Nama Lengkap</label>
                                <input type="text" name="name" value="{{ old('name') }}" required autofocus 
                                       placeholder="John Doe"
                                       class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label for="email" class="block text-xs font-medium text-slate-300 mb-1">Email</label>
                                <input type="email" name="email" id="email" value="{{ old('email') }}" required 
                                       placeholder="nama@jersic.id"
                                       class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Role / Jabatan</label>
                            <select name="role" id="role" required 
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                                <option value="">Pilih Role</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role }}" {{ old('role') == $role ? 'selected' : '' }}>
                                        @if($role === 'SUPERVISOR') Supervisor / Admin Gudang
                                        @elseif($role === 'STAFF_GUDANG') Staff Gudang
                                        @elseif($role === 'SUBLIM') Staff Sublimasi
                                        @elseif($role === 'KONVEKSI') Staff Konveksi
                                        @else {{ $role }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div id="workspace-container" style="display: none;">
                            <label class="block text-xs font-medium text-slate-300 mb-1">Workspace Default</label>
                            <select name="workspace_default" id="workspace_default" required
                                    class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                                <option value="">Pilih Workspace</option>
                            </select>
                        </div>

                        <!-- Password dengan Toggle di Dalam Field -->
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
                                    id="register_password"
                                    required 
                                    placeholder="••••••••"
                                    class="w-full pl-12 pr-12 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"
                                    oninput="checkPasswordStrength(this.value)"
                                />
                                <button 
                                    type="button" id="btn-register-pwd" 
                                    onclick="togglePassword('register_password', this)"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-cyan-400 transition-colors focus:outline-none"
                                >
                                    <svg class="w-5 h-5 eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            
                            <!-- Password Strength Bar -->
                            <div class="mt-2">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-slate-400">Kekuatan Password:</span>
                                    <span id="strength-text" class="text-xs font-semibold text-slate-500">Belum diisi</span>
                                </div>
                                <div class="w-full bg-slate-700 rounded-full h-1.5">
                                    <div id="strength-bar" class="h-1.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                                </div>
                                <ul class="mt-2 text-xs text-slate-400 space-y-1">
                                    <li id="req-length" class="flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full bg-slate-700 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-2.5 h-2.5 text-white hidden check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </span>
                                        Minimal 12 karakter
                                    </li>
                                    <li id="req-upper" class="flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full bg-slate-700 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-2.5 h-2.5 text-white hidden check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </span>
                                        Huruf besar (A-Z)
                                    </li>
                                    <li id="req-lower" class="flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full bg-slate-700 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-2.5 h-2.5 text-white hidden check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </span>
                                        Huruf kecil (a-z)
                                    </li>
                                    <li id="req-number" class="flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full bg-slate-700 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-2.5 h-2.5 text-white hidden check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </span>
                                        Angka (0-9)
                                    </li>
                                    <li id="req-symbol" class="flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full bg-slate-700 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-2.5 h-2.5 text-white hidden check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </span>
                                        Simbol (@$!%*?&)
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Konfirmasi Password dengan Toggle di Dalam Field -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Konfirmasi Password</label>
                            <div class="relative">
                                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <input 
                                    type="password" 
                                    name="password_confirmation" 
                                    id="register_password_confirmation"
                                    required 
                                    placeholder="••••••••"
                                    class="w-full pl-12 pr-12 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"
                                    oninput="checkPasswordMatch()"
                                />
                                <button 
                                    type="button" id="btn-register-pwd-cfm" 
                                    onclick="togglePassword('register_password_confirmation', this)"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-cyan-400 transition-colors focus:outline-none"
                                >
                                    <svg class="w-5 h-5 eye-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <p id="match-message" class="mt-1 text-xs hidden"></p>
                        </div>

                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 hover:shadow-cyan-500/40 transform hover:scale-[1.02] transition-all duration-200">
                            Daftar Sekarang
                        </button>

                        <!-- ✅ Link Login - Hanya muncul di Mobile -->
                        <div class="md:hidden text-center mt-4 pt-4 border-t border-slate-800">
                            <p class="text-sm text-slate-400 mb-2">Sudah punya akun?</p>
                            <a href="{{ route('login') }}" 
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/40 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 transition-all shadow-lg hover:shadow-cyan-500/10">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                                <span>Masuk ke Akun</span>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Workspace selector logic
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const workspaceContainer = document.getElementById('workspace-container');
    const workspaceSelect = document.getElementById('workspace_default');
    
    if (!roleSelect || !workspaceContainer || !workspaceSelect) return;
    
    const workspaceMapping = {
        'SUPERVISOR': ['GUDANG'],
        'STAFF_GUDANG': ['GUDANG', 'PACKING'],
        'SUBLIM': ['PO'],
        'KONVEKSI': ['PO']
    };
    
    roleSelect.addEventListener('change', function() {
        const selectedRole = this.value;
        
        if (selectedRole && workspaceMapping[selectedRole]) {
            workspaceContainer.style.display = 'block';
            workspaceSelect.innerHTML = '<option value="">Pilih Workspace</option>';
            
            workspaceMapping[selectedRole].forEach(function(ws) {
                const option = document.createElement('option');
                option.value = ws;
                option.textContent = ws;
                workspaceSelect.appendChild(option);
            });
            
            if (workspaceMapping[selectedRole].length === 1) {
                workspaceSelect.value = workspaceMapping[selectedRole][0];
            }
        } else {
            workspaceContainer.style.display = 'none';
            workspaceSelect.innerHTML = '<option value="">Pilih Workspace</option>';
        }
    });
    
    if (roleSelect.value) {
        roleSelect.dispatchEvent(new Event('change'));
    }
});

// Toggle Password Visibility
function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    const eyeIcon = button.querySelector('.eye-icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
        `;
    } else {
        input.type = 'password';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        `;
    }
}

// Check Password Strength
function checkPasswordStrength(password) {
    let strength = 0;
    const requirements = {
        length: password.length >= 12,
        upper: /[A-Z]/.test(password),
        lower: /[a-z]/.test(password),
        number: /[0-9]/.test(password),
        symbol: /[@$!%*?&]/.test(password)
    };

    // Update requirement indicators
    for (let [key, met] of Object.entries(requirements)) {
        const element = document.getElementById(`req-${key}`);
        const checkIcon = element.querySelector('.check-icon');
        const circle = element.querySelector('span');
        
        if (met) {
            checkIcon.classList.remove('hidden');
            circle.classList.remove('bg-slate-700');
            circle.classList.add('bg-emerald-500');
            strength++;
        } else {
            checkIcon.classList.add('hidden');
            circle.classList.remove('bg-emerald-500');
            circle.classList.add('bg-slate-700');
        }
    }

    // Update strength bar
    const strengthBar = document.getElementById('strength-bar');
    const strengthText = document.getElementById('strength-text');
    const percentage = (strength / 5) * 100;
    
    strengthBar.style.width = `${percentage}%`;
    
    if (strength === 0) {
        strengthText.textContent = 'Belum diisi';
        strengthText.className = 'text-xs font-semibold text-slate-500';
        strengthBar.className = 'h-1.5 rounded-full transition-all duration-300 bg-slate-700';
    } else if (strength <= 2) {
        strengthText.textContent = 'Lemah';
        strengthText.className = 'text-xs font-semibold text-red-400';
        strengthBar.className = 'h-1.5 rounded-full transition-all duration-300 bg-red-500';
    } else if (strength <= 4) {
        strengthText.textContent = 'Sedang';
        strengthText.className = 'text-xs font-semibold text-yellow-400';
        strengthBar.className = 'h-1.5 rounded-full transition-all duration-300 bg-yellow-500';
    } else {
        strengthText.textContent = 'Kuat';
        strengthText.className = 'text-xs font-semibold text-emerald-400';
        strengthBar.className = 'h-1.5 rounded-full transition-all duration-300 bg-emerald-500';
    }

    checkPasswordMatch();
}

// Check Password Match
function checkPasswordMatch() {
    const password = document.getElementById('register_password').value;
    const confirmation = document.getElementById('register_password_confirmation').value;
    const message = document.getElementById('match-message');
    
    if (confirmation.length === 0) {
        message.classList.add('hidden');
        return;
    }
    
    message.classList.remove('hidden');
    
    if (password === confirmation) {
        message.textContent = '✓ Password cocok';
        message.className = 'mt-1 text-xs text-emerald-400';
    } else {
        message.textContent = '✗ Password tidak cocok';
        message.className = 'mt-1 text-xs text-red-400';
    }
}
</script>
</x-guest-layout>