@echo off
REM Makro WhatsApp Web dari terminal (Windows)
REM   wa-login.bat            buka menu (login QR otomatis jika belum login)
REM   wa-login.bat --gui      tampilkan jendela browser (debug)
REM   wa-login.bat --browser  mode lama: Chrome biasa + ekstensi
setlocal
chcp 65001 >nul
set "DIR=%~dp0"
set "BOT_DIR=%DIR%bot"
set "SERVER_DIR=%DIR%server"
set "EXT_DIR=%DIR%extension"

where node >nul 2>nul
if errorlevel 1 (
  echo Node.js belum terpasang. Unduh di https://nodejs.org
  pause
  exit /b 1
)

if /i "%~1"=="--browser" goto browser

if not exist "%BOT_DIR%\node_modules\puppeteer-core" (
  echo Memasang dependensi ^(sekali saja^)...
  pushd "%BOT_DIR%"
  call npm install --silent --no-audit --no-fund
  popd
)
node "%BOT_DIR%\bot.js" %*
goto :eof

:browser
if "%~2"=="--exit" set "WA_EXIT_ON_LOGIN=1"
if not exist "%SERVER_DIR%\node_modules\qrcode-terminal" (
  pushd "%SERVER_DIR%"
  call npm install --silent --no-audit --no-fund
  popd
)
set "CHROME="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not defined CHROME if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not defined CHROME if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROME=%LocalAppData%\Google\Chrome\Application\chrome.exe"
if defined CHROME (
  start "" "%CHROME%" --load-extension="%EXT_DIR%" "https://web.whatsapp.com"
) else (
  start "" "https://web.whatsapp.com"
)
node "%SERVER_DIR%\server.js"
endlocal
