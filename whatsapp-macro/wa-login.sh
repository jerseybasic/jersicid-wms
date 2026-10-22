#!/usr/bin/env bash
# Makro WhatsApp Web dari terminal (Linux / macOS)
#
#   ./wa-login.sh            buka menu (login QR otomatis jika belum login)
#   ./wa-login.sh --gui      sama, tapi jendela browser ditampilkan (debug)
#   ./wa-login.sh --browser  mode lama: Chrome biasa + ekstensi
set -e

DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
BOT_DIR="$DIR/bot"
SERVER_DIR="$DIR/server"
EXT_DIR="$DIR/extension"

need_node() {
  if ! command -v node >/dev/null 2>&1; then
    echo "Node.js belum terpasang. Install: sudo apt install nodejs npm"
    exit 1
  fi
}

install_deps() { # $1 = folder, $2 = modul penanda
  if [ ! -d "$1/node_modules/$2" ]; then
    echo "Memasang dependensi (sekali saja)..."
    (cd "$1" && npm install --silent --no-audit --no-fund)
  fi
}

need_node

# ---------- mode lama: Chrome biasa + ekstensi ----------
if [ "$1" = "--browser" ]; then
  shift
  [ "$1" = "--exit" ] && export WA_EXIT_ON_LOGIN=1
  install_deps "$SERVER_DIR" qrcode-terminal
  BROWSER=""
  for b in google-chrome google-chrome-stable chromium chromium-browser brave-browser microsoft-edge; do
    if command -v "$b" >/dev/null 2>&1; then BROWSER="$b"; break; fi
  done
  if [ -n "$BROWSER" ]; then
    "$BROWSER" --load-extension="$EXT_DIR" "https://web.whatsapp.com" >/dev/null 2>&1 &
  else
    xdg-open "https://web.whatsapp.com" >/dev/null 2>&1 || true
  fi
  pkill -f "server/server.js" 2>/dev/null && sleep 0.5 || true
  exec node "$SERVER_DIR/server.js"
fi

# ---------- mode headless (default) ----------
# --exit dari versi lama tidak diperlukan lagi: mode login otomatis keluar setelah berhasil
ARGS=()
for a in "$@"; do [ "$a" != "--exit" ] && ARGS+=("$a"); done

install_deps "$BOT_DIR" puppeteer-core

# Path file job relatif terhadap folder tempat perintah dijalankan
exec node "$BOT_DIR/bot.js" "${ARGS[@]}"
