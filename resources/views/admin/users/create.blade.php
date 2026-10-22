<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">
                {{ __('Tambah User Baru') }}
            </h2>
            <p class="text-sm text-slate-400 mt-1">Buat akun pengguna baru</p>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <div class="bg-gradient-to-br from-slate-800 to-slate-900 border border-slate-700/50 rounded-2xl p-6 shadow-xl">
            
            @if($errors->any())
                <div class="mb-4 p-4 bg-red-900/30 border border-red-500/50 rounded-xl">
                    <ul class="text-sm text-red-400 space-y-1">
                        @foreach($errors->all() as $error)
                            <li>• {{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
                @csrf
                
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Nama Lengkap</label>
                    <input type="text" name="name" value="{{ old('name') }}" required 
                           placeholder="John Doe"
                           class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required 
                           placeholder="nama@jersic.id"
                           class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Role</label>
                        <select name="role" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                            <option value="">Pilih Role</option>
                            <option value="OWNER" {{ old('role') == 'OWNER' ? 'selected' : '' }}>Owner</option>
                            <option value="SUPERVISOR" {{ old('role') == 'SUPERVISOR' ? 'selected' : '' }}>Supervisor</option>
                            <option value="ADMIN_GUDANG" {{ old('role') == 'ADMIN_GUDANG' ? 'selected' : '' }}>Admin Gudang</option>
                            <option value="STAFF_GUDANG" {{ old('role') == 'STAFF_GUDANG' ? 'selected' : '' }}>Staff Gudang</option>
                            <option value="SUBLIM" {{ old('role') == 'SUBLIM' ? 'selected' : '' }}>Sublim</option>
                            <option value="KONVEKSI" {{ old('role') == 'KONVEKSI' ? 'selected' : '' }}>Konveksi</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Workspace</label>
                        <select name="workspace_default" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                            <option value="">Pilih Workspace</option>
                            <option value="UTAMA" {{ old('workspace_default') == 'UTAMA' ? 'selected' : '' }}>UTAMA</option>
                            <option value="GUDANG" {{ old('workspace_default') == 'GUDANG' ? 'selected' : '' }}>GUDANG</option>
                            <option value="PACKING" {{ old('workspace_default') == 'PACKING' ? 'selected' : '' }}>PACKING</option>
                            <option value="PO" {{ old('workspace_default') == 'PO' ? 'selected' : '' }}>PO</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Status</label>
                    <select name="status" required class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all">
                        <option value="AKTIF" {{ old('status') == 'AKTIF' ? 'selected' : '' }}>AKTIF</option>
                        <option value="NONAKTIF" {{ old('status') == 'NONAKTIF' ? 'selected' : '' }}>NONAKTIF</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">No. WhatsApp</label>
                        <input type="text" name="whatsapp" value="{{ old('whatsapp') }}" placeholder="081234567890"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                        @error('whatsapp')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-3 md:pt-8 text-sm text-slate-300">
                        <input type="hidden" name="notify_whatsapp" value="0">
                        <input type="checkbox" name="notify_whatsapp" value="1" {{ old('notify_whatsapp') ? 'checked' : '' }} class="rounded bg-slate-800 border-slate-600">
                        Kirim notifikasi WhatsApp ke anggota ini
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                        <input type="password" name="password" required 
                               placeholder="••••••••"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Konfirmasi Password</label>
                        <input type="password" name="password_confirmation" required 
                               placeholder="••••••••"
                               class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 focus:outline-none transition-all"/>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-4">
                    <a href="{{ route('users.index') }}" class="px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-xl transition-all">
                        Batal
                    </a>
                    <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 hover:shadow-cyan-500/40 transform hover:scale-[1.02] transition-all duration-200">
                        Simpan User
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>