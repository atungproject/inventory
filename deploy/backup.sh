#!/bin/sh
# Backup harian database inventaris + file upload TRIO Inventory Control.
# Pakai sqlite3 ".backup" — aman dijalankan walau server sedang berjalan.
# Jadwalkan via cron (lihat DEPLOY.md bagian A9):
#   152 * * * * /opt/trio/deploy/backup.sh >> /var/log/trio-backup.log2>&1
set -e

APP_DIR="${APP_DIR:-/opt/trio}"
DEST="${DEST:-/var/backups/trio}"
KEEP_DAYS="${KEEP_DAYS:-14}"

STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$DEST"

sqlite3 "$APP_DIR/data/trio.db" ".backup '$DEST/trio-$STAMP.db'"
if [ -d "$APP_DIR/uploads" ]; then
    tar czf "$DEST/uploads-$STAMP.tar.gz" -C "$APP_DIR" uploads 2>/dev/null || true
fi

# bersihkan backup yang lebih tua dari KEEP_DAYS
find "$DEST" -name 'trio-*.db' -mtime "+$KEEP_DAYS" -delete
find "$DEST" -name 'uploads-*.tar.gz' -mtime "+$KEEP_DAYS" -delete

echo "[$(date '+%Y-%m-%d %H:%M:%S')] backup ok -> $DEST/trio-$STAMP.db"
