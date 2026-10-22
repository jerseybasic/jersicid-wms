// Membaca QR login WhatsApp Web dan meneruskannya ke server terminal.
// WhatsApp menyimpan isi QR di atribut data-ref pada elemen pembungkus canvas.

(() => {
  let lastRef = null;
  let lastStatus = null;

  function send(payload) {
    try {
      chrome.runtime.sendMessage(payload);
    } catch (e) {
      /* ekstensi di-reload, abaikan */
    }
  }

  function setStatus(status) {
    if (status === lastStatus) return;
    lastStatus = status;
    send({ type: "status", status });
  }

  function tick() {
    // 1. Sudah login? (daftar chat muncul)
    if (document.querySelector("#pane-side, [data-testid='chat-list']")) {
      lastRef = null;
      setStatus("logged_in");
      return;
    }

    // 2. QR kedaluwarsa -> klik tombol "muat ulang" otomatis
    const reloadBtn = document.querySelector(
      "div[data-ref] button, span[data-icon='refresh-large'], span[data-icon='refresh-l-light']"
    );
    if (reloadBtn) {
      (reloadBtn.closest("button") || reloadBtn).click();
      setStatus("qr_reloading");
      return;
    }

    // 3. Ambil QR
    const holder = document.querySelector("div[data-ref]");
    const ref = holder && holder.getAttribute("data-ref");
    if (ref && ref !== lastRef) {
      lastRef = ref;
      lastStatus = "qr";
      send({ type: "qr", ref });
    } else if (!ref) {
      setStatus("waiting");
    }
  }

  setInterval(tick, 1000);
  tick();
})();
