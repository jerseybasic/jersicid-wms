<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">
                    {{ __('Kelola Supplier') }}
                </h2>
                <p class="text-sm text-slate-400 mt-1">Manajemen data supplier</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">

        @if(session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-lg flex items-start gap-2 text-sm">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg flex items-start gap-2 text-sm">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <!-- Filter Bar -->
        <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
            <form method="GET" action="{{ route('suppliers.index') }}" class="flex flex-wrap gap-2">
                <div class="relative flex-1 min-w-[200px]">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}" 
                           placeholder="Cari kode, nama, atau kontak..." 
                           class="w-full pl-10 pr-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                </div>

                <select name="type" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Tipe</option>
                    <option value="SUBLIMASI" {{ request('type') == 'SUBLIMASI' ? 'selected' : '' }}>Sublimasi</option>
                    <option value="KONVEKSI" {{ request('type') == 'KONVEKSI' ? 'selected' : '' }}>Konveksi</option>
                    <option value="UMUM" {{ request('type') == 'UMUM' ? 'selected' : '' }}>Umum</option>
                </select>

                <select name="status" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                    <option value="">Semua Status</option>
                    <option value="AKTIF" {{ request('status') == 'AKTIF' ? 'selected' : '' }}>Aktif</option>
                    <option value="NONAKTIF" {{ request('status') == 'NONAKTIF' ? 'selected' : '' }}>Nonaktif</option>
                </select>

                <button type="submit" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-semibold rounded-lg transition-all text-sm">
                    Filter
                </button>

                <a href="{{ route('suppliers.index') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-lg border border-slate-700 transition-all text-sm">
                    Reset
                </a>

                <button type="button"
                    onclick="openAddModal()" 
                    class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-slate-900 font-semibold rounded-lg shadow-lg shadow-cyan-500/25 transition-all text-sm flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Tambah Supplier
                </button>
            </form>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-slate-400 mb-1">Total Supplier</p>
                <p class="text-xl font-bold text-white">{{ \App\Models\Supplier::count() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-emerald-400 mb-1">Aktif</p>
                <p class="text-xl font-bold text-white">{{ \App\Models\Supplier::where('status', 'AKTIF')->count() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-purple-400 mb-1">Sublimasi</p>
                <p class="text-xl font-bold text-white">{{ \App\Models\Supplier::where('type', 'SUBLIMASI')->count() }}</p>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-lg p-4">
                <p class="text-xs text-pink-400 mb-1">Konveksi</p>
                <p class="text-xl font-bold text-white">{{ \App\Models\Supplier::where('type', 'KONVEKSI')->count() }}</p>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-lg shadow-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                <h3 class="text-base font-semibold text-white">Daftar Supplier</h3>
                <span class="text-xs text-slate-400 bg-slate-800 px-2 py-1 rounded">
                    {{ $suppliers->total() }} total
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-xs text-slate-400 border-b border-slate-800 bg-slate-800/30">
                            <th class="text-left py-2.5 px-4 font-medium">Kode</th>
                            <th class="text-left py-2.5 px-4 font-medium">Nama Supplier</th>
                            <th class="text-left py-2.5 px-4 font-medium">Kontak</th>
                            <th class="text-left py-2.5 px-4 font-medium">Tipe</th>
                            <th class="text-left py-2.5 px-4 font-medium">Kota</th>
                            <th class="text-left py-2.5 px-4 font-medium">Status</th>
                            <th class="text-right py-2.5 px-4 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse($suppliers as $supplier)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-4">
                                <span class="text-xs font-mono font-bold text-cyan-400">{{ $supplier->supplier_code }}</span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-sm font-medium text-white">{{ $supplier->name }}</p>
                                <p class="text-xs text-slate-500">{{ $supplier->contact_person }}</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-xs text-slate-300">{{ $supplier->phone }}</p>
                                <p class="text-xs text-slate-500">{{ $supplier->email }}</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $supplier->type_color }}">
                                    {{ $supplier->type }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-300">{{ $supplier->city ?? '-' }}</td>
                            <td class="py-3 px-4">
                                <form method="POST" action="{{ route('suppliers.toggle-status', $supplier->id) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="text-xs px-2 py-0.5 rounded-full {{ $supplier->status_color }} hover:opacity-80 transition-opacity">
                                        {{ $supplier->status }}
                                    </button>
                                </form>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button onclick='openEditModal(@json($supplier))' class="p-1.5 text-slate-400 hover:text-cyan-400 hover:bg-slate-800 rounded transition-colors" title="Edit">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </button>
                                    <form method="POST" action="{{ route('suppliers.destroy', $supplier->id) }}" class="inline" onsubmit="return confirm('Yakin ingin menghapus supplier ini?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded transition-colors" title="Hapus">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center py-12">
                                <p class="text-sm text-slate-500">Belum ada supplier</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($suppliers->hasPages())
                <div class="p-3 border-t border-slate-800 bg-slate-800/20">
                    {{ $suppliers->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- ==================== MODAL TAMBAH SUPPLIER ==================== -->
    <div id="addSupplierModal" class="fixed inset-0 z-50 hidden">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeAddModal()"></div>
        
        <!-- Modal Container -->
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="relative bg-slate-900 border border-slate-800 rounded-lg shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-800 bg-slate-800/50 flex-shrink-0">
                    <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Tambah Supplier Baru
                    </h3>
                    <button onclick="closeAddModal()" class="p-1 text-slate-400 hover:text-white hover:bg-slate-700 rounded transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Modal Body (Scrollable) -->
                <div class="flex-1 overflow-y-auto p-4">
                    <form id="addSupplierForm" method="POST" action="{{ route('suppliers.store') }}" class="space-y-3">
                        @csrf
                        
                        <!-- Informasi Dasar -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Kode Supplier</label>
                                <input type="text" name="supplier_code" placeholder="Auto-generate"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Nama Supplier <span class="text-red-400">*</span></label>
                                <input type="text" name="name" required placeholder="Nama lengkap"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Kontak -->
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Contact Person</label>
                                <input type="text" name="contact_person" placeholder="Nama kontak"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Telepon</label>
                                <input type="text" name="phone" placeholder="08xxx"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Email</label>
                                <input type="email" name="email" placeholder="email@supplier.com"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Alamat -->
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Alamat Lengkap</label>
                            <textarea name="address" rows="2" placeholder="Alamat lengkap"
                                      class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all resize-none"></textarea>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Kota</label>
                                <input type="text" name="city" placeholder="Kota"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Provinsi</label>
                                <input type="text" name="province" placeholder="Provinsi"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Kode Pos</label>
                                <input type="text" name="postal_code" placeholder="Kode pos"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Legal & Klasifikasi -->
                        <div class="grid grid-cols-4 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">NPWP</label>
                                <input type="text" name="npwp" placeholder="Nomor NPWP"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Tipe <span class="text-red-400">*</span></label>
                                <select name="type" required class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                                    <option value="UMUM">Umum</option>
                                    <option value="SUBLIMASI">Sublimasi</option>
                                    <option value="KONVEKSI">Konveksi</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Status <span class="text-red-400">*</span></label>
                                <select name="status" required class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                                    <option value="AKTIF">Aktif</option>
                                    <option value="NONAKTIF">Nonaktif</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Catatan</label>
                                <input type="text" name="notes" placeholder="Catatan"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Error Container -->
                        <div id="errorContainer" class="hidden p-2 bg-red-900/30 border border-red-500/50 rounded">
                            <ul id="errorList" class="text-xs text-red-400 space-y-0.5"></ul>
                        </div>
                    </form>
                </div>

                <!-- Modal Footer -->
                <div class="flex items-center justify-end gap-2 px-4 py-2.5 border-t border-slate-800 bg-slate-800/50 flex-shrink-0">
                    <button type="button" onclick="closeAddModal()" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-sm font-medium rounded transition-all border border-slate-700">
                        Batal
                    </button>
                    <button type="submit" form="addSupplierForm" id="submitBtn" class="px-3 py-1.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-white text-sm font-semibold rounded shadow-lg shadow-emerald-500/25 transition-all border border-slate-700">
                        Simpan Supplier
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== MODAL EDIT SUPPLIER ==================== -->
    <div id="editSupplierModal" class="fixed inset-0 z-50 hidden">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeEditModal()"></div>
        
        <!-- Modal Container -->
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="relative bg-slate-900 border border-slate-800 rounded-lg shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-800 bg-slate-800/50 flex-shrink-0">
                    <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Edit Supplier
                    </h3>
                    <button onclick="closeEditModal()" class="p-1 text-slate-400 hover:text-white hover:bg-slate-700 rounded transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Modal Body (Scrollable) -->
                <div class="flex-1 overflow-y-auto p-4">
                    <form id="editSupplierForm" method="POST" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" id="edit_supplier_id" name="id">
                        
                        <!-- Informasi Dasar -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Kode Supplier</label>
                                <input type="text" name="supplier_code" id="edit_supplier_code"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Nama Supplier <span class="text-red-400">*</span></label>
                                <input type="text" name="name" id="edit_supplier_name" required
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Kontak -->
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Contact Person</label>
                                <input type="text" name="contact_person" id="edit_contact_person"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Telepon</label>
                                <input type="text" name="phone" id="edit_phone"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Email</label>
                                <input type="email" name="email" id="edit_email"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Alamat -->
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Alamat Lengkap</label>
                            <textarea name="address" id="edit_address" rows="2"
                                      class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all resize-none"></textarea>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Kota</label>
                                <input type="text" name="city" id="edit_city"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Provinsi</label>
                                <input type="text" name="province" id="edit_province"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Kode Pos</label>
                                <input type="text" name="postal_code" id="edit_postal_code"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Legal & Klasifikasi -->
                        <div class="grid grid-cols-4 gap-2">
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">NPWP</label>
                                <input type="text" name="npwp" id="edit_npwp"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Tipe <span class="text-red-400">*</span></label>
                                <select name="type" id="edit_type" required class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                                    <option value="UMUM">Umum</option>
                                    <option value="SUBLIMASI">Sublimasi</option>
                                    <option value="KONVEKSI">Konveksi</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Status <span class="text-red-400">*</span></label>
                                <select name="status" id="edit_status" required class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all">
                                    <option value="AKTIF">Aktif</option>
                                    <option value="NONAKTIF">Nonaktif</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-1">Catatan</label>
                                <input type="text" name="notes" id="edit_notes"
                                       class="w-full px-2.5 py-1.5 bg-slate-800 border border-slate-700 rounded text-white text-sm focus:border-cyan-400 focus:outline-none transition-all"/>
                            </div>
                        </div>

                        <!-- Error Container -->
                        <div id="editErrorContainer" class="hidden p-2 bg-red-900/30 border border-red-500/50 rounded">
                            <ul id="editErrorList" class="text-xs text-red-400 space-y-0.5"></ul>
                        </div>
                    </form>
                </div>

                <!-- Modal Footer -->
                <div class="flex items-center justify-end gap-2 px-4 py-2.5 border-t border-slate-800 bg-slate-800/50 flex-shrink-0">
                    <button type="button" onclick="closeEditModal()" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-sm font-medium rounded transition-all">
                        Batal
                    </button>
                    <button type="submit" form="editSupplierForm" id="editSubmitBtn" class="px-3 py-1.5 bg-gradient-to-r from-cyan-500 to-cyan-600 hover:from-cyan-400 hover:to-cyan-500 text-white text-sm font-semibold rounded shadow-lg shadow-cyan-500/25 transition-all">
                        Update Supplier
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Modal Functions
    function openAddModal() {
        document.getElementById('addSupplierModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeAddModal() {
        document.getElementById('addSupplierModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
        document.getElementById('addSupplierForm').reset();
        document.getElementById('errorContainer').classList.add('hidden');
    }

    function openEditModal(supplier) {
        document.getElementById('edit_supplier_id').value = supplier.id;
        document.getElementById('edit_supplier_code').value = supplier.supplier_code || '';
        document.getElementById('edit_supplier_name').value = supplier.name || '';
        document.getElementById('edit_contact_person').value = supplier.contact_person || '';
        document.getElementById('edit_phone').value = supplier.phone || '';
        document.getElementById('edit_email').value = supplier.email || '';
        document.getElementById('edit_address').value = supplier.address || '';
        document.getElementById('edit_city').value = supplier.city || '';
        document.getElementById('edit_province').value = supplier.province || '';
        document.getElementById('edit_postal_code').value = supplier.postal_code || '';
        document.getElementById('edit_npwp').value = supplier.npwp || '';
        document.getElementById('edit_type').value = supplier.type || 'UMUM';
        document.getElementById('edit_status').value = supplier.status || 'AKTIF';
        document.getElementById('edit_notes').value = supplier.notes || '';
        
        document.getElementById('editSupplierForm').action = '/suppliers/' + supplier.id;
        document.getElementById('editSupplierModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeEditModal() {
        document.getElementById('editSupplierModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
        document.getElementById('editErrorContainer').classList.add('hidden');
    }

    // Handle Add Form Submit
    document.getElementById('addSupplierForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const submitBtn = document.getElementById('submitBtn');
        const errorContainer = document.getElementById('errorContainer');
        const errorList = document.getElementById('errorList');
        const originalText = submitBtn.textContent;
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Menyimpan...';
        
        try {
            const formData = new FormData(this);
            const response = await fetch(this.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (response.ok) {
                closeAddModal();
                window.location.reload();
            } else {
                errorList.innerHTML = '';
                if (data.errors) {
                    Object.values(data.errors).flat().forEach(error => {
                        errorList.innerHTML += `<li>• ${error}</li>`;
                    });
                } else {
                    errorList.innerHTML = `<li>• ${data.message || 'Terjadi kesalahan'}</li>`;
                }
                errorContainer.classList.remove('hidden');
            }
        } catch (error) {
            errorList.innerHTML = `<li>• Network error: ${error.message}</li>`;
            errorContainer.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    });

    // Handle Edit Form Submit
    document.getElementById('editSupplierForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const submitBtn = document.getElementById('editSubmitBtn');
        const errorContainer = document.getElementById('editErrorContainer');
        const errorList = document.getElementById('editErrorList');
        const originalText = submitBtn.textContent;
        
        submitBtn.disabled = true;
        submitBtn.textContent = 'Mengupdate...';
        
        try {
            const formData = new FormData(this);
            formData.append('_method', 'PUT');
            const response = await fetch(this.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (response.ok) {
                closeEditModal();
                window.location.reload();
            } else {
                errorList.innerHTML = '';
                if (data.errors) {
                    Object.values(data.errors).flat().forEach(error => {
                        errorList.innerHTML += `<li>• ${error}</li>`;
                    });
                } else {
                    errorList.innerHTML = `<li>• ${data.message || 'Terjadi kesalahan'}</li>`;
                }
                errorContainer.classList.remove('hidden');
            }
        } catch (error) {
            errorList.innerHTML = `<li>• Network error: ${error.message}</li>`;
            errorContainer.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddModal();
            closeEditModal();
        }
    });
    </script>
</x-app-layout>