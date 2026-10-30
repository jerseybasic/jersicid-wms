{{-- Menjaga posisi scroll menu sidebar saat pindah halaman, supaya tidak kembali ke atas. --}}
<script>
    (function () {
        var nav = document.querySelector('aside nav');
        if (!nav) return;
        var key = 'sidebar-scroll';

        function save() {
            try { sessionStorage.setItem(key, String(nav.scrollTop)); } catch (e) {}
        }

        function restore() {
            var saved = null;
            try { saved = sessionStorage.getItem(key); } catch (e) {}
            if (saved !== null) nav.scrollTop = parseInt(saved, 10) || 0;

            // Pastikan menu yang sedang dibuka tetap terlihat
            var active = null;
            nav.querySelectorAll('a[href]').forEach(function (a) {
                if (!active && a.pathname === location.pathname && a.offsetParent) active = a;
            });
            if (active) {
                var top = active.getBoundingClientRect().top - nav.getBoundingClientRect().top;
                if (top < 0 || top + active.offsetHeight > nav.clientHeight) {
                    nav.scrollTop += top - nav.clientHeight / 3;
                }
            }
        }

        // Grup menu baru terbuka/tertutup setelah Alpine jalan, jadi pulihkan setelahnya
        if (window.Alpine) {
            requestAnimationFrame(restore);
        } else {
            document.addEventListener('alpine:initialized', function () { requestAnimationFrame(restore); });
        }

        nav.addEventListener('click', save);
        window.addEventListener('pagehide', save);
    })();
</script>
