# TRIO Inventory Control

Website sistem pengendalian inventaris **TRIO MOTOR** — *Aset Terjaga, Operasional Lebih Baik*.

Versi ini menggunakan **backend + database server** (bukan localStorage):

| Lapisan | Teknologi |
|---|---|
| Frontend | HTML + CSS + Vanilla JavaScript (SPA hash-router, tanpa build tool) |
| Backend | **PHP 8.2+ / CodeIgniter 4** (rekomendasi shared hosting, tanpa Composer) **atau** Python 3 murni (`http.server`, tanpa `pip install`) — API identik, keduanya lulus 185 pemeriksaan |
| Database | SQLite (`data/trio.db`) |
| Autentikasi | Login + sesi cookie `HttpOnly`, password di-hash **PBKDF2-HMAC-SHA256** (120.000 iterasi) |
| Hak akses (RBAC) | 3 role: **Administrator · Auditor · Manajemen Cabang** — izin dicek di server, bukan hanya di UI |
| File upload | Folder `uploads/` (foto & dokumen) |
| Scan QR | **jsQR** (decoder murni JavaScript — jalan di Chrome/Edge/Firefox) + fallback BarcodeDetector |
| QR generator | qrcodejs (lokal, tanpa CDN) |

## Halaman

| # | Halaman | Route |
|---|---------|-------|
| 0 | Login (wajib sebelum masuk) | `#/login` (otomatis) |
| 1 | Dashboard | `#/dashboard` |
| 2 | Daftar Inventaris | `#/inventaris` |
| 3 | Detail Inventaris (tab Riwayat/Mutasi/Opname/Perbaikan/Dokumen, Cetak QR) | `#/detail/:kode` |
| 4 | Tambah / Edit Inventaris | `#/tambah`, `#/edit/:kode` |
| 5 | Stock Opname — daftar sesi, filter & buat sesi baru | `#/opname`, `#/opname/baru` |
| 6 | Penghitungan sesi Stock Opname (scan/manual/qty, ringkasan, export PDF & Excel) | `#/opname/:id` (`#/scan` = alias ke daftar) |
| 7 | Laporan + Export Excel & PDF | `#/laporan` |
| + | Transaksi & Approval Mutasi | `#/transaksi` |
| + | Master Data | `#/master` |
| + | Audit Trail | `#/audit` |
| + | Pengaturan (profil, akun, backup & reset) | `#/pengaturan` |
| + | User & Hak Akses (**khusus Administrator**) | `#/users` |

Menu dan tombol yang tidak berhak akan **disembunyikan**, dan setiap route/end-point
tetap dicek ulang di server (bypass URL tetap ditolak dengan *Akses ditolak* / HTTP 403).

## Menjalankan

```bash
python server.py            # buka http://localhost:8000
```

Atau klik dua kali **`start.bat`** (Windows).

**Alternatif — backend PHP** (CodeIgniter 4, tanpa dependensi; dipakai untuk shared hosting):

```bat
php -S 127.0.0.1:8123 router.php     # buka http://localhost:8123
```

**Login pertama kali** (akun dibuat otomatis — *segera ganti password-nya*
lewat tombol 🔑 di topbar atau menu Pengaturan → Ganti Password):

| Username | Password | Role |
|---|---|---|
| `admin` | `admin123` | Administrator — semua akses |
| `auditor` | `audit123` | Auditor — semua akses kecuali konfigurasi user & login |
| `cabang` | `cabang123` | Manajemen Cabang (TM Buntok) — lihat, tambah, ajukan mutasi |

Opsi lain:

```bash
python server.py --port 8080
python server.py --host 0.0.0.0 --port 8000   # akses dari HP/LAN: http://IP-KOMPUTER:8000
```

> **Kamera/scan QR**: browser hanya mengizinkan kamera di `localhost` atau **HTTPS**.
> Untuk akses dari perangkat lain gunakan HTTPS (lihat bagian Deploy).

Database dibuat otomatis saat pertama jalan (`data/trio.db`) dan langsung diisi data demo.

## Struktur

```
index.html        # shell aplikasi (sidebar + topbar)
css/style.css     # seluruh desain
js/app.js         # logika frontend + client API
lib/              # qrcode.min.js, jsQR.min.js (lokal, tanpa CDN)
assets/           # ikon & placeholder
server.py         # backend REST + static server (Python stdlib)
php/              # backend PHP (CodeIgniter 4) — untuk shared hosting cPanel
deploy/           # skrip deploy: setup.sh, backup.sh, make_sharedhost_zip.py, dll.
dist/             # hasil build paket hosting (dibuat otomatis, tidak ikut VCS)
data/trio.db      # SQLite (dibuat otomatis)
uploads/          # file yang diunggah
Dockerfile        # opsional, untuk deploy container
```

## User & Hak Akses (Role)

Dikelola lewat menu **User & Akses** (hanya Administrator): buat user, tentukan
role & cabangnya, reset password, aktif/nonaktif.

| Akses | Administrator | Auditor | Manajemen Cabang |
|---|---|---|---|
| Lihat dashboard/inventaris/detail | ✔ semua cabang | ✔ semua cabang | ✔ **cabangnya saja** |
| Tambah inventaris | ✔ | ✔ | ✔ (otomatis masuk cabangnya) |
| Edit data, foto & dokumen | ✔ | ✔ | ✖ |
| Stock Opname (tulis) | ✔ | ✔ | ✖ |
| Laporan + export Excel/PDF | ✔ | ✔ | ✖ |
| Ajukan mutasi | ✔ | ✔ | ✔ (aset cabangnya) |
| **Approval mutasi** | ✔ | ✔ | ✖ |
| Master Data / Audit Trail / Pengaturan | ✔ | ✔ | ✖ |
| **Konfigurasi user & login** | ✔ | ✖ | ✖ |
| Ganti password sendiri | ✔ | ✔ | ✔ |

Catatan:

- Role **Cabang** wajib memiliki cabang; aset yang dibuat/dimutasikannya diverifikasi
  server terhadap cabang tersebut (melewati UI pun tetap ditolak, HTTP 403).
- Hak akses tersimpan sebagai daftar permission per role di `server.py` dan
  `php/app/Libraries/Trio.php` (`ROLE_PERMS`) — mudah ditambah/diubah bila perlu role baru.
- Menambah user baru: menu **User & Akses** → *Tambah User* → isi username, nama,
  role, cabang, password (min 6 karakter).

## Pemisahan Inventaris per Cabang

Satu file database (`data/trio.db`), tetapi **data inventaris tiap cabang terisolasi**:

| Cabang | Prefix kode | Contoh kode berikutnya |
|---|---|---|
| TM Buntok | `INV-BTK-` | INV-BTK-0025 |
| TM Baru | `INV-BRU-` | INV-BRU-0001 |
| TM Kuala Kapuas | `INV-KKP-` | INV-KKP-0001 |

- **Urutan nomor terpisah per cabang** — menambah aset di TM Baru tidak pernah mengubah
  nomor TM Buntok. Form *Tambah Inventaris* menampilkan preview kode berikutnya sesuai
  cabang yang dipilih.
- **Role Cabang** hanya menerima & melihat aset milik cabangnya — berlaku di UI maupun
  API (dicek server). Kolom **Cabang** tampil pada Daftar Inventaris.
- **Admin & Auditor** melihat seluruh cabang (pemilih cabang di topbar & filter di
  Laporan: pilih satu cabang atau "Semua Cabang").
- **Mutasi antar cabang**: pilihan tujuan pada modal Ajukan Mutasi memuat grup
  *Pindah Cabang* (cabang aset sendiri disembunyikan). Setelah di-approve, aset berpindah
  ke cabang tujuan — cabang lama berhak, cabang baru menerima, dan riwayat tercatat
  `Antar cabang: ...`. **Kode aset bersifat permanen** meski aset pindah cabang.
- **Backup per cabang**: Laporan → filter Cabang → *Export Excel / CSV / PDF*.

## API (JSON)

Semua endpoint di bawah **wajib login** ( cookie sesi `trio_session` ), kecuali
`POST /api/auth/login` dan `GET /api/auth/me`:

| Method | Endpoint | Fungsi | Izin minimal |
|---|---|---|---|
| POST | `/api/auth/login` | login `{username,password}` → set cookie sesi | publik |
| POST | `/api/auth/logout` | hapus sesi | login |
| GET | `/api/auth/me` | profil + permission user login | publik |
| PUT | `/api/auth/password` | ganti password sendiri `{oldPassword,newPassword}` | login |
| GET | `/api/users` · POST | daftar / buat user | `users.manage` (admin) |
| PUT/DELETE | `/api/users/{id}` | ubah / hapus user | `users.manage` (admin) |
| GET | `/api/db` | bootstrap seluruh data (difilter sesuai cabang user) | login |
| POST | `/api/upload` | unggah foto (2MB) & dokumen (5MB) | login |
| POST | `/api/assets` | tambah inventaris (kode dibuat server) | `asset.create` |
| PUT | `/api/assets/{kode}` | ubah inventaris | `asset.update` |
| POST | `/api/opname-sessions` | buat sesi stock opname (snapshot inventaris cabang) | `opname.write` |
| POST | `/api/opname-sessions/{id}/start` · `/end` · `/cancel` | mulai / akhiri / batalkan sesi | `opname.write` |
| PUT | `/api/opname-sessions/{id}/items/{kode}` | simpan hitungan item (qty fisik / status) | `opname.write` |
| POST/DELETE | `/api/assets/{kode}/docs[/{i}]` | kelola dokumen | `asset.update` |
| POST | `/api/mutations` | ajukan mutasi | `mutation.create` |
| PUT | `/api/mutations/{id}` | setujui/tolak mutasi | `mutation.approve` |
| PUT | `/api/settings` | simpan pengaturan | `settings.write` |
| POST/DELETE | `/api/lists/{key}` | master data | `master.write` |
| POST | `/api/logs` | entri audit trail | login |
| POST | `/api/admin/reset` | isi ulang data demo | `settings.write` |
| POST | `/api/admin/import` | impor aset lama dari browser (migrasi) | `users.manage` |

Semua kebijakan bisnis (kode aset berikutnya, riwayat, audit log, approval yang
tidak boleh diproses dua kali, validasi field wajib, batas ukuran file,
penolakan hapus data yang masih dipakai, **scope cabang & hak akses**)
dijalankan di **server**.

## Deploy

Panduan langkah demi langkah khusus **DomaiNesia** (shared hosting, VPS, sampai opsi gratis):
lihat **[DEPLOY.md](DEPLOY.md)**. **Jalur C (shared hosting) kini jalur utama**: bangun
`dist\trio-hosting.zip` dengan `python deploy\make_sharedhost_zip.py`, upload ke File
Manager cPanel, ekstrak di home directory — tanpa SSH/composer/daemon. File pendukung ada di
folder [`deploy/`](deploy/): `make_sharedhost_zip.py`, systemd `trio.service`, `Caddyfile`,
`backup.sh`, **`setup.sh`** (setup server satu perintah), dan **`deploy.bat`**
(upload + setup dari Windows).

### A. VPS / Server Linux (direkomendasikan)

```bash
scp -r . user@server:/opt/trio
ssh user@server
cd /opt/trio
python3 server.py --host 127.0.0.1 --port 8000
```

Jalankan permanen dengan **systemd** (`/etc/systemd/system/trio.service`):

```ini
[Unit]
Description=TRIO Inventory Control
After=network.target

[Service]
WorkingDirectory=/opt/trio
ExecStart=/usr/bin/python3 /opt/trio/server.py --host 127.0.0.1 --port 8000
Restart=always

[Install]
WantedBy=multi-user.target
```

Depaninya dengan **Caddy/Nginx** agar HTTPS aktif (wajib untuk kamera QR):

```
# Caddy (HTTPS otomatis)
triomotor.example.com {
    reverse_proxy 127.0.0.1:8000
}
```

### B. Docker

```bash
docker build -t trio-inventory .
docker run -d -p 8000:8000 -v trio-data:/app/data -v trio-uploads:/app/uploads trio-inventory
```

### C. Shared hosting cPanel (PHP — tanpa proses)

Pakai backend PHP: `python deploy\make_sharedhost_zip.py` → upload
`dist\trio-hosting.zip` ke File Manager → ekstrak di home directory → isi `app.baseURL`
di `.env` → pasang SSL. Langkah lengkap + troubleshooting: **DEPLOY.md → Jalur C**.

Untuk Plesk/IIS/hosting tanpa PHP, backend Python tetap dijalankan sebagai proses
(pythonw/nssm/supervisor, lihat opsi A). Hosting *static* murni (GitHub Pages/Netlify)
tidak mendukung backend — gunakan opsi A/B.

## Backup & Reset

- **Backup**: menu Pengaturan → *Backup Inventaris* (CSV), atau salin `data/` + `uploads/`.
- **Reset demo**: menu Pengaturan → *Reset Data Demo* (data inventaris; user tetap).
- **Data lama versi localStorage**: saat **admin** login pertama kali di browser
  yang masih menyimpan cache lama, aplikasi menawarkan impor data lama
  (item dengan kode baru) ke database server.

## Pengujian

Integration test — **185 pemeriksaan** (static, auth, RBAC 3 role, route, CRUD, sesi stock
opname, mutasi, upload, scoping per cabang; butuh Node.js + jsdom, terpasang di folder test):

```bash
python server.py --port 8123 --data-dir %TEMP%/trio-test --upload-dir %TEMP%/trio-test-uploads &
node test.js        # suite utama (185 pemeriksaan)
node smoke_revisi3.js   # suite ringkas (60 pemeriksaan)
```

Suite yang sama juga dijalankan terhadap backend PHP — pada layout pengembangan
(`php -S` + `router.php`) maupun layout paket hosting (`dist/trio-hosting`) — semuanya
lulus **185/185** (suite ringkas **60/60**).

## Catatan Keamanan

- **Sudah ada login & hak akses**: setiap request API dicek sesi + permission
  role-nya. Tetap **jangan di-expose ke internet publik** tanpa HTTPS
  (reverse-proxy Caddy/Nginx seperti contoh Deploy).
- **Ganti password default** (`admin123`, `audit123`, `cabang123`) segera setelah
  deploy — tombol 🔑 di topbar → *Ganti Password*. Password disimpan sebagai
  hash PBKDF2 (tidak pernah plaintext).
- Sesi memakai cookie `HttpOnly` + `SameSite=Lax`, masa berlaku 7 hari.
- `server.py`, `README.md`, dan `data/` tidak disajikan ke publik
  (hanya `css/ js/ lib/ assets/ uploads/`); pada versi PHP, folder `app/`, `system/`,
  `data/`, dan `uploads/` berada **di luar docroot** sehingga tidak terekspos web sama sekali.
- Batas ukuran body 16MB, foto 2MB, dokumen 5MB, dan validasi tipe file dilakukan server.
