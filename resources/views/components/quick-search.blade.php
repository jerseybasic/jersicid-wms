{{-- Kotak cari cepat di header: saran muncul saat mengetik, Enter membuka hasil (atau langsung ke data yang kodenya persis). Tekan "/" untuk fokus. --}}
<div x-data="quickSearch(@js(route('search')))" @keydown.window="shortcut($event)" @click.away="open = false" class="relative">
    {{-- Tombol untuk layar kecil --}}
    <button type="button" @click="mobile = true; $nextTick(() => $refs.input.focus())" x-show="!mobile"
        class="md:hidden p-2.5 text-slate-400 hover:text-white hover:bg-slate-800/80 rounded-xl border border-slate-800" title="Cari">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </button>

    <form method="GET" action="{{ route('search') }}" @submit="submit($event)"
        :class="mobile ? 'fixed inset-x-0 top-0 z-50 p-3 bg-slate-900 border-b border-slate-800 flex' : 'hidden md:flex'"
        class="items-center gap-2">
        <div class="relative flex-1">
            <svg class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input x-ref="input" type="search" name="q" x-model="query" @input.debounce.250ms="fetchResults()" @focus="open = groups.length > 0"
                @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.escape="close()"
                autocomplete="off" placeholder="Cari order, resi, SKU, rak..."
                class="w-full md:w-56 xl:w-72 pl-9 pr-10 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
            <kbd class="hidden md:block absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-slate-500 border border-slate-600 rounded px-1.5">/</kbd>
        </div>
        <button type="button" x-show="mobile" @click="close()" class="px-3 py-2 text-sm text-slate-300">Tutup</button>

        <div x-show="open" x-cloak
            class="absolute left-0 right-0 md:right-auto md:w-[28rem] top-full mt-2 mx-3 md:mx-0 bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl overflow-hidden max-h-[70vh] overflow-y-auto">
            <template x-for="group in groups" :key="group.label">
                <div>
                    <p class="px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 bg-slate-800/60" x-text="group.label"></p>
                    <template x-for="item in group.items" :key="item.url">
                        <a :href="item.url" class="block px-4 py-2.5 border-b border-slate-800 hover:bg-slate-800/60"
                            :class="flat[active] === item ? 'bg-slate-800/80' : ''">
                            <p class="font-mono text-sm font-semibold text-cyan-400" x-text="item.title"></p>
                            <p class="text-xs text-slate-400 truncate" x-text="item.subtitle"></p>
                        </a>
                    </template>
                </div>
            </template>
            <p x-show="!groups.length && searched" class="px-4 py-6 text-sm text-center text-slate-400">Tidak ada yang cocok.</p>
        </div>
    </form>
</div>

@once
    <script>
        function quickSearch(url) {
            return {
                query: '', groups: [], flat: [], active: -1, open: false, searched: false, mobile: false, seq: 0,

                shortcut(e) {
                    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
                    if (e.key === '/' && !typing) {
                        e.preventDefault();
                        this.mobile = window.innerWidth < 768;
                        this.$nextTick(() => this.$refs.input.focus());
                    }
                },

                async fetchResults() {
                    const q = this.query.trim();
                    if (q.length < 2) { this.groups = []; this.flat = []; this.open = false; this.searched = false; return; }
                    const seq = ++this.seq;
                    try {
                        const res = await fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        if (seq !== this.seq) return;
                        this.groups = data.groups || [];
                        this.flat = this.groups.flatMap(g => g.items);
                        this.active = -1;
                        this.searched = true;
                        this.open = true;
                    } catch (e) { /* biarkan Enter membuka halaman hasil */ }
                },

                move(step) {
                    if (!this.flat.length) return;
                    this.open = true;
                    this.active = (this.active + step + this.flat.length) % this.flat.length;
                },

                submit(e) {
                    if (this.active >= 0 && this.flat[this.active]) {
                        e.preventDefault();
                        window.location = this.flat[this.active].url;
                    }
                },

                close() {
                    this.open = false;
                    this.mobile = false;
                    this.$refs.input.blur();
                },
            };
        }
    </script>
@endonce
