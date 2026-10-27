<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'JERSIC.ID WMS') }}</title>
    <meta name="theme-color" content="#0B3B66">
    <x-theme-script />

    <!-- Preconnect to font domains -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>

    <!-- Load Google Fonts with display=swap (non-blocking) -->
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"></noscript>

    <!-- Bunny.net fonts (non-blocking) -->
    <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800">
    </noscript>

    @vite(['resources/css/app.css'])
    <!-- Preload critical scripts -->
    <link rel="modulepreload" href="{{ Vite::asset('resources/js/app.js') }}">

    <style>
        [x-cloak] {
            display: none !important;
        }

        /* Custom Scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.3);
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #06b6d4 0%, #3b82f6 100%);
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #22d3ee 0%, #60a5fa 100%);
        }

        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: #06b6d4 rgba(15, 23, 42, 0.3);
        }

        .sidebar-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.3);
            border-radius: 2px;
        }

        /* Daftar Tugas Hari Ini di lonceng: lebar penuh di HP, kotak kecil di bawah lonceng di layar lebar.
           Ditulis di sini (bukan kelas Tailwind) supaya tampil benar walau CSS belum di-build ulang. */
        .work-dropdown {
            position: fixed;
            left: 1rem;
            right: 1rem;
            top: 5rem;
            z-index: 1000;
        }

        @media (min-width: 640px) {
            .work-dropdown {
                position: absolute;
                left: auto;
                right: 0;
                top: 100%;
                margin-top: 0.75rem;
                width: 24rem;
            }
        }

        /* Notification Badge Pulse */
        @keyframes pulse-ring {
            0% {
                transform: scale(0.8);
                opacity: 0.8;
            }

            100% {
                transform: scale(2.2);
                opacity: 0;
            }
        }

        .notification-badge::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 9999px;
            background: currentColor;
            animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
            z-index: -1;
            will-change: transform, opacity;
        }
    </style>
</head>

<body class="font-sans antialiased bg-slate-950 text-slate-200" x-data="{
    // Di HP/tablet sidebar tertutup dan muncul sebagai lapisan di atas konten
    sidebarOpen: window.innerWidth >= 1024,
}">
    <!-- Skip to main content link for keyboard users -->
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-cyan-500 focus:text-white focus:rounded-lg">
        Skip to main content
    </a>

    <!-- ==================== LAYOUT CONTAINER (FLEXBOX) ==================== -->
    <div class="flex h-screen overflow-hidden">

        <!-- ==================== SIDEBAR ==================== -->
        <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-[45] bg-black/60 lg:hidden"></div>
        <aside
            class="app-sidebar-bg fixed inset-y-0 left-0 z-50 lg:static lg:z-auto flex-shrink-0 flex flex-col overflow-hidden duration-300 ease-in-out -translate-x-full lg:translate-x-0"
            x-data="{ menuQuery: '' }"
            x-init="$nextTick(() => $el.classList.add('transition-all'))"
            :class="sidebarOpen ? 'w-64 !translate-x-0' : 'w-[4.5rem]'" style="height: 100vh;">

            <!-- Logo & Toggle Button -->
            <div class="flex items-center gap-3 px-3 pt-4 pb-3 flex-shrink-0 overflow-hidden">
                <button @click="sidebarOpen = !sidebarOpen" aria-label="Buka/tutup menu"
                    class="w-11 h-11 flex items-center justify-center rounded-xl hover:bg-white/10 transition-colors flex-shrink-0 group"
                    title="Buka/tutup menu">
                    <img src="{{ asset('images/logo-jersic.png') }}" alt="JERSIC.ID Logo"
                        class="w-9 h-9 bg-white rounded-xl object-contain p-0.5 group-hover:scale-105 transition-transform duration-200"
                        width="36"
                        height="36"
                        loading="eager"
                        fetchpriority="high">
                </button>
                <div x-show="sidebarOpen" class="overflow-hidden whitespace-nowrap">
                    <p class="text-[15px] font-extrabold tracking-wide text-on-accent">JERSIC.ID</p>
                    <p class="text-[10.5px] font-semibold tracking-wider" style="color:#9CD3F0">Gudang &amp; Stok</p>
                </div>
            </div>

            <!-- Cari menu -->
            <div x-show="sidebarOpen" class="px-3 pb-2 flex-shrink-0">
                <div class="relative">
                    <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style="color:#9CD3F0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="search" x-model="menuQuery" placeholder="Cari menu..." aria-label="Cari menu"
                        class="w-full h-10 pl-9 pr-3 rounded-xl border-0 bg-white/10 text-sm text-on-accent placeholder-white/60 focus:ring-2 focus:ring-white/40">
                </div>
            </div>

            <!-- Menu (Scrollable) -->
            <nav class="flex-1 pl-3 py-3 overflow-y-auto overflow-x-hidden sidebar-scroll" aria-label="Menu utama">
                @php
                    $user = auth()->user();
                    $role = $user->role;

                    $menus = [
                        'OWNER' => [
                            'Dashboard' => [
                                'route' => 'dashboard.owner',
                                'icon' =>
                                    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
                            ],
                            'Master Data' => [
                                'items' => [
                                    [
                                        'name' => 'Master SKU',
                                        'route' => 'master-skus.index',
                                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                                    ],
                                    [
                                        'name' => 'Pemetaan SKU',
                                        'route' => 'sku-aliases.index',
                                        'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                                    ],
                                    [
                                        'name' => 'Supplier',
                                        'route' => 'suppliers.index',
                                        'icon' =>
                                            'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
                                    ],
                                    [
                                        'name' => 'Purchase Order',
                                        'route' => 'po.index',
                                        'icon' =>
                                            'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                                    ],
                                ],
                            ],
                            'Operasional Gudang' => [
                                'items' => [
                                    [
                                        'name' => 'Inbound',
                                        'route' => 'gudang.inbound',
                                        'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4',
                                    ],
                                    [
                                        'name' => 'Outbound',
                                        'route' => 'gudang.outbound',
                                        'icon' => 'M17 8V20m0 0l4-4m-4 4l-4-4M7 16V4m0 0L3 8m4-4l4 4',
                                    ],
                                    [
                                        'name' => 'Retur',
                                        'route' => 'gudang.retur',
                                        'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                                    ],
                                    [
                                        'name' => 'Picking',
                                        'route' => 'gudang.picking',
                                        'icon' =>
                                            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                                    ],
                                    [
                                        'name' => 'Packing',
                                        'route' => 'gudang.packing',
                                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                                    ],
                                    [
                                        'name' => 'Serah Terima Kurir',
                                        'route' => 'gudang.handover',
                                        'icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0',
                                    ],
                                    [
                                        'name' => 'Kembalikan ke Rak',
                                        'route' => 'gudang.restock',
                                        'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                                    ],
                                    [
                                        'name' => 'Transfer Stok',
                                        'route' => 'gudang.transfer',
                                        'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                                    ],
                                    [
                                        'name' => 'Isi Ulang Rak',
                                        'route' => 'gudang.replenishment',
                                        'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                                    ],
                                    [
                                        'name' => 'Stock Opname',
                                        'route' => 'gudang.opname',
                                        'icon' =>
                                            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                                    ],
                                    [
                                        'name' => 'Persetujuan Stok',
                                        'route' => 'gudang.stock-approvals',
                                        'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                                    ],
                                    [
                                        'name' => 'Cek Barang & Lokasi',
                                        'route' => 'gudang.scanner',
                                        'icon' =>
                                            'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z',
                                    ],
                                    [
                                        'name' => 'Thermal Printer',
                                        'route' => 'gudang.printer',
                                        'icon' =>
                                            'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z',
                                    ],
                                ],
                            ],
                            'Warehouse' => [
                                'items' => [
                                    [
                                        'name' => 'Denah Gudang',
                                        'route' => 'gudang.mapping',
                                        'icon' =>
                                            'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
                                    ],
                                ],
                            ],
                            'Reports & Admin' => [
                                'items' => [
                                    [
                                        'name' => 'Laporan',
                                        'route' => 'reports.index',
                                        'icon' =>
                                            'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                                    ],
                                    [
                                        'name' => 'Kelola User',
                                        'route' => 'users.index',
                                        'icon' =>
                                            'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
                                    ],
                                    [
                                        'name' => 'Riwayat Aktivitas',
                                        'route' => 'audit-trails.index',
                                        'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                                    ],
                                ],
                            ],
                        ],
                        'SUPERVISOR' => [
                            'Dashboard' => [
                                'route' => 'dashboard.gudang',
                                'icon' =>
                                    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
                            ],
                            'Master Data' => [
                                'items' => [
                                    [
                                        'name' => 'Master SKU',
                                        'route' => 'master-skus.index',
                                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                                    ],
                                    [
                                        'name' => 'Pemetaan SKU',
                                        'route' => 'sku-aliases.index',
                                        'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                                    ],
                                    [
                                        'name' => 'Supplier',
                                        'route' => 'suppliers.index',
                                        'icon' =>
                                            'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
                                    ],
                                    [
                                        'name' => 'Purchase Order',
                                        'route' => 'po.index',
                                        'icon' =>
                                            'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                                    ],
                                ],
                            ],
                            'Operasional Gudang' => [
                                'items' => [
                                    [
                                        'name' => 'Inbound',
                                        'route' => 'gudang.inbound',
                                        'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4',
                                    ],
                                    [
                                        'name' => 'Outbound',
                                        'route' => 'gudang.outbound',
                                        'icon' => 'M17 8V20m0 0l4-4m-4 4l-4-4M7 16V4m0 0L3 8m4-4l4 4',
                                    ],
                                    [
                                        'name' => 'Retur',
                                        'route' => 'gudang.retur',
                                        'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                                    ],
                                    [
                                        'name' => 'Picking',
                                        'route' => 'gudang.picking',
                                        'icon' =>
                                            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                                    ],
                                    [
                                        'name' => 'Packing',
                                        'route' => 'gudang.packing',
                                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                                    ],
                                    [
                                        'name' => 'Serah Terima Kurir',
                                        'route' => 'gudang.handover',
                                        'icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0',
                                    ],
                                    [
                                        'name' => 'Kembalikan ke Rak',
                                        'route' => 'gudang.restock',
                                        'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                                    ],
                                    [
                                        'name' => 'Transfer Stok',
                                        'route' => 'gudang.transfer',
                                        'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                                    ],
                                    [
                                        'name' => 'Isi Ulang Rak',
                                        'route' => 'gudang.replenishment',
                                        'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                                    ],
                                    [
                                        'name' => 'Stock Opname',
                                        'route' => 'gudang.opname',
                                        'icon' =>
                                            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                                    ],
                                    [
                                        'name' => 'Persetujuan Stok',
                                        'route' => 'gudang.stock-approvals',
                                        'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                                    ],
                                    [
                                        'name' => 'Cek Barang & Lokasi',
                                        'route' => 'gudang.scanner',
                                        'icon' =>
                                            'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z',
                                    ],
                                    [
                                        'name' => 'Thermal Printer',
                                        'route' => 'gudang.printer',
                                        'icon' =>
                                            'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z',
                                    ],
                                ],
                            ],
                            'Warehouse' => [
                                'items' => [
                                    [
                                        'name' => 'Denah Gudang',
                                        'route' => 'gudang.mapping',
                                        'icon' =>
                                            'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
                                    ],
                                ],
                            ],
                            'Reports' => [
                                'items' => [
                                    [
                                        'name' => 'Laporan',
                                        'route' => 'reports.index',
                                        'icon' =>
                                            'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                                    ],
                                    [
                                        'name' => 'Riwayat Aktivitas',
                                        'route' => 'audit-trails.index',
                                        'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                                    ],
                                ],
                            ],
                        ],
                        'STAFF_GUDANG' => [
                            'Dashboard' => [
                                'route' => 'dashboard.gudang',
                                'icon' =>
                                    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
                            ],
                            'Operasional Gudang' => [
                                'items' => [
                                    [
                                        'name' => 'Inbound',
                                        'route' => 'gudang.inbound',
                                        'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4',
                                    ],
                                    [
                                        'name' => 'Outbound',
                                        'route' => 'gudang.outbound',
                                        'icon' => 'M17 8V20m0 0l4-4m-4 4l-4-4M7 16V4m0 0L3 8m4-4l4 4',
                                    ],
                                    [
                                        'name' => 'Retur',
                                        'route' => 'gudang.retur',
                                        'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                                    ],
                                    [
                                        'name' => 'Picking',
                                        'route' => 'gudang.picking',
                                        'icon' =>
                                            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                                    ],
                                    [
                                        'name' => 'Packing',
                                        'route' => 'gudang.packing',
                                        'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                                    ],
                                    [
                                        'name' => 'Serah Terima Kurir',
                                        'route' => 'gudang.handover',
                                        'icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0',
                                    ],
                                    [
                                        'name' => 'Kembalikan ke Rak',
                                        'route' => 'gudang.restock',
                                        'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
                                    ],
                                    [
                                        'name' => 'Transfer Stok',
                                        'route' => 'gudang.transfer',
                                        'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                                    ],
                                    [
                                        'name' => 'Isi Ulang Rak',
                                        'route' => 'gudang.replenishment',
                                        'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                                    ],
                                    [
                                        'name' => 'Stock Opname',
                                        'route' => 'gudang.opname',
                                        'icon' =>
                                            'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                                    ],
                                    [
                                        'name' => 'Cek Barang & Lokasi',
                                        'route' => 'gudang.scanner',
                                        'icon' =>
                                            'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z',
                                    ],
                                    [
                                        'name' => 'Thermal Printer',
                                        'route' => 'gudang.printer',
                                        'icon' =>
                                            'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z',
                                    ],
                                ],
                            ],
                            'Warehouse' => [
                                'items' => [
                                    [
                                        'name' => 'Denah Gudang',
                                        'route' => 'gudang.mapping',
                                        'icon' =>
                                            'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
                                    ],
                                ],
                            ],
                        ],
                    ];

                    // ADMIN_GUDANG: semua operasional gudang + Purchase Order (lihat & update tahap produksi)
                    $menus['ADMIN_GUDANG'] = $menus['STAFF_GUDANG'];
                    $menus['ADMIN_GUDANG']['Pembelian'] = [
                        'items' => [
                            [
                                'name' => 'Purchase Order',
                                'route' => 'po.index',
                                'icon' =>
                                    'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                            ],
                        ],
                    ];

                    // Tugas Saya (daftar kerja ringkas untuk HP) tepat di bawah Dashboard untuk semua role
                    foreach ($menus as $menuRole => $groups) {
                        $menus[$menuRole] = array_slice($groups, 0, 1, true) + [
                            'Tugas Saya' => [
                                'route' => 'gudang.tugas',
                                'icon' =>
                                    'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
                            ],
                        ] + array_slice($groups, 1, null, true);
                    }

                    $currentMenus = $menus[$role] ?? [];
                @endphp

                @php
                    // Menu satu tautan (Dashboard, Tugas Saya) dikumpulkan di grup "Utama",
                    // grup lain tampil rata dengan judul kecil (tanpa buka-tutup).
                    $navGroups = ['Utama' => []];
                    foreach ($currentMenus as $groupName => $group) {
                        if (isset($group['route'])) {
                            $navGroups['Utama'][] = ['name' => $groupName] + $group;
                        } else {
                            $navGroups[$groupName] = $group['items'];
                        }
                    }
                @endphp

                @foreach ($navGroups as $groupName => $items)
                    <div class="mb-3" data-names="{{ strtolower(collect($items)->pluck('name')->implode('|')) }}"
                        x-show="!menuQuery || $el.dataset.names.includes(menuQuery.toLowerCase())">
                        <p x-show="sidebarOpen" class="sb-label">{{ $groupName }}</p>
                        <div x-show="!sidebarOpen" class="mx-3 mb-2 border-t border-white/15" style="border-color: rgb(255 255 255 / 0.15)"></div>
                        <div class="space-y-1">
                            @foreach ($items as $item)
                                @php $isActive = request()->routeIs($item['route']) || request()->routeIs($item['route'] . '.*'); @endphp
                                <a href="{{ route($item['route']) }}" data-name="{{ strtolower($item['name']) }}"
                                    x-show="!menuQuery || $el.dataset.name.includes(menuQuery.toLowerCase())"
                                    class="sb-link {{ $isActive ? 'is-active' : '' }}"
                                    :class="sidebarOpen ? '' : 'justify-center'"
                                    @if ($isActive) aria-current="page" @endif
                                    title="{{ $item['name'] }}">
                                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}" />
                                    </svg>
                                    <span x-show="sidebarOpen" class="flex-1 whitespace-nowrap overflow-hidden text-ellipsis">{{ $item['name'] }}</span>
                                    @if ($item['route'] === 'gudang.tugas' && $workTotal > 0)
                                        <span class="flex-shrink-0 min-w-[1.375rem] h-[1.375rem] px-1.5 rounded-full bg-red-500 text-on-accent text-[11px] font-bold flex items-center justify-center"
                                            :class="sidebarOpen ? '' : 'absolute top-0.5 right-1 min-w-[1.125rem] h-[1.125rem] text-[9px]'">
                                            {{ $workTotal > 99 ? '99+' : $workTotal }}
                                        </span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>

            <!-- Footer: pilihan tema & akun -->
            <div class="px-3 pt-3 pb-4 flex-shrink-0 space-y-3" style="border-top: 1px solid rgb(255 255 255 / 0.12)" x-data="themeSwitch">
                <div x-show="sidebarOpen" class="grid grid-cols-3 gap-1 p-1 rounded-xl bg-black/20" role="radiogroup" aria-label="Tema tampilan">
                    @foreach (['light' => ['Terang', 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z'], 'dark' => ['Gelap', 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z'], 'system' => ['Sistem', 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z']] as $themeKey => [$themeLabel, $themeIcon])
                        <button type="button" role="radio" @click="set('{{ $themeKey }}')" :aria-checked="mode === '{{ $themeKey }}'"
                            class="flex items-center justify-center gap-1 h-8 rounded-lg text-[11.5px] font-semibold transition-colors"
                            :class="mode === '{{ $themeKey }}' ? 'bg-white text-[#0B3B66] shadow' : 'text-[#D6ECFA] hover:bg-white/10'">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $themeIcon }}" /></svg>
                            {{ $themeLabel }}
                        </button>
                    @endforeach
                </div>
                <button x-show="!sidebarOpen" type="button" @click="toggle()" class="w-11 h-11 mx-auto flex items-center justify-center rounded-xl hover:bg-white/10" style="color:#D6ECFA"
                    :title="current === 'dark' ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'">
                    <svg x-show="current === 'dark'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                    <svg x-show="current !== 'dark'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
                </button>

                <div class="flex items-center gap-2.5" :class="sidebarOpen ? 'p-2 rounded-xl bg-white/10' : 'justify-center'">
                    <x-avatar :user="$user" />
                    <div x-show="sidebarOpen" class="flex-1 min-w-0">
                        <p class="text-[13px] font-bold text-on-accent truncate">{{ $user->name }}</p>
                        <p class="text-[10.5px] uppercase tracking-wider truncate" style="color:#9CD3F0">{{ str_replace('_', ' ', $user->role) }}</p>
                    </div>
                    <form x-show="sidebarOpen" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Logout" aria-label="Logout"
                            class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white/15" style="color:#D6ECFA">
                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- ==================== MAIN CONTENT AREA ==================== -->
        <div class="flex-1 flex flex-col overflow-hidden min-w-0">
            <!-- Header -->
            <header class="bg-slate-900 border-b border-slate-800 sticky top-0 z-40">
                <div class="flex items-center justify-between gap-3 px-3 lg:px-6 py-3">
                    <!-- Logo & Title -->
                    <div class="flex items-center gap-3 min-w-0">
                        <button @click="sidebarOpen = !sidebarOpen" aria-label="Buka menu"
                            class="lg:hidden w-10 h-10 flex items-center justify-center rounded-xl border border-slate-800 text-slate-400 hover:text-white flex-shrink-0">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <div class="min-w-0">
                            <h1 class="text-lg lg:text-xl font-bold text-white truncate">{{ $header ?? 'Dashboard' }}</h1>
                            <p class="text-xs lg:text-sm text-slate-400 truncate">{{ $subheader ?? '' }}</p>
                        </div>
                    </div>

                    <!-- Right Side: Notifications & User Menu -->
                    <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">

                        <x-quick-search />

                        <!-- Ganti tema terang/gelap -->
                        <button type="button" x-data="themeSwitch" @click="toggle()"
                            :title="current === 'dark' ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'"
                            :aria-label="current === 'dark' ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'"
                            class="p-2.5 text-slate-400 hover:text-white hover:bg-slate-800/80 rounded-xl transition-colors border border-slate-800 hover:border-cyan-500/50">
                            <svg x-show="current === 'dark'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                            <svg x-show="current !== 'dark'" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
                        </button>

                        <!-- Notifications Dropdown -->
                        <div class="relative" x-data="{ open: false }" @click.away="open = false">
                            <button @click="open = !open"
                                class="relative p-2.5 text-slate-400 hover:text-white hover:bg-slate-800/80 rounded-xl transition-all duration-200 group border border-slate-800 hover:border-cyan-500/50">
                                <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                                @if ($workTotal > 0)
                                    <span data-work-badge
                                        class="absolute -top-1 -right-1 min-w-[1.25rem] h-5 px-1 bg-gradient-to-br from-red-500 to-pink-600 text-on-accent text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-slate-900 shadow-lg shadow-red-500/50">
                                        {{ $workTotal > 99 ? '99+' : $workTotal }}
                                    </span>
                                @endif
                            </button>

                            <!-- Daftar pekerjaan yang menunggu (Tugas Hari Ini) -->
                            <div x-show="open" x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="work-dropdown bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl overflow-hidden"
                                x-cloak>
                                <div class="bg-gradient-to-br from-slate-800 to-slate-900 border-b border-slate-700 px-5 py-4">
                                    <h3 class="text-sm font-bold text-white">Tugas Hari Ini</h3>
                                    <p class="text-xs text-slate-400">
                                        {{ $workTotal > 0 ? $workTotal . ' pekerjaan menunggu' : 'Tidak ada pekerjaan yang menunggu' }}
                                    </p>
                                </div>

                                <div class="max-h-96 overflow-y-auto custom-scrollbar">
                                    @forelse (collect($workItems)->where('count', '>', 0) as $item)
                                        <a href="{{ $item['url'] }}"
                                            class="flex items-center justify-between gap-3 px-5 py-3 border-b border-slate-800 hover:bg-slate-800/50 transition-colors">
                                            <span class="min-w-0">
                                                <span class="block text-sm text-white font-medium truncate">{{ $item['label'] }}</span>
                                                <span class="block text-xs text-slate-400 truncate">{{ $item['hint'] }}</span>
                                            </span>
                                            <span class="flex-shrink-0 min-w-[2rem] px-2 py-1 rounded-lg bg-cyan-500/15 text-cyan-400 text-sm font-bold text-center">
                                                {{ $item['count'] }}
                                            </span>
                                        </a>
                                    @empty
                                        <div class="px-5 py-10 text-center">
                                            <p class="text-sm text-emerald-400 font-medium">Semua beres</p>
                                            <p class="text-xs text-slate-500 mt-1">Tidak ada order, PO, atau opname yang menunggu.</p>
                                        </div>
                                    @endforelse
                                </div>

                                <div class="bg-slate-800/50 border-t border-slate-700 px-5 py-3">
                                    <a href="{{ route('gudang.tugas') }}"
                                        class="text-xs text-cyan-400 hover:text-cyan-300 font-semibold transition-colors">
                                        Buka Tugas Saya
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- User Menu Dropdown -->
                        <div class="relative" x-data="{ open: false }" @click.away="open = false">
                            <button @click="open = !open"
                                class="flex items-center gap-3 p-1.5 rounded-xl hover:bg-slate-800/80 transition-all duration-200 border border-slate-800 hover:border-cyan-500/50">
                                <div class="text-right hidden sm:block">
                                    <p class="text-sm font-semibold text-white">{{ auth()->user()->name ?? 'User' }}
                                    </p>
                                    <p class="text-[10px] text-slate-400 uppercase tracking-wider">
                                        {{ auth()->user()->role ?? '' }}</p>
                                </div>
                                <x-avatar :user="auth()->user()" />
                            </button>

                            <!-- User Dropdown Menu - MODERN FUTURISTIC -->
                            <div x-show="open" x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute right-0 mt-3 w-56 bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl overflow-hidden"
                                style="z-index: 1000;" x-cloak>
                                <!-- User Info Header -->
                                <div
                                    class="bg-gradient-to-br from-slate-800 to-slate-900 border-b border-slate-700 px-4 py-3">
                                    <p class="text-sm font-semibold text-white">{{ auth()->user()->name ?? 'User' }}
                                    </p>
                                    <p class="text-xs text-slate-400 truncate">{{ auth()->user()->email ?? '' }}</p>
                                </div>

                                <!-- Menu Items -->
                                <div class="py-1">
                                    <a href="{{ route('settings.update-profile') }}"
                                        class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition-colors group">
                                        <svg class="w-4 h-4 text-slate-500 group-hover:text-cyan-400 transition-colors"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                        <span>Profil</span>
                                    </a>
                                    @if ($user->role === 'OWNER')
                                    <a href="{{ route('settings.index') }}"
                                        class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition-colors group">
                                        <svg class="w-4 h-4 text-slate-500 group-hover:text-cyan-400 transition-colors"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        <span>Pengaturan</span>
                                    </a>
                                    @endif
                                </div>

                                <!-- Logout -->
                                <div class="border-t border-slate-700 py-1">
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit"
                                            class="w-full text-left px-4 py-2.5 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-colors flex items-center gap-3 group">
                                            <svg class="w-4 h-4 group-hover:scale-110 transition-transform"
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                            </svg>
                                            <span>Logout</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content (Scrollable) -->
            <main id="main-content" class="flex-1 overflow-y-auto bg-slate-950 p-4 lg:p-6">
                @isset($slot)
                    {{ $slot }}
                @else
                    @yield('content')
                @endisset
            </main>
        </div>
    </div>
    <!-- ✅ FIX: Load JS dengan defer di footer -->
    @vite(['resources/js/app.js'])
</body>

</html>
