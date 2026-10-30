<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('reports.index') }}" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Import Master Data SKU</h2>
                <p class="text-sm text-slate-400 mt-1">Upload file Excel untuk menambah SKU dan memperbarui stok. Perubahannya ditampilkan dulu sebelum disimpan.</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">

        @if(session('success'))
            <div class="p-4 bg-emerald-900/30 border border-emerald-500/50 text-emerald-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="flex-1">
                    <p class="font-semibold mb-2">Berhasil!</p>
                    <div class="text-sm whitespace-pre-line">{{ session('success') }}</div>
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-900/30 border border-red-500/50 text-red-400 rounded-xl flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="font-semibold">Gagal Import</p>
                    <p class="text-sm">{{ session('error') }}</p>
                </div>
            </div>
        @endif

        <!-- Upload Form Section -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    Upload File Excel
                </h3>
                <div class="flex gap-2">
                    <a href="{{ route('reports.download-template') }}" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-on-accent text-sm font-semibold rounded-lg transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Template Kosong
                    </a>
                    <a href="{{ route('reports.download-template', ['with_stock' => true]) }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-on-accent text-sm font-semibold rounded-lg transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Download dengan Stok
                    </a>
                </div>
            </div>

            <form method="POST" action="{{ route('reports.import-sku') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf

                <!-- Drag & Drop Area -->
                <div class="border-2 border-dashed border-slate-700 rounded-xl p-8 text-center hover:border-cyan-500/50 transition-colors bg-slate-800/30" id="dropZone">
                    <svg class="w-16 h-16 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-sm text-slate-400 mb-2">Drag & drop file Excel di sini, atau</p>
                    <input type="file" name="file" id="fileInput" accept=".xlsx,.xls,.csv" required class="hidden">
                    <button type="button" onclick="document.getElementById('fileInput').click()" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">
                        Pilih File
                    </button>
                    <p class="text-xs text-slate-500 mt-3">Format: .xlsx, .xls, .csv (Max 10MB)</p>
                    <p id="fileName" class="text-sm text-cyan-400 mt-2 font-medium"></p>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3">
                    <a href="{{ route('reports.index') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-white font-semibold rounded-xl border border-slate-700 transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Batal
                    </a>
                    <button type="submit" id="submitBtn" class="flex-1 py-3 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-400 hover:to-emerald-500 text-on-accent font-bold rounded-xl shadow-lg shadow-emerald-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer border-2 border-slate-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Cek perubahan
                    </button>
                </div>
            </form>
        </div>

        <!-- Panduan Format Kolom -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Panduan Format Kolom Excel
            </h3>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-400 uppercase bg-slate-800/50 border-b border-slate-700">
                        <tr>
                            <th class="py-2 px-3 text-left">Nama Kolom</th>
                            <th class="py-2 px-3 text-left">Wajib</th>
                            <th class="py-2 px-3 text-left">Format</th>
                            <th class="py-2 px-3 text-left">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">SKU</td>
                            <td class="py-2 px-3"><span class="text-red-400">✓</span></td>
                            <td class="py-2 px-3 text-slate-300">JER-ARTICLE-SIZE</td>
                            <td class="py-2 px-3 text-slate-400">Harus unik, huruf kapital</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">ARTICLE</td>
                            <td class="py-2 px-3"><span class="text-red-400">✓</span></td>
                            <td class="py-2 px-3 text-slate-300">Text</td>
                            <td class="py-2 px-3 text-slate-400">Kode artikel produk</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">PRODUCT_NAME</td>
                            <td class="py-2 px-3"><span class="text-red-400">✓</span></td>
                            <td class="py-2 px-3 text-slate-300">Text</td>
                            <td class="py-2 px-3 text-slate-400">Nama lengkap produk</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">VERSION</td>
                            <td class="py-2 px-3 text-slate-500">-</td>
                            <td class="py-2 px-3 text-slate-300">1.0</td>
                            <td class="py-2 px-3 text-slate-400">Versi produk (default: 1.0)</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">SIZE_CATEGORY</td>
                            <td class="py-2 px-3"><span class="text-red-400">✓</span></td>
                            <td class="py-2 px-3 text-slate-300">DEWASA/KIDS/TEENS</td>
                            <td class="py-2 px-3 text-slate-400">Kategori ukuran</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">SIZE</td>
                            <td class="py-2 px-3"><span class="text-red-400">✓</span></td>
                            <td class="py-2 px-3 text-slate-300">S/M/L/XL</td>
                            <td class="py-2 px-3 text-slate-400">Ukuran produk</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">PRICE</td>
                            <td class="py-2 px-3 text-slate-500">-</td>
                            <td class="py-2 px-3 text-slate-300">Angka</td>
                            <td class="py-2 px-3 text-slate-400">Harga dalam Rupiah (default: 0)</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">MIN_STOCK</td>
                            <td class="py-2 px-3 text-slate-500">-</td>
                            <td class="py-2 px-3 text-slate-300">Angka</td>
                            <td class="py-2 px-3 text-slate-400">Stok minimum untuk alert (default: 0)</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">STATUS</td>
                            <td class="py-2 px-3"><span class="text-red-400">✓</span></td>
                            <td class="py-2 px-3 text-slate-300">AKTIF/NONAKTIF</td>
                            <td class="py-2 px-3 text-slate-400">Status SKU (default: AKTIF)</td>
                        </tr>
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 px-3 font-mono text-cyan-400">CURRENT_STOCK</td>
                            <td class="py-2 px-3 text-slate-500">-</td>
                            <td class="py-2 px-3 text-slate-300">Angka</td>
                            <td class="py-2 px-3 text-slate-400">Total stok terbaru. Stok di sistem disamakan dengan angka ini; sel kosong berarti stok tidak diubah</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 p-4 bg-amber-900/20 border border-amber-500/30 rounded-lg">
                <p class="text-sm text-amber-400 font-semibold mb-2">⚠️ Catatan Penting:</p>
                <ul class="text-xs text-amber-300/80 space-y-1 list-disc list-inside">
                    <li>Setelah upload muncul pratinjau: SKU baru, stok bertambah, stok berkurang, dan data produk yang berubah. Belum ada yang disimpan sebelum Anda menekan Simpan</li>
                    <li>Judul kolom bahasa Indonesia juga dikenali: Artikel, Nama Produk, Versi, Kategori Size, Harga (Rp), Min. Stok, Total Stok Saat Ini</li>
                    <li>Sel berisi rumus (misalnya dari Google Sheets) dibaca dari hasil terakhirnya</li>
                    <li>Harga 0 atau sel kosong tidak menghapus data yang sudah tersimpan</li>
                    <li>Selisih stok tercatat di Laporan Pergerakan Stok sebagai ADJUSTMENT</li>
                    <li>File maksimal 10MB; jika ada error saat menyimpan, semua dibatalkan</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Drag & Drop
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const fileName = document.getElementById('fileName');

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-cyan-500', 'bg-cyan-500/5');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-cyan-500', 'bg-cyan-500/5');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-cyan-500', 'bg-cyan-500/5');
            fileInput.files = e.dataTransfer.files;
            updateFileName();
        });

        fileInput.addEventListener('change', updateFileName);

        function updateFileName() {
            if (fileInput.files.length > 0) {
                fileName.textContent = '📄 ' + fileInput.files[0].name;
            } else {
                fileName.textContent = '';
            }
        }
    </script>
</x-app-layout>