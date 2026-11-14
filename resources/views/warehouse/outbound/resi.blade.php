<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
            <a href="{{ route('gudang.outbound') }}" title="Kembali" class="shrink-0 px-3 sm:px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Kembali</span>
            </a>
            <div class="min-w-0">
                <h2 class="font-semibold text-lg sm:text-xl text-white leading-tight">Input Resi dari BigSeller</h2>
                <p class="hidden sm:block text-sm text-slate-400 mt-1">Pesanan yang sudah dikirim tanpa diproses di aplikasi: stoknya dipotong supaya sama dengan rak</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6">
        @if (session('success'))
            <div class="p-3 bg-emerald-900/30 border border-emerald-500/50 text-emerald-300 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-900/30 border border-red-500/50 text-red-400 rounded-lg text-sm">{{ $errors->first() }}</div>
        @endif
        @if (session('resi_failed'))
            <div class="p-3 bg-amber-900/20 border border-amber-500/40 text-amber-200 rounded-lg text-sm space-y-1">
                <p class="font-semibold">Tidak jadi diproses:</p>
                <ul class="list-disc list-inside">
                    @foreach (session('resi_failed') as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('gudang.outbound.resi.preview') }}"
              class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-6 space-y-4">
            @csrf
            <div>
                <label for="resi" class="block text-sm font-medium text-slate-300 mb-2">Daftar nomor resi atau nomor pesanan</label>
                <textarea id="resi" name="resi" rows="10" required autofocus
                          placeholder="SPXID0123456789&#10;JP1234567890&#10;2511ABCDEF..."
                          class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 font-mono text-sm focus:border-cyan-400 focus:outline-none">{{ old('resi', $text) }}</textarea>
                <p class="text-xs text-slate-500 mt-1">Satu resi per baris. Bisa langsung ditempel dari kolom resi di BigSeller, atau di-scan satu per satu (scanner otomatis pindah baris). Paling banyak {{ \App\Services\ResiShipment::MAX_CODES }} resi sekali proses.</p>
            </div>
            <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-on-accent text-sm font-semibold rounded-lg transition-all">Cek Resi</button>
            <p class="text-xs text-slate-500">Belum ada yang disimpan di langkah ini. Anda akan melihat order dan stok yang akan dipotong dulu.</p>
        </form>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-6 text-sm text-slate-300 space-y-3">
            <h3 class="text-base font-semibold text-white">Kapan dipakai</h3>
            <p>Saat pesanan sudah diproses dan dikirim lewat BigSeller, tapi tidak lewat picking dan packing di aplikasi ini (misalnya staf pengganti belum memakai aplikasinya). Tanpa ini, stok di sistem lebih banyak dari barang yang ada di rak.</p>
            <h3 class="text-base font-semibold text-white pt-2">Caranya</h3>
            <ol class="list-decimal list-inside space-y-1">
                <li>Pastikan pesanannya sudah ada di aplikasi. Jika belum, ekspor pesanannya dari BigSeller lalu upload di <a href="{{ route('gudang.outbound.import') }}" class="text-cyan-400 hover:underline">Import BigSeller</a> (nomor resi ikut tersimpan).</li>
                <li>Salin nomor resi pesanan yang sudah dikirim dari BigSeller, tempel di kotak di atas, lalu klik <strong>Cek Resi</strong>.</li>
                <li>Periksa daftarnya, lalu klik <strong>Proses</strong>.</li>
            </ol>
            <h3 class="text-base font-semibold text-white pt-2">Yang terjadi</h3>
            <ul class="list-disc list-inside space-y-1">
                <li>Order <strong>Pending</strong> / <strong>Siap Picking</strong>: stok dipotong dari rak yang ada isinya, lalu order jadi Dikirim.</li>
                <li>Order <strong>Siap Kirim</strong>: cukup ditandai Dikirim, karena stoknya sudah dipotong saat picking.</li>
                <li>Order yang sudah Dikirim / Selesai dilewati, jadi resi yang sama aman diinput dua kali.</li>
                <li>Order yang sedang picking / packing, dibatalkan, atau stoknya di sistem kurang dilewati dengan alasannya.</li>
                <li>Semua tercatat di Pergerakan Stok, Riwayat Aktivitas, dan riwayat tiap order.</li>
            </ul>
        </div>
    </div>
</x-app-layout>
