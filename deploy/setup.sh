#!/usr/bin/env bash
# =============================================================================
# TRIO Inventory Control — setup otomatis satu kali untuk VPS Linux
# (Ubuntu/Debian: Oracle Cloud Always Free, DomaiNesia VPS, dsb.)
#
# Pemakaian (setelah isi proyek di-upload ke server):
#   sudo bash deploy/setup.sh <domain> [folder-aplikasi]
#
# Contoh:
#   sudo bash deploy/setup.sh trio-inventaris.duckdns.org
#   sudo bash deploy/setup.sh triomotor.example.com /opt/trio
#
# Yang dikerjakan (setara DEPLOY.md A4-A9):
#   paket python3, user service, layanan systemd, Caddy + HTTPS otomatis,
#   firewall ufw, cron backup harian, keep-alive anti-idle (Oracle).
# Aman dijalankan berulang (idempotent).
# =============================================================================
set -euo pipefail

DOMAIN="${1:-}"
APP_DIR="${2:-/opt/trio}"
SERVICE_USER="trio"
SERVICE_NAME="trio"
HTTP_PORT="8000"

die() { echo "ERROR: $*" >&2; exit 1; }

# --- pemeriksaan awal --------------------------------------------------------
if [ -z "$DOMAIN" ]; then
  echo "Pemakaian: sudo bash deploy/setup.sh <domain> [folder-aplikasi]" >&2
  echo "contoh   : sudo bash deploy/setup.sh trio-inventaris.duckdns.org" >&2
  exit 1
fi
case "$DOMAIN" in
  *.*) ;;
  *) die "format domain tidak wajar: $DOMAIN" ;;
esac
[ "$EUID" -eq 0 ] || die "jalankan dengan sudo / sebagai root"
command -v apt-get >/dev/null 2>&1 || die "butuh sistem berbasis apt (Ubuntu/Debian)"
case "$APP_DIR" in
  *" "*) die "hindari spasi di path aplikasi: $APP_DIR" ;;
esac
[ -f "$APP_DIR/server.py" ] || die "$APP_DIR/server.py tidak ditemukan — upload dulu isi proyek ke $APP_DIR (lihat DEPLOY.md langkah A3), lalu jalankan lagi script ini"
[ -f "$APP_DIR/deploy/trio.service" ] || die "$APP_DIR/deploy/trio.service tidak ada — pastikan folder deploy/ ikut ter-upload"

echo "[1/8] Domain : $DOMAIN"
echo "      Folder : $APP_DIR"

# ---2: paket ----------------------------------------------------------------
echo "[2/8] Install paket (python3 sqlite3 ufw curl caddy-deps) ..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y python3 sqlite3 ufw curl ca-certificates gnupg \
  debian-keyring debian-archive-keyring apt-transport-https
command -v crontab >/dev/null 2>&1 || apt-get install -y cron

# ---3: user service + hak akses --------------------------------------------
echo "[3/8] User service '$SERVICE_USER' + hak akses folder ..."
if ! id "$SERVICE_USER" >/dev/null 2>&1; then
  useradd -r -M -s /usr/sbin/nologin "$SERVICE_USER"
fi
chown -R "$SERVICE_USER:$SERVICE_USER" "$APP_DIR"

# ---4: layanan systemd ------------------------------------------------------
echo "[4/8] Layanan systemd '$SERVICE_NAME' (auto-start + auto-restart) ..."
sed "s|/opt/trio|$APP_DIR|g" "$APP_DIR/deploy/trio.service" > "/etc/systemd/system/$SERVICE_NAME.service"
systemctl daemon-reload
systemctl enable "$SERVICE_NAME" >/dev/null 2>&1 || true
systemctl restart "$SERVICE_NAME"
sleep 2
BACKEND_CODE="$(curl -s -o /dev/null -w '%{http_code}' -m5 "http://127.0.0.1:$HTTP_PORT/" || true)"
if systemctl is-active --quiet "$SERVICE_NAME" && [ "$BACKEND_CODE" = "200" ]; then
  echo "      OK — backend merespons HTTP $BACKEND_CODE."
else
  echo "      PERINGATAN: backend belum sehat (status=$(systemctl is-active "$SERVICE_NAME" || true), code=$BACKEND_CODE)."
  echo "      Cek: journalctl -u $SERVICE_NAME -e"
fi

# ---5: Caddy + HTTPS otomatis ----------------------------------------------
echo "[5/8] Caddy (sertifikat HTTPS Let's Encrypt otomatis) ..."
if ! command -v caddy >/dev/null 2>&1; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -y
  apt-get install -y caddy
fi
# nginx (kalau kebetulan terpasang) berebut port80/443 → matikan
if systemctl is-active --quiet nginx 2>/dev/null; then
  echo "      nginx terdeteksi — dimatikan agar Caddy bisa memegang port80/443."
  systemctl disable --now nginx >/dev/null 2>&1 || true
fi
mkdir -p /etc/caddy
printf '%s {\n\treverse_proxy 127.0.0.1:%s\n}\n' "$DOMAIN" "$HTTP_PORT" > /etc/caddy/Caddyfile
systemctl enable caddy >/dev/null 2>&1 || true
systemctl restart caddy

# ---6: firewall -------------------------------------------------------------
echo "[6/8] Firewall ufw: SSH(22), HTTP(80), HTTPS(443) ..."
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 22/tcp >/dev/null 2>&1 || true
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status | sed -n '1,8p' | sed 's/^/      /'

# ---7: cron backup + keep-alive --------------------------------------------
echo "[7/8] Cron: backup harian 02:15 + keep-alive anti-idle tiap10 menit ..."
chmod +x "$APP_DIR/deploy/backup.sh"
add_cron() {
  local marker="$1" line="$2" cur
  cur="$(crontab -l 2>/dev/null || true)"
  cur="$(printf '%s\n' "$cur" | grep -vF "$marker" || true)"
  printf '%s\n%s\n' "$cur" "$line" | sed '/^[[:space:]]*$/d' | crontab -
}
add_cron "deploy/backup.sh" \
  "15 2 * * * $APP_DIR/deploy/backup.sh >> /var/log/trio-backup.log 2>&1"
add_cron "https://$DOMAIN/" \
  "*/10 * * * * curl -s -o /dev/null -m15 https://$DOMAIN/ || true"
systemctl enable cron >/dev/null 2>&1 || systemctl enable crond >/dev/null 2>&1 || true

# ---8: verifikasi HTTPS -----------------------------------------------------
echo "[8/8] Verifikasi HTTPS (maks 60 detik; DNS harus sudah menunjuk ke IP server) ..."
HTTPS_OK="tidak"
for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
  CODE="$(curl -s -o /dev/null -w '%{http_code}' -m10 "https://$DOMAIN/" || true)"
  if [ "$CODE" = "200" ]; then HTTPS_OK="ya"; break; fi
  sleep 5
done

cat <<SUMMARY

================================================================
 SELESAI — Rangkuman
================================================================
 Aplikasi  : $APP_DIR   (systemd: $SERVICE_NAME, port $HTTP_PORT)
 URL       : https://$DOMAIN
 Database  : $APP_DIR/data/trio.db
 Backup    : cron 02:15 harian -> /var/backups/trio (+ unduh rutin ke PC)
 Keep-alive: tiap 10 menit (anti "idle reclaim" Oracle Cloud)
================================================================
SUMMARY

if [ "$HTTPS_OK" = "ya" ]; then
  echo " HTTPS berfungsi (HTTP 200) — kamera QR siap dipakai."
else
  cat <<WARN
 HTTPS belum bisa diverifikasi. Periksa:
 1. DNS domain sudah menunjuk ke IP server ini & sudah propagasi
      nslookup $DOMAIN
 2. Port 80/443 terbuka di firewall CLOUD (Oracle: Security List VCN;
    DomaiNesia: firewall panel VPS).
 3. Log Caddy: journalctl -u caddy -e
 Caddy akan terus mencoba otomatis — cukup ulangi perintah ini nanti:
      sudo bash $APP_DIR/deploy/setup.sh $DOMAIN
WARN
fi

cat <<TODO
----------------------------------------------------------------
 Yang harus Anda lakukan MANUAL (wajib):
1. Buka https://$DOMAIN  -> login  admin / admin123
2. SEGERA ganti password admin (ikon kunci di topbar), lalu password
   auditor & cabang lewat menu User & Akses.
3. Uji login ketiga role + scan QR dari HP (kamera butuh HTTPS).
4. Simpan file kunci SSH (.pem) di tempat aman.
----------------------------------------------------------------
TODO
