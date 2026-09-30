@echo off
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo PHP 8.2+ tidak ditemukan. Install PHP lalu tambahkan folder php.exe ke PATH.
  pause
  exit /b 1
)
echo ============================================
echo   TRIO INVENTORY CONTROL
echo   Lokal : http://localhost:8123
echo   LAN   : http://IP-KOMPUTER-ANDA:8123
echo   Data  : %~dp0data\trio.db
echo   Tekan Ctrl+C untuk berhenti
echo ============================================
php -S 0.0.0.0:8123 router.php
if errorlevel 1 pause
