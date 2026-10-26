@props([
    // id input yang diisi hasil scan kamera
    'for',
    // Setelah terisi, form input itu langsung dikirim
    'submit' => true,
    'title' => 'Scan pakai kamera',
])

{{-- Tombol kamera untuk satu kolom isian: sekali scan, kamera tertutup dan kolom terisi --}}
<button type="button" onclick="cameraFill(@js($for), @js((bool) $submit), @js($title))" title="{{ $title }}"
    {{ $attributes->merge(['class' => 'shrink-0 flex items-center justify-center gap-2 px-4 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-bold rounded-xl']) }}>
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    <span>{{ $slot->isEmpty() ? 'Kamera' : $slot }}</span>
</button>

@once
    <script>
        async function cameraFill(id, submit, title) {
            const input = document.getElementById(id);
            await window.openCameraScan((code, camera) => {
                input.value = code;
                camera.close();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                if (submit && input.form) {
                    input.form.requestSubmit();
                } else {
                    input.focus();
                }
            }, { title: title });
        }
    </script>
@endonce
