@echo off
setlocal
rem =============================================================================
rem TRIO Inventory Control — upload + setup otomatis dari Windows ke VPS Linux.
rem Pemakaian:
rem   deploy.bat IP-VPS DOMAIN [KEY.pem] [user-ssh]
rem Contoh:
rem   deploy.bat 157.245.1.10 trio-inventaris.duckdns.org C:\keys\trio.pem ubuntu
rem Keterangan:
rem   KEY.pem  = kunci SSH dari Oracle Cloud (opsional; tanpa ini = login password)
rem   user-ssh = default "ubuntu" (gambar Ubuntu di Oracle Cloud); DomaiNesia VPS: root
rem =============================================================================
if "%~2"=="" (
  echo Cara pakai: deploy.bat IP-VPS DOMAIN [KEY.pem] [user-ssh]
  echo contoh   : deploy.bat 157.245.1.10 trio-inventaris.duckdns.org C:\keys\trio.pem
  exit /b 1
)
set "VPSIP=%~1"
set "DOMAIN=%~2"
set "KEYOPT="
if not "%~3"=="" set "KEYOPT=-i %~3"
set "VPSUSER=%~4"
if "%VPSUSER%"=="" set "VPSUSER=ubuntu"
cd /d "%~dp0.."

echo [1/4] Menyiapkan folder /opt/trio di server ...
ssh %KEYOPT% %VPSUSER%@%VPSIP% "sudo mkdir -p /opt/trio && sudo chown $USER:$USER /opt/trio"
if errorlevel 1 goto gagal

echo [2/4] Upload file aplikasi (server.py, index.html, css, js, lib, assets, deploy) ...
scp %KEYOPT% -r server.py index.html css js lib assets deploy %VPSUSER%@%VPSIP%:/opt/trio/
if errorlevel 1 goto gagal

rem Data lama diunggah HANYA bila di server belum ada (tidak menimpa data live)
for /f %%R in ('ssh %KEYOPT% %VPSUSER%@%VPSIP% "if [ -e /opt/trio/data/trio.db ]; then echo ADA; else echo KOSONG; fi"') do set "DBREM=%%R"
if not defined DBREM goto gagal
if "%DBREM%"=="KOSONG" if exist data scp %KEYOPT% -r data %VPSUSER%@%VPSIP%:/opt/trio/
for /f %%R in ('ssh %KEYOPT% %VPSUSER%@%VPSIP% "if [ -d /opt/trio/uploads ] && find /opt/trio/uploads -mindepth1 -print -quit | grep -q .; then echo ADA; else echo KOSONG; fi"') do set "UPREM=%%R"
if not defined UPREM goto gagal
if "%UPREM%"=="KOSONG" if exist uploads scp %KEYOPT% -r uploads %VPSUSER%@%VPSIP%:/opt/trio/

echo [3/4] Menjalankan setup di server (systemd + Caddy HTTPS + backup) ...
ssh %KEYOPT% %VPSUSER%@%VPSIP% "sudo bash /opt/trio/deploy/setup.sh %DOMAIN%"
if errorlevel 1 goto gagal

echo.
echo [4/4] SELESAI. Langkah berikutnya:
echo   - Buka  https://%DOMAIN%  lalu login  admin / admin123
echo   - SEGERA ganti password admin, lalu auditor ^& cabang (menu User ^& Akses)
echo   - Uji scan QR dari HP (kamera butuh HTTPS)
exit /b 0

:gagal
echo.
echo ADA ERROR di atas. Cek: IP / user / kunci SSH benar? Port 22 terbuka?
echo Panduan: DEPLOY.md (bagian Jalur A) dan troubleshooting Oracle di Jalur E.
exit /b 1
