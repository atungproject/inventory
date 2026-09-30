"""Buat paket deploy shared hosting (Domainesia/cPanel) untuk TRIO INVENTORY CONTROL.

Menghasilkan:
  dist/trio-hosting/        struktur siap upload (dipakai uji lokal juga)
  dist/trio-hosting.zip     arsip untuk File Manager

Struktur ZIP TANPA folder pembungkus -- ekstrak langsung di direktori HOME (/home/user):
  public_html/   index.html, index.php (CI4), .htaccess, .user.ini,
                 favicon.ico, robots.txt, css/, js/, lib/, assets/
  app/ system/ writable/  (+ .htaccess tolak akses)
  data/ uploads/          (dibuat otomatis database; di luar docroot)
  .env                    CI_ENVIRONMENT + baseURL + zona waktu
  BACA-DULU.txt           langkah singkat

Pemakaian:  python make_sharedhost_zip.py
"""
import shutil
import zipfile
from pathlib import Path

ROOT = Path(r"C:\Users\fransiskus.jonathan\Documents\Default Project")
PHPDIR = ROOT / "php"
DIST = ROOT / "dist"
STAGE = DIST / "trio-hosting"
ZIP_PATH = DIST / "trio-hosting.zip"

DENY = """# Dilarang diakses langsung dari web
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
"""

USER_INI = """; TRIO INVENTORY CONTROL -- batas PHP untuk shared hosting
; Berlaku untuk folder ini dan turunannya (PHP CGI/FPM cPanel)
post_max_size = 16M
upload_max_filesize = 16M
memory_limit = 256M
max_execution_time = 60
"""

ENV = """CI_ENVIRONMENT = production
app.baseURL = 'https://domain-anda.example/'
app.appTimezone = 'Asia/Jakarta'
"""

BACA = """TRIO INVENTORY CONTROL -- PAKET SHARED HOSTING (CodeIgniter 4 + SQLite)
======================================================================

ISI PAKET
  public_html/   Aplikasi web (index.html, index.php CI4, css, js, lib, assets)
  app/           Kode aplikasi (di luar docroot, tidak terekspos web)
  system/        Framework CodeIgniter 4
  writable/      Log & cache (harus bisa ditulis oleh hosting)
  data/          Database SQLite -- dibuat otomatis pada kunjungan pertama
  uploads/       Foto/dokumen -- di luar docroot, disajikan lewat /uploads/
  .env           Konfigurasi (mode produksi, domain, zona waktu)
  BACA-DULU.txt  Berkas ini

LANGKAH PEMASANGAN (cPanel Domainesia)
1. cPanel > File Manager > masuk ke direktori HOME (induk public_html).
2. Upload trio-hosting.zip lalu Extract di direktori itu; konfirmasi timpa
   bila diminta. Hasil: /home/ANDA/public_html/index.html dan
   /home/ANDA/app, /home/ANDA/system, dst.
3. cPanel > MultiPHP Manager: pilih PHP 8.2 atau 8.3 untuk domain Anda.
   Pastikan ekstensi sqlite3 & pdo_sqlite aktif (Select PHP Version).
4. Pasang SSL: cPanel > SSL/TLS Status > Run AutoSSL / Let's Encrypt.
   WAJIB untuk pemindaian QR Stock Opname (kamera hanya jalan di https:// atau localhost).
5. Edit /home/ANDA/.env -> ganti app.baseURL dengan domain Anda, misal:
      app.baseURL = 'https://tokoanda.example/'
6. Buka https://domainANDA/ lalu login: admin/admin123, auditor/audit123,
   cabang/cabang123. SEGERA ganti password lewat menu User & Akses.

MASALAH UMUM? Lihat DEPLOY.md bagian "Jalur C" (troubleshooting: ubah
CI_ENVIRONMENT=development di .env, izin folder writable, versi PHP,
dan log di writable/logs/).
"""


def public_htaccess() -> str:
    original = (PHPDIR / "public" / ".htaccess").read_text(encoding="utf-8")
    header = (
        "# --- TRIO: tampilkan index.html (aplikasi) lebih dulu dari index.php ---\n"
        "DirectoryIndex index.html index.php\n"
        "\n"
        "# --- Larang unduh berkas konfigurasi PHP ---\n"
        '<FilesMatch "^\\.user\\.ini$">\n'
        "    Require all denied\n"
        "</FilesMatch>\n"
        "\n"
        "# --- Parity header statis server.py (index.html no-store + nosniff) ---\n"
        "<IfModule mod_headers.c>\n"
        "    Header set X-Content-Type-Options nosniff\n"
        '    <FilesMatch "^index\\.html$">\n'
        '        Header set Cache-Control "no-store"\n'
        "    </FilesMatch>\n"
        "</IfModule>\n"
        "\n"
        "# --- Opsional: paksa HTTPS (aktifkan setelah SSL terpasang) ---\n"
        "# <IfModule mod_rewrite.c>\n"
        "# RewriteEngine On\n"
        "# RewriteCond %{HTTPS} off\n"
        "# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n"
        "# </IfModule>\n"
        "\n"
    )
    return header + original


def build_stage() -> None:
    if STAGE.exists():
        shutil.rmtree(STAGE)
    pub = STAGE / "public_html"
    pub.mkdir(parents=True)

    # Tampang depan
    shutil.copy2(ROOT / "index.html", pub / "index.html")
    for name in ("css", "js", "lib", "assets"):
        shutil.copytree(ROOT / name, pub / name)

    # Front controller CI4 + aset kecil
    shutil.copy2(PHPDIR / "public" / "index.php", pub / "index.php")
    for name in ("favicon.ico", "robots.txt"):
        src = PHPDIR / "public" / name
        if src.exists():
            shutil.copy2(src, pub / name)
    (pub / ".htaccess").write_text(public_htaccess(), encoding="utf-8")
    (pub / ".user.ini").write_text(USER_INI, encoding="utf-8")

    # Kerangka CI4 di induk docroot
    shutil.copytree(PHPDIR / "app", STAGE / "app")
    shutil.copytree(PHPDIR / "system", STAGE / "system")
    shutil.copytree(PHPDIR / "writable", STAGE / "writable")

    # Bersihkan jejak uji lokal (log debug, cache, sesi) -- paket harus bersih
    for sub in ("logs", "cache", "debugbar", "session"):
        d = STAGE / "writable" / sub
        if d.is_dir():
            for p in d.iterdir():
                if p.is_file() and p.name not in ("index.html", ".htaccess", ".gitkeep"):
                    p.unlink()

    # Folder data & uploads (di luar docroot)
    (STAGE / "data").mkdir()
    (STAGE / "uploads").mkdir()

    for name in ("app", "system", "writable", "data", "uploads"):
        (STAGE / name / ".htaccess").write_text(DENY, encoding="utf-8")

    (STAGE / ".env").write_text(ENV, encoding="utf-8")
    (STAGE / "BACA-DULU.txt").write_text(BACA, encoding="utf-8")


def make_zip() -> int:
    if ZIP_PATH.exists():
        ZIP_PATH.unlink()
    count = 0
    with zipfile.ZipFile(ZIP_PATH, "w", zipfile.ZIP_DEFLATED) as z:
        for path in sorted(STAGE.rglob("*")):
            rel = path.relative_to(STAGE)
            if path.is_dir():
                z.writestr(rel.as_posix() + "/", b"")
            else:
                z.write(path, rel.as_posix())
                count += 1
    return count


REQUIRED = [
    "public_html/index.html", "public_html/index.php", "public_html/.htaccess",
    "public_html/.user.ini", "public_html/js/app.js", "public_html/css/style.css",
    "public_html/lib/jsQR.min.js", "public_html/assets/", "public_html/favicon.ico",
    "app/Config/Database.php", "app/Config/Routes.php", "app/Libraries/Trio.php",
    "app/Controllers/Api.php", "app/Filters/AuthFilter.php",
    "system/Boot.php", "system/Filters/FilterInterface.php",
    "writable/logs/", "writable/cache/", "data/", "uploads/",
    ".env", "BACA-DULU.txt",
]


def verify() -> None:
    with zipfile.ZipFile(ZIP_PATH) as z:
        names = set(z.namelist())
        bad = z.testzip()
        missing = [r for r in REQUIRED if r not in names]
    if bad is not None:
        raise SystemExit(f"ZIP RUSAK pada: {bad}")
    if missing:
        raise SystemExit("ZIP TIDAK LENGKAP: " + ", ".join(missing))
    print(f"verifikasi zip: OK ({len(REQUIRED)} entri wajib ada, arsip utuh)")


def main() -> None:
    build_stage()
    n = make_zip()
    verify()
    size = ZIP_PATH.stat().st_size
    files = sum(1 for p in STAGE.rglob("*") if p.is_file())
    print(f"staging : {STAGE} ({files} berkas)")
    print(f"zip     : {ZIP_PATH} ({n} entri, {size / 1024 / 1024:.2f} MB)")
    print("struktur tingkat-1:", ", ".join(sorted(p.name for p in STAGE.iterdir())))


if __name__ == "__main__":
    main()
