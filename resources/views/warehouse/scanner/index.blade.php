<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('dashboard.gudang') }}"
                class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl transition-all flex items-center gap-2 border border-slate-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="font-semibold text-xl text-white leading-tight">Cek Barang &amp; Lokasi</h2>
                <p class="text-sm text-slate-400 mt-1">Scan SKU untuk melihat lokasinya, atau scan bin/rak untuk melihat isinya</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-6" x-data="stockCheck()" @scan-code.window="check($event.detail)">

        <x-scan-box storage-key="cek-lokasi" :with-bin="false"
            hint="Halaman ini hanya untuk cek. Untuk menambah atau mengurangi stok, scan di layar Inbound, Picking, atau Stock Opname." />

        <div x-show="result" x-cloak class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-5 border-b border-slate-800">
                <template x-if="result && result.sku">
                    <div>
                        <p class="text-lg font-mono font-bold text-cyan-400" x-text="result.sku.sku"></p>
                        <p class="text-sm text-white" x-text="result.sku.product_name + ' • ' + (result.sku.size || '-')"></p>
                        <p class="text-xs mt-1"
                            :class="result.sku.min_stock > 0 && result.sku.total <= result.sku.min_stock ? 'text-red-400' : 'text-slate-400'"
                            x-text="'Total stok ' + result.sku.total + ' pcs' + (result.sku.min_stock > 0 ? ' (minimum ' + result.sku.min_stock + ')' : '')"></p>
                    </div>
                </template>
                <template x-if="result && !result.sku">
                    <p class="text-lg font-semibold text-white" x-text="result.message"></p>
                </template>
            </div>

            <table class="w-full text-sm" x-show="result && result.rows.length">
                <thead class="bg-slate-800/50 text-xs text-slate-400">
                    <tr>
                        <th class="text-left py-2 px-5" x-text="result && result.sku ? 'Lokasi Bin' : 'SKU'"></th>
                        <th class="text-right py-2 px-5">Qty</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <template x-for="row in (result ? result.rows : [])" :key="row.label">
                        <tr>
                            <td class="py-2 px-5">
                                <p class="font-mono text-white" x-text="row.label"></p>
                                <p class="text-xs text-slate-500" x-show="row.detail" x-text="row.detail"></p>
                            </td>
                            <td class="py-2 px-5 text-right font-bold text-emerald-400" x-text="row.qty"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p class="p-5 text-sm text-slate-500" x-show="result && !result.rows.length">Tidak ada stok.</p>
        </div>
    </div>

    <script>
        function stockCheck() {
            return {
                result: null,

                async check({ code, respond }) {
                    try {
                        const response = await fetch(@js(route('gudang.scanner.lookup')) + '?code=' + encodeURIComponent(code), {
                            headers: { 'Accept': 'application/json' },
                        });
                        const data = await response.json();
                        if (data.ok) {
                            this.result = data;
                        }
                        respond({ ok: !!data.ok, message: data.message || 'Kode tidak dikenal.' });
                    } catch (e) {
                        respond({ ok: false, message: 'Koneksi gagal. Coba scan lagi.' });
                    }
                },
            };
        }
    </script>
</x-app-layout>
