// ============================================
// IMPORT ALPINE.JS
// ============================================
import Alpine from 'alpinejs';
import '../css/app.css';

// ============================================
// THEME MANAGER - Dark/Light Mode
// ============================================
class ThemeManager {
    constructor() {
        this.theme = localStorage.getItem('theme') || 'dark';
    }

    init() {
        this.applyTheme(this.theme);
        window.addEventListener('storage', (e) => {
            if (e.key === 'theme') {
                this.applyTheme(e.newValue || 'dark');
            }
        });
    }

    applyTheme(theme) {
        const html = document.documentElement;
        html.classList.remove('dark', 'light');

        if (theme === 'light') {
            html.classList.add('light');
        } else {
            html.classList.add('dark');
        }

        this.theme = theme;
        localStorage.setItem('theme', theme);
        this.updateToggleIcon(theme);
    }

    toggle() {
        const newTheme = this.theme === 'dark' ? 'light' : 'dark';
        this.applyTheme(newTheme);
    }

    updateToggleIcon(theme) {
        const sunIcon = document.getElementById('sun-icon');
        const moonIcon = document.getElementById('moon-icon');

        if (sunIcon && moonIcon) {
            if (theme === 'light') {
                sunIcon.classList.remove('hidden');
                moonIcon.classList.add('hidden');
            } else {
                sunIcon.classList.add('hidden');
                moonIcon.classList.remove('hidden');
            }
        }
    }
}

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

// Theme Manager - Global
const themeManagerInstance = new ThemeManager();

window.themeManager = {
    theme: themeManagerInstance.theme,
    toggle: function() {
        themeManagerInstance.toggle();
        this.theme = themeManagerInstance.theme;
    },
    init: function() {
        themeManagerInstance.init();
    }
};

// Global function untuk onclick handler di HTML
window.toggleTheme = function() {
    if (window.themeManager) {
        window.themeManager.toggle();
    } else {
        console.error('❌ themeManager not loaded yet!');
    }
};

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

// Initialize theme manager sebelum Alpine start
themeManagerInstance.init();

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

// Initialize theme manager
if (window.themeManager) {
    window.themeManager.init();
}

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
