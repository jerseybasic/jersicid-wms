@props([
    // Daftar ambil berurutan per rak: [key, bin, sku, name, size, need, qty]. key sama dengan data-scan-qty di daftar.
    'items' => [],
])

{{-- Kartu besar "Ambil sekarang": barang berikutnya yang belum lengkap, ikut berubah setiap scan/ketuk --}}
<div x-data="pickNext(@js($items))" @scan-result.window="update($event.detail)" x-show="next" x-cloak
    class="rounded-2xl border-2 border-cyan-500 bg-cyan-950/40 p-4">
    <template x-if="next">
        <div class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-bold uppercase tracking-wide text-cyan-300">Ambil sekarang</p>
                <p class="text-xs text-slate-400"><span x-text="left"></span> barang lagi</p>
            </div>
            <div class="flex items-stretch gap-3">
                <div class="shrink-0 min-w-[6.5rem] rounded-xl bg-slate-900 border border-slate-700 px-3 py-2 text-center">
                    <p class="text-[11px] text-slate-500 uppercase">Rak</p>
                    <p class="font-mono text-2xl font-black text-cyan-300 break-all" x-show="next.bin !== 'UNKNOWN'" x-text="next.bin"></p>
                    <p class="text-sm font-bold text-amber-400" x-show="next.bin === 'UNKNOWN'">Belum ada di rak</p>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-mono text-base font-bold text-white break-all" x-text="next.sku"></p>
                    <p class="text-sm text-slate-300 truncate" x-text="next.name"></p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span x-show="next.size" class="px-3 py-1 rounded-lg bg-white text-slate-900 text-lg font-black" x-text="'Ukuran ' + next.size"></span>
                        <span class="px-3 py-1 rounded-lg bg-cyan-500 text-slate-900 text-lg font-black" x-text="'Ambil ' + (next.need - next.qty) + ' pcs'"></span>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

@once
    <script>
        function pickNext(items) {
            return {
                items: items,
                get next() {
                    return this.items.find((item) => item.qty < item.need) || null;
                },
                get left() {
                    return this.items.filter((item) => item.qty < item.need).length;
                },
                update(result) {
                    (result.items || []).forEach((changed) => {
                        this.items.filter((item) => String(item.key) === String(changed.id)).forEach((item) => item.qty = Number(changed.qty));
                    });
                },
            };
        }
    </script>
@endonce
