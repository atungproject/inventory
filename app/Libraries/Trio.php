<?php

namespace App\Libraries;

use App\Exceptions\ApiException;
use CodeIgniter\Database\BaseConnection;

/**
 * TRIO Inventory Control — logika bisnis inti aplikasi.
 *
 * Kontrak API, pesan error, kode status, dan format JSON dibuat konsisten
 * sehingga frontend & suite uji memakai aturan yang sama.
 * Semua fungsi di sini TIDAK commit — commit/rollback ditangani controller (tx).
 */
class Trio
{
    // ---------------------------------------------------------- konstanta
    public const SESSION_COOKIE = 'trio_session';
    public const SESSION_TTL    = 604800; //7 hari
    public const PBKDF2_ITER    = 120000;
    public const MAX_BODY       = 16777216;
    public const MAX_PHOTO      = 2097152;
    public const MAX_DOC        = 5242880;

    public const KONDISI = ['Baik', 'Rusak Ringan', 'Rusak Berat', 'Tidak Ditemukan', 'Tidak Layak'];
    public const LIST_KEYS = ['kategori', 'divisi', 'lokasi'];
    public const ASSET_FIELDS = ['nama', 'kategori', 'merek', 'serial', 'tahun', 'nilai', 'tgl',
        'cabang', 'lokasi', 'divisi', 'pic', 'kondisi', 'status', 'catatan', 'foto', 'dokumen'];
    public const TRACKED = ['nama', 'kondisi', 'lokasi', 'pic', 'status', 'cabang'];

    // Modul Stock Opname berbasis sesi: Draft -> Berjalan -> Selesai, atau Dibatalkan
    public const OPNAME_STATUS = ['Draft', 'Berjalan', 'Selesai', 'Dibatalkan'];
    public const OPNAME_ITEM_STATUS = ['Belum', 'Ditemukan', 'Tidak Ditemukan'];

    /**
     * Katalog izin: modul x fungsi. Sumber kebenaran untuk matriks Hak Akses.
     * Setiap izin di sini punya arti nyata: menu (nav/route) dan/atau endpoint server.
     */
    public const PERM_CATALOG = [
        ['key' => 'inventaris', 'label' => 'Aset & Inventaris', 'icon' => 'fa-boxes-stacked', 'perms' => [
            ['key' => 'asset.view', 'label' => 'Lihat daftar & detail'],
            ['key' => 'asset.create', 'label' => 'Tambah aset'],
            ['key' => 'asset.update', 'label' => 'Ubah data aset'],
            ['key' => 'asset.print', 'label' => 'Cetak QR / label'],
        ]],
        ['key' => 'transaksi', 'label' => 'Mutasi & Perbaikan', 'icon' => 'fa-right-left', 'perms' => [
            ['key' => 'mutation.view', 'label' => 'Lihat daftar pengajuan'],
            ['key' => 'mutation.create', 'label' => 'Ajukan mutasi / perbaikan'],
            ['key' => 'mutation.approve', 'label' => 'Setujui / tolak / selesai'],
            ['key' => 'mutation.print', 'label' => 'Export Excel'],
        ]],
        ['key' => 'opname', 'label' => 'Stock Opname', 'icon' => 'fa-clipboard-check', 'perms' => [
            ['key' => 'opname.write', 'label' => 'Kelola sesi & penghitungan'],
            ['key' => 'opname.print', 'label' => 'Export Excel / PDF'],
        ]],
        ['key' => 'laporan', 'label' => 'Laporan', 'icon' => 'fa-chart-column', 'perms' => [
            ['key' => 'report.view', 'label' => 'Lihat laporan'],
            ['key' => 'report.print', 'label' => 'Export Excel / PDF / backup'],
        ]],
        ['key' => 'master', 'label' => 'Master Data', 'icon' => 'fa-database', 'perms' => [
            ['key' => 'master.view', 'label' => 'Lihat master data'],
            ['key' => 'master.write', 'label' => 'Tambah / hapus data master'],
        ]],
        ['key' => 'audit', 'label' => 'Audit Trail', 'icon' => 'fa-clock-rotate-left', 'perms' => [
            ['key' => 'audit.view', 'label' => 'Lihat audit trail'],
            ['key' => 'audit.print', 'label' => 'Export CSV'],
        ]],
        ['key' => 'pengaturan', 'label' => 'Pengaturan', 'icon' => 'fa-gear', 'perms' => [
            ['key' => 'settings.view', 'label' => 'Lihat pengaturan'],
            ['key' => 'settings.write', 'label' => 'Ubah pengaturan & reset data'],
        ]],
        ['key' => 'akses', 'label' => 'User & Hak Akses', 'icon' => 'fa-users-gear', 'perms' => [
            ['key' => 'users.manage', 'label' => 'Kelola user'],
            ['key' => 'roles.manage', 'label' => 'Kelola hak akses (role)'],
        ]],
    ];

    /**
     * Role bawaan — hanya dipakai sebagai seed awal tabel `roles`.
     * Setelah seed, isi tabel `roles` (hasil edit admin) yang berlaku.
     */
    public const DEFAULT_ROLES = [
        'admin' => ['label' => 'Administrator', 'perms' => [
            'asset.view', 'asset.create', 'asset.update', 'asset.print',
            'mutation.view', 'mutation.create', 'mutation.approve', 'mutation.print',
            'opname.write', 'opname.print',
            'report.view', 'report.print',
            'master.view', 'master.write',
            'audit.view', 'audit.print',
            'settings.view', 'settings.write',
            'users.manage', 'roles.manage',
        ]],
        'audit' => ['label' => 'Auditor', 'perms' => [
            'asset.view', 'asset.create', 'asset.update', 'asset.print',
            'mutation.view', 'mutation.create', 'mutation.approve', 'mutation.print',
            'opname.write', 'opname.print',
            'report.view', 'report.print',
            'master.view', 'master.write',
            'audit.view', 'audit.print',
            'settings.view', 'settings.write',
        ]],
        'cabang' => ['label' => 'Manajemen Cabang', 'perms' => [
            'asset.view', 'asset.create', 'asset.print',
            'mutation.view', 'mutation.create', 'mutation.print',
        ]],
    ];

    public const DEFAULT_CABANGS = [
        ['BTK', 'TM Buntok'],
        ['KTR', 'TM Kotabaru'],
        ['SPT', 'TM Sampit'],
    ];

    public const DEFAULT_SETTINGS = [
        'perusahaan' => 'TRIO MOTOR',
        'tagline'    => 'Satu Hati, Bersama Anda',
        'cabang'     => 'TM Buntok',
    ];
    public const DEFAULT_LISTS = [
        'kategori' => ['Elektronik', 'Mebel', 'Alat Kerja', 'Kendaraan', 'Peralatan'],
        'divisi'   => ['H1', 'H2', 'H3', 'Finance', 'OPR', 'IT', 'Umum'],
        'lokasi'   => ['Ruang Finance', 'R. OPR', 'R. H1', 'R. H2', 'R. Servis', 'R. Sparepart',
            'R. Server', 'R. Meeting', 'R. Tamu', 'Gudang', 'Parkiran'],
    ];
    public const DEMO_USERS = [
        ['admin', 'Administrator', 'admin123', 'admin', '*'],
        ['auditor', 'Tim Audit', 'audit123', 'audit', '*'],
        ['cabang', 'Manajemen Cabang Buntok', 'cabang123', 'cabang', 'TM Buntok'],
    ];

    public const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings(id INTEGER PRIMARY KEY CHECK(id=1), data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS lists(key TEXT NOT NULL, value TEXT NOT NULL, PRIMARY KEY(key, value));
CREATE TABLE IF NOT EXISTS assets(kode TEXT PRIMARY KEY, seq INTEGER NOT NULL, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS mutations(id TEXT PRIMARY KEY, seq INTEGER NOT NULL, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS opname_sessions(id TEXT PRIMARY KEY, seq INTEGER NOT NULL, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS logs(id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    nama TEXT NOT NULL,
    pw_salt TEXT NOT NULL,
    pw_hash TEXT NOT NULL,
    role TEXT NOT NULL,
    cabang TEXT NOT NULL DEFAULT '*',
    aktif INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sessions(
    token TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS roles(
    kode TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    perms TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS cabangs(
    kode TEXT PRIMARY KEY,
    nama TEXT NOT NULL,
    urut INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS pics(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama TEXT NOT NULL,
    cabang TEXT NOT NULL,
    UNIQUE(nama, cabang)
);
SQL;

    /** @var array|null [token, baris user|null] hasil resolve sesi request berjalan */
    public static ?array $session = null;
    /** @var array|null baris user login yang sedang aktif */
    public static ?array $user = null;
    /** @var array body JSON request berjalan (sudah divalidasi filter) */
    public static array $body = [];
    private static bool $initialized = false;

    /**
     * Baca & validasi body JSON:
     * kosong -> {}, >16MB ->413, JSON rusak ->400, bukan objek ->400.
     */
    public static function parseBody($request): array
    {
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY) {
            throw new ApiException(413, 'Payload terlalu besar.');
        }
        $asObj = json_decode($raw); // objek -> stdClass, list -> array
        if ($asObj === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException(400, 'Body harus JSON yang valid.');
        }
        if (! $asObj instanceof \stdClass) {
            throw new ApiException(400, 'Body harus berupa objek JSON.');
        }
        return json_decode($raw, true);
    }

    // ---------------------------------------------------------- util kecil
    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function stamp(): string
    {
        return date('Y-m-d H:i');
    }

    public static function enc($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Konversi boolean fleksibel: "0" dianggap true; "", 0, false, null dianggap false. */
    public static function truthy($v): bool
    {
        if (is_array($v)) {
            return $v !== [];
        }
        if (is_string($v)) {
            return $v !== '';
        }
        return (bool) $v;
    }

    /** Nilai dengan fallback bila kosong/null (expr or default). */
    public static function orv($v, $default)
    {
        return self::truthy($v) ? $v : $default;
    }

    /** Konversi skalar ke string ('' bila null; pemakaian selalu dijaga). */
    public static function strv($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return (string) $v;
    }

    /** Konversi nilai ke integer untuk field angka. */
    public static function intv($v): int
    {
        if (! self::truthy($v)) {
            return 0;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^[+-]?\d+$/', trim($v))) {
            return (int) trim($v);
        }
        throw new ApiException(500, 'Kesalahan server: invalid literal for int() with base10: ' . self::strv($v));
    }

    // ---------------------------------------------------------- password
    public static function hashPassword(string $password, ?string $salt = null): array
    {
        $salt = $salt ?? bin2hex(random_bytes(16));
        $dk   = hash_pbkdf2('sha256', $password, hex2bin($salt), self::PBKDF2_ITER, 0, true);
        return [$salt, bin2hex($dk)];
    }

    public static function verifyPassword(string $password, string $salt, string $expected): bool
    {
        $dk = hash_pbkdf2('sha256', $password, hex2bin($salt), self::PBKDF2_ITER, 0, true);
        return hash_equals(bin2hex($dk), $expected);
    }

    // ---------------------------------------------------------- koneksi & skema
    public static function db(): BaseConnection
    {
        return \Config\Database::connect();
    }

    /** Inisialisasi skema + seed — dijalankan sekali per request di filter. */
    public static function ensureInit(?BaseConnection $db = null): BaseConnection
    {
        $db = $db ?? self::db();
        if (self::$initialized) {
            return $db;
        }
        self::$initialized = true;
        $db->query('PRAGMA journal_mode=WAL');
        foreach (explode(';', self::SCHEMA) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $db->query($stmt);
            }
        }
        self::ensureSeedUsers($db);
        self::migrateV4($db);
        self::ensureBaseData($db);
        $seeded = $db->query('SELECT v FROM meta WHERE k=?', ['seeded'])->getFirstRow('array');
        if (! $seeded) {
            self::seedFresh($db);
        }
        return $db;
    }

    /** Seed awal: master saja (kategori/divisi/lokasi, cabang, role) — tanpa data dummy. */
    public static function seedFresh(BaseConnection $db): void
    {
        self::putSettings($db, self::DEFAULT_SETTINGS);
        foreach (self::DEFAULT_LISTS as $key => $values) {
            foreach ($values as $v) {
                $db->query('INSERT OR IGNORE INTO lists(key, value) VALUES (?,?)', [$key, $v]);
            }
        }
        self::insertDefaultRoles($db);
        self::insertDefaultCabangs($db);
        $db->query('INSERT OR REPLACE INTO meta(k, v) VALUES (?,?)', ['seeded', self::stamp()]);
    }

    /**
     * Reset data (tombol Pengaturan): kosongkan seluruh data operasional,
     * kembalikan master & pengaturan ke keadaan awal. User tidak disentuh —
     * role/cabang yang masih dipakai user tetap dipertahankan.
     */
    public static function resetData(BaseConnection $db): void
    {
        $keepRoles = [];
        foreach ($db->query('SELECT DISTINCT role FROM users')->getResult('array') as $r) {
            $row = $db->query('SELECT * FROM roles WHERE kode=?', [$r['role']])->getFirstRow('array');
            if ($row) {
                $keepRoles[] = $row;
            }
        }
        $keepCabangs = [];
        foreach ($db->query('SELECT DISTINCT cabang FROM users')->getResult('array') as $r) {
            $c = (string) $r['cabang'];
            if ($c === '' || $c === '*') {
                continue;
            }
            $row = $db->query('SELECT * FROM cabangs WHERE nama=?', [$c])->getFirstRow('array');
            if ($row) {
                $keepCabangs[] = $row;
            }
        }
        $db->query('DELETE FROM logs');
        $db->query('DELETE FROM mutations');
        $db->query('DELETE FROM opname_sessions');
        $db->query('DELETE FROM assets');
        $db->query('DELETE FROM lists');
        $db->query('DELETE FROM roles');
        $db->query('DELETE FROM cabangs');
        $db->query('DELETE FROM pics');
        self::seedFresh($db);
        foreach ($keepRoles as $row) {
            $db->query('INSERT OR IGNORE INTO roles(kode, label, perms) VALUES (?,?,?)',
                [$row['kode'], $row['label'], $row['perms']]);
        }
        foreach ($keepCabangs as $row) {
            $db->query('INSERT OR IGNORE INTO cabangs(kode, nama, urut) VALUES (?,?,?)',
                [$row['kode'], $row['nama'], $row['urut']]);
        }
    }

    /** Role & cabang default diisi bila tabelnya masih kosong (bukan tiap request). */
    private static function ensureBaseData(BaseConnection $db): void
    {
        if ((int) $db->query('SELECT COUNT(*) c FROM roles')->getFirstRow('array')['c'] === 0) {
            self::insertDefaultRoles($db);
        }
        if ((int) $db->query('SELECT COUNT(*) c FROM cabangs')->getFirstRow('array')['c'] === 0) {
            self::insertDefaultCabangs($db);
        }
    }

    /** Perapian data lama: buang master & field yang sudah tidak dipakai. */
    private static function migrateV4(BaseConnection $db): void
    {
        $done = $db->query('SELECT v FROM meta WHERE k=?', ['migrated_v4'])->getFirstRow('array');
        if ($done) {
            return;
        }
        $db->query('DELETE FROM lists WHERE key NOT IN (?,?,?)', self::LIST_KEYS);
        foreach ($db->query('SELECT kode, data FROM assets')->getResult('array') as $r) {
            $a = json_decode($r['data'], true);
            if (is_array($a) && array_key_exists('sumberDana', $a)) {
                unset($a['sumberDana']);
                $db->query('UPDATE assets SET data=? WHERE kode=?', [self::enc($a), $r['kode']]);
            }
        }
        $db->query('INSERT OR REPLACE INTO meta(k, v) VALUES (?,?)', ['migrated_v4', self::stamp()]);
    }

    // ---------------------------------------------------------- settings / log
    public static function getSettings(BaseConnection $db): array
    {
        $row = $db->query('SELECT data FROM settings WHERE id=?', [1])->getFirstRow('array');
        $data = $row ? (json_decode($row['data'], true) ?: []) : [];
        return array_replace(self::DEFAULT_SETTINGS, $data);
    }

    public static function putSettings(BaseConnection $db, array $settings): void
    {
        $db->query('INSERT OR REPLACE INTO settings(id, data) VALUES (?,?)',
            [1, self::enc($settings)]);
    }

    public static function addLog(BaseConnection $db, array $user, string $aksi, $kode, string $keterangan): void
    {
        $data = [
            'stamp'       => self::stamp(),
            'user'        => self::orv(self::orv($user['nama'] ?? null, $user['username'] ?? null), 'System'),
            'aksi'        => $aksi,
            'kode'        => $kode,
            'keterangan'  => $keterangan,
        ];
        $db->query('INSERT INTO logs(data) VALUES (?)', [self::enc($data)]);
    }

    // ---------------------------------------------------------- master role (Hak Akses)
    /** Semua kunci izin dari katalog modul x fungsi. */
    public static function allPerms(): array
    {
        $out = [];
        foreach (self::PERM_CATALOG as $mod) {
            foreach ($mod['perms'] as $p) {
                $out[] = $p['key'];
            }
        }
        return $out;
    }

    public static function rolesList(BaseConnection $db): array
    {
        $out = [];
        foreach ($db->query('SELECT * FROM roles ORDER BY rowid ASC')->getResult('array') as $r) {
            $out[] = [
                'kode'  => $r['kode'],
                'label' => $r['label'],
                'perms' => json_decode($r['perms'], true) ?: [],
            ];
        }
        return $out;
    }

    public static function rolePerms(BaseConnection $db, string $kode): array
    {
        $row = $db->query('SELECT perms FROM roles WHERE kode=?', [$kode])->getFirstRow('array');
        if ($row) {
            return json_decode($row['perms'], true) ?: [];
        }
        $d = self::DEFAULT_ROLES[$kode] ?? null;
        return $d ? $d['perms'] : [];
    }

    public static function roleLabel(BaseConnection $db, string $kode): string
    {
        $row = $db->query('SELECT label FROM roles WHERE kode=?', [$kode])->getFirstRow('array');
        if ($row && $row['label'] !== '') {
            return $row['label'];
        }
        $d = self::DEFAULT_ROLES[$kode] ?? null;
        return $d ? $d['label'] : $kode;
    }

    public static function roleExists(BaseConnection $db, string $kode): bool
    {
        return (bool) $db->query('SELECT 1 x FROM roles WHERE kode=?', [$kode])->getFirstRow('array');
    }

    private static function insertDefaultRoles(BaseConnection $db): void
    {
        foreach (self::DEFAULT_ROLES as $kode => $r) {
            $db->query('INSERT OR IGNORE INTO roles(kode, label, perms) VALUES (?,?,?)',
                [$kode, $r['label'], self::enc($r['perms'])]);
        }
    }

    private static function insertDefaultCabangs(BaseConnection $db): void
    {
        $urut = 0;
        foreach (self::DEFAULT_CABANGS as [$kode, $nama]) {
            $db->query('INSERT OR IGNORE INTO cabangs(kode, nama, urut) VALUES (?,?,?)',
                [$kode, $nama, $urut++]);
        }
    }

    // ---------------------------------------------------------- master cabang & PIC
    /** Nama semua cabang (dipakai validasi mutasi & select form). */
    public static function cabangNames(BaseConnection $db): array
    {
        return array_column(self::cabangList($db), 'nama');
    }

    public static function cabangList(BaseConnection $db): array
    {
        $out = [];
        foreach ($db->query('SELECT * FROM cabangs ORDER BY urut ASC, rowid ASC')->getResult('array') as $r) {
            $out[] = ['kode' => $r['kode'], 'nama' => $r['nama']];
        }
        return $out;
    }

    public static function firstCabang(BaseConnection $db): string
    {
        $cabang = (string) self::getSettings($db)['cabang'];
        if ($cabang !== '') {
            return $cabang;
        }
        $names = self::cabangNames($db);
        return $names[0] ?? '';
    }

    public static function picsList(BaseConnection $db): array
    {
        $out = [];
        foreach ($db->query('SELECT * FROM pics ORDER BY cabang ASC, nama ASC')->getResult('array') as $r) {
            $out[] = ['id' => (int) $r['id'], 'nama' => $r['nama'], 'cabang' => $r['cabang']];
        }
        return $out;
    }

    public static function apiCabangCreate(BaseConnection $db, array $user, array $body): void
    {
        $kode = strtoupper(trim(self::strv(self::orv($body['kode'] ?? null, ''))));
        $nama = trim(self::strv(self::orv($body['nama'] ?? null, '')));
        if (! preg_match('/^[A-Z0-9]{2,6}$/', $kode)) {
            throw new ApiException(400, 'Kode cabang2-6 karakter (huruf/angka), contoh: BTK.');
        }
        if ($nama === '') {
            throw new ApiException(400, 'Nama cabang wajib diisi.');
        }
        if ($db->query('SELECT 1 x FROM cabangs WHERE kode=?', [$kode])->getFirstRow('array')) {
            throw new ApiException(400, 'Kode cabang sudah dipakai.');
        }
        if ($db->query('SELECT 1 x FROM cabangs WHERE LOWER(nama)=?', [strtolower($nama)])->getFirstRow('array')) {
            throw new ApiException(400, 'Nama cabang sudah ada.');
        }
        $next = (int) ($db->query('SELECT MAX(urut) m FROM cabangs')->getFirstRow('array')['m'] ?? -1) + 1;
        $db->query('INSERT INTO cabangs(kode, nama, urut) VALUES (?,?,?)', [$kode, $nama, $next]);
        self::addLog($db, $user, 'Master Cabang', $kode, 'Menambah cabang ' . $nama . ' (kode inventaris INV-' . $kode . '-....)');
    }

    public static function apiCabangDelete(BaseConnection $db, array $user, string $kode): void
    {
        $kode = strtoupper(trim($kode));
        $row = $db->query('SELECT * FROM cabangs WHERE kode=?', [$kode])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'Cabang tidak ditemukan.');
        }
        $c = (int) $db->query('SELECT COUNT(*) c FROM cabangs')->getFirstRow('array')['c'];
        if ($c <= 1) {
            throw new ApiException(400, 'Tidak boleh menghapus cabang terakhir.');
        }
        $used = 0;
        foreach ($db->query('SELECT data FROM assets')->getResult('array') as $r) {
            $a = json_decode($r['data'], true);
            if (is_array($a) && ($a['cabang'] ?? null) === $row['nama']) {
                $used++;
            }
        }
        if ($used > 0) {
            throw new ApiException(400, 'Cabang masih memiliki ' . $used . ' aset dan tidak bisa dihapus.');
        }
        $users = (int) $db->query('SELECT COUNT(*) c FROM users WHERE cabang=?', [$row['nama']])->getFirstRow('array')['c'];
        if ($users > 0) {
            throw new ApiException(400, 'Cabang masih dipakai ' . $users . ' user dan tidak bisa dihapus.');
        }
        $db->query('DELETE FROM pics WHERE cabang=?', [$row['nama']]);
        $db->query('DELETE FROM cabangs WHERE kode=?', [$kode]);
        self::addLog($db, $user, 'Master Cabang', $kode, 'Menghapus cabang ' . $row['nama']);
    }

    public static function apiPicCreate(BaseConnection $db, array $user, array $body): void
    {
        $nama = trim(self::strv(self::orv($body['nama'] ?? null, '')));
        $cabang = trim(self::strv(self::orv($body['cabang'] ?? null, '')));
        if ($nama === '') {
            throw new ApiException(400, 'Nama PIC wajib diisi.');
        }
        if ($cabang === '' || ! in_array($cabang, self::cabangNames($db), true)) {
            throw new ApiException(400, 'Cabang PIC tidak valid.');
        }
        $exists = $db->query('SELECT 1 x FROM pics WHERE nama=? AND cabang=?', [$nama, $cabang])->getFirstRow('array');
        if ($exists) {
            throw new ApiException(400, 'PIC tersebut sudah terdaftar di cabang ini.');
        }
        $db->query('INSERT INTO pics(nama, cabang) VALUES (?,?)', [$nama, $cabang]);
        self::addLog($db, $user, 'Master PIC', '-', 'Menambah PIC ' . $nama . ' (' . $cabang . ')');
    }

    public static function apiPicDelete(BaseConnection $db, array $user, int $id): void
    {
        $row = $db->query('SELECT * FROM pics WHERE id=?', [$id])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'PIC tidak ditemukan.');
        }
        $used = 0;
        foreach ($db->query('SELECT data FROM assets')->getResult('array') as $r) {
            $a = json_decode($r['data'], true) ?: [];
            if (($a['pic'] ?? null) === $row['nama'] && ($a['cabang'] ?? null) === $row['cabang']) {
                $used++;
            }
        }
        if ($used > 0) {
            throw new ApiException(400, 'PIC masih digunakan ' . $used . ' aset dan tidak bisa dihapus.');
        }
        $db->query('DELETE FROM pics WHERE id=?', [$id]);
        self::addLog($db, $user, 'Master PIC', '-', 'Menghapus PIC ' . $row['nama'] . ' (' . $row['cabang'] . ')');
    }

    public static function apiRoleCreate(BaseConnection $db, array $user, array $body): void
    {
        $kode = strtolower(trim(self::strv(self::orv($body['kode'] ?? null, ''))));
        $label = trim(self::strv(self::orv($body['label'] ?? null, '')));
        if (! preg_match('/^[a-z0-9._-]{2,30}$/', $kode)) {
            throw new ApiException(400, 'Kode role2-30 karakter (huruf kecil, angka, . _ -).');
        }
        if ($label === '') {
            throw new ApiException(400, 'Nama role wajib diisi.');
        }
        if (self::roleExists($db, $kode)) {
            throw new ApiException(400, 'Kode role sudah ada.');
        }
        $perms = self::cleanPerms($body['perms'] ?? null);
        $db->query('INSERT INTO roles(kode, label, perms) VALUES (?,?,?)', [$kode, $label, self::enc($perms)]);
        self::addLog($db, $user, 'Hak Akses', '-', 'Membuat role ' . $label . ' (' . $kode . ') dengan ' . count($perms) . ' izin');
    }

    public static function apiRoleUpdate(BaseConnection $db, array $user, string $kode, array $body): void
    {
        $row = $db->query('SELECT * FROM roles WHERE kode=?', [strtolower($kode)])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'Role tidak ditemukan.');
        }
        $label = trim(self::strv(self::orv($body['label'] ?? null, $row['label'])));
        if ($label === '') {
            $label = $row['label'];
        }
        $perms = array_key_exists('perms', $body)
            ? self::cleanPerms($body['perms'])
            : (json_decode($row['perms'], true) ?: []);
        $db->query('UPDATE roles SET label=?, perms=? WHERE kode=?', [$label, self::enc($perms), $row['kode']]);
        self::addLog($db, $user, 'Hak Akses', '-', 'Mengubah role ' . $label . ' (' . $row['kode'] . ') menjadi ' . count($perms) . ' izin');
    }

    public static function apiRoleDelete(BaseConnection $db, array $user, string $kode): void
    {
        $kode = strtolower(trim($kode));
        $row = $db->query('SELECT * FROM roles WHERE kode=?', [$kode])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'Role tidak ditemukan.');
        }
        if ($kode === 'admin') {
            throw new ApiException(400, 'Role admin bawaan tidak bisa dihapus.');
        }
        $c = (int) $db->query('SELECT COUNT(*) c FROM roles')->getFirstRow('array')['c'];
        if ($c <= 1) {
            throw new ApiException(400, 'Tidak boleh menghapus semua role.');
        }
        $used = (int) $db->query('SELECT COUNT(*) c FROM users WHERE role=?', [$kode])->getFirstRow('array')['c'];
        if ($used > 0) {
            throw new ApiException(400, 'Role masih dipakai ' . $used . ' user dan tidak bisa dihapus.');
        }
        $db->query('DELETE FROM roles WHERE kode=?', [$kode]);
        self::addLog($db, $user, 'Hak Akses', '-', 'Menghapus role ' . $row['label'] . ' (' . $kode . ')');
    }

    /** Buang izin yang tidak ada di katalog agar data role selalu valid. */
    private static function cleanPerms($perms): array
    {
        if (! is_array($perms)) {
            return [];
        }
        $valid = self::allPerms();
        $out = [];
        foreach ($perms as $p) {
            $p = self::strv($p);
            if ($p !== '' && in_array($p, $valid, true) && ! in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function publicUser(array $row): array
    {
        $role = $row['role'];
        return [
            'id'        => (int) $row['id'],
            'username'  => $row['username'],
            'nama'      => $row['nama'],
            'role'      => $role,
            'roleLabel' => self::roleLabel(self::db(), $role),
            'cabang'    => $row['cabang'],
            'aktif'     => (bool) $row['aktif'],
            'perms'     => self::rolePerms(self::db(), $role),
        ];
    }

    public static function userRow(int $id): ?array
    {
        $row = self::db()->query('SELECT * FROM users WHERE id=?', [$id])->getFirstRow('array');
        return $row ?: null;
    }

    public static function ensureSeedUsers(BaseConnection $db): void
    {
        $c = $db->query('SELECT COUNT(*) c FROM users')->getFirstRow('array');
        if ((int) $c['c'] > 0) {
            return;
        }
        foreach (self::DEMO_USERS as [$username, $nama, $password, $role, $cabang]) {
            [$salt, $h] = self::hashPassword($password);
            $db->query('INSERT OR IGNORE INTO users(username, nama, pw_salt, pw_hash, role, cabang, aktif, created_at)'
                . ' VALUES (?,?,?,?,?,?,1,?)',
                [$username, $nama, $salt, $h, $role, $cabang, self::today()]);
        }
    }

    public static function demoHints(BaseConnection $db): array
    {
        $hints = [];
        foreach (self::DEMO_USERS as [$u, $_n, $pw, $_r, $_c]) {
            $row = $db->query('SELECT pw_salt s, pw_hash h, aktif a FROM users WHERE username=?', [$u])->getFirstRow('array');
            if ($row && $row['a'] && self::verifyPassword($pw, $row['s'], $row['h'])) {
                $hints[] = $u . ' / ' . $pw;
            }
        }
        return $hints;
    }

    // ---------------------------------------------------------- state / scope
    public static function loadAsset(BaseConnection $db, string $kode): array
    {
        $row = $db->query('SELECT data FROM assets WHERE kode=?', [$kode])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, "Inventaris {$kode} tidak ditemukan.");
        }
        return json_decode($row['data'], true);
    }

    public static function saveAsset(BaseConnection $db, array $asset, ?int $seq = null): void
    {
        if ($seq === null) {
            $row = $db->query('SELECT seq FROM assets WHERE kode=?', [$asset['kode']])->getFirstRow('array');
            $seq = $row ? (int) $row['seq'] : 0;
        }
        $db->query('INSERT OR REPLACE INTO assets(kode, seq, data) VALUES (?,?,?)',
            [$asset['kode'], $seq, self::enc($asset)]);
    }

    public static function assertAssetInScope(array $user, array $asset): void
    {
        if ($user['role'] === 'cabang' && ($asset['cabang'] ?? null) !== $user['cabang']) {
            throw new ApiException(403, 'Aset tersebut berada di luar cabang Anda.', 'OUT_OF_SCOPE');
        }
    }

    public static function buildState(BaseConnection $db, array $user): array
    {
        $assets = [];
        foreach ($db->query('SELECT data FROM assets ORDER BY seq ASC')->getResult('array') as $r) {
            $assets[] = json_decode($r['data'], true);
        }
        if ($user['role'] === 'cabang') {
            $assets = array_values(array_filter($assets,
                fn ($a) => ($a['cabang'] ?? null) === $user['cabang']));
        }
        $codes = array_column($assets, 'kode');

        $mutations = [];
        foreach ($db->query('SELECT data FROM mutations ORDER BY seq ASC')->getResult('array') as $r) {
            $m = json_decode($r['data'], true);
            if (! isset($m['jenis']) || $m['jenis'] === '' || $m['jenis'] === null) {
                $m['jenis'] = 'Mutasi';   // data lama tanpa jenis = mutasi biasa
            }
            if (in_array($m['kode'] ?? null, $codes, true)) {
                $mutations[] = $m;
            }
        }

        $opnameSessions = [];
        foreach ($db->query('SELECT data FROM opname_sessions ORDER BY seq ASC')->getResult('array') as $r) {
            $s = json_decode($r['data'], true);
            if ($user['role'] !== 'cabang' || ($s['cabang'] ?? null) === $user['cabang']) {
                $opnameSessions[] = $s;
            }
        }

        $logs = [];
        if ($user['role'] === 'cabang') {
            $rows = $db->query('SELECT data FROM logs ORDER BY id DESC LIMIT 200')->getResult('array');
            foreach ($rows as $r) {
                $d = json_decode($r['data'], true);
                if (in_array($d['kode'] ?? null, $codes, true) || ($d['kode'] ?? null) === '-') {
                    $logs[] = $d;
                }
            }
        } else {
            foreach ($db->query('SELECT data FROM logs ORDER BY id DESC LIMIT 500')->getResult('array') as $r) {
                $logs[] = json_decode($r['data'], true);
            }
        }

        $lists = [];
        foreach (self::DEFAULT_LISTS as $k => $v) {
            $lists[$k] = [];
        }
        foreach ($db->query('SELECT key, value FROM lists ORDER BY rowid')->getResult('array') as $r) {
            if (isset($lists[$r['key']])) {
                $lists[$r['key']][] = $r['value'];
            }
        }

        return [
            'settings'  => self::getSettings($db),
            'lists'     => $lists,
            'roles'     => self::rolesList($db),
            'permCatalog' => self::PERM_CATALOG,
            'cabangs'   => self::cabangList($db),
            'pics'      => self::picsList($db),
            'assets'    => $assets,
            'mutations' => $mutations,
            'opnameSessions' => $opnameSessions,
            'logs'      => $logs,
        ];
    }

    public static function nextSeq(BaseConnection $db, string $table): int
    {
        // $table hanya diisi 'assets'/'mutations' dari kode internal — aman.
        $row = $db->query('SELECT MIN(seq) AS m FROM ' . $table)->getFirstRow('array');
        $m = $row['m'] ?? null;
        return ($m === null ? 0 : (int) $m) -1;
    }

    /** Kode inventaris per cabang — diambil dari tabel cabangs, fallback huruf awal nama. */
    public static function cabangPrefix(BaseConnection $db, $cabang): string
    {
        $c = trim(self::strv($cabang));
        $row = $db->query('SELECT kode FROM cabangs WHERE nama=?', [$c])->getFirstRow('array');
        if ($row && $row['kode'] !== '') {
            return strtoupper($row['kode']);
        }
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $c) ?: [],
            fn ($w) => $w !== '' && strtoupper($w) !== 'TM'));
        if ($words === []) {
            return 'GEN';
        }
        $ini = strtoupper(implode('', array_map(fn ($w) => $w[0], $words)));
        return strlen($ini) >= 2 ? substr($ini, 0, 4) : strtoupper(substr($words[0], 0, 3));
    }

    public static function nextKode(BaseConnection $db, $cabang): string
    {
        $pfx = self::cabangPrefix($db, $cabang);
        $mx = 0;
        foreach ($db->query('SELECT kode FROM assets WHERE kode LIKE ?', ['INV-' . $pfx . '-%'])->getResult('array') as $r) {
            if (preg_match('/^INV-' . preg_quote($pfx, '/') . '-(\d+)$/', (string) $r['kode'], $m)) {
                $mx = max($mx, (int) $m[1]);
            }
        }
        return sprintf('INV-%s-%04d', $pfx, $mx + 1);
    }

    public static function nextMutId(BaseConnection $db): string
    {
        $mx = 0;
        foreach ($db->query('SELECT data FROM mutations')->getResult('array') as $r) {
            $d = json_decode($r['data'], true) ?: [];
            if (preg_match('/^MUT-(\d+)$/', (string) ($d['id'] ?? ''), $m)) {
                $mx = max($mx, (int) $m[1]);
            }
        }
        return sprintf('MUT-%04d', $mx + 1);
    }

    public static function appendRiwayat(array &$asset, string $aktivitas, string $keterangan, array $user): void
    {
        if (! isset($asset['riwayat']) || ! is_array($asset['riwayat'])) {
            $asset['riwayat'] = [];
        }
        $asset['riwayat'][] = [
            'tgl'        => self::today(),
            'aktivitas'  => $aktivitas,
            'keterangan' => $keterangan,
            'user'       => self::orv(self::orv($user['nama'] ?? null, $user['username'] ?? null), 'System'),
        ];
        usort($asset['riwayat'], fn ($a, $b) => strcmp((string) ($a['tgl'] ?? ''), (string) ($b['tgl'] ?? '')));
    }

    // ---------------------------------------------------------- auth
    public static function cookieToken(): ?string
    {
        $header = $_SERVER['HTTP_COOKIE'] ?? '';
        foreach (explode(';', $header) as $part) {
            $parts = explode('=', trim($part),2);
            if (($parts[0] ?? '') === self::SESSION_COOKIE) {
                return $parts[1] ?? '';
            }
        }
        return null;
    }

    public static function resolveSession(BaseConnection $db, ?string $token): ?array
    {
        if (! $token) {
            return null;
        }
        $row = $db->query(
            'SELECT s.token token, s.expires_at expires_at, u.* FROM sessions s'
            . ' JOIN users u ON u.id = s.user_id WHERE s.token=? AND u.aktif=1',
            [$token]
        )->getFirstRow('array');
        if (! $row) {
            return null;
        }
        if ((int) $row['expires_at'] < time()) {
            $db->query('DELETE FROM sessions WHERE token=?', [$token]);
            return null;
        }
        return $row;
    }

    public static function authLogin(BaseConnection $db, array $body): array
    {
        $username = trim(self::strv(self::orv($body['username'] ?? null, '')));
        $password = self::strv($body['password'] ?? null);
        if ($username === '' || $password === '') {
            throw new ApiException(400, 'Username dan password wajib diisi.');
        }
        $row = $db->query('SELECT * FROM users WHERE username=?', [$username])->getFirstRow('array');
        if (! $row || ! self::verifyPassword($password, $row['pw_salt'], $row['pw_hash'])) {
            throw new ApiException(401, 'Username atau password salah.', 'BAD_CREDENTIALS');
        }
        if (! $row['aktif']) {
            throw new ApiException(403, 'Akun ini dinonaktifkan. Hubungi administrator.', 'ACCOUNT_DISABLED');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->query('INSERT INTO sessions(token, user_id, created_at, expires_at) VALUES (?,?,?,?)',
            [$token, $row['id'], self::stamp(), time() + self::SESSION_TTL]);
        $db->query('DELETE FROM sessions WHERE expires_at<?', [time()]);
        self::addLog($db, ['nama' => $row['nama'], 'username' => $row['username']], 'Login', '-',
            'Masuk sebagai ' . self::roleLabel($db, $row['role']));
        return [$token, self::publicUser($row)];
    }

    public static function authLogout(BaseConnection $db, ?string $token): void
    {
        if ($token) {
            $db->query('DELETE FROM sessions WHERE token=?', [$token]);
        }
    }

    public static function authChangePassword(BaseConnection $db, array $user, array $body): void
    {
        $old = self::strv($body['oldPassword'] ?? null);
        $new = self::strv($body['newPassword'] ?? null);
        if (strlen($new) <6) {
            throw new ApiException(400, 'Password baru minimal 6 karakter.');
        }
        $row = $db->query('SELECT * FROM users WHERE id=?', [$user['id']])->getFirstRow('array');
        if (! self::verifyPassword($old, $row['pw_salt'], $row['pw_hash'])) {
            throw new ApiException(400, 'Password lama salah.');
        }
        [$salt, $h] = self::hashPassword($new);
        $db->query('UPDATE users SET pw_salt=?, pw_hash=? WHERE id=?', [$salt, $h, $row['id']]);
        self::addLog($db, $user, 'Akun', '-', 'Mengubah password sendiri');
    }

    // ---------------------------------------------------------- user management
    public static function usersList(BaseConnection $db): array
    {
        $out = [];
        foreach ($db->query('SELECT * FROM users ORDER BY id ASC')->getResult('array') as $r) {
            $out[] = self::publicUser($r);
        }
        return $out;
    }

    public static function usersCreate(BaseConnection $db, array $actor, array $body): void
    {
        $username = strtolower(trim(self::strv(self::orv($body['username'] ?? null, ''))));
        $nama = trim(self::strv(self::orv($body['nama'] ?? null, '')));
        $password = self::strv($body['password'] ?? null);
        $role = self::strv($body['role'] ?? null);
        $cabang = trim(self::strv(self::orv($body['cabang'] ?? null, '*')));
        if ($cabang === '') {
            $cabang = '*';
        }
        if (! preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
            throw new ApiException(400, 'Username 3-30 karakter (huruf kecil, angka, . _ -).');
        }
        if ($nama === '') {
            throw new ApiException(400, 'Nama wajib diisi.');
        }
        if (strlen($password) <6) {
            throw new ApiException(400, 'Password minimal 6 karakter.');
        }
        if (! self::roleExists($db, $role)) {
            throw new ApiException(400, 'Role tidak valid.');
        }
        if ($role === 'cabang') {
            if ($cabang === '*' || ! in_array($cabang, self::cabangNames($db), true)) {
                throw new ApiException(400, 'User role Cabang harus memiliki cabang yang valid.');
            }
        }
        if ($db->query('SELECT 1 x FROM users WHERE username=?', [$username])->getFirstRow('array')) {
            throw new ApiException(400, 'Username sudah dipakai.');
        }
        [$salt, $h] = self::hashPassword($password);
        $aktif = array_key_exists('aktif', $body) ? (self::truthy($body['aktif']) ?1:0) :1;
        $db->query('INSERT INTO users(username, nama, pw_salt, pw_hash, role, cabang, aktif, created_at)'
            . ' VALUES (?,?,?,?,?,?,?,?)',
            [$username, $nama, $salt, $h, $role, $cabang, $aktif, self::today()]);
        self::addLog($db, $actor, 'Akun', '-',
            'Membuat user ' . $username . ' (' . self::roleLabel($db, $role) . ')');
    }

    public static function usersUpdate(BaseConnection $db, array $actor, int $uid, array $body): void
    {
        $row = $db->query('SELECT * FROM users WHERE id=?', [$uid])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'User tidak ditemukan.');
        }
        $nama = trim(self::strv(self::orv($body['nama'] ?? null, $row['nama'])));
        if ($nama === '') {
            $nama = $row['nama'];
        }
        $role = self::strv(self::orv($body['role'] ?? null, $row['role']));
        $cabang = trim(self::strv(self::orv($body['cabang'] ?? null, $row['cabang'])));
        if ($cabang === '') {
            $cabang = '*';
        }
        $aktif = array_key_exists('aktif', $body)
            ? (self::truthy($body['aktif']) ?1:0)
            : ((int) $row['aktif'] !==0 ?1:0);
        if (! self::roleExists($db, $role)) {
            throw new ApiException(400, 'Role tidak valid.');
        }
        if ($role === 'cabang') {
            if ($cabang === '*' || ! in_array($cabang, self::cabangNames($db), true)) {
                throw new ApiException(400, 'User role Cabang harus memiliki cabang yang valid.');
            }
        }
        if ((int) $row['id'] === (int) $actor['id'] && ($role !== $row['role'] || ! $aktif)) {
            throw new ApiException(400, 'Anda tidak dapat mengubah role/menonaktifkan akun sendiri.');
        }
        $newPassword = self::strv($body['password'] ?? null);
        $salt = $row['pw_salt'];
        $h = $row['pw_hash'];
        $reset = false;
        if ($newPassword !== '') {
            if (strlen($newPassword) <6) {
                throw new ApiException(400, 'Password minimal 6 karakter.');
            }
            [$salt, $h] = self::hashPassword($newPassword);
            $reset = true;
        }
        $db->query('UPDATE users SET nama=?, role=?, cabang=?, aktif=?, pw_salt=?, pw_hash=? WHERE id=?',
            [$nama, $role, $cabang, $aktif, $salt, $h, $uid]);
        if ($reset) {
            $db->query('DELETE FROM sessions WHERE user_id=?', [$uid]);
        }
        self::addLog($db, $actor, 'Akun', '-',
            'Mengubah user ' . $row['username'] . ($reset ? ' (reset password)' : ''));
    }

    public static function usersDelete(BaseConnection $db, array $actor, int $uid): void
    {
        $row = $db->query('SELECT * FROM users WHERE id=?', [$uid])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'User tidak ditemukan.');
        }
        if ((int) $row['id'] === (int) $actor['id']) {
            throw new ApiException(400, 'Anda tidak dapat menghapus akun sendiri.');
        }
        $c = $db->query('SELECT COUNT(*) c FROM users')->getFirstRow('array');
        if ((int) $c['c'] <=1) {
            throw new ApiException(400, 'Tidak boleh menghapus semua user.');
        }
        $db->query('DELETE FROM sessions WHERE user_id=?', [$uid]);
        $db->query('DELETE FROM users WHERE id=?', [$uid]);
        self::addLog($db, $actor, 'Akun', '-', 'Menghapus user ' . $row['username']);
    }

    // ---------------------------------------------------------- aset
    public static function apiCreateAsset(BaseConnection $db, array $user, array $body): array
    {
        foreach (['nama', 'kategori', 'lokasi', 'status'] as $f) {
            if (trim(self::strv(self::orv($body[$f] ?? null, ''))) === '') {
                throw new ApiException(400, "Field '{$f}' wajib diisi.");
            }
        }
        if ($user['role'] === 'cabang') {
            $cabang = $user['cabang'];
        } else {
            $cabang = self::strv(self::orv($body['cabang'] ?? null,
                self::firstCabang($db)));
        }
        $kode = self::nextKode($db, $cabang);
        $asset = ['kode' => $kode, 'cabang' => $cabang, 'riwayat' => [], 'opname' => [], 'perbaikan' => []];
        foreach (self::ASSET_FIELDS as $f) {
            if ($f === 'cabang') {
                continue;
            }
            $v = $body[$f] ?? null;
            if ($f === 'nilai') {
                $asset[$f] = self::intv($v);
            } elseif ($f === 'dokumen') {
                $asset[$f] = (is_array($v) && array_is_list($v)) ? $v : [];
            } elseif ($f === 'foto') {
                $asset[$f] = self::strv(self::orv($v, ''));
            } elseif ($f === 'kondisi') {
                $asset[$f] = in_array($v, self::KONDISI, true) ? $v : 'Baik';
            } elseif ($f === 'catatan') {
                $asset[$f] = self::strv(self::orv($v, '-'));
            } else {
                if ($v === null) {
                    $asset[$f] = in_array($f, ['merek', 'serial', 'divisi', 'pic'], true) ? '-' : '';
                } else {
                    $asset[$f] = self::strv($v);
                }
            }
        }
        $asset['tgl'] = self::strv(self::orv($asset['tgl'] ?? null, self::today()));
        self::appendRiwayat($asset, 'Penambahan', 'Inventaris baru', $user);
        $seq = self::nextSeq($db, 'assets');
        self::saveAsset($db, $asset, $seq);
        self::addLog($db, $user, 'Penambahan', $kode,
            'Inventaris baru: ' . $asset['nama'] . ' (' . $cabang . ')');
        return ['kode' => $kode];
    }

    public static function apiUpdateAsset(BaseConnection $db, array $user, string $kode, array $body): void
    {
        $asset = self::loadAsset($db, $kode);
        self::assertAssetInScope($user, $asset);
        $changes = [];
        foreach (self::ASSET_FIELDS as $f) {
            if (! array_key_exists($f, $body)) {
                continue;
            }
            $v = $body[$f];
            if ($f === 'nilai') {
                $v = self::intv($v);
            } elseif ($f === 'dokumen') {
                $v = (is_array($v) && array_is_list($v)) ? $v : [];
            } elseif ($f === 'kondisi' && ! in_array($v, self::KONDISI, true)) {
                continue;
            } elseif ($f === 'cabang' && $user['role'] !== 'admin') {
                continue; // hanya admin boleh pindahkan cabang
            } elseif (in_array($f, ['status', 'tgl'], true) && $user['role'] !== 'admin') {
                continue; // status & tanggal perolehan hanya admin
            } elseif (in_array($f, ['nama', 'lokasi', 'divisi', 'pic', 'kondisi'], true)) {
                continue; // hanya lewat transaksi (mutasi/opname)
            } else {
                $v = $v === null ? '' : self::strv($v);
            }
            if (self::strv($asset[$f] ?? '') !== self::strv($v)) {
                if (in_array($f, self::TRACKED, true)) {
                    $changes[] = $f . ': ' . self::strv($asset[$f] ?? '') . ' → ' . self::strv($v);
                }
                $asset[$f] = $v;
            }
        }
        if ($changes !== []) {
            self::appendRiwayat($asset, 'Perubahan Data', implode(', ', $changes), $user);
        }
        self::saveAsset($db, $asset);
        self::addLog($db, $user, 'Edit', $kode,
            $changes !== [] ? implode(', ', $changes) : 'Pembaruan data inventaris');
    }

    public static function apiAssetDocs(BaseConnection $db, array $user, string $kode, array $body, ?int $index = null): void
    {
        $asset = self::loadAsset($db, $kode);
        self::assertAssetInScope($user, $asset);
        if (! isset($asset['dokumen']) || ! is_array($asset['dokumen'])) {
            $asset['dokumen'] = [];
        }
        $docs = &$asset['dokumen'];
        if ($index === null) {
            $nama = self::strv(self::orv($body['nama'] ?? null, 'Dokumen'));
            $docs[] = ['nama' => $nama, 'url' => self::strv($body['url'] ?? null)];
            self::addLog($db, $user, 'Dokumen', $kode, 'Upload ' . $nama);
        } else {
            if ($index >=0 && $index < count($docs)) {
                $removed = array_splice($docs, $index,1)[0];
                self::addLog($db, $user, 'Dokumen', $kode, 'Hapus ' . ($removed['nama'] ?? '-'));
            } else {
                throw new ApiException(400, 'Indeks dokumen tidak valid.');
            }
        }
        unset($docs);
        self::saveAsset($db, $asset);
    }

    // ---------------------------------------------------------- opname sesi
    /** @return array{0:array,1:int} [data_sesi, seq] */
    public static function fetchSession(BaseConnection $db, string $sid): array
    {
        $row = $db->query('SELECT data, seq FROM opname_sessions WHERE id=?', [$sid])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, 'Sesi opname tidak ditemukan.');
        }
        return [json_decode($row['data'], true), (int) $row['seq']];
    }

    public static function saveSession(BaseConnection $db, array $sess, int $seq): void
    {
        $db->query('INSERT OR REPLACE INTO opname_sessions(id, seq, data) VALUES (?,?,?)',
            [$sess['id'], $seq, self::enc($sess)]);
    }

    public static function assertSessionInScope(array $user, array $sess): void
    {
        if (($user['role'] ?? null) === 'cabang' && ($sess['cabang'] ?? null) !== $user['cabang']) {
            throw new ApiException(403, 'Sesi opname bukan milik cabang Anda.', 'FORBIDDEN');
        }
    }

    public static function nextOpnameId(BaseConnection $db): string
    {
        $mx = 0;
        foreach ($db->query('SELECT data FROM opname_sessions')->getResult('array') as $r) {
            $d = json_decode($r['data'], true);
            if (preg_match('/^OPN-(\d+)$/', (string) ($d['id'] ?? ''), $m)) {
                $mx = max($mx, (int) $m[1]);
            }
        }
        return sprintf('OPN-%04d', $mx + 1);
    }

    /** FR-2: maksimal satu sesi Berjalan per cabang. */
    public static function activeSessionOf(BaseConnection $db, $cabang): ?array
    {
        foreach ($db->query('SELECT data FROM opname_sessions ORDER BY seq ASC')->getResult('array') as $r) {
            $s = json_decode($r['data'], true);
            if (($s['cabang'] ?? null) === $cabang && ($s['status'] ?? null) === 'Berjalan') {
                return $s;
            }
        }
        return null;
    }

    public static function apiCreateOpname(BaseConnection $db, array $user, array $body): array
    {
        $cabang  = trim(self::strv(self::orv($body['cabang'] ?? null, '')));
        $nama    = trim(self::strv(self::orv($body['nama'] ?? null, '')));
        $tanggal = trim(self::strv(self::orv($body['tanggal'] ?? null, '')));
        $catatan = trim(self::strv(self::orv($body['catatan'] ?? null, '')));
        if ($catatan === '') {
            $catatan = '-';
        }
        if ($cabang === '') {
            throw new ApiException(400, 'Cabang wajib dipilih.');
        }
        if ($nama === '') {
            throw new ApiException(400, 'Nama sesi opname wajib diisi.');
        }
        if ($tanggal === '') {
            throw new ApiException(400, 'Tanggal opname wajib diisi.');
        }
        if (self::activeSessionOf($db, $cabang) !== null) {
            throw new ApiException(409,
                'Masih ada sesi opname berjalan pada cabang ini. Akhiri sesi terlebih dahulu.',
                'ACTIVE_SESSION');
        }
        $sid = self::nextOpnameId($db);
        // FR-5: snapshot seluruh inventaris cabang pada saat sesi dibuat.
        // Lokasi/PIC/kondisi disimpan ganda (nilai kerja + *_awal) supaya ringkasan
        // bisa menghitung "data berubah" dan item bisa dikembalikan ke nilai awal.
        $items = [];
        foreach ($db->query('SELECT data FROM assets ORDER BY seq ASC')->getResult('array') as $r) {
            $a = json_decode($r['data'], true);
            if (($a['cabang'] ?? null) !== $cabang) {
                continue;
            }
            $lokasi  = self::orv($a['lokasi'] ?? null, '-');
            $pic     = self::orv($a['pic'] ?? null, '-');
            $kondisi = self::orv($a['kondisi'] ?? null, 'Baik');
            $items[] = [
                'kode' => $a['kode'], 'sku' => $a['kode'], 'barcode' => $a['kode'],
                'nama' => $a['nama'] ?? '-', 'kategori' => $a['kategori'] ?? '-',
                'lokasi' => $lokasi, 'pic' => $pic, 'kondisi' => $kondisi,
                'lokasi_awal' => $lokasi, 'pic_awal' => $pic, 'kondisi_awal' => $kondisi,
                'status' => 'Belum', 'dicek' => false,
                'counted_at' => null, 'counted_by' => null,
            ];
        }
        $sess = [
            'id' => $sid, 'cabang' => $cabang, 'nama' => $nama, 'tanggal' => $tanggal,
            'catatan' => $catatan, 'status' => 'Draft',
            'user' => self::orv($user['nama'] ?? null, $user['username'] ?? null),
            'created_at' => self::stamp(), 'started_at' => null, 'ended_at' => null,
            'items' => $items, 'summary' => null,
        ];
        self::saveSession($db, $sess, self::nextSeq($db, 'opname_sessions'));
        self::addLog($db, $user, 'Stock Opname', '-',
            'Buat sesi opname ' . $sid . ' (' . $nama . ') cabang ' . $cabang . ', ' . count($items) . ' item');
        return ['id' => $sid];
    }

    public static function apiStartOpname(BaseConnection $db, array $user, string $sid, array $body): void
    {
        [$sess, $seq] = self::fetchSession($db, $sid);
        self::assertSessionInScope($user, $sess);
        if (($sess['status'] ?? null) !== 'Draft') {
            throw new ApiException(409, 'Hanya sesi berstatus Draft yang bisa dimulai.', 'SESSION_STATE');
        }
        $running = self::activeSessionOf($db, $sess['cabang'] ?? null);
        if ($running !== null && ($running['id'] ?? null) !== $sid) {
            throw new ApiException(409,
                'Masih ada sesi opname berjalan pada cabang ini. Akhiri sesi terlebih dahulu.',
                'ACTIVE_SESSION');
        }
        $sess['status'] = 'Berjalan';
        $sess['started_at'] = self::stamp();
        self::saveSession($db, $sess, $seq);
        self::addLog($db, $user, 'Stock Opname', '-',
            'Mulai sesi opname ' . $sid . ' (' . ($sess['nama'] ?? '') . ')');
    }

    public static function apiCountOpname(BaseConnection $db, array $user, string $sid, string $kode, array $body): void
    {
        $newStatus = $body['status'] ?? null;
        if (! in_array($newStatus, self::OPNAME_ITEM_STATUS, true)) {
            throw new ApiException(400, 'Status item opname tidak valid.');
        }
        [$sess, $seq] = self::fetchSession($db, $sid);
        self::assertSessionInScope($user, $sess);
        if (($sess['status'] ?? null) !== 'Berjalan') {
            throw new ApiException(409, 'Sesi opname tidak dalam status Berjalan, penghitungan terkunci.',
                'SESSION_LOCKED');
        }
        $itemIdx = null;
        foreach (array_keys($sess['items'] ?? []) as $ix) {
            if (($sess['items'][$ix]['kode'] ?? null) === $kode) {
                $itemIdx = $ix;
                break;
            }
        }
        if ($itemIdx === null) {
            throw new ApiException(404, 'Item opname tidak ditemukan.');
        }
        $item = $sess['items'][$itemIdx];

        $awalLokasi  = array_key_exists('lokasi_awal', $item) ? $item['lokasi_awal'] : ($item['lokasi'] ?? null);
        $awalPic     = array_key_exists('pic_awal', $item) ? $item['pic_awal'] : ($item['pic'] ?? null);
        $awalKondisi = array_key_exists('kondisi_awal', $item) ? $item['kondisi_awal'] : ($item['kondisi'] ?? null);
        $old = [$item['status'] ?? null, $item['lokasi'] ?? null, $item['pic'] ?? null,
            $item['kondisi'] ?? null, ! empty($item['dicek'])];
        $countedAt = null;
        $countedBy = null;

        if ($newStatus === 'Belum') {
            // hitungan dibatalkan -> data dikembalikan ke nilai awal snapshot
            $status  = 'Belum';
            $lokasi  = $awalLokasi;
            $pic     = $awalPic;
            $kondisi = $awalKondisi;
            $dicek   = false;
        } elseif ($newStatus === 'Tidak Ditemukan') {
            // barang tidak ditemukan: lokasi/PIC tetap nilai awal, kondisi ditandai hilang
            $status  = 'Tidak Ditemukan';
            $lokasi  = $awalLokasi;
            $pic     = $awalPic;
            $kondisi = 'Tidak Ditemukan';
            $dicek   = true;
            $countedAt = self::stamp();
            $countedBy = self::orv($user['nama'] ?? null, $user['username'] ?? null);
        } else {
            $status    = 'Ditemukan';
            $rawLokasi  = $body['lokasi'] ?? null;
            $rawPic     = $body['pic'] ?? null;
            $rawKondisi = $body['kondisi'] ?? null;
            $lokasi  = $rawLokasi !== null ? trim(self::strv($rawLokasi)) : (string) ($item['lokasi'] ?? '');
            $pic     = $rawPic !== null ? trim(self::strv($rawPic)) : (string) ($item['pic'] ?? '');
            $kondisi = $rawKondisi !== null ? trim(self::strv($rawKondisi)) : (string) ($item['kondisi'] ?? '');
            if ($lokasi === '') {
                throw new ApiException(400, 'Lokasi wajib diisi.');
            }
            if ($pic === '') {
                throw new ApiException(400, 'PIC wajib diisi.');
            }
            if (! in_array($kondisi, self::KONDISI, true)) {
                throw new ApiException(400, 'Kondisi tidak valid.');
            }
            $dicek = true;
            $countedAt = self::stamp();
            $countedBy = self::orv($user['nama'] ?? null, $user['username'] ?? null);
        }

        if ([$status, $lokasi, $pic, $kondisi, $dicek] === $old) {
            return; // tanpa perubahan: tidak ada log
        }

        $item['status']     = $status;
        $item['lokasi']     = $lokasi;
        $item['pic']        = $pic;
        $item['kondisi']    = $kondisi;
        $item['dicek']      = $dicek;
        $item['counted_at'] = $countedAt;
        $item['counted_by'] = $countedBy;
        $sess['items'][$itemIdx] = $item;
        if ($status === 'Ditemukan') {
            self::addLog($db, $user, 'Stock Opname', $kode,
                'Hitung ' . $sid . ': ' . $status . ' (Lokasi: ' . $lokasi . ', PIC: ' . $pic
                . ', Kondisi: ' . $kondisi . ')');
        } else {
            self::addLog($db, $user, 'Stock Opname', $kode, 'Hitung ' . $sid . ': ' . $status);
        }
        self::saveSession($db, $sess, $seq);
    }

    /** Lokasi/PIC/kondisi berbeda dari nilai awal snapshot (akan ditulis balik ke aset). */
    public static function opnameItemChanged(array $i): bool
    {
        $awalLokasi  = array_key_exists('lokasi_awal', $i) ? $i['lokasi_awal'] : ($i['lokasi'] ?? null);
        $awalPic     = array_key_exists('pic_awal', $i) ? $i['pic_awal'] : ($i['pic'] ?? null);
        $awalKondisi = array_key_exists('kondisi_awal', $i) ? $i['kondisi_awal'] : ($i['kondisi'] ?? null);

        return ($i['lokasi'] ?? null) !== $awalLokasi
            || ($i['pic'] ?? null) !== $awalPic
            || ($i['kondisi'] ?? null) !== $awalKondisi;
    }

    public static function opnameSummary(array $items): array
    {
        $total = count($items);
        $dicek = 0;
        $ditemukan = 0;
        $tidakDitemukan = 0;
        $berubah = 0;
        foreach ($items as $i) {
            if (! empty($i['dicek'])) {
                $dicek++;
            }
            if (($i['status'] ?? null) === 'Ditemukan') {
                $ditemukan++;
            } elseif (($i['status'] ?? null) === 'Tidak Ditemukan') {
                $tidakDitemukan++;
            }
            if (self::opnameItemChanged($i)) {
                $berubah++;
            }
        }
        return ['total' => $total, 'dicek' => $dicek, 'belum' => $total - $dicek,
            'ditemukan' => $ditemukan, 'tidak_ditemukan' => $tidakDitemukan,
            'berubah' => $berubah];
    }

    /**
     * Tulis hasil opname ke tabel inventaris (dipanggil saat sesi diakhiri).
     * Ditemukan  -> Lokasi/PIC/kondisi mengikuti hasil pemeriksaan.
     * Tidak Ditemukan (termasuk yang ditandai otomatis) -> kondisi aset = Tidak Ditemukan.
     * Mengembalikan jumlah aset yang benar-benar berubah.
     */
    public static function writeBackOpname(BaseConnection $db, array $user, string $sid, array $items): int
    {
        $updated = 0;
        foreach ($items as $i) {
            try {
                $asset = self::loadAsset($db, (string) ($i['kode'] ?? ''));
            } catch (ApiException $e) {
                continue;                       // aset dihapus di tengah sesi -> lewati
            }
            $changed = [];
            if (($i['status'] ?? null) === 'Ditemukan') {
                foreach (['lokasi', 'pic', 'kondisi'] as $f) {
                    $v = $i[$f] ?? null;
                    if ($v !== null && $v !== '' && ($asset[$f] ?? null) !== $v) {
                        $asset[$f] = $v;
                        $changed[] = $f;
                    }
                }
                $ket = 'Opname ' . $sid . ': ' . implode(', ', $changed) . ' diperbarui';
            } else {
                if (($asset['kondisi'] ?? null) !== 'Tidak Ditemukan') {
                    $asset['kondisi'] = 'Tidak Ditemukan';
                    $changed[] = 'kondisi';
                }
                $ket = 'Opname ' . $sid . ': kondisi disetel Tidak Ditemukan';
            }
            if ($changed === []) {
                continue;
            }
            self::appendRiwayat($asset, 'Stock Opname', $ket, $user);
            self::saveAsset($db, $asset);
            $updated++;
        }
        return $updated;
    }

    public static function apiEndOpname(BaseConnection $db, array $user, string $sid, array $body): void
    {
        [$sess, $seq] = self::fetchSession($db, $sid);
        self::assertSessionInScope($user, $sess);
        if (($sess['status'] ?? null) !== 'Berjalan') {
            throw new ApiException(409, 'Hanya sesi berjalan yang bisa diakhiri.', 'SESSION_STATE');
        }
        $items = $sess['items'] ?? [];
        $ended = self::stamp();
        foreach ($items as $k => $i) {
            if (empty($i['dicek'])) {              // item yang belum dicek dianggap tidak ada
                $items[$k]['status'] = 'Tidak Ditemukan';
                $items[$k]['kondisi'] = 'Tidak Ditemukan';
                $items[$k]['dicek'] = true;
                $items[$k]['counted_at'] = $ended;
                $items[$k]['counted_by'] = 'Otomatis';
            }
        }
        $summary = self::opnameSummary($items);   // ringkasan dihitung SETELAH semua item final
        $updated = self::writeBackOpname($db, $user, $sid, $items);
        $sess['items'] = $items;
        $sess['status'] = 'Selesai';
        $sess['ended_at'] = $ended;
        $sess['summary'] = $summary;
        self::saveSession($db, $sess, $seq);
        self::addLog($db, $user, 'Stock Opname', '-',
            'Akhir sesi opname ' . $sid . ': ' . $summary['dicek'] . '/' . $summary['total'] . ' item dicek, '
            . $summary['ditemukan'] . ' ditemukan, ' . $summary['tidak_ditemukan'] . ' tidak ditemukan, '
            . $summary['berubah'] . ' data berubah, ' . $updated . ' aset diperbarui');
    }

    public static function apiCancelOpname(BaseConnection $db, array $user, string $sid, array $body): void
    {
        [$sess, $seq] = self::fetchSession($db, $sid);
        self::assertSessionInScope($user, $sess);
        if (in_array($sess['status'] ?? null, ['Selesai', 'Dibatalkan'], true)) {
            throw new ApiException(409, 'Sesi yang sudah diakhiri tidak bisa dibatalkan.', 'SESSION_STATE');
        }
        $sess['status'] = 'Dibatalkan';
        $sess['ended_at'] = self::stamp();
        self::saveSession($db, $sess, $seq);
        self::addLog($db, $user, 'Stock Opname', '-',
            'Batalkan sesi opname ' . $sid . ' (' . ($sess['nama'] ?? '') . ')');
    }

    // ---------------------------------------------------------- mutasi
    public static function apiCreateMutation(BaseConnection $db, array $user, array $body): array
    {
        $kode = strtoupper(trim(self::strv(self::orv($body['kode'] ?? null, ''))));
        $jenis = trim(self::strv(self::orv($body['jenis'] ?? null, 'Mutasi')));
        if ($jenis === '') {
            $jenis = 'Mutasi';
        }
        if (! in_array($jenis, ['Mutasi', 'Perbaikan'], true)) {
            throw new ApiException(400, 'Jenis transaksi tidak valid.');
        }
        $ke = trim(self::strv(self::orv($body['ke'] ?? null, '')));
        $alasan = self::strv(self::orv($body['alasan'] ?? null, '-'));
        if ($alasan === '') {
            $alasan = '-';
        }
        $divisi = trim(self::strv(self::orv($body['divisi'] ?? null, '')));
        $pic = trim(self::strv(self::orv($body['pic'] ?? null, '')));
        $cabangBaru = trim(self::strv(self::orv($body['cabang'] ?? null, '')));
        if ($jenis === 'Perbaikan') {
            $ke = 'Main Dealer';    // lokasi tujuan perbaikan selalu Main Dealer
            $cabangBaru = '';       // perbaikan tidak pernah mengubah cabang
        } elseif ($ke === '') {
            throw new ApiException(400, 'Lokasi tujuan wajib diisi.');
        }
        if ($cabangBaru !== '' && ! in_array($cabangBaru, self::cabangNames($db), true)) {
            throw new ApiException(400, 'Cabang tujuan tidak valid.');
        }
        $asset = self::loadAsset($db, $kode);
        self::assertAssetInScope($user, $asset);
        if ($cabangBaru === strval(self::orv($asset['cabang'] ?? null, ''))) {
            $cabangBaru = '';
        }
        $mid = self::nextMutId($db);
        $rec = [
            'id' => $mid, 'kode' => $kode, 'tgl' => self::today(),
            'dari' => $asset['lokasi'] ?? null, 'ke' => $ke, 'alasan' => $alasan,
            'status' => 'Pending', 'jenis' => $jenis,
            'user' => self::orv($user['nama'] ?? null, $user['username'] ?? null),
            'cabang' => $asset['cabang'] ?? null,
            'divisi' => $divisi, 'pic' => $pic, 'cabang_baru' => $cabangBaru,
        ];
        if ($jenis === 'Perbaikan') {
            $rec['status_awal'] = ($asset['status'] ?? '') !== '' ? $asset['status'] : 'Aktif';
        }
        $seq = self::nextSeq($db, 'mutations');
        $db->query('INSERT INTO mutations(id, seq, data) VALUES (?,?,?)', [$mid, $seq, self::enc($rec)]);
        if ($jenis === 'Perbaikan') {
            self::appendRiwayat($asset, 'Perbaikan',
                'Pengajuan perbaikan ' . ($asset['lokasi'] ?? '') . ' → ' . $ke . ' (' . $mid . ')', $user);
        } else {
            self::appendRiwayat($asset, 'Mutasi',
                'Pengajuan ' . ($asset['lokasi'] ?? '') . ' → ' . $ke . ' (' . $mid . ')', $user);
        }
        self::saveAsset($db, $asset);
        self::addLog($db, $user, $jenis, $kode,
            'Mengajukan ' . ($jenis === 'Perbaikan' ? 'perbaikan' : 'mutasi') . ' ' . $mid . ' ke ' . $ke);
        return ['id' => $mid];
    }

    public static function apiDecideMutation(BaseConnection $db, array $user, string $mid, array $body): void
    {
        $result = self::orv($body['status'] ?? null, null);
        if (! in_array($result, ['Disetujui', 'Ditolak'], true)) {
            throw new ApiException(400, 'Status keputusan tidak valid.');
        }
        $row = $db->query('SELECT data FROM mutations WHERE id=?', [$mid])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, "Mutasi {$mid} tidak ditemukan.");
        }
        $rec = json_decode($row['data'], true);
        if (($rec['status'] ?? null) !== 'Pending') {
            throw new ApiException(400, "Mutasi {$mid} sudah diproses ({$rec['status']}).");
        }
        $jenis = ($rec['jenis'] ?? '') !== '' ? $rec['jenis'] : 'Mutasi';
        $rec['status'] = $result;
        $db->query('UPDATE mutations SET data=? WHERE id=?', [self::enc($rec), $mid]);
        try {
            $asset = self::loadAsset($db, $rec['kode']);
            if ($result === 'Disetujui') {
                $asset['lokasi'] = $rec['ke'];
                $extra = [];
                if ($jenis === 'Perbaikan') {
                    // perbaikan: hanya lokasi → Main Dealer & status → Dalam Perbaikan;
                    // cabang, divisi, dan PIC tidak pernah berubah
                    $asset['status'] = 'Dalam Perbaikan';
                    $keterangan = 'Masuk perbaikan: ' . $rec['dari'] . ' → ' . $rec['ke']
                        . ' (' . $mid . ', disetujui)';
                } else {
                    if (strval(self::orv($rec['divisi'] ?? null, '')) !== '') {
                        $extra[] = 'divisi ' . ($asset['divisi'] ?? '-') . ' → ' . $rec['divisi'];
                        $asset['divisi'] = $rec['divisi'];
                    }
                    if (strval(self::orv($rec['pic'] ?? null, '')) !== '') {
                        $extra[] = 'PIC ' . ($asset['pic'] ?? '-') . ' → ' . $rec['pic'];
                        $asset['pic'] = $rec['pic'];
                    }
                    $cabangBaru = strval(self::orv($rec['cabang_baru'] ?? null, ''));
                    if ($cabangBaru !== '' && $cabangBaru !== ($asset['cabang'] ?? null)) {
                        // pindah cabang melalui field Cabang terpisah pada form mutasi
                        $lama = $asset['cabang'] ?? '-';
                        $asset['cabang'] = $cabangBaru;
                        $keterangan = 'Antar cabang: ' . $lama . ' → ' . $cabangBaru
                            . ' (' . $rec['dari'] . ' → ' . $rec['ke'] . ', disetujui)';
                    } elseif (in_array($rec['ke'], self::cabangNames($db), true) && ($asset['cabang'] ?? null) !== $rec['ke']) {
                        // mutasi antar cabang: aset berpindah ke cabang tujuan
                        $lama = $asset['cabang'] ?? '-';
                        $asset['cabang'] = $rec['ke'];
                        $keterangan = 'Antar cabang: ' . $lama . ' → ' . $rec['ke']
                            . ' (' . $rec['dari'] . ' → ' . $rec['ke'] . ', disetujui)';
                    } else {
                        $keterangan = 'Dari ' . $rec['dari'] . ' ke ' . $rec['ke'] . ' (disetujui)';
                    }
                    if ($extra !== []) {
                        $keterangan .= '; ' . implode(', ', $extra);
                    }
                }
            } else {
                $keterangan = 'Pengajuan ke ' . $rec['ke'] . ' — ditolak';
            }
            self::appendRiwayat($asset, $jenis === 'Perbaikan' ? 'Perbaikan' : 'Mutasi',
                $keterangan, $user);
            self::saveAsset($db, $asset);
        } catch (ApiException $e) {
            // aset sudah hilang: keputusan tetap dicatat
        }
        self::addLog($db, $user, $jenis, $rec['kode'],
            $mid . ' ' . strtolower($result) . ' (' . $rec['dari'] . ' → ' . $rec['ke'] . ')');
    }

    /**
     * Aksi Selesai Perbaikan: aset kembali ke lokasi awal, status kembali normal,
     * kondisi dipilih, dan permintaan ditutup sebagai Selesai.
     */
    public static function apiSelesaiMutation(BaseConnection $db, array $user, string $mid, array $body): void
    {
        $kondisi = trim(self::strv(self::orv($body['kondisi'] ?? null, '')));
        if (! in_array($kondisi, self::KONDISI, true)) {
            throw new ApiException(400, 'Kondisi tidak valid.');
        }
        $row = $db->query('SELECT data FROM mutations WHERE id=?', [$mid])->getFirstRow('array');
        if (! $row) {
            throw new ApiException(404, "Mutasi {$mid} tidak ditemukan.");
        }
        $rec = json_decode($row['data'], true);
        if ((($rec['jenis'] ?? '') !== '' ? $rec['jenis'] : 'Mutasi') !== 'Perbaikan') {
            throw new ApiException(409, "{$mid} bukan permintaan perbaikan.");
        }
        $st = $rec['status'] ?? null;
        if ($st === 'Selesai') {
            throw new ApiException(409, "Perbaikan {$mid} sudah diselesaikan.");
        }
        if ($st === 'Pending') {
            throw new ApiException(409, "Perbaikan {$mid} belum disetujui.");
        }
        if ($st === 'Ditolak') {
            throw new ApiException(409, "Perbaikan {$mid} sudah ditolak.");
        }
        $rec['status'] = 'Selesai';
        $rec['kondisi'] = $kondisi;
        $db->query('UPDATE mutations SET data=? WHERE id=?', [self::enc($rec), $mid]);
        $kembali = '-';
        try {
            $asset = self::loadAsset($db, $rec['kode']);
            $kembali = strval(self::orv($rec['dari'] ?? null, '')) !== ''
                ? strval($rec['dari']) : strval($asset['lokasi'] ?? '-');
            if ($kembali === '') {
                $kembali = '-';
            }
            $asset['lokasi'] = $kembali;
            $asset['status'] = ($rec['status_awal'] ?? '') !== '' ? $rec['status_awal'] : 'Aktif';
            $asset['kondisi'] = $kondisi;
            self::appendRiwayat($asset, 'Perbaikan',
                'Perbaikan selesai: ' . $rec['ke'] . ' → ' . $kembali . ' (kondisi ' . $kondisi . ')', $user);
            self::saveAsset($db, $asset);
        } catch (ApiException $e) {
            // aset sudah hilang: penutupan perbaikan tetap dicatat
        }
        self::addLog($db, $user, 'Perbaikan', $rec['kode'],
            $mid . ' selesai perbaikan — kondisi ' . $kondisi . ', kembali ke ' . $kembali);
    }

    // ---------------------------------------------------------- misc
    public static function apiUpload(array $body): array
    {
        $data = self::strv($body['data'] ?? null);
        $kind = $body['kind'] ?? 'dokumen';
        $name = basename(self::strv(self::orv($body['name'] ?? null, 'file')));
        if (! preg_match('#^data:(image/(?:png|jpe?g|webp|gif)|application/pdf);base64,(.+)$#s', $data, $m)) {
            throw new ApiException(400, 'Format file tidak didukung (hanya JPG/PNG/WEBP/GIF/PDF).');
        }
        $mime = $m[1];
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $m[2]);
        if (strlen($b64) %4 ===1) {
            throw new ApiException(400, 'Data file tidak valid (base64 rusak).');
        }
        $raw = base64_decode($b64);
        if ($raw === false) {
            throw new ApiException(400, 'Data file tidak valid (base64 rusak).');
        }
        $limit = $kind === 'foto' ? self::MAX_PHOTO : self::MAX_DOC;
        if (strlen($raw) > $limit) {
            throw new ApiException(400, 'Ukuran file melebihi batas ' . intdiv($limit,1048576) . 'MB.');
        }
        $extMap = [
            'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/jpg' => '.jpg',
            'image/webp' => '.webp', 'image/gif' => '.gif', 'application/pdf' => '.pdf',
        ];
        $ext = $extMap[$mime] ?? '.bin';
        $dir = self::uploadDir();
        if (! is_dir($dir)) {
            @mkdir($dir,0775, true);
        }
        $fname = bin2hex(random_bytes(8)) . substr((string) (int) floor(microtime(true) *1000), -6) . $ext;
        file_put_contents($dir . DIRECTORY_SEPARATOR . $fname, $raw);
        return ['ok' => true, 'url' => '/uploads/' . $fname, 'nama' => $name, 'size' => strlen($raw)];
    }

    public static function uploadDir(): string
    {
        $env = getenv('TRIO_UPLOAD_DIR');
        if ($env) {
            return $env;
        }
        // produksi: sibling docroot (di luar public_html) — disajikan oleh controller Files
        return dirname(FCPATH) . DIRECTORY_SEPARATOR . 'uploads';
    }

    public static function apiSettings(BaseConnection $db, array $user, array $body): void
    {
        $current = self::getSettings($db);
        foreach (['perusahaan', 'tagline', 'cabang'] as $k) {
            if (array_key_exists($k, $body)) {
                $current[$k] = self::strv($body[$k]);
            }
        }
        if (array_key_exists('cabang', $body)
            && ! in_array($current['cabang'], self::cabangNames($db), true)) {
            throw new ApiException(400, 'Cabang default tidak valid.');
        }
        self::putSettings($db, $current);
        self::addLog($db, $user, 'Pengaturan', '-', 'Memperbarui pengaturan aplikasi');
    }

    public static function apiList(BaseConnection $db, array $user, string $key, array $body, bool $delete = false): void
    {
        if (! in_array($key, self::LIST_KEYS, true)) {
            throw new ApiException(404, "Jenis master data '{$key}' tidak dikenal.");
        }
        $value = trim(self::strv(self::orv($body['value'] ?? null, '')));
        if ($value === '') {
            throw new ApiException(400, 'Nilai tidak boleh kosong.');
        }
        if ($delete) {
            foreach ($db->query('SELECT data FROM assets')->getResult('array') as $r) {
                $a = json_decode($r['data'], true) ?: [];
                if (($a[$key] ?? null) === $value) {
                    throw new ApiException(400, 'Data masih digunakan inventaris dan tidak bisa dihapus.');
                }
            }
            $db->query('DELETE FROM lists WHERE key=? AND value=?', [$key, $value]);
            self::addLog($db, $user, 'Master Data', '-', 'Menghapus ' . $key . ': ' . $value);
        } else {
            $exists = $db->query('SELECT 1 x FROM lists WHERE key=? AND value=?', [$key, $value])->getFirstRow('array');
            if ($exists) {
                throw new ApiException(400, 'Data sudah ada.');
            }
            $db->query('INSERT INTO lists(key, value) VALUES (?,?)', [$key, $value]);
            self::addLog($db, $user, 'Master Data', '-', 'Menambah ' . $key . ': ' . $value);
        }
    }

    public static function apiLogs(BaseConnection $db, array $user, array $body): void
    {
        self::addLog($db, $user,
            self::strv(self::orv($body['aksi'] ?? null, 'Aktivitas')),
            self::strv(self::orv($body['kode'] ?? null, '-')),
            self::strv(self::orv($body['keterangan'] ?? null, '-')));
    }

    public static function apiImport(BaseConnection $db, array $user, array $body): array
    {
        $assets = $body['assets'] ?? null;
        if (! is_array($assets) || ! array_is_list($assets)) {
            throw new ApiException(400, 'Payload import tidak valid.');
        }
        $imported = 0;
        $skipped = 0;
        $seq = self::nextSeq($db, 'assets');
        foreach ($assets as $a) {
            if (! is_array($a) || empty($a['kode'])) {
                $skipped++;
                continue;
            }
            $exists = $db->query('SELECT 1 x FROM assets WHERE kode=?', [$a['kode']])->getFirstRow('array');
            if ($exists) {
                $skipped++;
                continue;
            }
            $a += ['cabang' => self::firstCabang($db)];
            $a += ['riwayat' => []];
            $a += ['opname' => []];
            $a += ['perbaikan' => []];
            $a += ['dokumen' => []];
            $a += ['foto' => ''];
            self::saveAsset($db, $a, $seq);
            $seq--;
            $imported++;
        }
        if ($imported >0) {
            self::addLog($db, $user, 'Import', '-', 'Import ' . $imported . ' aset lama dari browser');
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ---------------------------------------------------------- peta izin (RBAC)
    public static function permFor(string $method, string $path): ?string
    {
        if (str_starts_with($path, '/api/auth/')) {
            return null;
        }
        if (str_starts_with($path, '/api/users')) {
            return 'users.manage';
        }
        if ($path === '/api/admin/reset') {
            return 'settings.write';
        }
        if ($path === '/api/admin/import') {
            return 'users.manage';
        }
        if (in_array($path, ['/api/db', '/api/upload', '/api/logs'], true)) {
            return null;
        }
        if (str_starts_with($path, '/api/lists')) {
            return $method === 'GET' ? null : 'master.write';
        }
        if (str_starts_with($path, '/api/roles')) {
            return $method === 'GET' ? null : 'roles.manage';
        }
        if (str_starts_with($path, '/api/cabangs') || str_starts_with($path, '/api/pics')) {
            return $method === 'GET' ? null : 'master.write';
        }
        if ($path === '/api/settings') {
            return $method === 'GET' ? null : 'settings.write';
        }
        if (str_starts_with($path, '/api/mutations')) {
            return $method === 'PUT' ? 'mutation.approve' : 'mutation.create';
        }
        if (str_starts_with($path, '/api/opname-sessions')) {
            // data sesi dibaca lewat /api/db; semua endpoint tulis butuh opname.write
            return 'opname.write';
        }
        if (str_starts_with($path, '/api/assets')) {
            if (str_contains($path, '/docs')) {
                return in_array($method, ['POST', 'DELETE'], true) ? 'asset.update' : 'asset.view';
            }
            return $method === 'POST' ? 'asset.create' : 'asset.update';
        }
        return null;
    }

    /** Endpoint yang boleh diakses tanpa login. */
    public static function isPublicAuth(string $method, string $path): bool
    {
        return ($path === '/api/auth/login' && $method === 'POST')
            || ($path === '/api/auth/logout' && $method === 'POST')
            || ($path === '/api/auth/me' && $method === 'GET');
    }
}
