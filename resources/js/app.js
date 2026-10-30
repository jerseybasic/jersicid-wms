// ============================================
// IMPORT ALPINE.JS
// ============================================
import Alpine from 'alpinejs';
import '../css/app.css';

// ============================================
// TEMA - Terang / Gelap / Sistem
// Kelas awal sudah dipasang <x-theme-script> di <head> (tanpa kedip).
// Di sini hanya mengganti, menyimpan, dan memberi tahu halaman (event "themechange").
// ============================================
const THEME_KEY = 'theme';
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');
let themeSwitchTimer;

function storedTheme() {
    try {
        return localStorage.getItem(THEME_KEY) || 'system';
    } catch (e) {
        return 'system';
    }
}

function resolveTheme(mode) {
    return mode === 'dark' || (mode === 'system' && darkQuery.matches) ? 'dark' : 'light';
}

function applyTheme(mode, animate) {
    const html = document.documentElement;
    const resolved = resolveTheme(mode);

    // Transisi warna hanya selama pergantian, bukan sepanjang waktu
    if (animate && !html.classList.contains(resolved)) {
        html.classList.add('theme-switching');
        clearTimeout(themeSwitchTimer);
        themeSwitchTimer = setTimeout(() => html.classList.remove('theme-switching'), 300);
    }

    html.classList.remove('dark', 'light');
    html.classList.add(resolved);
    html.style.colorScheme = resolved;
    window.dispatchEvent(new CustomEvent('themechange', { detail: { mode, theme: resolved } }));
}

window.theme = {
    get mode() {
        return storedTheme();
    },
    get current() {
        return resolveTheme(storedTheme());
    },
    set(mode) {
        try {
            localStorage.setItem(THEME_KEY, mode);
        } catch (e) {}
        applyTheme(mode, true);
    },
    toggle() {
        this.set(this.current === 'dark' ? 'light' : 'dark');
    },
};

window.toggleTheme = () => window.theme.toggle();

// Mode "Sistem" ikut berubah saat setelan perangkat berubah
darkQuery.addEventListener('change', () => {
    if (storedTheme() === 'system') applyTheme('system', true);
});

// Tab lain mengganti tema
window.addEventListener('storage', (e) => {
    if (e.key === THEME_KEY) applyTheme(storedTheme(), true);
});

// ============================================
// LAYOUT MANAGER - Sidebar & Responsive
// ============================================
class LayoutManagerClass {
    constructor() {
        this.sidebarOpen = false;
        this.sidebarHidden = localStorage.getItem('sidebar_hidden') === 'true';
        this.sidebarCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        this.moreDrawerOpen = false;
        this.checkScreenSize();
    }

    checkScreenSize() {
        const width = window.innerWidth;
        this.isDesktop = width >= 1024;
        this.isTablet = width >= 768 && width < 1024;
        this.isMobile = width < 768;
    }

    init() {
        this.checkScreenSize();
        this.applySidebarState();
    }

    applySidebarState() {
        const sidebar = document.querySelector('.app-sidebar');
        if (!sidebar) return;

        sidebar.classList.remove('sidebar-open', 'sidebar-hidden', 'sidebar-collapsed');

        if (this.isDesktop) {
            if (this.sidebarHidden) {
                sidebar.classList.add('sidebar-hidden');
            } else if (this.sidebarCollapsed) {
                sidebar.classList.add('sidebar-collapsed');
            }
        } else {
            if (this.sidebarOpen) {
                sidebar.classList.add('sidebar-open');
            }
        }
    }

    handleResize() {
        const wasDesktop = this.isDesktop;
        this.checkScreenSize();

        if (wasDesktop && !this.isDesktop) {
            this.sidebarOpen = false;
            this.sidebarHidden = false;
        }

        if (!wasDesktop && this.isDesktop) {
            this.sidebarOpen = false;
        }

        this.applySidebarState();
    }

    toggleSidebar() {
        if (this.isDesktop) {
            this.sidebarHidden = !this.sidebarHidden;
            this.sidebarCollapsed = false;
            localStorage.setItem('sidebar_hidden', this.sidebarHidden);
        } else {
            this.sidebarOpen = !this.sidebarOpen;
        }
        this.applySidebarState();
    }

    toggleCollapse() {
        if (!this.isDesktop) return;

        this.sidebarCollapsed = !this.sidebarCollapsed;
        this.sidebarHidden = false;
        localStorage.setItem('sidebar_collapsed', this.sidebarCollapsed);
        this.applySidebarState();
    }

    toggleMoreDrawer() {
        this.moreDrawerOpen = !this.moreDrawerOpen;
    }

    closeSidebar() {
        this.sidebarOpen = false;
        this.applySidebarState();
    }
}

// ============================================
// EXPOSE KE WINDOW (GLOBAL) - PENTING!
// ============================================

// Layout Manager - Global Function untuk Alpine
window.layoutManager = function() {
    const manager = new LayoutManagerClass();

    return {
        sidebarOpen: manager.sidebarOpen,
        sidebarHidden: manager.sidebarHidden,
        sidebarCollapsed: manager.sidebarCollapsed,
        moreDrawerOpen: manager.moreDrawerOpen,
        isDesktop: manager.isDesktop,
        isTablet: manager.isTablet,
        isMobile: manager.isMobile,

        init: function() {
            manager.init();
            this.sidebarOpen = manager.sidebarOpen;
            this.sidebarHidden = manager.sidebarHidden;
            this.sidebarCollapsed = manager.sidebarCollapsed;
            this.isDesktop = manager.isDesktop;
            this.isTablet = manager.isTablet;
            this.isMobile = manager.isMobile;
        },

        handleResize: function() {
            manager.handleResize();
            this.sidebarOpen = manager.sidebarOpen;
            this.sidebarHidden = manager.sidebarHidden;
            this.sidebarCollapsed = manager.sidebarCollapsed;
            this.isDesktop = manager.isDesktop;
            this.isTablet = manager.isTablet;
            this.isMobile = manager.isMobile;
        },

        toggleSidebar: function() {
            manager.toggleSidebar();
            this.sidebarOpen = manager.sidebarOpen;
            this.sidebarHidden = manager.sidebarHidden;
            this.sidebarCollapsed = manager.sidebarCollapsed;
        },

        toggleCollapse: function() {
            manager.toggleCollapse();
            this.sidebarHidden = manager.sidebarHidden;
            this.sidebarCollapsed = manager.sidebarCollapsed;
        },

        toggleMoreDrawer: function() {
            manager.toggleMoreDrawer();
            this.moreDrawerOpen = manager.moreDrawerOpen;
        },

        closeSidebar: function() {
            manager.closeSidebar();
            this.sidebarOpen = manager.sidebarOpen;
        }
    };
};

// ============================================
// SCAN PAKAI KAMERA HP (dimuat hanya saat tombol kamera ditekan)
// ============================================
window.openCameraScan = (onCode, options) => import('./camera-scan.js').then((m) => m.openCameraScan(onCode, options));

// ============================================
// INITIALIZE ALPINE.JS
// ============================================
window.Alpine = Alpine;

// Tombol tema (dipakai di header & sidebar)
Alpine.data('themeSwitch', () => ({
    mode: window.theme.mode,
    current: window.theme.current,
    init() {
        window.addEventListener('themechange', (e) => {
            this.mode = e.detail.mode;
            this.current = e.detail.theme;
        });
    },
    set(mode) {
        window.theme.set(mode);
    },
    toggle() {
        window.theme.toggle();
    },
}));

// Start Alpine
Alpine.start();

// ============================================
// AUTO-UPPERCASE UNTUK INPUT SKU
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Auto-uppercase untuk semua input dengan class 'sku-input'
    document.querySelectorAll('.sku-input').forEach(input => {
        input.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
    });

    // Auto-uppercase untuk semua input dengan name yang mengandung 'sku'
    document.querySelectorAll('input[name*="sku"]').forEach(input => {
        input.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
    });

    // Auto-uppercase untuk input SKU di stock opname
    document.querySelectorAll('input[name*="SKU"], input[name*="sku"]').forEach(input => {
        input.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
    });
});

// Prevent flash on page load
document.documentElement.classList.add('page-loading');

window.addEventListener('load', () => {
    document.documentElement.classList.remove('page-loading');
    document.documentElement.classList.add('page-loaded');
});

// Tambahkan di app.js
window.toggleSidebarCollapse = function() {
    const sidebar = document.querySelector('.app-sidebar');
    const mainWrapper = document.querySelector('.app-main-wrapper');
    const collapseIcon = document.getElementById('collapseIcon');

    if (!sidebar || !mainWrapper) return;

    sidebar.classList.toggle('sidebar-collapsed');
    mainWrapper.classList.toggle('sidebar-collapsed');

    // Rotate icon
    if (collapseIcon) {
        if (sidebar.classList.contains('sidebar-collapsed')) {
            collapseIcon.style.transform = 'rotate(180deg)';
        } else {
            collapseIcon.style.transform = 'rotate(0deg)';
        }
    }

    // Save to localStorage
    const isCollapsed = sidebar.classList.contains('sidebar-collapsed');
    localStorage.setItem('sidebar_collapsed', isCollapsed);
};

// Load sidebar state on page load
document.addEventListener('DOMContentLoaded', function() {
    const isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
    const sidebar = document.querySelector('.app-sidebar');
    const mainWrapper = document.querySelector('.app-main-wrapper');
    const collapseIcon = document.getElementById('collapseIcon');

    if (isCollapsed && sidebar && mainWrapper) {
        sidebar.classList.add('sidebar-collapsed');
        mainWrapper.classList.add('sidebar-collapsed');
        if (collapseIcon) {
            collapseIcon.style.transform = 'rotate(180deg)';
        }
    }
});
