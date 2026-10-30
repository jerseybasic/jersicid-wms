import defaultTheme from 'tailwindcss/defaultTheme';
import defaultColors from 'tailwindcss/colors';
import plugin from 'tailwindcss/plugin';

/*
 * Tema Terang/Gelap.
 * Semua warna slate dan warna aksen dibaca dari CSS variable, jadi kelas yang sudah ada
 * (bg-slate-900, text-cyan-400, border-slate-800, ...) otomatis ikut berganti saat
 * <html> diberi kelas "light" atau "dark". Mode gelap memakai nilai Tailwind asli,
 * sehingga tampilan gelap tidak berubah. Tidak ada CSS ganda per halaman.
 */
const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
const ACCENTS = ['cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal'];

// Mode terang: shade mana yang dipakai untuk tiap shade di kelas.
// Teks aksen 300/400 (terang di latar gelap) jadi 700 supaya tetap kontras di latar putih,
// latar aksen 800/900/950 (gelap) jadi 200/100/50.
const ACCENT_LIGHT = { 50: '950', 100: '900', 200: '800', 300: '700', 400: '700', 500: '600', 600: '600', 700: '700', 800: '200', 900: '100', 950: '50' };
const ACCENT_BORDER_LIGHT = { 50: '50', 100: '100', 200: '200', 300: '300', 400: '400', 500: '500', 600: '400', 700: '300', 800: '200', 900: '200', 950: '100' };

// Slate di mode terang: latar gelap jadi terang, teks terang jadi gelap.
const SLATE_LIGHT = {
    50: '#0B1220', 100: '#0F172A', 200: '#1E293B', 300: '#334155', 400: '#5B6B82', 500: '#64748B',
    600: '#E2E8F0', 700: '#EEF2F7', 800: '#F8FAFC', 900: '#FFFFFF', 950: '#F3F6FB',
};
const SLATE_BORDER_LIGHT = {
    50: '#334155', 100: '#475569', 200: '#64748B', 300: '#94A3B8', 400: '#94A3B8', 500: '#94A3B8',
    600: '#CBD5E1', 700: '#D6DEE8', 800: '#E2E8F0', 900: '#E2E8F0', 950: '#E2E8F0',
};

const rgb = (hex) => {
    const n = parseInt(hex.slice(1), 16);
    return `${(n >> 16) & 255} ${(n >> 8) & 255} ${n & 255}`;
};
const ref = (name) => `rgb(var(--${name}) / <alpha-value>)`;

const PALETTES = ['slate', ...ACCENTS];
const colorVars = {};
const borderVars = {};
for (const p of PALETTES) {
    colorVars[p] = {};
    borderVars[p] = {};
    for (const s of SHADES) {
        colorVars[p][s] = ref(`c-${p}-${s}`);
        borderVars[p][s] = ref(`b-${p}-${s}`);
    }
}

function themeVariables() {
    const dark = { '--c-white': '255 255 255', 'color-scheme': 'dark' };
    const light = { '--c-white': rgb('#0F172A'), 'color-scheme': 'light' };
    for (const s of SHADES) {
        dark[`--c-slate-${s}`] = rgb(defaultColors.slate[s]);
        dark[`--b-slate-${s}`] = rgb(defaultColors.slate[s]);
        light[`--c-slate-${s}`] = rgb(SLATE_LIGHT[s]);
        light[`--b-slate-${s}`] = rgb(SLATE_BORDER_LIGHT[s]);
        for (const p of ACCENTS) {
            dark[`--c-${p}-${s}`] = rgb(defaultColors[p][s]);
            dark[`--b-${p}-${s}`] = rgb(defaultColors[p][s]);
            light[`--c-${p}-${s}`] = rgb(defaultColors[p][ACCENT_LIGHT[s]]);
            light[`--b-${p}-${s}`] = rgb(defaultColors[p][ACCENT_BORDER_LIGHT[s]]);
        }
    }
    return { ':root, html.dark': dark, 'html.light': light };
}

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },
            colors: colorVars,
            borderColor: borderVars,
            textColor: {
                // Teks putih di atas latar gelap ikut jadi gelap di mode terang.
                white: ref('c-white'),
                // Teks yang selalu putih (di atas tombol/latar berwarna).
                'on-accent': '#FFFFFF',
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
        plugin(({ addBase }) => addBase(themeVariables())),
    ],
};
