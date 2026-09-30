@echo off
cd /d "%~dp0"
echo ============================================
echo   TRIO INVENTORY CONTROL
echo   Membuka http://localhost:8000
echo   Tekan Ctrl+C untuk berhenti
echo ============================================
python server.py %*
if errorlevel 1 pause
