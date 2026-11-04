#!/usr/bin/env bash
# Agen WhatsApp ringan JERSIC.ID WMS (Linux / macOS)
#
#   ./start.sh                  jalankan agen (pertama kali: pasang dependensi + isi alamat WMS & token)
#   ./start.sh --setup          ubah alamat WMS / token
#   ./start.sh --pasang-otomatis  jalan otomatis di latar belakang setiap komputer menyala (systemd)
#   ./start.sh --hapus-otomatis   hentikan dan hapus jalan otomatis
set -e
DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
cd "$DIR"

if ! command -v node >/dev/null 2>&1; then
  echo "Node.js belum terpasang. Butuh Node.js 20 atau lebih baru: https://nodejs.org"
  exit 1
fi
if [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  echo "Node.js $(node -v) terlalu lama. Butuh Node.js 20 atau lebih baru: https://nodejs.org"
  exit 1
fi
if [ ! -d node_modules/@whiskeysockets/baileys ]; then
  echo "Memasang dependensi (sekali saja)..."
  npm install --omit=dev --no-audit --no-fund --silent
fi

UNIT="$HOME/.config/systemd/user/jersic-wa-agent.service"
case "$1" in
  --setup)
    exec node agent.mjs --setup
    ;;
  --pasang-otomatis)
    [ -f config.json ] || node agent.mjs --setup
    mkdir -p "$(dirname "$UNIT")"
    cat > "$UNIT" <<UNIT_EOF
[Unit]
Description=Agen WhatsApp JERSIC.ID WMS
After=network-online.target

[Service]
WorkingDirectory=$DIR
ExecStart=$(command -v node) --max-old-space-size=192 $DIR/agent.mjs
Restart=always
RestartSec=10

[Install]
WantedBy=default.target
UNIT_EOF
    systemctl --user daemon-reload
    systemctl --user enable --now jersic-wa-agent
    loginctl enable-linger "$USER" 2>/dev/null || true
    echo "Agen berjalan otomatis. Status: systemctl --user status jersic-wa-agent"
    echo "Log: journalctl --user -u jersic-wa-agent -f"
    echo "Sekarang buka WMS → Pengaturan → Notifikasi WhatsApp untuk scan QR."
    exit 0
    ;;
  --hapus-otomatis)
    systemctl --user disable --now jersic-wa-agent 2>/dev/null || true
    rm -f "$UNIT"
    systemctl --user daemon-reload
    echo "Jalan otomatis dihapus."
    exit 0
    ;;
esac

[ -f config.json ] || [ -n "$WMS_TOKEN" ] || node agent.mjs --setup
exec node --max-old-space-size=192 agent.mjs
