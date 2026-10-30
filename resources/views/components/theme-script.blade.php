{{-- Pasang tema sebelum halaman digambar supaya tidak ada kedip putih/gelap.
     Pilihan: light, dark, atau system (ikut setelan perangkat). --}}
<script>
    (function () {
        var mode = 'system';
        try { mode = localStorage.getItem('theme') || 'system'; } catch (e) {}
        var dark = mode === 'dark' || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var html = document.documentElement;
        html.classList.remove('dark', 'light');
        html.classList.add(dark ? 'dark' : 'light');
        html.style.colorScheme = dark ? 'dark' : 'light';
    })();
</script>
