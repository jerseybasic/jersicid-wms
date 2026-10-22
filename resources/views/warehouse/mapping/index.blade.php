<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-white leading-tight">Denah Gudang</h2>
            <p class="text-sm text-slate-400 mt-1">Visualisasi layout rak dan lorong gudang</p>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-6">

        @if ($unplaced['qty'] > 0)
            <div class="p-4 bg-amber-500/10 border border-amber-500/30 text-amber-300 rounded-xl text-sm">
                <strong>{{ number_format($unplaced['qty']) }} pcs</strong> stok ({{ $unplaced['skus'] }} SKU) belum punya lokasi rak, jadi belum terlihat di denah.
                Buka salah satu rak, tambahkan produknya, lalu biarkan pilihan <strong>Pindahkan stok yang belum punya rak</strong> tercentang.
            </div>
        @endif

        <!-- Warehouse Map Container -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-xl overflow-x-auto">

            <!-- Warehouse Title -->
            <div class="text-center mb-6">
                <h3 class="text-sm font-semibold text-slate-400 tracking-widest uppercase">— Dinding Belakang Gudang —
                </h3>
            </div>

            <!-- Back Wall Racks (A B C D) -->
            <div class="flex justify-center gap-4 mb-4">
                @foreach (['D', 'C', 'B', 'A'] as $rackCode)
                    @php
                        $rack = $racks->firstWhere('code', $rackCode);
                        $productCount = $rack ? $rack->products_count : 0;
                        $rackQty = $rack ? $rack->total_qty : 0;
                    @endphp
                    <a href="{{ route('gudang.mapping.show', $rackCode) }}"
                        class="w-20 h-24 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-slate-700 hover:border-cyan-500/50 rounded-xl flex flex-col items-center justify-center transition-all hover:scale-105 hover:shadow-lg hover:shadow-cyan-500/20 group relative">
                        <svg class="w-5 h-5 text-cyan-400 mb-1 group-hover:scale-110 transition-transform"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span class="text-lg font-bold text-white">{{ $rackCode }}</span>
                        <span
                            class="text-[10px] {{ $productCount > 0 ? 'text-emerald-400 font-semibold' : 'text-slate-400' }}">
                            {{ $productCount }} SKU · {{ number_format($rackQty) }} pcs
                        </span>
                        @if ($productCount > 0)
                            <div
                                class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center border-2 border-slate-900">
                                <span class="text-[10px] font-bold text-white">{{ $productCount }}</span>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>

            <!-- Back Aisle (Lorong Belakang) -->
            <div class="flex justify-center mb-6">
                <div class="w-full max-w-4xl border-2 border-dashed border-slate-600 rounded-lg py-2 text-center">
                    <span class="text-xs font-semibold text-slate-500 tracking-wider">LORONG BELAKANG</span>
                </div>
            </div>

            <!-- Main Warehouse Grid (Vertical Aisles) -->
            <div class="relative">

                <!-- Row 1: E I M Q U Y -->
                <div class="flex justify-center gap-3 mb-2">
                    @foreach (['E', 'I', 'M', 'Q', 'U', 'Y'] as $rackCode)
                        @php
                            $rack = $racks->firstWhere('code', $rackCode);
                            $productCount = $rack ? $rack->products_count : 0;
                        $rackQty = $rack ? $rack->total_qty : 0;
                        @endphp
                        <a href="{{ route('gudang.mapping.show', $rackCode) }}"
                            class="w-20 h-24 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-slate-700 hover:border-cyan-500/50 rounded-xl flex flex-col items-center justify-center transition-all hover:scale-105 hover:shadow-lg hover:shadow-cyan-500/20 group relative {{ in_array($rackCode, ['E', 'Y']) ? 'ring-2 ring-amber-500/30' : '' }}">
                            <svg class="w-5 h-5 text-cyan-400 mb-1 group-hover:scale-110 transition-transform"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span class="text-lg font-bold text-white">{{ $rackCode }}</span>
                            <span
                                class="text-[10px] {{ $productCount > 0 ? 'text-emerald-400 font-semibold' : 'text-slate-400' }}">
                                {{ $productCount }} SKU · {{ number_format($rackQty) }} pcs
                            </span>
                            @if (in_array($rackCode, ['E', 'Y']))
                                <span class="text-[8px] text-amber-400 mt-0.5">Ujung</span>
                            @endif
                            @if ($productCount > 0)
                                <div
                                    class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center border-2 border-slate-900">
                                    <span class="text-[10px] font-bold text-white">{{ $productCount }}</span>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>

                <!-- Row 2: F J N R V Z -->
                <div class="flex justify-center gap-3 mb-2">
                    @foreach (['F', 'J', 'N', 'R', 'V', 'Z'] as $rackCode)
                        @php
                            $rack = $racks->firstWhere('code', $rackCode);
                            $productCount = $rack ? $rack->products_count : 0;
                        $rackQty = $rack ? $rack->total_qty : 0;
                        @endphp
                        <a href="{{ route('gudang.mapping.show', $rackCode) }}"
                            class="w-20 h-24 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-slate-700 hover:border-cyan-500/50 rounded-xl flex flex-col items-center justify-center transition-all hover:scale-105 hover:shadow-lg hover:shadow-cyan-500/20 group relative {{ in_array($rackCode, ['F', 'Z']) ? 'ring-2 ring-amber-500/30' : '' }}">
                            <svg class="w-5 h-5 text-cyan-400 mb-1 group-hover:scale-110 transition-transform"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span class="text-lg font-bold text-white">{{ $rackCode }}</span>
                            <span
                                class="text-[10px] {{ $productCount > 0 ? 'text-emerald-400 font-semibold' : 'text-slate-400' }}">
                                {{ $productCount }} SKU · {{ number_format($rackQty) }} pcs
                            </span>
                            @if (in_array($rackCode, ['F', 'Z']))
                                <span class="text-[8px] text-amber-400 mt-0.5">Ujung</span>
                            @endif
                            @if ($productCount > 0)
                                <div
                                    class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center border-2 border-slate-900">
                                    <span class="text-[10px] font-bold text-white">{{ $productCount }}</span>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>

                <!-- Row 3: G K O S W AA -->
                <div class="flex justify-center gap-3 mb-2">
                    @foreach (['G', 'K', 'O', 'S', 'W', 'AA'] as $rackCode)
                        @php
                            $rack = $racks->firstWhere('code', $rackCode);
                            $productCount = $rack ? $rack->products_count : 0;
                        $rackQty = $rack ? $rack->total_qty : 0;
                        @endphp
                        <a href="{{ route('gudang.mapping.show', $rackCode) }}"
                            class="w-20 h-24 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-slate-700 hover:border-cyan-500/50 rounded-xl flex flex-col items-center justify-center transition-all hover:scale-105 hover:shadow-lg hover:shadow-cyan-500/20 group relative {{ in_array($rackCode, ['G', 'AA']) ? 'ring-2 ring-amber-500/30' : '' }}">
                            <svg class="w-5 h-5 text-cyan-400 mb-1 group-hover:scale-110 transition-transform"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span class="text-lg font-bold text-white">{{ $rackCode }}</span>
                            <span
                                class="text-[10px] {{ $productCount > 0 ? 'text-emerald-400 font-semibold' : 'text-slate-400' }}">
                                {{ $productCount }} SKU · {{ number_format($rackQty) }} pcs
                            </span>
                            @if (in_array($rackCode, ['G', 'AA']))
                                <span class="text-[8px] text-amber-400 mt-0.5">Ujung</span>
                            @endif
                            @if ($productCount > 0)
                                <div
                                    class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center border-2 border-slate-900">
                                    <span class="text-[10px] font-bold text-white">{{ $productCount }}</span>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>

                <!-- Row 4: H L P T X AB -->
                <div class="flex justify-center gap-3 mb-6">
                    @foreach (['H', 'L', 'P', 'T', 'X', 'AB'] as $rackCode)
                        @php
                            $rack = $racks->firstWhere('code', $rackCode);
                            $productCount = $rack ? $rack->products_count : 0;
                        $rackQty = $rack ? $rack->total_qty : 0;
                        @endphp
                        <a href="{{ route('gudang.mapping.show', $rackCode) }}"
                            class="w-20 h-24 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-slate-700 hover:border-cyan-500/50 rounded-xl flex flex-col items-center justify-center transition-all hover:scale-105 hover:shadow-lg hover:shadow-cyan-500/20 group relative {{ in_array($rackCode, ['H', 'AB']) ? 'ring-2 ring-amber-500/30' : '' }}">
                            <svg class="w-5 h-5 text-cyan-400 mb-1 group-hover:scale-110 transition-transform"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span class="text-lg font-bold text-white">{{ $rackCode }}</span>
                            <span
                                class="text-[10px] {{ $productCount > 0 ? 'text-emerald-400 font-semibold' : 'text-slate-400' }}">
                                {{ $productCount }} SKU · {{ number_format($rackQty) }} pcs
                            </span>
                            @if (in_array($rackCode, ['H', 'AB']))
                                <span class="text-[8px] text-amber-400 mt-0.5">Ujung</span>
                            @endif
                            @if ($productCount > 0)
                                <div
                                    class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center border-2 border-slate-900">
                                    <span class="text-[10px] font-bold text-white">{{ $productCount }}</span>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>

                <!-- Vertical Aisles Labels -->
                <div class="flex justify-center gap-3 mb-4">
                    <div class="w-20"></div>
                    <div class="flex flex-col items-center">
                        <div
                            class="w-full border-2 border-dashed border-cyan-500/50 rounded-lg py-2 text-center bg-cyan-500/5">
                            <span class="text-xs font-bold text-cyan-400 tracking-wider">LORONG 1</span>
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1">E-F-G-H ↔ I-J-K-L</span>
                    </div>
                    <div class="w-20"></div>
                    <div class="flex flex-col items-center">
                        <div
                            class="w-full border-2 border-dashed border-cyan-500/50 rounded-lg py-2 text-center bg-cyan-500/5">
                            <span class="text-xs font-bold text-cyan-400 tracking-wider">LORONG 2</span>
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1">I-J-K-L ↔ M-N-O-P</span>
                    </div>
                    <div class="w-20"></div>
                    <div class="flex flex-col items-center">
                        <div
                            class="w-full border-2 border-dashed border-cyan-500/50 rounded-lg py-2 text-center bg-cyan-500/5">
                            <span class="text-xs font-bold text-cyan-400 tracking-wider">LORONG 3</span>
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1">M-N-O-P ↔ Q-R-S-T</span>
                    </div>
                    <div class="w-20"></div>
                    <div class="flex flex-col items-center">
                        <div
                            class="w-full border-2 border-dashed border-cyan-500/50 rounded-lg py-2 text-center bg-cyan-500/5">
                            <span class="text-xs font-bold text-cyan-400 tracking-wider">LORONG 4</span>
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1">Q-R-S-T ↔ U-V-W-X</span>
                    </div>
                    <div class="w-20"></div>
                    <div class="flex flex-col items-center">
                        <div
                            class="w-full border-2 border-dashed border-cyan-500/50 rounded-lg py-2 text-center bg-cyan-500/5">
                            <span class="text-xs font-bold text-cyan-400 tracking-wider">LORONG 5</span>
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1">U-V-W-X ↔ Y-Z-AA-AB</span>
                    </div>
                    <div class="w-20"></div>
                </div>

                <!-- Entrance Door -->
                <div class="flex justify-center">
                    <div
                        class="px-6 py-3 bg-gradient-to-r from-cyan-500 to-cyan-600 rounded-xl shadow-lg shadow-cyan-500/30 flex items-center gap-2">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                        </svg>
                        <span class="text-sm font-bold text-white tracking-wider">PINTU MASUK GUDANG</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h3 class="text-base font-semibold text-white mb-4">Keterangan</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="w-12 h-12 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-slate-700 rounded-lg flex items-center justify-center">
                        <span class="text-sm font-bold text-white">A</span>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium">Rak Penyimpanan</p>
                        <p class="text-xs text-slate-400">Klik untuk lihat detail</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div
                        class="w-12 h-12 bg-gradient-to-br from-slate-800 to-slate-900 border-2 border-amber-500/50 rounded-lg flex items-center justify-center ring-2 ring-amber-500/30 relative">
                        <span class="text-sm font-bold text-white">E</span>
                        <div
                            class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center border-2 border-slate-900">
                            <span class="text-[10px] font-bold text-white">5</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium">Rak dengan Produk</p>
                        <p class="text-xs text-slate-400">Badge hijau = jumlah produk</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div
                        class="w-12 h-8 border-2 border-dashed border-cyan-500/50 rounded-lg bg-cyan-500/5 flex items-center justify-center">
                        <span class="text-[10px] font-bold text-cyan-400">L1</span>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium">Lorong Vertikal</p>
                        <p class="text-xs text-slate-400">Jalur pergerakan forklift</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
