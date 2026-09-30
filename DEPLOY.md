# Panduan Deploy — TRIO Inventory Control di DomaiNesia

Panduan langkah demi langkah dari nol. Harga dikutip dari domainesia.com (September 2026) —
cek lagi saat order karena promo bisa berubah.

Aplikasi ini tersedia dengan **dua backend setara** (API JSON identik; keduanya lulus suite tes
185 check otomatis — termasuk RBAC Admin/Audit/Cabang, scoping per-cabang, mutasi, sesi stock
opname, upload, dan manajemen user):

| Backend | Teknologi | Lingkungan ideal |
|---|---|---|
| **PHP (utama)** | CodeIgniter 4 + SQLite 3, tanpa Composer | **Shared hosting cPanel biasa** — upload ZIP, ekstrak, selesai |
| Python (cadangan) | `server.py` murni + SQLite file | VPS (Jalur A / E) yang boleh menjalankan proses layanan |

Implikasi penting:

- **Shared hosting cPanel KINI BISA** — tidak perlu proses daemon; cukup PHP 8.2+ (semua paket
  Domainesia punya PHP). Lihat **Jalur C** — jalur termurah & termudah.
- **VPS** tetap paling bebas (SSH, cron, backup otomatis, kontrol penuh) — Jalur A dengan
  backend Python yang sudah matang.
- **HTTPS wajib** untuk kamera QR dan login aman — masing-masing jalur sudah disiapkan HTTPS
  gratis.

---

## 1. Ringkasan pilihan

| # | Jalur | Biaya | Status aplikasi ini |
|---|-------|-------|---------------------|
| **C** | **Shared hosting biasa** (REKOMENDASI: termurah & tanpa terminal) | **Rp18.000/bln** (Nimbus One) ke atas | ✅ Paket ZIP siap upload — langkah lengkap di bawah |
| A | Cloud VPS Lite 1GB | Rp43.200/bln (promo `CLOUDVPSHEMAT`, siklus 1 tahun; normal Rp48.000) + domain | ✅ Backend Python, langkah lengkap di bawah |
| B | Managed Cloud VPS (tim DomaiNesia yang atur) | mulai Rp985.500/bulan | ✅ Kalau tidak mau menyentuh terminal |
| D | **Gratis** — Hosting NGO DomaiNesia | Rp0 (hosting + domain `.or.id`) | ⚠️ Hanya yayasan/lembaga non-profit; versi PHP jalan di paket dasarnya |
| E | **Gratis** — VPS free-tier di luar DomaiNesia (mis. Oracle Cloud Always Free) + domain DomaiNesia | Rp0 server + domain | ✅ Langkah teknis sama persis dengan Jalur A |

Keterangan paket shared hosting DomaiNesia (September 2026) — **semua paket bisa menjalankan
versi PHP**; kolom Python hanya relevan bila Anda memilih backend Python:

| Paket | Harga promo | PHP (versi aplikasi ini) | Python (`server.py`) | Catatan |
|-------|-------------|--------------------------|----------------------|---------|
| Nimbus One | Rp18.000/bln | ✅ | ❌ | Termurah; sudah cukup untuk Jalur C |
| Nimbus Go | Rp32.000/bln | ✅ | ❌ | Ada SSH |
| Nimbus Plus | Rp59.000/bln | ✅ | ✅ | + SSH, SQLite, Git — paket termurah yang mencantumkan Python |
| Cloud Hosting (Cirrus 2GB) | Rp112.500/bln | ✅ | ✅ | Semi-dedicated, JetBackup |

> Sekadar jalan dengan biaya terendah → **shared hosting Rp18.000/bln (Jalur C)**.
> Butuh kontrol penuh + cron/backup otomatis → **VPS Rp43.200/bln (Jalur A)**.

---

## 2. Beli domain (berlaku untuk semua jalur)

1. Buka **domainesia.com/domain**, cari nama domain, checkout di MyDomaiNesia.
2. Harga mulai Rp68.000/tahun pertama (ekstensi `.ID` mulai Rp9.900/tahun, cek halaman
   [harga domain](https://www.domainesia.com/harga-domain/)); promo "beli 1 gratis 1" sering tersedia.
3. Alternatif hemat: beli shared hosting siklus 1 tahun (Jalur C) → gratis domain `.COM`
   tahun pertama. Kalau nanti pindah ke VPS, domainnya tetap dipakai (tinggal ganti DNS).

---

# Jalur C — Shared hosting cPanel (REKOMENDASI)

Tidak butuh SSH, terminal, composer, atau daemon. Cukup: **bangun ZIP → upload → ekstrak →
isi domain di `.env` → pasang SSL**. Backend PHP-nya sendiri sudah lolos dua kali suite tes
185 check: pada layout pengembangan dan pada layout pasti sama dengan hasil ekstrak ZIP ini.

## C1. Bangun paket ZIP (dari PC)

```bat
python deploy\make_sharedhost_zip.py
```

Output:

- `dist\trio-hosting.zip` — arsip ±1,3 MB (±750 berkas) untuk di-upload;
- `dist\trio-hosting\` — isi ZIP yang sama dalam bentuk folder (dipakai untuk uji lokal);
- baris `verifikasi zip: OK` — pastikan muncul (berkas wajib lengkap + arsip utuh).

Isi ZIP (struktur **dua zona** — dokumen web vs data):

```
/home/ANDA/
├── public_html/        ← docroot: index.html, index.php, .htaccess, .user.ini,
│                          css/ js/ lib/ assets/ favicon.ico robots.txt
├── app/ system/ writable/   ← kerangka CodeIgniter 4 (di LUAR docroot)
├── data/               ← database SQLite trio.db (di LUAR docroot — tidak terekspos web)
├── uploads/            ← foto/dokumen (di LUAR docroot; disajikan aplikasi via /uploads/)
├── .env                ← konfigurasi: mode produksi, domain, zona waktu
└── BACA-DULU.txt       ← ringkasan langkah di dalam paket
```

Kenapa `data/` & `uploads/` di luar docroot: `data/trio.db` berisi seluruh inventaris — kalau
berada di `public_html`, file `.db` berisiko terunduh publik. Di layout ini yang bisa diakses
web hanya isi `public_html`, dan itu pun hanya file statis + `index.php` (CI4) yang meneruskan
`/api/*` & `/uploads/*`.

## C2. Siapkan hosting & versi PHP

1. Order paket (mulai **Nimbus One Rp18.000/bln** sudah cukup) di
   [domainesia.com/shared-hosting](https://www.domainesia.com/shared-hosting/), atau pakai
   hosting lama Anda. Gratis domain `.COM` tahun pertama bila siklus 1 tahun.
2. cPanel → **MultiPHP Manager** → pilih domain → **PHP Version: 8.2 atau 8.3** → Apply.
3. cPanel → **Select PHP Version** / PHP Extensions → pastikan ekstensi **`sqlite3`** dan
   **`pdo_sqlite`** tercentang (bawaan aktif pada EasyApache 4).

> CodeIgniter 4.7 membutuhkan **PHP 8.2 ke atas**. Jika panel Anda tidak menawarkan versi
> itu, minta support DomaiNesia mengaktifkan PHP 8.2+ (EasyApache 4). Gejalanya jelas: halaman
> menampilkan pesan *"Your PHP version must be 8.2 or higher"* (HTTP 503).

## C3. Upload & ekstrak (File Manager — dua zona)

1. cPanel → **File Manager** → masuk ke **direktori HOME** (induk `public_html`, biasanya
   `/home/username` — bukan masuk ke `public_html`).
2. Klik **Upload** → pilih `dist\trio-hosting.zip` → tunggu selesai → **Go Back**.
3. Di home directory: klik `trio-hosting.zip` → **Extract** → pastikan lokasi = home
   directory itu sendiri → **Confirm Files** (setuju bila diminta menimpa).
4. Hasil akhir yang harus terlihat:
   - di home: folder `app`, `system`, `writable`, `data`, `uploads` + berkas `.env`,
     `BACA-DULU.txt`;
   - di `public_html/`: `index.html`, `index.php`, `.htaccess`, `.user.ini`, folder
     `css`, `js`, `lib`, `assets`.
5. Aktifkan **Show Hidden Files** (ikon Settings di kanan atas File Manager) untuk memastikan
   `.env`, `.htaccess`, dan `.user.ini` ikut ter-ekstrak — ketiganya berkas tersembunyi.

Panduan ini mengasumsikan docroot `public_html` (bawaan DomaiNesia). Paket Anda memakai
docroot lain? Sesuaikan nama foldernya — prinsipnya sama: isi aplikasi di docroot, sisanya
(induk `app/`, `data/`, `uploads/`) tetap di induk docroot.

## C4. Isi domain di `.env`

Buka File Manager → home → edit `.env` → ganti baris `app.baseURL` dengan domain Anda:

```
CI_ENVIRONMENT = production
app.baseURL = 'https://tokoanda.example/'
app.appTimezone = 'Asia/Jakarta'
```

Sisanya biarkan. `CI_ENVIRONMENT = production` menyembunyikan detail error dari publik
(sementara pengembangan bisa diubah ke `development` — lihat C10).

## C5. Pasang SSL (wajib — kamera QR & login aman)

1. cPanel → **SSL/TLS Status** → pilih domain → **Run AutoSSL** (Let's Encrypt, gratis);
   atau menu **Let's Encrypt SSL** bila tersedia.
2. Opsional — paksa semua kunjungan ke HTTPS: edit `public_html/.htaccess`, **hapus tanda
   `#`** pada blok *"Opsional: paksa HTTPS"* (hanya setelah SSL aktif, kalau tidak situs jadi
   tidak terbuka).
3. Uji: buka `https://domainanda.com` — harus membuka aplikasi, bukan peringatan sertifikat.

Kenapa wajib: kamera untuk **Scan QR** memakai `getUserMedia()` yang hanya diizinkan browser
pada konteks aman (`https://` atau `localhost`).

## C6. Kunjungan pertama, ganti password, uji

1. Buka `https://domainanda.com` → halaman login tampil; data demo (24 aset + 3 user) dibuat
   **otomatis** pada kunjungan pertama.
2. Login `admin / admin123` → **SEGERA ganti password** (ikon 🔑 di topbar) — lalu ganti juga
   password `auditor / audit123` dan `cabang / cabang123` lewat menu **User & Akses**.
3. Uji inti: tambah inventaris → buat & jalankan sesi stock opname → ajukan + setujui mutasi →
   upload foto → laporan.
4. Uji dari HP: login → **Stock Opname** → buka sesi berjalan → **Gunakan Kamera** (harus jalan
   karena sudah HTTPS).

## C7. Izin folder (hanya bila ada keluhan database gagal)

File hasil ekstrak biasanya sudah milik akun hosting → langsung jalan. Jika muncul keluhan
"database ditolak / gagal dibuat / tidak bisa menulis":

1. File Manager → folder **`data`** → **Permissions** → isi `755` (bila tetap gagal: `775`).
2. Ulangi untuk folder **`writable`** (log & cache CodeIgniter).
3. Muat ulang halaman aplikasi.

## C8. Backup rutin (wajib)

- **cPanel → Backup / JetBackup** (JetBackup tersedia di paket Cirrus): backup account penuh,
  bisa diunduh berkala.
- **Manual minimal:** File Manager → pilih folder `data` + `uploads` → **Compress** (zip) →
  **Download** → simpan offline (misalnya tiap minggu). `data/trio.db` = seluruh isi
  inventaris Anda; `uploads/` = lampiran/foto.
- **Via aplikasi:** menu Pengaturan → *Backup Inventaris* (CSV) untuk cadangan data aset
  ringan.

Backup SELALU sebelum update (C9).

## C9. Update ke versi baru

1. Build ZIP baru di PC: `python deploy\make_sharedhost_zip.py`.
2. **Catat dulu isi `.env` Anda** (atau rename jadi `.env.bak`) — ZIP berisi `.env` template
   dan akan menimpanya saat ekstrak.
3. Upload ZIP baru → Extract ke home directory → Overwrite. Folder `data/` & `uploads/` di ZIP
   hanya berisi folder kosong, jadi **data Anda tidak ikut terhapus/tertimpa**.
4. Kembalikan/isi ulang `.env` (domain Anda) sesuai C4.
5. Buka situs — selesai. Tidak ada langkah migrasi database.

## C10. Troubleshooting (Jalur C)

| Gejala | Penyebab / solusi |
|--------|-------------------|
| *"Your PHP version must be 8.2 or higher"* (503) | PHP host < 8.2 → MultiPHP Manager pilih 8.2/8.3 (lihat C2) |
| Halaman kosong / error 500 | Buka `.env`, ubah `CI_ENVIRONMENT` ke `development` → muat ulang untuk melihat pesan error; detail juga ada di `writable/logs/log-*.log` |
| "Database ditolak / gagal dibuat" | Hak tulis folder → C7 (`data` & `writable` → 755/775) |
| `/api/...` error atau halaman 404 aneh | `mod_rewrite` / `.htaccess` tidak terbaca → pastikan `public_html/.htaccess` ada (Show Hidden Files) dan hubungi support bila `AllowOverride` non-All |
| Kamera QR ditolak browser | Bukan `https://` → pasang SSL (C5) + opsional paksa HTTPS di `.htaccess` |
| Upload foto gagal untuk file besar | `.user.ini` sudah menaikkan `post_max_size` ke 16M; pastikan berkas `.user.ini` ikut ter-ekstrak dan terbaca (PHP CGI/FPM) |
| Setelah update, ada error aneh terkait domain | `.env` tertimpa template → isi ulang `app.baseURL` (C9 langkah 2 & 4) |
| `.env` / `.htaccess` tidak terlihat | File Manager → Settings → aktifkan **Show Hidden Files**; ZIP sudah memuatnya |

---

# Jalur A — Cloud VPS Lite (Python)

> Jalur ini memakai **backend Python** (`server.py`) yang berjalan sebagai layanan systemd —
> cocok untuk VPS yang Anda kendalikan penuh. **Target Anda shared hosting?** Gunakan
> **Jalur C** (backend PHP) — langkahnya jauh lebih singkat.

## A1. Order VPS

1. Buka **domainesia.com/cloud-vps-lite** → paket **Cloud VPS Lite 1GB**
   (1 Core CPU, 1 GB RAM, 20 GB SSD NVMe, dedicated IP, unlimited bandwidth).
2. Siklus **1 Tahun** + kode promo **`CLOUDVPSHEMAT`** → Rp43.200/bulan.
3. Sistem operasi: **Ubuntu 24.04 LTS** (atau 22.04 LTS).
4. Lokasi server: **Jakarta** (latensi terendah dalam negeri).
5. Bayar, lalu **catat dari email MyDomaiNesia**: IP server, username `root`, password root.
   Aktivasi biasanya hitungan menit.

## A2. Arahkan domain ke VPS (DNS)

Di **MyDomaiNesia → Domain → kelola domain → DNS Management**, tambahkan:

| Tipe | Nama | Nilai |
|------|------|-------|
| A | `@` | IP VPS Anda |
| A | `www` | IP VPS Anda |

Propagasi biasanya menit s.d. beberapa jam. (Domain di registrar lain? Tambah A record yang
sama di panel mereka.)

## A3. Upload aplikasi dari PC

Yang diupload: `server.py`, `index.html`, `css/`, `js/`, `lib/`, `assets/`
(+ `data/trio.db` & `uploads/` bila sudah ada data). Tidak perlu: `README.md`, `Dockerfile`,
`start.bat`, `__pycache__`.

**Cara termudah — WinSCP/FileZilla (SFTP):** host = IP VPS, user `root`, password root →
drag folder ke `/opt/trio`.

**Cara terminal (PowerShell/CMD):**

```bat
ssh root@IP-VPS "mkdir -p /opt/trio/data /opt/trio/uploads"
scp -r server.py index.html css js lib assets root@IP-VPS:/opt/trio/
scp data/trio.db root@IP-VPS:/opt/trio/data/
scp -r uploads\* root@IP-VPS:/opt/trio/uploads/
```

Kalau server belum pernah jalan, langkah salin `data/trio.db` bisa dilewati — database akan
diisi data demo otomatis saat pertama start.

> **Jalur cepat (otomatis)** — langkah A4–A9 di bawah bisa dikerjakan script:
>
> - **Dari Windows:** `deploy\deploy.bat IP-VPS DOMAIN [KEY.pem] [user]`
>   (contoh: `deploy\deploy.bat 157.245.1.10 trio-inventaris.duckdns.org C:\keys\trio.pem ubuntu`)
>   — upload file + menjalankan `setup.sh` di server, sekaligus membawa data lokal
>   *hanya bila di server belum ada* (tidak pernah menimpa data live).
> - **Dari terminal server:** setelah upload, jalankan
>   `sudo bash /opt/trio/deploy/setup.sh <domain>`
>
> Langkah manual A4–A9 tetap ditulis lengkap untuk Anda yang ingin memahami tiap tahap.

## A4. Siapkan server (SSH ke VPS)

```bash
ssh root@IP-VPS
apt-get update && apt-get install -y python3 sqlite3 ufw
# python3 sudah bawaan Ubuntu; sqlite3 untuk fitur backup aman

# user service khusus (agar tidak jalan sebagai root)
useradd -r -M -s /usr/sbin/nologin trio
chown -R trio:trio /opt/trio
```

## A5. Jalankan sebagai layanan (systemd) — start otomatis & auto-restart

Salin isi [`deploy/trio.service`](deploy/trio.service) ke `/etc/systemd/system/trio.service`:

```bash
scp deploy/trio.service root@IP-VPS:/etc/systemd/system/
ssh root@IP-VPS "systemctl daemon-reload && systemctl enable --now trio"
ssh root@IP-VPS "systemctl status trio"          # harus: active (running)
ssh root@IP-VPS "curl -s http://127.0.0.1:8000 | head -3"  # harus memuat index.html
```

Aplikasi kini jalan terus, otomatis nyala lagi kalau server reboot.

## A6. HTTPS otomatis dengan Caddy (wajib — kamera QR & login)

Install Caddy:

```bash
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
apt-get update && apt-get install -y caddy
```

Salin [`deploy/Caddyfile`](deploy/Caddyfile) → `/etc/caddy/Caddyfile`, **ganti
`trio.contohanda.com` dengan domain Anda**, lalu:

```bash
systemctl reload caddy
```

Caddy otomatis minta sertifikat Let's Encrypt (gratis) begitu domain menunjuk ke IP ini.
Hasilnya: `https://domainanda.com` → reverse proxy ke aplikasi di port 8000.

## A7. Firewall

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```

Port 8000 sengaja tidak dibuka — hanya Caddy yang boleh mengaksesnya.

## A8. Verifikasi & AMANKAN

1. Buka **https://domainanda.com** → halaman login tampil.
2. Login `admin / admin123` → **SEGERA ganti password** (ikon 🔑 di topbar) — dan ganti juga
   password `auditor` & `cabang` lewat menu **User & Akses**. Jangan tunda ini.
3. Uji dengan HP: login → menu Stock Opname → buka sesi berjalan → izinkan kamera (harus jalan karena sudah HTTPS).
4. Uji role lain: logout → login `cabang/cabang123` → hanya lihat inventaris cabangnya.

## A9. Backup database harian

```bash
scp deploy/backup.sh root@IP-VPS:/opt/trio/deploy/backup.sh
ssh root@IP-VPS "chmod +x /opt/trio/deploy/backup.sh"
ssh root@IP-VPS "crontab -e"   # tambahkan baris berikut (tanpa tanda # di depan):
15 2 * * * /opt/trio/deploy/backup.sh >> /var/log/trio-backup.log 2>&1
```

Backup harian `data/trio.db` (pakai `sqlite3 .backup` — aman walau server jalan) + `uploads/`,
disimpan 14 hari di `/var/backups/trio`. Unduh/offline-kan berkala. Saat order VPS, cek juga
addon **Snapshot Otomatis** di halaman pembelian (tersedia gratis di beberapa paket).

**Backup manual via aplikasi**: menu Pengaturan → *Backup Inventaris* (CSV).

## A10. Update aplikasi versi baru

```bat
scp -r server.py index.html css js lib root@IP-VPS:/opt/trio/
ssh root@IP-VPS "systemctl restart trio"
```

Database tidak tersentuh oleh update (file `data/trio.db` tetap).

## Troubleshooting (Jalur A)

| Gejala | Penyebab / solusi |
|--------|-------------------|
| `502 Bad Gateway` | Layanan aplikasi mati → `systemctl status trio` / `journalctl -u trio -e` |
| Domain belum terbuka | DNS belum prop → `nslookup domainanda.com` harus = IP VPS; tunggu/pastikan record `@` |
| Halaman terbuka tapi kamera ditolak | Akses lewat `http://` → wajib `https://` (cek redirect Caddy) |
| "Database ditolak/dibuat gagal" | Hak folder → `chown -R trio:trio /opt/trio` |
| Port 80/443 terblokir | Cek `ufw status` + firewall/panel VPS di MyDomaiNesia |

---

# Jalur B — Managed VPS (tanpa sentuh terminal)

1. Buka **domainesia.com/managed-vps** (mulai Rp985.500/bulan).
2. Setelah aktif, buka tiket/live chat DomaiNesia — tim teknis mereka yang setup sesuai
   permintaan Anda. Cukup lampirkan isi panduan **Jalur A** di atas: "jalankan aplikasi Python
   ini dengan systemd + Caddy di domain saya".
3. Sertakan juga permintaan: ganti password default, backup harian `data/trio.db`.

---

# Jalur D — Gratis: Hosting NGO DomaiNesia

DomaiNesia memberi **hosting + domain `.or.id` gratis selamanya** (review tahunan) untuk
organisasi non-profit Indonesia. Syarat utama (halaman [domainesia.com/ngo](https://www.domainesia.com/ngo/)):

- Yayasan/lembaga resmi di bidang **kemanusiaan, pendidikan, atau lingkungan hidup**
  (bukan politik/keagamaan), terdaftar di Indonesia.
- Dokumen: KTP, **Akta Notaris + SK Kemenkumham + NPWP organisasi**, surat permohonan domain,
  laporan dokumentasi kegiatan.
- Wajib memasang logo DomaiNesia & PANDI di website.
- Proses kurasi maksimal 7 hari kerja; daftar di **domainesia.com/ngo**.

Catatan: paket gratisannya **Nimbus One** — Python tidak tersedia di sana, **tetapi PHP ada**,
sehingga **versi PHP (Jalur C) bisa dipakai apa adanya** di paket dasar itu: ekstrak
`trio-hosting.zip` lewat File Manager, ikuti C2–C6. FAQ DomaiNesia menyebut layanan lain bisa
diberikan "apabila organisasi memerlukan" — bisa diajukan saat kurasi, tapi tidak bisa
dijanjikan. Jika lembaga Anda komersial (mis. dealer), jalur ini tidak berlaku.

---

# Jalur E — Gratis total (di luar DomaiNesia)

Untuk kebutuhan **"website + database sama-sama berfungsi" tanpa biaya**, pilihan yang
benar-benar cocok hanya **VPS gratis dengan disk persisten**. Layanan gratis lain umumnya
gagal di satu sisi:

| Layanan gratis | Kenapa TIDAK cocok untuk aplikasi ini |
|---|---|
| GitHub Pages / Netlify / Cloudflare Pages | Statis saja — tanpa backend, login, database |
| Render / Railway / Replit / Fly.io (paket free) | Disk **ephemeral** — `data/trio.db` & `uploads/` hilang saat redeploy; app juga sering sleep |
| Hosting cPanel gratis (InfinityFree, 000webhost, dll.) | Dulu terhalang aturan Python daemon; versi PHP *mungkin* bisa, namun kuota ketat, iklan, dan kebijakan sewenang-wenang — **tidak layak untuk data bisnis** |
| Paket gratis DomaiNesia (Hosting NGO, lihat Jalur D) | Hanya lembaga non-profit (versi PHP-nya sendiri sudah jalan) |

> Catatan untuk VPS gratis (Oracle/Google): panduan ini menyiapkan **backend Python** di sana
> (Jalur A). Menjalankan versi PHP di VPS butuh setup Apache/Nginx + PHP-FPM yang tidak
> dicakup panduan ini — di VPS, gunakan backend Python yang memang sudah matang.

## Pilihan 1 — Oracle Cloud Always Free (REKOMENDASI)

Terverifikasi dari dokumentasi resmi Oracle (September 2026):

- **2× VM `VM.Standard.E2.1.Micro`** (AMD 1/8 OCPU, 1 GB RAM) gratis **selamanya**, atau
  **1× VM Ampere A1 (Arm)** hingga **2 OCPU / 12 GB** (kuota dipangkas dari 4/24 pada Juni 2026 —
  VM mikro & storage tidak berubah).
- **200 GB** boot + block volume (persisten → SQLite aman), 5 backup volume, **10 TB** egress/bulan.
- Image **Ubuntu** tersedia → seluruh langkah Jalur A di atas berlaku persis.
- Syarat: **kartu kredit/debit untuk verifikasi identitas** — selama di batas Always Free tidak
  ditagih (kredit trial $300/30 hari tidak wajib dipakai).
- Risiko yang harus diketahui:
  - "Out of host capacity" saat membuat VM → coba availability domain / waktu lain.
  - Aturan **idle**: VM bisa direklamasi bila selama 7 hari CPU *p95* < 20% **dan** jaringan < 20%
    (memori hanya untuk bentuk A1) → dijaga oleh cron keep-alive + pemakaian rutin.
  - Kebijakan free tier bisa berubah sewaktu-waktu tanpa pemberitahahan (bukti: Juni 2026) →
    **backup lokal rutin wajib**.

### Langkah

1. Daftar di **oracle.com/cloud/free** → pilih *home region* terdekat:
   **Singapore (`ap-sosink-1`)**, Osaka, atau Tokyo.
2. Console → **Compute → Instance → Create**:
   - Shape: `VM.Standard.E2.1.Micro` (atau `VM.Standard.A1.Flex` 1 OCPU / 6 GB bila kapasitas ada)
   - Image: **Ubuntu 22.04 / 24.04** (pastikan label *Always Free Eligible*)
   - Boot volume: **±50 GB** (masuk kuota 200 GB)
   - Networking: wizard *"Create virtual cloud network with internet connectivity"*;
     pastikan security list membuka **TCP 80 & 443** (dan 22 dari IP Anda saja bila memungkinkan)
   - Buat *key pair* → simpan file `.pem`; login: `ssh -i trio-key.pem ubuntu@IP-VPS`
3. **Domain gratis**: daftar **duckdns.org** (login via GitHub/Google) → buat subdomain, mis.
   `trio-inventaris.duckdns.org` → isi IP VPS. (Alternatif: domain DomaiNesia — lihat Jalur 2.)
4. **Setup otomatis (satu perintah)** — upload isi proyek ke `/opt/trio` (WinSCP/scp, atau
   `deploy\deploy.bat IP DOMAIN KEY.pem ubuntu` dari Windows), lalu di server:
   ```bash
   sudo bash /opt/trio/deploy/setup.sh trio-inventaris.duckdns.org
   ```
   Script itu mengerjakan **A4–A9 sekaligus** (python3, user `trio`, systemd, Caddy HTTPS,
   ufw, cron backup, keep-alive anti-idle) plus verifikasi HTTPS dan pengingat ganti password.
5. **Migrasi nanti saat siap bayar**: ikuti **Jalur A (VPS DomaiNesia Rp43.200/bln)** —
   salin `data/trio.db` + `uploads/`, ganti DNS (atau satu baris `Caddyfile`),
   `systemctl restart trio`. Tanpa perubahan kode.

## Pilihan 2 — Google Cloud free tier (cadangan bila signup Oracle ditolak)

- **1× VM `e2-micro`** (0,25 vCPU, 1 GB RAM) gratis selamanya di region AS
  (`us-west1` / `us-central1` / `us-east1`) + **30 GB disk persisten** + 1 GB egress/bulan
  (sempit — pantau pemakaian).
- **Syarat penting**: free tier hanya bertahan bila setelah masa trial 90 hari Anda
  **upgrade ke billing berbayar** — jika tidak, resource dihentikan lalu dihapus 30 hari
  kemudian. Artinya "gratis" selama dalam batas, tapi bisa ditagih otomatis bila melewati
  batas → pasang *budget alert*.
- Latensi dari Indonesia ke AS ±150–200 ms — terasa lebih lambat dari Oracle Singapore.
- Langkah teknis tetap Jalur A (systemd + Caddy), cukup ganti IP/domain.

Peringatan penutup: free tier bukan layanan DomaiNesia — uptime & kebijakan ada di pihak lain,
jadi **backup lokal rutin** adalah jaring pengaman Anda. Untuk produksi bisnis yang tenang,
Jalur C (Rp18.000/bln) atau Jalur A (Rp43.200/bln) tetap disarankan.

---

## Checklist pasca-deploy (wajib)

- [ ] Password `admin`, `auditor`, `cabang` **diganti semua** dari default-nya.
- [ ] Situs dibuka via `https://` (bukan `http://`) dan kamera QR diuji dari HP.
- [ ] Login diuji untuk ketiga role + satu kali mutasi & stock opname jalan.
- [ ] (Jalur C) PHP 8.2/8.3 aktif di MultiPHP Manager; `sqlite3` + `pdo_sqlite` tercentang;
      `.env` berisi `app.baseURL` domain yang benar.
- [ ] (Jalur C) `data/` & `writable/` bisa ditulis (755/775 bila perlu).
- [ ] Backup rutin aktif: (C) unduhan kompresi `data/` + `uploads/` atau JetBackup;
      (A) cron `/var/backups/trio` terisi atau snapshot aktif.
- [ ] (Jalur A/E) `systemctl enable trio` sudah `enabled` → tetap hidup setelah reboot.
- [ ] Data lokal lama (bila ada) sudah dipindah: `data/trio.db` + `uploads/`.
