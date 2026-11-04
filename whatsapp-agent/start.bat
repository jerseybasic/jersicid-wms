@echo off
rem Agen WhatsApp ringan JERSIC.ID WMS (Windows)
rem   start.bat          jalankan agen
rem   start.bat --setup  ubah alamat WMS / token
cd /d "%~dp0"
where node >nul 2>nul || (echo Node.js belum terpasang. Butuh Node.js 20 atau lebih baru: https://nodejs.org & pause & exit /b 1)
if not exist node_modules\@whiskeysockets\baileys (
  echo Memasang dependensi ^(sekali saja^)...
  call npm install --omit=dev --no-audit --no-fund --silent
)
if "%1"=="--setup" (
  node agent.mjs --setup
  exit /b
)
if not exist config.json node agent.mjs --setup
node --max-old-space-size=192 agent.mjs
pause
