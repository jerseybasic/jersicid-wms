// Meneruskan pesan dari content script ke server terminal lokal.
const SERVER = "http://127.0.0.1:8765/event";

chrome.runtime.onMessage.addListener((msg) => {
  fetch(SERVER, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...msg, at: Date.now() }),
  }).catch(() => {
    // Server terminal belum jalan; abaikan.
  });
});
