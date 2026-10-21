<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.outbound') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Import Pesanan BigSeller</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">Masukkan pesanan Shopee, Tokopedia, dan marketplace lain sekaligus</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('gudang.outbound.import.preview') }}" enctype="multipart/form-data"
              class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
            @csrf
            <div>
                <label for="file" class="block text-sm font-medium text-slate-300 mb-2">File ekspor pesanan (.xlsx, .xls, atau .csv)</label>
                <input id="file" type="file" name="file" required accept=".xlsx,.xls,.csv"
                       class="block w-full text-sm text-slate-300 file:mr-4 file:px-4 file:py-2 file:rounded-lg file:border-0 file:bg-cyan-600 file:text-white file:font-semibold hover:file:bg-cyan-500 bg-slate-800 border border-slate-700 rounded-lg">
            </div>
            <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg transition-all">Lihat Pratinjau</button>
            <p class="text-xs text-slate-500">Belum ada yang disimpan di langkah ini. Anda akan melihat daftar pesanannya dulu.</p>
        </form>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 text-sm text-slate-300 space-y-3">
            <h3 class="text-base font-semibold text-white">Cara mengambil file dari BigSeller</h3>
            <ol class="list-decimal list-inside space-y-1">
                <li>Buka menu Pesanan di BigSeller, lalu pilih pesanan yang mau diproses (misalnya tab "Sedang Diproses").</li>
                <li>Klik Ekspor, lalu pilih ekspor per SKU / item dan unduh file Excel-nya.</li>
                <li>Upload file itu di sini.</li>
            </ol>
            <h3 class="text-base font-semibold text-white pt-2">Yang perlu diperhatikan</h3>
            <ul class="list-disc list-inside space-y-1">
                <li>SKU di BigSeller harus sama dengan kode di <strong>Master SKU</strong>. Jika kolom "SKU Gudang" terisi, kolom itu yang dipakai.</li>
                <li>Pesanan yang nomornya sudah pernah diimpor atau sudah dibatalkan otomatis dilewati, jadi file yang sama aman diupload ulang.</li>
                <li>Nomor resi dari marketplace ikut tersimpan, jadi retur bisa dicari lewat scan resi.</li>
            </ul>
        </div>
    </div>
</x-app-layout>
