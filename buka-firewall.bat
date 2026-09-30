@echo off
echo ============================================================
echo  TRIO Inventory - Buka firewall untuk akses dari HP
echo  (harus dijalankan dengan klik kanan ^> Run as administrator)
echo ============================================================
echo.
echo Menambahkan aturan firewall: izinkan TCP port 8123 masuk...
netsh advfirewall firewall add rule name="TRIO PHP8123" dir=in action=allow protocol=TCP localport=8123
echo.
echo --- Verifikasi aturan ---
netsh advfirewall firewall show rule name="TRIO PHP8123"
echo.
echo Selesai. Buka dari HP: http://192.168.66.102:8123
pause
