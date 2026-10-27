<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('settings.index') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Edit Profil</h2>
                <p class="text-sm text-slate-400 mt-1">Perbarui informasi akun Anda</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto">
        @if (session('success'))
            <div
                class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl mb-6 flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <form method="POST" action="{{ route('settings.profile.update') }}" enctype="multipart/form-data" class="space-y-4"
                x-data="{ preview: null, remove: false }">
                @csrf
                @method('PUT')

                @if ($errors->any())
                    <div class="p-4 bg-red-900/30 border border-red-500/50 rounded-xl">
                        <ul class="text-sm text-red-400 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>• {{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex items-center gap-4">
                    <div class="relative">
                        <template x-if="preview">
                            <img :src="preview" alt="" class="w-20 h-20 rounded-full object-cover border-2 border-cyan-500/30">
                        </template>
                        <div x-show="!preview" :class="remove ? 'opacity-30' : ''">
                            <x-avatar :user="$user" size="w-20 h-20 text-2xl" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="inline-block px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl border border-slate-700 cursor-pointer transition-all">
                            {{ $user->profile_photo_path ? 'Ganti foto' : 'Pilih foto' }}
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="hidden"
                                @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null; if (f) remove = false">
                        </label>
                        @if ($user->profile_photo_path)
                            <label class="flex items-center gap-2 text-sm text-slate-400">
                                <input type="checkbox" name="remove_photo" value="1" x-model="remove" :disabled="preview !== null"
                                    class="w-4 h-4 rounded border-slate-600 bg-slate-800 text-cyan-500 focus:ring-cyan-500"/>
                                Hapus foto
                            </label>
                        @endif
                        <p class="text-xs text-slate-500">JPG, PNG, atau WEBP, maksimal 5 MB. Foto dipotong persegi otomatis.</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Nama Lengkap</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Email</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                        class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-xl text-white focus:border-cyan-400 focus:outline-none transition-all" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Role</label>
                    <input type="text" value="{{ $user->role }}" disabled
                        class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-xl text-slate-500 cursor-not-allowed" />
                </div>

                <div class="flex items-center gap-3 pt-4 border-t border-slate-800">
                    <a href="{{ route('settings.index') }}"
                        class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all">Batal</a>
                    <button type="submit"
                        class="flex-1 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-bold rounded-xl shadow-lg shadow-cyan-500/25 transition-all">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
