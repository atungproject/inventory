<?php

namespace App\Libraries;

use App\Exceptions\ApiException;
use CodeIgniter\Database\BaseConnection;

/**
 * TRIO Inventory Control — logika bisnis (port setia dari server.py).
 *
 * Kontrak API, pesan error, kode status, dan format JSON dibuat sama persis
 * dengan versi Python supaya frontend & suite tes145 check berlaku utuh.
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
    public const ROLES   = ['admin', 'audit', 'cabang'];
    public const ROLE_LABEL = [
        'admin'  => 'Administrator',
        'audit'  => 'Auditor',
        'cabang' => 'Manajemen Cabang',
    ];
    public const LIST_KEYS = ['kategori', 'divisi', 'lokasi', 'sumberDana', 'pic'];
    public const CABANG_LIST = ['TM Buntok', 'TM Baru', 'TM Kuala Kapuas'];
    public const CABANG_PREFIX = [
        'TM Buntok'        => 'BTK',
        'TM Baru'          => 'BRU',
        'TM Kuala Kapuas'  => 'KKP',
    ];
    public const ASSET_FIELDS = ['nama', 'kategori', 'merek', 'serial', 'tahun', 'nilai', 'tgl',
        'sumberDana', 'cabang', 'lokasi', 'divisi', 'pic', 'kondisi', 'status', 'catatan', 'foto', 'dokumen'];
    public const TRACKED = ['nama', 'kondisi', 'lokasi', 'pic', 'status', 'cabang'];

    // Modul Stock Opname berbasis sesi: Draft -> Berjalan -> Selesai, atau Dibatalkan
    public const OPNAME_STATUS = ['Draft', 'Berjalan', 'Selesai', 'Dibatalkan'];
    public const OPNAME_ITEM_STATUS = ['Belum', 'Ditemukan', 'Tidak Ditemukan'];

    public const ALL_PERMS = [
        'asset.view', 'asset.create', 'asset.update',
        'opname.write', 'report.view',
        'mutation.view', 'mutation.create', 'mutation.approve',
        'master.view', 'master.write',
        'audit.view', 'settings.view', 'settings.write',
        'users.manage',
    ];
    public const ROLE_PERMS = [
        'admin' => [
            'asset.view', 'asset.create', 'asset.update',
            'opname.write', 'report.view',
            'mutation.view', 'mutation.create', 'mutation.approve',
            'master.view', 'master.write',
            'audit.view', 'settings.view', 'settings.write',
            'users.manage',
        ],
        'audit' => [
            'asset.view', 'asset.create', 'asset.update',
            'opname.write', 'report.view',
            'mutation.view', 'mutation.create', 'mutation.approve',
            'master.view', 'master.write',
            'audit.view', 'settings.view', 'settings.write',
        ],
        'cabang' => ['asset.view', 'asset.create', 'mutation.view', 'mutation.create'],
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
        'sumberDana' => ['Operasional', 'Inventaris', 'Bantuan'],
        'pic' => ['Adi Nor Iqbal', 'Budi Santoso', 'Rina Marlina', 'Dedi Saputra', 'Yuni Astari',
            'Andi Wijaya', 'Hasan Basri', 'Sari Rahmawati'],
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
SQL;

    // [kode, nama, kategori, merek, serial, tahun, nilai, tgl, sumberDana, lokasi, divisi, pic, kondisi, status]
    public const RAW_ASSETS = [
        ['INV-BTK-0001', 'Laptop Lenovo ThinkPad E14', 'Elektronik', 'Lenovo ThinkPad E14', 'PF3JHBK3', 2025, 12500000, '2025-01-10', 'Operasional', 'Ruang Finance', 'Finance', 'Adi Nor Iqbal', 'Baik', 'Aktif'],
        ['INV-BTK-0002', 'Printer Epson L3250', 'Elektronik', 'Epson L3250', 'X9DK2211', 2024, 3400000, '2024-03-05', 'Operasional', 'R. OPR', 'OPR', 'Budi Santoso', 'Baik', 'Aktif'],
        ['INV-BTK-0003', 'Meja Kerja', 'Mebel', 'Meja Kerja Minimalis', '-', 2023, 2100000, '2023-07-18', 'Inventaris', 'R. H1', 'H1', 'Rina Marlina', 'Baik', 'Aktif'],
        ['INV-BTK-0004', 'Kursi Staff', 'Mebel', 'Kursi Kantor Ergonomis', '-', 2023, 950000, '2023-07-18', 'Inventaris', 'R. H2', 'H2', 'Dedi Saputra', 'Rusak Ringan', 'Aktif'],
        ['INV-BTK-0005', 'AC 1 PK', 'Elektronik', 'Panasonic CS-YN9WKJ', 'PNK992134', 2022, 5800000, '2022-02-11', 'Operasional', 'R. Servis', 'H2', 'Dedi Saputra', 'Baik', 'Aktif'],
        ['INV-BTK-0006', 'Handphone Samsung', 'Elektronik', 'Samsung Galaxy A55 5G', 'RF8N90K21', 2025, 4300000, '2025-05-20', 'Operasional', 'R. Sparepart', 'H3', 'Yuni Astari', 'Baik', 'Aktif'],
        ['INV-BTK-0007', 'Lemari Arsip', 'Mebel', 'Lemari Besi2 Pintu', '-', 2021, 2750000, '2021-09-30', 'Inventaris', 'R. Finance', 'Finance', 'Adi Nor Iqbal', 'Rusak Berat', 'Aktif'],
        ['INV-BTK-0008', 'CCTV DVR', 'Elektronik', 'Hikvision DS-7208', 'HKV771209', 2024, 4900000, '2024-01-22', 'Operasional', 'R. Server', 'IT', 'Andi Wijaya', 'Baik', 'Aktif'],
        ['INV-BTK-0009', 'Timbangan Digital', 'Alat Kerja', 'Digital Scale300kg', 'DSC300112', 2023, 3200000, '2023-11-08', 'Inventaris', 'R. Sparepart', 'H3', 'Yuni Astari', 'Baik', 'Aktif'],
        ['INV-BTK-0010', 'Tangga Aluminum', 'Alat Kerja', 'Hydro Step3M', '-', 2022, 1450000, '2022-06-14', 'Inventaris', 'Gudang', 'H3', 'Hasan Basri', 'Tidak Ditemukan', 'Aktif'],
        ['INV-BTK-0011', 'Komputer PC Rakitan', 'Elektronik', 'Intel i5 /16GB / SSD512', 'PC23011', 2024, 9200000, '2024-04-03', 'Operasional', 'R. Server', 'IT', 'Andi Wijaya', 'Baik', 'Aktif'],
        ['INV-BTK-0012', 'Proyektor Epson', 'Elektronik', 'Epson EB-X06', 'EPS662091', 2023, 6100000, '2023-02-27', 'Operasional', 'R. Meeting', 'H1', 'Rina Marlina', 'Baik', 'Aktif'],
        ['INV-BTK-0013', 'Sofa Ruang Tamu', 'Mebel', 'Sofa3 Seater', '-', 2022, 4750000, '2022-08-19', 'Inventaris', 'R. Tamu', 'Umum', 'Sari Rahmawati', 'Baik', 'Aktif'],
        ['INV-BTK-0014', 'Motor Dinas Honda Beat', 'Kendaraan', 'Honda Beat CBS2023', 'E3412K77', 2023, 17200000, '2023-05-12', 'Operasional', 'Parkiran', 'Umum', 'Hasan Basri', 'Baik', 'Aktif'],
        ['INV-BTK-0015', 'Genset5 KVA', 'Peralatan', 'Honda EU50is', 'GNT50021', 2021, 32000000, '2021-04-25', 'Inventaris', 'Gudang', 'OPR', 'Budi Santoso', 'Rusak Ringan', 'Aktif'],
        ['INV-BTK-0016', 'Mesin Press Hidrolik', 'Alat Kerja', 'Hydraulic Press10 Ton', 'HYP10092', 2020, 24500000, '2020-10-05', 'Inventaris', 'R. Servis', 'H2', 'Dedi Saputra', 'Rusak Berat', 'Aktif'],
        ['INV-BTK-0017', 'Tablet Samsung Tab A9', 'Elektronik', 'Samsung Galaxy Tab A9', 'TB992014', 2025, 3650000, '2025-08-14', 'Operasional', 'Ruang Finance', 'Finance', 'Adi Nor Iqbal', 'Baik', 'Aktif'],
        ['INV-BTK-0018', 'Kipas Angin Standing', 'Elektronik', 'Miyako FS-160', '-', 2019, 450000, '2019-03-09', 'Inventaris', 'R. H1', 'H1', 'Rina Marlina', 'Tidak Layak', 'Nonaktif'],
        ['INV-BTK-0019', 'Rak Gudang Besi', 'Mebel', 'Rak Heavy Duty4 Tingkat', '-', 2022, 6800000, '2022-12-02', 'Inventaris', 'Gudang', 'Umum', 'Hasan Basri', 'Baik', 'Aktif'],
        ['INV-BTK-0020', 'Barcode Scanner Zebra', 'Elektronik', 'Zebra DS2208', 'ZB220811', 2024, 1750000, '2024-07-21', 'Operasional', 'R. Sparepart', 'H3', 'Yuni Astari', 'Tidak Ditemukan', 'Aktif'],
        ['INV-BTK-0021', 'Brankas Kecil', 'Mebel', 'Brankas Sollingen30cm', 'BRK30117', 2023, 5400000, '2023-01-16', 'Inventaris', 'Ruang Finance', 'Finance', 'Adi Nor Iqbal', 'Baik', 'Aktif'],
        ['INV-BTK-0022', 'Telepon Kantor', 'Elektronik', 'Panasonic KX-T7730', '-', 2021, 890000, '2021-06-11', 'Operasional', 'R. H1', 'H1', 'Rina Marlina', 'Rusak Ringan', 'Aktif'],
        ['INV-BTK-0023', 'Sepeda Motor Pandu', 'Kendaraan', 'Yamaha Fino125', 'YMN77203', 2024, 19800000, '2024-09-09', 'Operasional', 'Parkiran', 'Umum', 'Hasan Basri', 'Baik', 'Aktif'],
        ['INV-BTK-0024', 'Lemari Pendingin', 'Peralatan', 'Polytron PRM-389', 'PLY38922', 2022, 4200000, '2022-05-30', 'Operasional', 'R. Servis', 'H2', 'Hasan Basri', 'Baik', 'Aktif'],
    ];

    public const DEMO_MUTATIONS = [
        ['MUT-0001', 'INV-BTK-0003', '2026-09-05', 'R. H1', 'R. Meeting', 'Kebutuhan rapat penjualan', 'Pending', 'Rina Marlina'],
        ['MUT-0002', 'INV-BTK-0011', '2026-09-12', 'R. Server', 'Ruang Finance', 'Pendukung kerja finance', 'Pending', 'Andi Wijaya'],
        ['MUT-0003', 'INV-BTK-0013', '2026-09-15', 'R. Tamu', 'R. Meeting', 'Penataan ruang meeting', 'Pending', 'Sari Rahmawati'],
        ['MUT-0004', 'INV-BTK-0006', '2026-09-18', 'R. Sparepart', 'R. H1', 'Dukungan tim penjualan', 'Pending', 'Yuni Astari'],
        ['MUT-0005', 'INV-BTK-0017', '2026-09-20', 'R. Server', 'Ruang Finance', 'Penggunaan oleh staf finance', 'Pending', 'Adi Nor Iqbal'],
        ['MUT-0006', 'INV-BTK-0001', '2025-06-15', 'R. Server', 'Ruang Finance', 'Serah terima aset', 'Disetujui', 'Budi Santoso'],
        ['MUT-0007', 'INV-BTK-0012', '2026-06-02', 'R. Meeting', 'R. H1', 'Penempatan tim', 'Disetujui', 'Rina Marlina'],
        ['MUT-0008', 'INV-BTK-0015', '2026-07-11', 'R. Servis', 'Gudang', 'Perawatan berkala', 'Ditolak', 'Budi Santoso'],
    ];

    public const DEMO_LOGS = [
        ['2026-09-20 08:15', 'Adi Nor Iqbal', 'Login', '-', 'Masuk ke sistem'],
        ['2026-09-19 16:42', 'Budi Santoso', 'Mutasi', 'INV-BTK-0001', 'Menyetujui mutasi ke Ruang Finance'],
        ['2026-09-18 10:05', 'Yuni Astari', 'Mutasi', 'INV-BTK-0006', 'Mengajukan mutasi ke R. H1'],
        ['2026-08-20 09:00', 'Tim Audit', 'Stock Opname', '-', 'Pelaksanaan stock opname cabang TM Buntok'],
        ['2026-01-10 14:22', 'Admin', 'Penambahan', 'INV-BTK-0001', 'Inventaris baru ditambahkan'],
    ];

    /** @var array|null [token, baris user|null] hasil resolve sesi request berjalan */
    public static ?array $session = null;
    /** @var array|null baris user login (padanan dict(row) di server.py) */
    public static ?array $user = null;
    /** @var array body JSON request berjalan (sudah divalidasi filter) */
    public static array $body = [];
    private static bool $initialized = false;

    /**
     * Baca & validasi body JSON — persis _read_body() server.py:
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

    /** kebenaran gaya Python ( "0" itu True; "",0,False,None,False itu False ). */
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

    /** padanan `x or default` di Python. */
    public static function orv($v, $default)
    {
        return self::truthy($v) ? $v : $default;
    }

    /** padanan str(x) Python untuk skalar (None -> '' karena pemakaian selalu dijaga). */
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

    /** padanan int(x) Python untuk field nilai. */
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

    /** Ibarat init_db() di server.py — dijalankan sekali per request di filter. */
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
        $seeded = $db->query('SELECT v FROM meta WHERE k=?', ['seeded'])->getFirstRow('array');
        if (! $seeded) {
            self::seedDemo($db);
        }
        return $db;
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

    public static function publicUser(array $row): array
    {
        $role = $row['role'];
        return [
            'id'        => (int) $row['id'],
            'username'  => $row['username'],
            'nama'      => $row['nama'],
            'role'      => $role,
            'roleLabel' => self::ROLE_LABEL[$role] ?? $role,
            'cabang'    => $row['cabang'],
            'aktif'     => (bool) $row['aktif'],
            'perms'     => self::ROLE_PERMS[$role] ?? [],
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

    // ---------------------------------------------------------- seed demo
    public static function buildDemoAsset(array $r): array
    {
        [$kode, $nama, $kategori, $merek, $serial, $tahun, $nilai, $tgl, $sumberDana,
            $lokasi, $divisi, $pic, $kondisi, $status] = $r;
        $riwayat = [['tgl' => $tgl, 'aktivitas' => 'Penambahan', 'keterangan' => 'Inventaris baru', 'user' => 'Admin']];
        if ($kode === 'INV-BTK-0001') {
            $riwayat[] = ['tgl' => '2025-06-15', 'aktivitas' => 'Mutasi',
                'keterangan' => 'Dari R. Server ke Ruang Finance', 'user' => 'Budi'];
            $riwayat[] = ['tgl' => '2026-08-20', 'aktivitas' => 'Stock Opname',
                'keterangan' => 'Ditemukan, kondisi baik', 'user' => 'Tim Audit'];
        }
        if ($kondisi !== 'Baik') {
            $riwayat[] = ['tgl' => '2026-08-20', 'aktivitas' => 'Stock Opname',
                'keterangan' => 'Ditemukan, kondisi ' . strtolower($kondisi), 'user' => 'Tim Audit'];
        }
        usort($riwayat, fn ($a, $b) => strcmp($a['tgl'], $b['tgl']));
        $opname = ($kondisi === 'Baik')
            ? [['tgl' => '2026-08-20', 'status' => 'Ditemukan', 'lokasi' => $lokasi, 'kondisi' => $kondisi,
                'catatan' => '-', 'user' => 'Tim Audit']]
            : [];
        $perbaikan = [];
        if (str_starts_with($kondisi, 'Rusak')) {
            $perbaikan = [['tgl' => '2026-08-25',
                'keluhan' => $kondisi === 'Rusak Berat' ? 'Kerusakan berat, perlu penggantian' : 'Kerusakan ringan, perlu perbaikan',
                'status' => 'Dalam Antrian', 'vendor' => '-', 'user' => 'Teknisi']];
        }
        return [
            'kode' => $kode, 'nama' => $nama, 'kategori' => $kategori, 'merek' => $merek,
            'serial' => $serial, 'tahun' => $tahun, 'nilai' => $nilai, 'tgl' => $tgl,
            'sumberDana' => $sumberDana, 'cabang' => 'TM Buntok', 'lokasi' => $lokasi,
            'divisi' => $divisi, 'pic' => $pic, 'kondisi' => $kondisi, 'status' => $status,
            'catatan' => '-', 'foto' => '', 'riwayat' => $riwayat, 'opname' => $opname,
            'perbaikan' => $perbaikan,
            'dokumen' => [['nama' => 'Bukti Perolehan.pdf', 'url' => '']],
        ];
    }

    public static function seedDemo(BaseConnection $db): void
    {
        $db->query('DELETE FROM logs');
        $db->query('DELETE FROM mutations');
        $db->query('DELETE FROM assets');
        $db->query('DELETE FROM lists');
        self::putSettings($db, self::DEFAULT_SETTINGS);
        foreach (self::DEFAULT_LISTS as $key => $values) {
            foreach ($values as $v) {
                $db->query('INSERT OR IGNORE INTO lists(key, value) VALUES (?,?)', [$key, $v]);
            }
        }
        $seq = -1;
        foreach (self::RAW_ASSETS as $r) {
            $a = self::buildDemoAsset($r);
            $db->query('INSERT INTO assets(kode, seq, data) VALUES (?,?,?)', [$a['kode'], $seq, self::enc($a)]);
            $seq--;
        }
        $mseq = -1;
        foreach (self::DEMO_MUTATIONS as [$mid, $kode, $tgl, $dari, $ke, $alasan, $status, $user]) {
            $rec = ['id' => $mid, 'kode' => $kode, 'tgl' => $tgl, 'dari' => $dari, 'ke' => $ke,
                'alasan' => $alasan, 'status' => $status, 'user' => $user];
            $db->query('INSERT INTO mutations(id, seq, data) VALUES (?,?,?)', [$mid, $mseq, self::enc($rec)]);
            $mseq--;
        }
        foreach (self::DEMO_LOGS as [$s, $u, $aksi, $kode, $ket]) {
            $db->query('INSERT INTO logs(data) VALUES (?)', [self::enc([
                'stamp' => $s, 'user' => $u, 'aksi' => $aksi, 'kode' => $kode, 'keterangan' => $ket,
            ])]);
        }
        $db->query('INSERT OR REPLACE INTO meta(k, v) VALUES (?,?)', ['seeded', self::stamp()]);
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
            $lists[$k] = $v;
        }
        foreach ($db->query('SELECT key, value FROM lists ORDER BY rowid')->getResult('array') as $r) {
            if (isset($lists[$r['key']]) && ! in_array($r['value'], $lists[$r['key']], true)) {
                $lists[$r['key']][] = $r['value'];
            }
        }

        return [
            'settings'  => self::getSettings($db),
            'lists'     => $lists,
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

    public static function cabangPrefix($cabang): string
    {
        $c = trim(self::strv($cabang));
        if (isset(self::CABANG_PREFIX[$c])) {
            return self::CABANG_PREFIX[$c];
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
        $pfx = self::cabangPrefix($cabang);
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
            'Masuk sebagai ' . (self::ROLE_LABEL[$row['role']] ?? $row['role']));
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
        if (! in_array($role, self::ROLES, true)) {
            throw new ApiException(400, 'Role tidak valid.');
        }
        if ($role === 'cabang' && $cabang === '*') {
            throw new ApiException(400, 'User role Cabang harus memiliki cabang.');
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
            'Membuat user ' . $username . ' (' . (self::ROLE_LABEL[$role] ?? $role) . ')');
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
        if (! in_array($role, self::ROLES, true)) {
            throw new ApiException(400, 'Role tidak valid.');
        }
        if ($role === 'cabang' && $cabang === '*') {
            throw new ApiException(400, 'User role Cabang harus memiliki cabang.');
        }
        if ((int) $row['id'] === (int) $actor['id'] && ($role !== 'admin' || ! $aktif)) {
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
                self::orv(self::getSettings($db)['cabang'] ?? null, self::CABANG_LIST[0])));
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
                    $asset[$f] = in_array($f, ['merek', 'serial', 'sumberDana', 'divisi', 'pic'], true) ? '-' : '';
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

    /** Qty fisik harus bilangan bulat >= 0 (str/int); selain itu -> null (tolak). */
    public static function parseQty($raw): ?int
    {
        if (is_bool($raw)) {
            return null;
        }
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : null;
        }
        if (is_string($raw) && trim($raw) !== '') {
            if (! preg_match('/^[+-]?\d+$/', trim($raw))) {
                return null;
            }
            $v = (int) trim($raw);
            return $v >= 0 ? $v : null;
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
        // FR-5: snapshot seluruh inventaris cabang pada saat sesi dibuat
        $items = [];
        foreach ($db->query('SELECT data FROM assets ORDER BY seq ASC')->getResult('array') as $r) {
            $a = json_decode($r['data'], true);
            if (($a['cabang'] ?? null) !== $cabang) {
                continue;
            }
            $items[] = [
                'kode' => $a['kode'], 'sku' => $a['kode'], 'barcode' => $a['kode'],
                'nama' => $a['nama'] ?? '-', 'kategori' => $a['kategori'] ?? '-',
                'satuan' => 'unit', 'qty_sistem' => 1, 'qty_fisik' => null,
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
        $qtyRaw    = $body['qty_fisik'] ?? null;
        if ($newStatus !== null && ! in_array($newStatus, self::OPNAME_ITEM_STATUS, true)) {
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
        $oldStatus = $item['status'] ?? null;
        $oldQty    = $item['qty_fisik'] ?? null;

        if ($newStatus === 'Belum') {
            $status = 'Belum';
            $qty = null;
            $dicek = false;
            $countedAt = null;
            $countedBy = null;
        } else {
            $hasQty = $qtyRaw !== null && $qtyRaw !== '';
            if ($hasQty) {
                $qty = self::parseQty($qtyRaw);
                if ($qty === null) {
                    throw new ApiException(400, 'Qty fisik tidak valid.');
                }
                $status = $qty > 0 ? 'Ditemukan' : 'Tidak Ditemukan';
            } else {
                $status = $newStatus ?? 'Ditemukan';
                $qty = $status === 'Ditemukan' ? (int) ($item['qty_sistem'] ?? 1) : 0;
            }
            $dicek = true;
            $countedAt = self::stamp();
            $countedBy = self::orv($user['nama'] ?? null, $user['username'] ?? null);
        }

        if ($status === $oldStatus && $qty === $oldQty) {
            return; // tanpa perubahan: tidak ada log
        }

        $item['status']     = $status;
        $item['qty_fisik']  = $qty;
        $item['dicek']      = $dicek;
        $item['counted_at'] = $countedAt;
        $item['counted_by'] = $countedBy;
        $sess['items'][$itemIdx] = $item;
        self::addLog($db, $user, 'Stock Opname', $kode,
            'Hitung ' . $sid . ': qty sistem ' . (int) ($item['qty_sistem'] ?? 1)
            . ' -> qty fisik ' . ($qty ?? 0) . ' (' . $status . ')');
        self::saveSession($db, $sess, $seq);
    }

    public static function opnameSummary(array $items): array
    {
        $total = count($items);
        $dicek = 0;
        $cocok = 0;
        $selisih = 0;
        $qtySistem = 0;
        $qtyFisik = 0;
        foreach ($items as $i) {
            $sistem = (int) ($i['qty_sistem'] ?? 1);
            $qtySistem += $sistem;
            if (! empty($i['dicek'])) {
                $dicek++;
                $fisik = (int) ($i['qty_fisik'] ?? 0);
                $qtyFisik += $fisik;
                if ($fisik === $sistem) {
                    $cocok++;
                } else {
                    $selisih++;
                }
            }
        }
        return ['total' => $total, 'dicek' => $dicek, 'belum' => $total - $dicek,
            'cocok' => $cocok, 'selisih' => $selisih,
            'qty_sistem' => $qtySistem, 'qty_fisik' => $qtyFisik];
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
                $items[$k]['qty_fisik'] = 0;
                $items[$k]['dicek'] = true;
                $items[$k]['counted_at'] = $ended;
                $items[$k]['counted_by'] = 'Otomatis';
            }
        }
        $summary = self::opnameSummary($items);   // ringkasan dihitung SETELAH semua item final
        $sess['items'] = $items;
        $sess['status'] = 'Selesai';
        $sess['ended_at'] = $ended;
        $sess['summary'] = $summary;
        self::saveSession($db, $sess, $seq);
        self::addLog($db, $user, 'Stock Opname', '-',
            'Akhir sesi opname ' . $sid . ': ' . $summary['dicek'] . '/' . $summary['total'] . ' item dicek, '
            . $summary['cocok'] . ' cocok, ' . $summary['selisih'] . ' selisih, '
            . $summary['belum'] . ' belum dicek');
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
        $ke = trim(self::strv(self::orv($body['ke'] ?? null, '')));
        $alasan = self::strv(self::orv($body['alasan'] ?? null, '-'));
        if ($alasan === '') {
            $alasan = '-';
        }
        $divisi = trim(self::strv(self::orv($body['divisi'] ?? null, '')));
        $pic = trim(self::strv(self::orv($body['pic'] ?? null, '')));
        if ($ke === '') {
            throw new ApiException(400, 'Lokasi tujuan wajib diisi.');
        }
        $asset = self::loadAsset($db, $kode);
        self::assertAssetInScope($user, $asset);
        $mid = self::nextMutId($db);
        $rec = [
            'id' => $mid, 'kode' => $kode, 'tgl' => self::today(),
            'dari' => $asset['lokasi'] ?? null, 'ke' => $ke, 'alasan' => $alasan,
            'status' => 'Pending',
            'user' => self::orv($user['nama'] ?? null, $user['username'] ?? null),
            'cabang' => $asset['cabang'] ?? null,
            'divisi' => $divisi, 'pic' => $pic,
        ];
        $seq = self::nextSeq($db, 'mutations');
        $db->query('INSERT INTO mutations(id, seq, data) VALUES (?,?,?)', [$mid, $seq, self::enc($rec)]);
        self::appendRiwayat($asset, 'Mutasi',
            'Pengajuan ' . ($asset['lokasi'] ?? '') . ' → ' . $ke . ' (' . $mid . ')', $user);
        self::saveAsset($db, $asset);
        self::addLog($db, $user, 'Mutasi', $kode, 'Mengajukan mutasi ' . $mid . ' ke ' . $ke);
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
        $rec['status'] = $result;
        $db->query('UPDATE mutations SET data=? WHERE id=?', [self::enc($rec), $mid]);
        try {
            $asset = self::loadAsset($db, $rec['kode']);
            if ($result === 'Disetujui') {
                $asset['lokasi'] = $rec['ke'];
                $extra = [];
                if (strval(self::orv($rec['divisi'] ?? null, '')) !== '') {
                    $extra[] = 'divisi ' . ($asset['divisi'] ?? '-') . ' → ' . $rec['divisi'];
                    $asset['divisi'] = $rec['divisi'];
                }
                if (strval(self::orv($rec['pic'] ?? null, '')) !== '') {
                    $extra[] = 'PIC ' . ($asset['pic'] ?? '-') . ' → ' . $rec['pic'];
                    $asset['pic'] = $rec['pic'];
                }
                if (in_array($rec['ke'], self::CABANG_LIST, true) && ($asset['cabang'] ?? null) !== $rec['ke']) {
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
            } else {
                $keterangan = 'Pengajuan ke ' . $rec['ke'] . ' — ditolak';
            }
            self::appendRiwayat($asset, 'Mutasi', $keterangan, $user);
            self::saveAsset($db, $asset);
        } catch (ApiException $e) {
            // aset sudah hilang: keputusan tetap dicatat
        }
        self::addLog($db, $user, 'Mutasi', $rec['kode'],
            $mid . ' ' . strtolower($result) . ' (' . $rec['dari'] . ' → ' . $rec['ke'] . ')');
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
            $a += ['cabang' => self::orv(self::getSettings($db)['cabang'] ?? null, self::CABANG_LIST[0])];
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

    /** Endpoint yang boleh diakses tanpa login (urutan check server.py). */
    public static function isPublicAuth(string $method, string $path): bool
    {
        return ($path === '/api/auth/login' && $method === 'POST')
            || ($path === '/api/auth/logout' && $method === 'POST')
            || ($path === '/api/auth/me' && $method === 'GET');
    }
}
