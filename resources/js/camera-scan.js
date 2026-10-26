// ============================================
// SCAN PAKAI KAMERA HP
// Layar penuh berisi kamera belakang. Setiap barcode/QR yang terbaca dikirim ke onCode,
// lalu hasilnya ditampilkan lewat setResult(). Kamera tetap menyala sampai ditutup,
// jadi picker bisa scan barang satu per satu tanpa membuka kamera lagi.
// ============================================

// Barcode yang sama baru dihitung lagi setelah sempat hilang dari kamera selama ini (ms):
// kamera yang terus diarahkan ke satu label tidak menambah jumlah berkali-kali.
const SAME_CODE_GONE = 800;
const ANY_CODE_PAUSE = 700;

let current = null;

export async function openCameraScan(onCode, options = {}) {
    if (current) {
        return current;
    }

    const ui = buildOverlay(options.title || 'Scan pakai kamera');
    let stream = null;
    let stopDetect = () => {};
    let lastCode = '';
    let lastAt = 0;
    let lastSeen = 0;
    let closed = false;

    const controller = {
        isOpen() {
            return !closed;
        },
        setResult(ok, message) {
            ui.message.textContent = message || '';
            ui.message.style.background = ok ? 'rgba(16,185,129,.9)' : 'rgba(239,68,68,.92)';
            ui.frame.style.borderColor = ok ? '#10b981' : '#ef4444';
            clearTimeout(ui.frameTimer);
            ui.frameTimer = setTimeout(() => ui.frame.style.borderColor = 'rgba(255,255,255,.85)', 900);
        },
        close() {
            if (closed) return;
            closed = true;
            stopDetect();
            stream?.getTracks().forEach((track) => track.stop());
            ui.root.remove();
            document.body.style.overflow = '';
            current = null;
            options.onClose?.();
        },
    };
    current = controller;
    ui.close.addEventListener('click', () => controller.close());

    const found = (raw) => {
        const code = String(raw || '').trim().toUpperCase();
        const now = Date.now();
        if (!code || closed) return;
        if (code === lastCode) {
            const gone = now - lastSeen;
            lastSeen = now;
            if (gone < SAME_CODE_GONE) return;
        }
        if (now - lastAt < ANY_CODE_PAUSE) return;
        lastCode = code;
        lastAt = now;
        lastSeen = now;
        navigator.vibrate?.(60);
        ui.message.textContent = code + ' ...';
        ui.message.style.background = 'rgba(15,23,42,.85)';
        onCode(code, controller);
    };

    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        controller.setResult(false, 'Kamera hanya bisa dibuka kalau aplikasi diakses lewat https://. Pakai scanner atau ketik kodenya.');
        return controller;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        });
    } catch (e) {
        controller.setResult(false, e?.name === 'NotAllowedError'
            ? 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan browser, lalu coba lagi.'
            : 'Kamera tidak bisa dibuka: ' + (e?.message || e));
        return controller;
    }
    if (closed) {
        stream.getTracks().forEach((track) => track.stop());
        return controller;
    }

    ui.video.srcObject = stream;
    await ui.video.play().catch(() => {});
    setupTorch(stream, ui.torch);
    ui.message.textContent = 'Arahkan kamera ke barcode atau QR.';

    try {
        stopDetect = await startDetector(ui.video, found);
    } catch (e) {
        controller.setResult(false, 'Pembaca barcode gagal dimuat: ' + (e?.message || e));
    }
    if (closed) stopDetect();

    return controller;
}

/** BarcodeDetector bawaan (Chrome Android) lebih cepat; selain itu pakai ZXing */
async function startDetector(video, found) {
    const formats = ['code_128', 'code_39', 'code_93', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'codabar', 'qr_code', 'data_matrix'];

    if ('BarcodeDetector' in window) {
        try {
            const supported = await window.BarcodeDetector.getSupportedFormats();
            const usable = formats.filter((f) => supported.includes(f));
            if (usable.includes('code_128') && usable.includes('qr_code')) {
                const detector = new window.BarcodeDetector({ formats: usable });
                let running = true;
                const tick = async () => {
                    if (!running) return;
                    if (video.readyState >= 2) {
                        try {
                            const codes = await detector.detect(video);
                            if (codes.length) found(codes[0].rawValue);
                        } catch (e) {}
                    }
                    setTimeout(tick, 120);
                };
                tick();
                return () => running = false;
            }
        } catch (e) {}
    }

    const { BrowserMultiFormatReader } = await import('@zxing/browser');
    const reader = new BrowserMultiFormatReader(undefined, { delayBetweenScanAttempts: 120 });
    const controls = await reader.decodeFromVideoElement(video, (result) => {
        if (result) found(result.getText());
    });
    return () => controls.stop();
}

/** Tombol senter kalau kamera mendukung (rak yang gelap) */
function setupTorch(stream, button) {
    const track = stream.getVideoTracks()[0];
    if (!track?.getCapabilities?.().torch) return;
    let on = false;
    button.style.display = '';
    button.addEventListener('click', async () => {
        on = !on;
        try {
            await track.applyConstraints({ advanced: [{ torch: on }] });
            button.textContent = on ? 'Senter: nyala' : 'Senter';
        } catch (e) {}
    });
}

function buildOverlay(title) {
    const root = document.createElement('div');
    root.setAttribute('data-camera-scan', '');
    root.style.cssText = 'position:fixed;inset:0;z-index:9999;background:#000;display:flex;flex-direction:column;';
    root.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;background:#0f172a;color:#fff;">
            <span style="flex:1;font-weight:700;font-size:16px;"></span>
            <button type="button" data-torch style="display:none;padding:8px 12px;border-radius:10px;background:#334155;color:#fff;font-weight:600;font-size:14px;">Senter</button>
            <button type="button" data-close style="padding:8px 14px;border-radius:10px;background:#ef4444;color:#fff;font-weight:700;font-size:14px;">Tutup</button>
        </div>
        <div style="position:relative;flex:1;overflow:hidden;">
            <video playsinline muted style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;"></video>
            <div data-frame style="position:absolute;left:8%;right:8%;top:30%;bottom:30%;border:3px solid rgba(255,255,255,.85);border-radius:16px;box-shadow:0 0 0 9999px rgba(0,0,0,.35);transition:border-color .2s;"></div>
        </div>
        <p data-message style="margin:0;padding:16px;min-height:64px;color:#fff;font-weight:700;font-size:16px;text-align:center;background:rgba(15,23,42,.85);">Membuka kamera...</p>`;
    root.querySelector('span').textContent = title;
    document.body.appendChild(root);
    document.body.style.overflow = 'hidden';

    return {
        root,
        video: root.querySelector('video'),
        frame: root.querySelector('[data-frame]'),
        message: root.querySelector('[data-message]'),
        close: root.querySelector('[data-close]'),
        torch: root.querySelector('[data-torch]'),
        frameTimer: null,
    };
}
