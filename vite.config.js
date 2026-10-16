import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        target: 'es2020', // ✅ Sudah bagus
        manifest: true,
        minify: 'terser', // ✅ Tambahkan ini
        terserOptions: {
            compress: {
                drop_console: true, // ✅ Hapus console.log di production
                drop_debugger: true,
            },
        },
        rollupOptions: {
            output: {
                manualChunks: {
                    vendor: ['alpinejs'], // ✅ Pisahkan vendor untuk caching lebih baik
                },
            },
        },
    },
});