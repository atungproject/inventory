#!/usr/bin/env python3
"""
TRIO Inventory Control — Backend (auth + RBAC + SQLite)
========================================================
Python standard library only (tidak perlu pip install).

Jalankan:
    python server.py                 # http://localhost:8000
    python server.py --port 8080
    python server.py --host 0.0.0.0  # akses dari LAN (kamera QR butuh HTTPS)

Akun demo (dibuat otomatis saat pertama jalan, segera GANTI):
    admin    / admin123    -> Administrator (semua akses)
    auditor  / audit123    -> Auditor       (semua akses kecuali konfigurasi user & login)
    cabang   / cabang123   -> Manajemen Cabang (tambah + ajukan mutasi, cabang TM Buntok)

Role & hak akses (ditegakkan di SERVER, bukan hanya di UI):
    admin  : semua permisi
    audit  : semua permisi kecuali users.manage
    cabang : asset.view, asset.create, mutation.view, mutation.create
             (hanya aset milik cabangnya sendiri; tanpa approval)

Penyimpanan:
    data/trio.db     SQLite  (users, sessions, settings, lists, assets, mutations,
                              opname_sessions, logs)
    uploads/         file foto & dokumen
"""
import argparse
import base64
import hashlib
import json
import mimetypes
import os
import re
import secrets
import sqlite3
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlparse

# ---------------------------------------------------------------- config
ROOT = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.environ.get("TRIO_DATA_DIR") or os.path.join(ROOT, "data")
UPLOAD_DIR = os.environ.get("TRIO_UPLOAD_DIR") or os.path.join(ROOT, "uploads")
DB_PATH = os.path.join(DATA_DIR, "trio.db")
MAX_BODY = 16 * 1024 * 1024
MAX_PHOTO = 2 * 1024 * 1024
MAX_DOC = 5 * 1024 * 1024
SESSION_COOKIE = "trio_session"
SESSION_TTL = 7 * 24 * 3600          # 7 hari
PBKDF2_ITER = 120_000

KONDISI = ["Baik", "Rusak Ringan", "Rusak Berat", "Tidak Ditemukan", "Tidak Layak"]
# Modul Stock Opname berbasis sesi: Draft -> Berjalan -> Selesai, atau Dibatalkan
OPNAME_STATUS = ["Draft", "Berjalan", "Selesai", "Dibatalkan"]
OPNAME_ITEM_STATUS = ["Belum", "Ditemukan", "Tidak Ditemukan"]
ROLES = ["admin", "audit", "cabang"]
ROLE_LABEL = {"admin": "Administrator", "audit": "Auditor", "cabang": "Manajemen Cabang"}
LIST_KEYS = ["kategori", "divisi", "lokasi", "sumberDana", "pic"]
CABANG_LIST = ["TM Buntok", "TM Baru", "TM Kuala Kapuas"]
CABANG_PREFIX = {"TM Buntok": "BTK", "TM Baru": "BRU", "TM Kuala Kapuas": "KKP"}
ASSET_FIELDS = ["nama", "kategori", "merek", "serial", "tahun", "nilai", "tgl", "sumberDana",
                "cabang", "lokasi", "divisi", "pic", "kondisi", "status", "catatan", "foto", "dokumen"]
TRACKED = ["nama", "kondisi", "lokasi", "pic", "status", "cabang"]

ALL_PERMS = [
    "asset.view", "asset.create", "asset.update",
    "opname.write", "report.view",
    "mutation.view", "mutation.create", "mutation.approve",
    "master.view", "master.write",
    "audit.view", "settings.view", "settings.write",
    "users.manage",
]
ROLE_PERMS = {
    "admin": list(ALL_PERMS),
    "audit": [p for p in ALL_PERMS if p != "users.manage"],
    "cabang": ["asset.view", "asset.create", "mutation.view", "mutation.create"],
}

DEFAULT_SETTINGS = {
    "perusahaan": "TRIO MOTOR",
    "tagline": "Satu Hati, Bersama Anda",
    "cabang": "TM Buntok",
}
DEFAULT_LISTS = {
    "kategori": ["Elektronik", "Mebel", "Alat Kerja", "Kendaraan", "Peralatan"],
    "divisi": ["H1", "H2", "H3", "Finance", "OPR", "IT", "Umum"],
    "lokasi": ["Ruang Finance", "R. OPR", "R. H1", "R. H2", "R. Servis", "R. Sparepart",
               "R. Server", "R. Meeting", "R. Tamu", "Gudang", "Parkiran"],
    "sumberDana": ["Operasional", "Inventaris", "Bantuan"],
    "pic": ["Adi Nor Iqbal", "Budi Santoso", "Rina Marlina", "Dedi Saputra", "Yuni Astari",
            "Andi Wijaya", "Hasan Basri", "Sari Rahmawati"],
}
DEMO_USERS = [
    # username, nama, password, role, cabang
    ("admin", "Administrator", "admin123", "admin", "*"),
    ("auditor", "Tim Audit", "audit123", "audit", "*"),
    ("cabang", "Manajemen Cabang Buntok", "cabang123", "cabang", "TM Buntok"),
]

SCHEMA = """
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
"""

_LOCK = threading.RLock()


def today():
    return time.strftime("%Y-%m-%d")


def stamp():
    return time.strftime("%Y-%m-%d %H:%M")


class ApiError(Exception):
    def __init__(self, status, message, code=None):
        super().__init__(message)
        self.status = status
        self.code = code


# ---------------------------------------------------------------- password / session
def hash_password(password, salt=None):
    salt = salt or secrets.token_hex(16)
    dk = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), bytes.fromhex(salt), PBKDF2_ITER)
    return salt, dk.hex()


def verify_password(password, salt, expected):
    dk = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), bytes.fromhex(salt), PBKDF2_ITER)
    return secrets.compare_digest(dk.hex(), expected)


# ---------------------------------------------------------------- db
def connect():
    os.makedirs(DATA_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.executescript(SCHEMA)
    return conn


def get_settings(conn):
    row = conn.execute("SELECT data FROM settings WHERE id=1").fetchone()
    data = json.loads(row["data"]) if row else {}
    merged = dict(DEFAULT_SETTINGS)
    merged.update(data)
    return merged


def put_settings(conn, settings):
    conn.execute("INSERT OR REPLACE INTO settings(id, data) VALUES (1, ?)",
                 (json.dumps(settings, ensure_ascii=False),))


def add_log(conn, user, aksi, kode, keterangan):
    data = {"stamp": stamp(), "user": user.get("nama") or user.get("username") or "System",
            "aksi": aksi, "kode": kode, "keterangan": keterangan}
    conn.execute("INSERT INTO logs(data) VALUES (?)", (json.dumps(data, ensure_ascii=False),))


def public_user(row):
    return {"id": row["id"], "username": row["username"], "nama": row["nama"],
            "role": row["role"], "roleLabel": ROLE_LABEL.get(row["role"], row["role"]),
            "cabang": row["cabang"], "aktif": bool(row["aktif"]),
            "perms": ROLE_PERMS.get(row["role"], [])}


def ensure_seed_users(conn):
    if conn.execute("SELECT COUNT(*) c FROM users").fetchone()["c"] > 0:
        return
    for username, nama, password, role, cabang in DEMO_USERS:
        salt, h = hash_password(password)
        conn.execute(
            "INSERT INTO users(username, nama, pw_salt, pw_hash, role, cabang, aktif, created_at)"
            " VALUES (?,?,?,?,?,?,1,?)",
            (username, nama, salt, h, role, cabang, today()))
    conn.commit()


def demo_hints(conn):
    # Petunjuk akun demo — hanya ditampilkan bila password-nya belum diganti.
    hints = []
    for (u, _n, pw, _r, _c) in DEMO_USERS:
        row = conn.execute("SELECT pw_salt s, pw_hash h, aktif a FROM users WHERE username=?",
                           (u,)).fetchone()
        if row and row["a"] and verify_password(pw, row["s"], row["h"]):
            hints.append(f"{u} / {pw}")
    return hints


# ---------------------------------------------------------------- demo seed data
RAW_ASSETS = [
    ("INV-BTK-0001", "Laptop Lenovo ThinkPad E14", "Elektronik", "Lenovo ThinkPad E14", "PF3JHBK3", 2025, 12500000, "2025-01-10", "Operasional", "Ruang Finance", "Finance", "Adi Nor Iqbal", "Baik", "Aktif"),
    ("INV-BTK-0002", "Printer Epson L3250", "Elektronik", "Epson L3250", "X9DK2211", 2024, 3400000, "2024-03-05", "Operasional", "R. OPR", "OPR", "Budi Santoso", "Baik", "Aktif"),
    ("INV-BTK-0003", "Meja Kerja", "Mebel", "Meja Kerja Minimalis", "-", 2023, 2100000, "2023-07-18", "Inventaris", "R. H1", "H1", "Rina Marlina", "Baik", "Aktif"),
    ("INV-BTK-0004", "Kursi Staff", "Mebel", "Kursi Kantor Ergonomis", "-", 2023, 950000, "2023-07-18", "Inventaris", "R. H2", "H2", "Dedi Saputra", "Rusak Ringan", "Aktif"),
    ("INV-BTK-0005", "AC 1 PK", "Elektronik", "Panasonic CS-YN9WKJ", "PNK992134", 2022, 5800000, "2022-02-11", "Operasional", "R. Servis", "H2", "Dedi Saputra", "Baik", "Aktif"),
    ("INV-BTK-0006", "Handphone Samsung", "Elektronik", "Samsung Galaxy A55 5G", "RF8N90K21", 2025, 4300000, "2025-05-20", "Operasional", "R. Sparepart", "H3", "Yuni Astari", "Baik", "Aktif"),
    ("INV-BTK-0007", "Lemari Arsip", "Mebel", "Lemari Besi 2 Pintu", "-", 2021, 2750000, "2021-09-30", "Inventaris", "R. Finance", "Finance", "Adi Nor Iqbal", "Rusak Berat", "Aktif"),
    ("INV-BTK-0008", "CCTV DVR", "Elektronik", "Hikvision DS-7208", "HKV771209", 2024, 4900000, "2024-01-22", "Operasional", "R. Server", "IT", "Andi Wijaya", "Baik", "Aktif"),
    ("INV-BTK-0009", "Timbangan Digital", "Alat Kerja", "Digital Scale 300kg", "DSC300112", 2023, 3200000, "2023-11-08", "Inventaris", "R. Sparepart", "H3", "Yuni Astari", "Baik", "Aktif"),
    ("INV-BTK-0010", "Tangga Aluminum", "Alat Kerja", "Hydro Step 3M", "-", 2022, 1450000, "2022-06-14", "Inventaris", "Gudang", "H3", "Hasan Basri", "Tidak Ditemukan", "Aktif"),
    ("INV-BTK-0011", "Komputer PC Rakitan", "Elektronik", "Intel i5 / 16GB / SSD 512", "PC23011", 2024, 9200000, "2024-04-03", "Operasional", "R. Server", "IT", "Andi Wijaya", "Baik", "Aktif"),
    ("INV-BTK-0012", "Proyektor Epson", "Elektronik", "Epson EB-X06", "EPS662091", 2023, 6100000, "2023-02-27", "Operasional", "R. Meeting", "H1", "Rina Marlina", "Baik", "Aktif"),
    ("INV-BTK-0013", "Sofa Ruang Tamu", "Mebel", "Sofa 3 Seater", "-", 2022, 4750000, "2022-08-19", "Inventaris", "R. Tamu", "Umum", "Sari Rahmawati", "Baik", "Aktif"),
    ("INV-BTK-0014", "Motor Dinas Honda Beat", "Kendaraan", "Honda Beat CBS 2023", "E3412K77", 2023, 17200000, "2023-05-12", "Operasional", "Parkiran", "Umum", "Hasan Basri", "Baik", "Aktif"),
    ("INV-BTK-0015", "Genset 5 KVA", "Peralatan", "Honda EU50is", "GNT50021", 2021, 32000000, "2021-04-25", "Inventaris", "Gudang", "OPR", "Budi Santoso", "Rusak Ringan", "Aktif"),
    ("INV-BTK-0016", "Mesin Press Hidrolik", "Alat Kerja", "Hydraulic Press 10 Ton", "HYP10092", 2020, 24500000, "2020-10-05", "Inventaris", "R. Servis", "H2", "Dedi Saputra", "Rusak Berat", "Aktif"),
    ("INV-BTK-0017", "Tablet Samsung Tab A9", "Elektronik", "Samsung Galaxy Tab A9", "TB992014", 2025, 3650000, "2025-08-14", "Operasional", "Ruang Finance", "Finance", "Adi Nor Iqbal", "Baik", "Aktif"),
    ("INV-BTK-0018", "Kipas Angin Standing", "Elektronik", "Miyako FS-160", "-", 2019, 450000, "2019-03-09", "Inventaris", "R. H1", "H1", "Rina Marlina", "Tidak Layak", "Nonaktif"),
    ("INV-BTK-0019", "Rak Gudang Besi", "Mebel", "Rak Heavy Duty 4 Tingkat", "-", 2022, 6800000, "2022-12-02", "Inventaris", "Gudang", "Umum", "Hasan Basri", "Baik", "Aktif"),
    ("INV-BTK-0020", "Barcode Scanner Zebra", "Elektronik", "Zebra DS2208", "ZB220811", 2024, 1750000, "2024-07-21", "Operasional", "R. Sparepart", "H3", "Yuni Astari", "Tidak Ditemukan", "Aktif"),
    ("INV-BTK-0021", "Brankas Kecil", "Mebel", "Brankas Sollingen 30cm", "BRK30117", 2023, 5400000, "2023-01-16", "Inventaris", "Ruang Finance", "Finance", "Adi Nor Iqbal", "Baik", "Aktif"),
    ("INV-BTK-0022", "Telepon Kantor", "Elektronik", "Panasonic KX-T7730", "-", 2021, 890000, "2021-06-11", "Operasional", "R. H1", "H1", "Rina Marlina", "Rusak Ringan", "Aktif"),
    ("INV-BTK-0023", "Sepeda Motor Pandu", "Kendaraan", "Yamaha Fino 125", "YMN77203", 2024, 19800000, "2024-09-09", "Operasional", "Parkiran", "Umum", "Hasan Basri", "Baik", "Aktif"),
    ("INV-BTK-0024", "Lemari Pendingin", "Peralatan", "Polytron PRM-389", "PLY38922", 2022, 4200000, "2022-05-30", "Operasional", "R. Servis", "H2", "Dedi Saputra", "Baik", "Aktif"),
]

DEMO_MUTATIONS = [
    ("MUT-0001", "INV-BTK-0003", "2026-09-05", "R. H1", "R. Meeting", "Kebutuhan rapat penjualan", "Pending", "Rina Marlina"),
    ("MUT-0002", "INV-BTK-0011", "2026-09-12", "R. Server", "Ruang Finance", "Pendukung kerja finance", "Pending", "Andi Wijaya"),
    ("MUT-0003", "INV-BTK-0013", "2026-09-15", "R. Tamu", "R. Meeting", "Penataan ruang meeting", "Pending", "Sari Rahmawati"),
    ("MUT-0004", "INV-BTK-0006", "2026-09-18", "R. Sparepart", "R. H1", "Dukungan tim penjualan", "Pending", "Yuni Astari"),
    ("MUT-0005", "INV-BTK-0017", "2026-09-20", "R. Server", "Ruang Finance", "Penggunaan oleh staf finance", "Pending", "Adi Nor Iqbal"),
    ("MUT-0006", "INV-BTK-0001", "2025-06-15", "R. Server", "Ruang Finance", "Serah terima aset", "Disetujui", "Budi Santoso"),
    ("MUT-0007", "INV-BTK-0012", "2026-06-02", "R. Meeting", "R. H1", "Penempatan tim", "Disetujui", "Rina Marlina"),
    ("MUT-0008", "INV-BTK-0015", "2026-07-11", "R. Servis", "Gudang", "Perawatan berkala", "Ditolak", "Budi Santoso"),
]

DEMO_LOGS = [
    ("2026-09-20 08:15", "Adi Nor Iqbal", "Login", "-", "Masuk ke sistem"),
    ("2026-09-19 16:42", "Budi Santoso", "Mutasi", "INV-BTK-0001", "Menyetujui mutasi ke Ruang Finance"),
    ("2026-09-18 10:05", "Yuni Astari", "Mutasi", "INV-BTK-0006", "Mengajukan mutasi ke R. H1"),
    ("2026-08-20 09:00", "Tim Audit", "Stock Opname", "-", "Pelaksanaan stock opname cabang TM Buntok"),
    ("2026-01-10 14:22", "Admin", "Penambahan", "INV-BTK-0001", "Inventaris baru ditambahkan"),
]


def build_demo_asset(r):
    (kode, nama, kategori, merek, serial, tahun, nilai, tgl, sumber_dana,
     lokasi, divisi, pic, kondisi, status) = r
    riwayat = [{"tgl": tgl, "aktivitas": "Penambahan", "keterangan": "Inventaris baru", "user": "Admin"}]
    if kode == "INV-BTK-0001":
        riwayat.append({"tgl": "2025-06-15", "aktivitas": "Mutasi",
                        "keterangan": "Dari R. Server ke Ruang Finance", "user": "Budi"})
        riwayat.append({"tgl": "2026-08-20", "aktivitas": "Stock Opname",
                        "keterangan": "Ditemukan, kondisi baik", "user": "Tim Audit"})
    if kondisi != "Baik":
        riwayat.append({"tgl": "2026-08-20", "aktivitas": "Stock Opname",
                        "keterangan": f"Ditemukan, kondisi {kondisi.lower()}", "user": "Tim Audit"})
    riwayat.sort(key=lambda x: x["tgl"])
    opname = ([{"tgl": "2026-08-20", "status": "Ditemukan", "lokasi": lokasi, "kondisi": kondisi,
                "catatan": "-", "user": "Tim Audit"}] if kondisi == "Baik" else [])
    perbaikan = []
    if kondisi.startswith("Rusak"):
        perbaikan = [{"tgl": "2026-08-25",
                      "keluhan": ("Kerusakan berat, perlu penggantian" if kondisi == "Rusak Berat"
                                  else "Kerusakan ringan, perlu perbaikan"),
                      "status": "Dalam Antrian", "vendor": "-", "user": "Teknisi"}]
    return {
        "kode": kode, "nama": nama, "kategori": kategori, "merek": merek, "serial": serial,
        "tahun": tahun, "nilai": nilai, "tgl": tgl, "sumberDana": sumber_dana,
        "cabang": "TM Buntok", "lokasi": lokasi, "divisi": divisi, "pic": pic,
        "kondisi": kondisi, "status": status, "catatan": "-", "foto": "",
        "riwayat": riwayat, "opname": opname, "perbaikan": perbaikan,
        "dokumen": [{"nama": "Bukti Perolehan.pdf", "url": ""}],
    }


def seed_demo(conn, actor=None):
    """Isi database dengan data contoh (dipakai saat pertama jalan & reset)."""
    with _LOCK:
        conn.execute("DELETE FROM logs")
        conn.execute("DELETE FROM mutations")
        conn.execute("DELETE FROM assets")
        conn.execute("DELETE FROM lists")
        put_settings(conn, dict(DEFAULT_SETTINGS))
        for key, values in DEFAULT_LISTS.items():
            for v in values:
                conn.execute("INSERT OR IGNORE INTO lists(key, value) VALUES (?,?)", (key, v))
        seq = -1
        for r in RAW_ASSETS:
            a = build_demo_asset(r)
            conn.execute("INSERT INTO assets(kode, seq, data) VALUES (?,?,?)",
                         (a["kode"], seq, json.dumps(a, ensure_ascii=False)))
            seq -= 1
        mseq = -1
        for (mid, kode, tgl, dari, ke, alasan, status, user) in DEMO_MUTATIONS:
            rec = {"id": mid, "kode": kode, "tgl": tgl, "dari": dari, "ke": ke,
                   "alasan": alasan, "status": status, "user": user}
            conn.execute("INSERT INTO mutations(id, seq, data) VALUES (?,?,?)",
                         (mid, mseq, json.dumps(rec, ensure_ascii=False)))
            mseq -= 1
        for (s, u, aksi, kode, ket) in DEMO_LOGS:
            conn.execute("INSERT INTO logs(data) VALUES (?)", (
                json.dumps({"stamp": s, "user": u, "aksi": aksi, "kode": kode, "keterangan": ket},
                           ensure_ascii=False),))
        conn.execute("INSERT OR REPLACE INTO meta(k, v) VALUES ('seeded', ?)", (stamp(),))
        conn.commit()


def init_db():
    conn = connect()
    try:
        ensure_seed_users(conn)
        if not conn.execute("SELECT v FROM meta WHERE k='seeded'").fetchone():
            seed_demo(conn)
    finally:
        conn.close()


# ---------------------------------------------------------------- state / scope
def fetch_asset_row(conn, kode):
    return conn.execute("SELECT data FROM assets WHERE kode=?", (kode,)).fetchone()


def load_asset(conn, kode):
    row = fetch_asset_row(conn, kode)
    if not row:
        raise ApiError(404, f"Inventaris {kode} tidak ditemukan.")
    return json.loads(row["data"])


def save_asset(conn, asset, seq=None):
    if seq is None:
        row = conn.execute("SELECT seq FROM assets WHERE kode=?", (asset["kode"],)).fetchone()
        seq = row["seq"] if row else 0
    conn.execute("INSERT OR REPLACE INTO assets(kode, seq, data) VALUES (?,?,?)",
                 (asset["kode"], seq, json.dumps(asset, ensure_ascii=False)))


def assert_asset_in_scope(user, asset):
    """Role cabang hanya boleh menyentuh aset milik cabangnya."""
    if user["role"] == "cabang" and asset.get("cabang") != user["cabang"]:
        raise ApiError(403, "Aset tersebut berada di luar cabang Anda.", "OUT_OF_SCOPE")


def build_state(conn, user):
    """Seluruh data yang boleh dilihat user ini (dipakai semua response tulis)."""
    assets = [json.loads(r["data"]) for r in
              conn.execute("SELECT data FROM assets ORDER BY seq ASC")]
    if user["role"] == "cabang":
        assets = [a for a in assets if a.get("cabang") == user["cabang"]]
    codes = {a["kode"] for a in assets}

    mutations = [json.loads(r["data"]) for r in
                 conn.execute("SELECT data FROM mutations ORDER BY seq ASC")]
    mutations = [m for m in mutations if m["kode"] in codes]

    opname_sessions = [json.loads(r["data"]) for r in
                       conn.execute("SELECT data FROM opname_sessions ORDER BY seq ASC")]
    if user["role"] == "cabang":
        opname_sessions = [s for s in opname_sessions if s.get("cabang") == user["cabang"]]

    if user["role"] == "cabang":
        logs = [json.loads(r["data"]) for r in
                conn.execute("SELECT data FROM logs ORDER BY id DESC LIMIT 200")
                if json.loads(r["data"]).get("kode") in codes or json.loads(r["data"]).get("kode") == "-"]
    else:
        logs = [json.loads(r["data"]) for r in
                conn.execute("SELECT data FROM logs ORDER BY id DESC LIMIT 500")]

    lists = {k: list(v) for k, v in DEFAULT_LISTS.items()}
    for r in conn.execute("SELECT key, value FROM lists ORDER BY rowid"):
        if r["key"] in lists and r["value"] not in lists[r["key"]]:
            lists[r["key"]].append(r["value"])

    return {"settings": get_settings(conn), "lists": lists, "assets": assets,
            "mutations": mutations, "opnameSessions": opname_sessions, "logs": logs}


def next_seq(conn, table):
    row = conn.execute(f"SELECT MIN(seq) AS m FROM {table}").fetchone()
    return (row["m"] or 0) - 1


def cabang_prefix(cabang):
    """Prefix kode aset per cabang — tiap cabang punya urutan nomor sendiri."""
    c = str(cabang or "").strip()
    if c in CABANG_PREFIX:
        return CABANG_PREFIX[c]
    words = [w for w in re.split(r"[^A-Za-z0-9]+", c) if w and w.upper() != "TM"]
    if not words:
        return "GEN"
    ini = "".join(w[0] for w in words).upper()
    return ini[:4] if len(ini) >= 2 else words[0][:3].upper()


def next_kode(conn, cabang):
    pfx = cabang_prefix(cabang)
    mx = 0
    for r in conn.execute("SELECT kode FROM assets WHERE kode LIKE ?", (f"INV-{pfx}-%",)):
        m = re.match(r"^INV-%s-(\d+)$" % re.escape(pfx), r["kode"] or "")
        if m:
            mx = max(mx, int(m.group(1)))
    return "INV-{}-{:04d}".format(pfx, mx + 1)


def next_mut_id(conn):
    mx = 0
    for r in conn.execute("SELECT data FROM mutations"):
        m = re.match(r"^MUT-(\d+)$", json.loads(r["data"]).get("id", ""))
        if m:
            mx = max(mx, int(m.group(1)))
    return "MUT-%04d" % (mx + 1)


def append_riwayat(asset, aktivitas, keterangan, user):
    asset.setdefault("riwayat", []).append(
        {"tgl": today(), "aktivitas": aktivitas, "keterangan": keterangan,
         "user": user.get("nama") or user.get("username") or "System"})
    asset["riwayat"].sort(key=lambda x: x.get("tgl", ""))


# ---------------------------------------------------------------- auth handlers
def auth_login(conn, body):
    username = str(body.get("username") or "").strip()
    password = str(body.get("password") or "")
    if not username or not password:
        raise ApiError(400, "Username dan password wajib diisi.")
    row = conn.execute("SELECT * FROM users WHERE username=?", (username,)).fetchone()
    if not row or not verify_password(password, row["pw_salt"], row["pw_hash"]):
        raise ApiError(401, "Username atau password salah.", "BAD_CREDENTIALS")
    if not row["aktif"]:
        raise ApiError(403, "Akun ini dinonaktifkan. Hubungi administrator.", "ACCOUNT_DISABLED")
    token = secrets.token_urlsafe(32)
    now = int(time.time())
    with _LOCK:
        conn.execute("INSERT INTO sessions(token, user_id, created_at, expires_at) VALUES (?,?,?,?)",
                     (token, row["id"], stamp(), now + SESSION_TTL))
        conn.execute("DELETE FROM sessions WHERE expires_at < ?", (now,))
        add_log(conn, {"nama": row["nama"], "username": row["username"]}, "Login", "-",
                f"Masuk sebagai {ROLE_LABEL.get(row['role'], row['role'])}")
        conn.commit()
    return token, public_user(row)


def auth_logout(conn, token):
    with _LOCK:
        if token:
            conn.execute("DELETE FROM sessions WHERE token=?", (token,))
            conn.commit()


def current_session_user(conn, headers):
    header = headers.get("Cookie") or ""
    token = None
    for part in header.split(";"):
        k, _, v = part.strip().partition("=")
        if k == SESSION_COOKIE:
            token = v
    if not token:
        return None, None
    row = conn.execute(
        "SELECT s.token token, s.expires_at expires_at, u.* FROM sessions s"
        " JOIN users u ON u.id = s.user_id WHERE s.token=? AND u.aktif=1", (token,)).fetchone()
    if not row:
        return token, None
    if row["expires_at"] < int(time.time()):
        conn.execute("DELETE FROM sessions WHERE token=?", (token,))
        conn.commit()
        return token, None
    return token, dict(row)


def auth_change_password(conn, user, body):
    old = str(body.get("oldPassword") or "")
    new = str(body.get("newPassword") or "")
    if len(new) < 6:
        raise ApiError(400, "Password baru minimal 6 karakter.")
    row = conn.execute("SELECT * FROM users WHERE id=?", (user["id"],)).fetchone()
    if not verify_password(old, row["pw_salt"], row["pw_hash"]):
        raise ApiError(400, "Password lama salah.")
    salt, h = hash_password(new)
    with _LOCK:
        conn.execute("UPDATE users SET pw_salt=?, pw_hash=? WHERE id=?", (salt, h, row["id"]))
        add_log(conn, user, "Akun", "-", "Mengubah password sendiri")
        conn.commit()


# ---------------------------------------------------------------- user management
def users_list(conn):
    return [public_user(r) for r in conn.execute("SELECT * FROM users ORDER BY id ASC")]


def users_create(conn, actor, body):
    username = str(body.get("username") or "").strip().lower()
    nama = str(body.get("nama") or "").strip()
    password = str(body.get("password") or "")
    role = str(body.get("role") or "")
    cabang = str(body.get("cabang") or "*").strip() or "*"
    if not re.match(r"^[a-z0-9._-]{3,30}$", username):
        raise ApiError(400, "Username 3-30 karakter (huruf kecil, angka, . _ -).")
    if not nama:
        raise ApiError(400, "Nama wajib diisi.")
    if len(password) < 6:
        raise ApiError(400, "Password minimal 6 karakter.")
    if role not in ROLES:
        raise ApiError(400, "Role tidak valid.")
    if role == "cabang" and cabang == "*":
        raise ApiError(400, "User role Cabang harus memiliki cabang.")
    if conn.execute("SELECT 1 FROM users WHERE username=?", (username,)).fetchone():
        raise ApiError(400, "Username sudah dipakai.")
    salt, h = hash_password(password)
    with _LOCK:
        conn.execute(
            "INSERT INTO users(username, nama, pw_salt, pw_hash, role, cabang, aktif, created_at)"
            " VALUES (?,?,?,?,?,?,?,?)",
            (username, nama, salt, h, role, cabang, 1 if body.get("aktif", True) else 0, today()))
        add_log(conn, actor, "Akun", "-", f"Membuat user {username} ({ROLE_LABEL.get(role, role)})")
        conn.commit()


def users_update(conn, actor, uid, body):
    row = conn.execute("SELECT * FROM users WHERE id=?", (uid,)).fetchone()
    if not row:
        raise ApiError(404, "User tidak ditemukan.")
    nama = str(body.get("nama") or row["nama"]).strip() or row["nama"]
    role = str(body.get("role") or row["role"])
    cabang = str(body.get("cabang") or row["cabang"]).strip() or "*"
    aktif = 1 if body.get("aktif", bool(row["aktif"])) else 0
    if role not in ROLES:
        raise ApiError(400, "Role tidak valid.")
    if role == "cabang" and cabang == "*":
        raise ApiError(400, "User role Cabang harus memiliki cabang.")
    if row["id"] == actor["id"] and (role != "admin" or not aktif):
        raise ApiError(400, "Anda tidak dapat mengubah role/menonaktifkan akun sendiri.")
    new_password = str(body.get("password") or "")
    salt, h = row["pw_salt"], row["pw_hash"]
    reset = False
    if new_password:
        if len(new_password) < 6:
            raise ApiError(400, "Password minimal 6 karakter.")
        salt, h = hash_password(new_password)
        reset = True
    with _LOCK:
        conn.execute("UPDATE users SET nama=?, role=?, cabang=?, aktif=?, pw_salt=?, pw_hash=? WHERE id=?",
                     (nama, role, cabang, aktif, salt, h, uid))
        if reset:
            conn.execute("DELETE FROM sessions WHERE user_id=?", (uid,))
        add_log(conn, actor, "Akun", "-",
                f"Mengubah user {row['username']}" + (" (reset password)" if reset else ""))
        conn.commit()


def users_delete(conn, actor, uid):
    row = conn.execute("SELECT * FROM users WHERE id=?", (uid,)).fetchone()
    if not row:
        raise ApiError(404, "User tidak ditemukan.")
    if row["id"] == actor["id"]:
        raise ApiError(400, "Anda tidak dapat menghapus akun sendiri.")
    if conn.execute("SELECT COUNT(*) c FROM users").fetchone()["c"] <= 1:
        raise ApiError(400, "Tidak boleh menghapus semua user.")
    with _LOCK:
        conn.execute("DELETE FROM sessions WHERE user_id=?", (uid,))
        conn.execute("DELETE FROM users WHERE id=?", (uid,))
        add_log(conn, actor, "Akun", "-", f"Menghapus user {row['username']}")
        conn.commit()


# ---------------------------------------------------------------- asset handlers
def api_create_asset(conn, user, body):
    for f in ("nama", "kategori", "lokasi", "status"):
        if not str(body.get(f) or "").strip():
            raise ApiError(400, f"Field '{f}' wajib diisi.")
    cabang = user["cabang"] if user["role"] == "cabang" else str(
        body.get("cabang") or get_settings(conn).get("cabang") or CABANG_LIST[0])
    kode = next_kode(conn, cabang)
    asset = {"kode": kode, "cabang": cabang, "riwayat": [], "opname": [], "perbaikan": []}
    for f in ASSET_FIELDS:
        if f == "cabang":
            continue
        v = body.get(f)
        if f == "nilai":
            asset[f] = int(v or 0)
        elif f == "dokumen":
            asset[f] = v if isinstance(v, list) else []
        elif f == "foto":
            asset[f] = str(v or "")
        elif f == "kondisi":
            asset[f] = v if v in KONDISI else "Baik"
        elif f == "catatan":
            asset[f] = str(v or "-") or "-"
        else:
            asset[f] = str(v if v is not None else ("-" if f in (
                "merek", "serial", "sumberDana", "divisi", "pic") else ""))
    asset["tgl"] = asset.get("tgl") or today()
    append_riwayat(asset, "Penambahan", "Inventaris baru", user)
    with _LOCK:
        seq = next_seq(conn, "assets")
        save_asset(conn, asset, seq)
        add_log(conn, user, "Penambahan", kode, f"Inventaris baru: {asset['nama']} ({cabang})")
        conn.commit()
    return {"kode": kode}


def api_update_asset(conn, user, kode, body):
    with _LOCK:
        asset = load_asset(conn, kode)
        assert_asset_in_scope(user, asset)
        changes = []
        for f in ASSET_FIELDS:
            if f not in body:
                continue
            v = body[f]
            if f == "nilai":
                v = int(v or 0)
            elif f == "dokumen":
                v = v if isinstance(v, list) else []
            elif f == "kondisi" and v not in KONDISI:
                continue
            elif f == "cabang" and user["role"] != "admin":
                continue                      # hanya admin boleh pindahkan cabang
            elif f in ("status", "tgl") and user["role"] != "admin":
                continue                      # status & tanggal perolehan hanya admin
            elif f in ("nama", "lokasi", "divisi", "pic", "kondisi"):
                continue                      # hanya lewat transaksi (mutasi/opname)
            else:
                v = str(v) if v is not None else ""
            if str(asset.get(f, "")) != str(v):
                if f in TRACKED:
                    changes.append(f"{f}: {asset.get(f)} → {v}")
                asset[f] = v
        if changes:
            append_riwayat(asset, "Perubahan Data", ", ".join(changes), user)
        save_asset(conn, asset)
        add_log(conn, user, "Edit", kode,
                ", ".join(changes) if changes else "Pembaruan data inventaris")
        conn.commit()


def api_asset_docs(conn, user, kode, body, index=None):
    with _LOCK:
        asset = load_asset(conn, kode)
        assert_asset_in_scope(user, asset)
        docs = asset.setdefault("dokumen", [])
        if index is None:
            nama = str(body.get("nama") or "Dokumen")
            docs.append({"nama": nama, "url": str(body.get("url") or "")})
            add_log(conn, user, "Dokumen", kode, f"Upload {nama}")
        else:
            if 0 <= index < len(docs):
                removed = docs.pop(index)
                add_log(conn, user, "Dokumen", kode, f"Hapus {removed.get('nama', '-')}")
            else:
                raise ApiError(400, "Indeks dokumen tidak valid.")
        save_asset(conn, asset)
        conn.commit()


# ------------------------------------------------------- opname sesi handlers
def fetch_session(conn, sid):
    """-> (data_sesi, seq);404 bila tidak ada."""
    row = conn.execute("SELECT data, seq FROM opname_sessions WHERE id=?", (sid,)).fetchone()
    if not row:
        raise ApiError(404, "Sesi opname tidak ditemukan.")
    return json.loads(row["data"]), row["seq"]


def save_session(conn, sess, seq):
    conn.execute("INSERT OR REPLACE INTO opname_sessions(id, seq, data) VALUES (?,?,?)",
                 (sess["id"], seq, json.dumps(sess, ensure_ascii=False)))


def assert_session_in_scope(user, sess):
    if user["role"] == "cabang" and sess.get("cabang") != user["cabang"]:
        raise ApiError(403, "Sesi opname bukan milik cabang Anda.", "FORBIDDEN")


def next_opname_id(conn):
    mx = 0
    for r in conn.execute("SELECT data FROM opname_sessions"):
        m = re.match(r"^OPN-(\d+)$", json.loads(r["data"]).get("id", ""))
        if m:
            mx = max(mx, int(m.group(1)))
    return "OPN-%04d" % (mx + 1)


def active_session_of(conn, cabang):
    """FR-2: maksimal satu sesi Berjalan per cabang."""
    for r in conn.execute("SELECT data FROM opname_sessions ORDER BY seq ASC"):
        s = json.loads(r["data"])
        if s.get("cabang") == cabang and s.get("status") == "Berjalan":
            return s
    return None


def api_create_opname(conn, user, body):
    cabang = str(body.get("cabang") or "").strip()
    nama = str(body.get("nama") or "").strip()
    tanggal = str(body.get("tanggal") or "").strip()
    catatan = str(body.get("catatan") or "").strip() or "-"
    if not cabang:
        raise ApiError(400, "Cabang wajib dipilih.")
    if not nama:
        raise ApiError(400, "Nama sesi opname wajib diisi.")
    if not tanggal:
        raise ApiError(400, "Tanggal opname wajib diisi.")
    with _LOCK:
        running = active_session_of(conn, cabang)
        if running:
            raise ApiError(409,
                           "Masih ada sesi opname berjalan pada cabang ini. Akhiri sesi terlebih dahulu.",
                           "ACTIVE_SESSION")
        sid = next_opname_id(conn)
        # FR-5: snapshot seluruh inventaris cabang pada saat sesi dibuat.
        # Lokasi/PIC/kondisi disimpan ganda (nilai kerja + *_awal) supaya ringkasan
        # bisa menghitung "data berubah" dan item bisa dikembalikan ke nilai awal.
        items = []
        for r in conn.execute("SELECT data FROM assets ORDER BY seq ASC"):
            a = json.loads(r["data"])
            if a.get("cabang") != cabang:
                continue
            lokasi = a.get("lokasi") or "-"
            pic = a.get("pic") or "-"
            kondisi = a.get("kondisi") or "Baik"
            items.append({"kode": a["kode"], "sku": a["kode"], "barcode": a["kode"],
                          "nama": a.get("nama") or "-", "kategori": a.get("kategori") or "-",
                          "lokasi": lokasi, "pic": pic, "kondisi": kondisi,
                          "lokasi_awal": lokasi, "pic_awal": pic, "kondisi_awal": kondisi,
                          "status": "Belum", "dicek": False,
                          "counted_at": None, "counted_by": None})
        sess = {"id": sid, "cabang": cabang, "nama": nama, "tanggal": tanggal,
                "catatan": catatan, "status": "Draft",
                "user": user.get("nama") or user.get("username"),
                "created_at": stamp(), "started_at": None, "ended_at": None,
                "items": items, "summary": None}
        save_session(conn, sess, next_seq(conn, "opname_sessions"))
        add_log(conn, user, "Stock Opname", "-",
                f"Buat sesi opname {sid} ({nama}) cabang {cabang}, {len(items)} item")
        conn.commit()
    return {"id": sid}


def api_start_opname(conn, user, sid, body):
    with _LOCK:
        sess, seq = fetch_session(conn, sid)
        assert_session_in_scope(user, sess)
        if sess.get("status") != "Draft":
            raise ApiError(409, "Hanya sesi berstatus Draft yang bisa dimulai.", "SESSION_STATE")
        running = active_session_of(conn, sess.get("cabang"))
        if running and running.get("id") != sid:
            raise ApiError(409,
                           "Masih ada sesi opname berjalan pada cabang ini. Akhiri sesi terlebih dahulu.",
                           "ACTIVE_SESSION")
        sess["status"] = "Berjalan"
        sess["started_at"] = stamp()
        save_session(conn, sess, seq)
        add_log(conn, user, "Stock Opname", "-", f"Mulai sesi opname {sid} ({sess.get('nama')})")
        conn.commit()


def api_count_opname(conn, user, sid, kode, body):
    new_status = body.get("status")
    if new_status not in OPNAME_ITEM_STATUS:
        raise ApiError(400, "Status item opname tidak valid.")
    with _LOCK:
        sess, seq = fetch_session(conn, sid)
        assert_session_in_scope(user, sess)
        if sess.get("status") != "Berjalan":
            raise ApiError(409, "Sesi opname tidak dalam status Berjalan, penghitungan terkunci.",
                           "SESSION_LOCKED")
        item = next((i for i in sess.get("items", []) if i.get("kode") == kode), None)
        if not item:
            raise ApiError(404, "Item opname tidak ditemukan.")

        awal_lokasi = item.get("lokasi_awal", item.get("lokasi"))
        awal_pic = item.get("pic_awal", item.get("pic"))
        awal_kondisi = item.get("kondisi_awal", item.get("kondisi"))
        old = (item.get("status"), item.get("lokasi"), item.get("pic"),
               item.get("kondisi"), item.get("dicek"))
        counted_at = counted_by = None

        if new_status == "Belum":
            # hitungan dibatalkan -> data dikembalikan ke nilai awal snapshot
            status, lokasi, pic, kondisi = "Belum", awal_lokasi, awal_pic, awal_kondisi
            dicek = False
        elif new_status == "Tidak Ditemukan":
            # barang tidak ditemukan: lokasi/PIC tetap nilai awal, kondisi ditandai hilang
            status, lokasi, pic, kondisi = "Tidak Ditemukan", awal_lokasi, awal_pic, "Tidak Ditemukan"
            dicek = True
            counted_at = stamp()
            counted_by = user.get("nama") or user.get("username")
        else:
            status = "Ditemukan"
            raw_lokasi = body.get("lokasi")
            raw_pic = body.get("pic")
            raw_kondisi = body.get("kondisi")
            lokasi = str(raw_lokasi).strip() if raw_lokasi is not None else (item.get("lokasi") or "")
            pic = str(raw_pic).strip() if raw_pic is not None else (item.get("pic") or "")
            kondisi = str(raw_kondisi).strip() if raw_kondisi is not None else (item.get("kondisi") or "")
            if not lokasi:
                raise ApiError(400, "Lokasi wajib diisi.")
            if not pic:
                raise ApiError(400, "PIC wajib diisi.")
            if kondisi not in KONDISI:
                raise ApiError(400, "Kondisi tidak valid.")
            dicek = True
            counted_at = stamp()
            counted_by = user.get("nama") or user.get("username")

        if (status, lokasi, pic, kondisi, dicek) == old:
            return                                   # tanpa perubahan: tidak ada log

        item.update({"status": status, "lokasi": lokasi, "pic": pic, "kondisi": kondisi,
                     "dicek": dicek, "counted_at": counted_at, "counted_by": counted_by})
        if status == "Ditemukan":
            add_log(conn, user, "Stock Opname", kode,
                    f"Hitung {sid}: {status} (Lokasi: {lokasi}, PIC: {pic}, Kondisi: {kondisi})")
        else:
            add_log(conn, user, "Stock Opname", kode, f"Hitung {sid}: {status}")
        save_session(conn, sess, seq)
        conn.commit()


def opname_item_changed(i):
    """Lokasi/PIC/kondisi berbeda dari nilai awal snapshot (akan ditulis balik ke aset)."""
    return (i.get("lokasi") != i.get("lokasi_awal", i.get("lokasi")) or
            i.get("pic") != i.get("pic_awal", i.get("pic")) or
            i.get("kondisi") != i.get("kondisi_awal", i.get("kondisi")))


def opname_summary(items):
    total = len(items)
    dicek = sum(1 for i in items if i.get("dicek"))
    return {"total": total, "dicek": dicek, "belum": total - dicek,
            "ditemukan": sum(1 for i in items if i.get("status") == "Ditemukan"),
            "tidak_ditemukan": sum(1 for i in items if i.get("status") == "Tidak Ditemukan"),
            "berubah": sum(1 for i in items if opname_item_changed(i))}


def write_back_opname(conn, user, sid, items):
    """Tulis hasil opname ke tabel inventaris (dipanggil saat sesi diakhiri).
    Ditemukan  -> Lokasi/PIC/kondisi mengikuti hasil pemeriksaan.
    Tidak Ditemukan (termasuk yang ditandai otomatis) -> kondisi aset = Tidak Ditemukan.
    Mengembalikan jumlah aset yang benar-benar berubah."""
    updated = 0
    for i in items:
        try:
            asset = load_asset(conn, i.get("kode"))
        except ApiError:
            continue                                  # aset dihapus di tengah sesi -> lewati
        changed = []
        if i.get("status") == "Ditemukan":
            for f in ("lokasi", "pic", "kondisi"):
                v = i.get(f)
                if v and asset.get(f) != v:
                    asset[f] = v
                    changed.append(f)
            ket = f"Opname {sid}: {', '.join(changed)} diperbarui"
        else:
            if asset.get("kondisi") != "Tidak Ditemukan":
                asset["kondisi"] = "Tidak Ditemukan"
                changed.append("kondisi")
            ket = f"Opname {sid}: kondisi disetel Tidak Ditemukan"
        if not changed:
            continue
        append_riwayat(asset, "Stock Opname", ket, user)
        save_asset(conn, asset)
        updated += 1
    return updated


def api_end_opname(conn, user, sid, body):
    with _LOCK:
        sess, seq = fetch_session(conn, sid)
        assert_session_in_scope(user, sess)
        if sess.get("status") != "Berjalan":
            raise ApiError(409, "Hanya sesi berjalan yang bisa diakhiri.", "SESSION_STATE")
        items = sess.get("items", [])
        ended = stamp()
        for i in items:                       # item yang belum dicek dianggap tidak ada
            if not i.get("dicek"):
                i["status"] = "Tidak Ditemukan"
                i["kondisi"] = "Tidak Ditemukan"
                i["dicek"] = True
                i["counted_at"] = ended
                i["counted_by"] = "Otomatis"
        summary = opname_summary(items)       # ringkasan dihitung SETELAH semua item final
        updated = write_back_opname(conn, user, sid, items)
        sess["status"] = "Selesai"
        sess["ended_at"] = ended
        sess["summary"] = summary
        save_session(conn, sess, seq)
        add_log(conn, user, "Stock Opname", "-",
                f"Akhir sesi opname {sid}: {summary['dicek']}/{summary['total']} item dicek, "
                f"{summary['ditemukan']} ditemukan, {summary['tidak_ditemukan']} tidak ditemukan, "
                f"{summary['berubah']} data berubah, {updated} aset diperbarui")
        conn.commit()


def api_cancel_opname(conn, user, sid, body):
    with _LOCK:
        sess, seq = fetch_session(conn, sid)
        assert_session_in_scope(user, sess)
        if sess.get("status") in ("Selesai", "Dibatalkan"):
            raise ApiError(409, "Sesi yang sudah diakhiri tidak bisa dibatalkan.", "SESSION_STATE")
        sess["status"] = "Dibatalkan"
        sess["ended_at"] = stamp()
        save_session(conn, sess, seq)
        add_log(conn, user, "Stock Opname", "-",
                f"Batalkan sesi opname {sid} ({sess.get('nama')})")
        conn.commit()


# ---------------------------------------------------------------- mutasi handlers
def api_create_mutation(conn, user, body):
    kode = str(body.get("kode") or "").strip().upper()
    ke = str(body.get("ke") or "").strip()
    alasan = str(body.get("alasan") or "-") or "-"
    divisi = str(body.get("divisi") or "").strip()
    pic = str(body.get("pic") or "").strip()
    if not ke:
        raise ApiError(400, "Lokasi tujuan wajib diisi.")
    with _LOCK:
        asset = load_asset(conn, kode)
        assert_asset_in_scope(user, asset)
        mid = next_mut_id(conn)
        rec = {"id": mid, "kode": kode, "tgl": today(), "dari": asset.get("lokasi"), "ke": ke,
               "alasan": alasan, "status": "Pending",
               "user": user.get("nama") or user.get("username"),
               "cabang": asset.get("cabang"),
               "divisi": divisi, "pic": pic}
        seq = next_seq(conn, "mutations")
        conn.execute("INSERT INTO mutations(id, seq, data) VALUES (?,?,?)",
                     (mid, seq, json.dumps(rec, ensure_ascii=False)))
        append_riwayat(asset, "Mutasi",
                       f"Pengajuan {asset.get('lokasi')} → {ke} ({mid})", user)
        save_asset(conn, asset)
        add_log(conn, user, "Mutasi", kode, f"Mengajukan mutasi {mid} ke {ke}")
        conn.commit()
    return {"id": mid}


def api_decide_mutation(conn, user, mid, body):
    result = body.get("status")
    if result not in ("Disetujui", "Ditolak"):
        raise ApiError(400, "Status keputusan tidak valid.")
    with _LOCK:
        row = conn.execute("SELECT data FROM mutations WHERE id=?", (mid,)).fetchone()
        if not row:
            raise ApiError(404, f"Mutasi {mid} tidak ditemukan.")
        rec = json.loads(row["data"])
        if rec.get("status") != "Pending":
            raise ApiError(400, f"Mutasi {mid} sudah diproses ({rec.get('status')}).")
        rec["status"] = result
        conn.execute("UPDATE mutations SET data=? WHERE id=?",
                     (json.dumps(rec, ensure_ascii=False), mid))
        try:
            asset = load_asset(conn, rec["kode"])
            if result == "Disetujui":
                asset["lokasi"] = rec["ke"]
                extra = []
                if str(rec.get("divisi") or ""):
                    extra.append(f"divisi {asset.get('divisi') or '-'} → {rec['divisi']}")
                    asset["divisi"] = rec["divisi"]
                if str(rec.get("pic") or ""):
                    extra.append(f"PIC {asset.get('pic') or '-'} → {rec['pic']}")
                    asset["pic"] = rec["pic"]
                if rec["ke"] in CABANG_LIST and rec["ke"] != asset.get("cabang"):
                    # mutasi antar cabang: aset berpindah ke database cabang tujuan
                    lama = asset.get("cabang") or "-"
                    asset["cabang"] = rec["ke"]
                    keterangan = f"Antar cabang: {lama} → {rec['ke']} ({rec['dari']} → {rec['ke']}, disetujui)"
                else:
                    keterangan = f"Dari {rec['dari']} ke {rec['ke']} (disetujui)"
                if extra:
                    keterangan += "; " + ", ".join(extra)
            else:
                keterangan = f"Pengajuan ke {rec['ke']} — ditolak"
            append_riwayat(asset, "Mutasi", keterangan, user)
            save_asset(conn, asset)
        except ApiError:
            pass
        add_log(conn, user, "Mutasi", rec["kode"],
                f"{mid} {result.lower()} ({rec['dari']} → {rec['ke']})")
        conn.commit()


# ---------------------------------------------------------------- misc handlers
def api_upload(conn, user, body):
    data = str(body.get("data") or "")
    kind = body.get("kind") or "dokumen"
    name = os.path.basename(str(body.get("name") or "file"))
    m = re.match(r"^data:(image/(?:png|jpe?g|webp|gif)|application/pdf);base64,(.+)$", data, re.S)
    if not m:
        raise ApiError(400, "Format file tidak didukung (hanya JPG/PNG/WEBP/GIF/PDF).")
    mime, b64 = m.group(1), m.group(2)
    try:
        raw = base64.b64decode(b64)
    except Exception:
        raise ApiError(400, "Data file tidak valid (base64 rusak).")
    limit = MAX_PHOTO if kind == "foto" else MAX_DOC
    if len(raw) > limit:
        raise ApiError(400, f"Ukuran file melebihi batas {limit // (1024 * 1024)}MB.")
    ext = mimetypes.guess_extension(mime) or ".bin"
    if ext == ".jpe":
        ext = ".jpg"
    os.makedirs(UPLOAD_DIR, exist_ok=True)
    fname = f"{uuid_hex()}{ext}"
    with open(os.path.join(UPLOAD_DIR, fname), "wb") as fh:
        fh.write(raw)
    return {"ok": True, "url": "/uploads/" + fname, "nama": name, "size": len(raw)}


def uuid_hex():
    return secrets.token_hex(8) + int(time.time() * 1000).__str__()[-6:]


def api_settings(conn, user, body):
    if not isinstance(body, dict):
        raise ApiError(400, "Payload pengaturan tidak valid.")
    with _LOCK:
        current = get_settings(conn)
        for k in ("perusahaan", "tagline", "cabang"):
            if k in body:
                current[k] = str(body[k])
        put_settings(conn, current)
        add_log(conn, user, "Pengaturan", "-", "Memperbarui pengaturan aplikasi")
        conn.commit()


def api_list(conn, user, key, body, delete=False):
    if key not in LIST_KEYS:
        raise ApiError(404, f"Jenis master data '{key}' tidak dikenal.")
    value = str(body.get("value") or "").strip()
    if not value:
        raise ApiError(400, "Nilai tidak boleh kosong.")
    with _LOCK:
        if delete:
            used = {"kategori": "kategori", "divisi": "divisi", "lokasi": "lokasi",
                    "sumberDana": "sumberDana", "pic": "pic"}[key]
            for r in conn.execute("SELECT data FROM assets"):
                if json.loads(r["data"]).get(used) == value:
                    raise ApiError(400, "Data masih digunakan inventaris dan tidak bisa dihapus.")
            conn.execute("DELETE FROM lists WHERE key=? AND value=?", (key, value))
            add_log(conn, user, "Master Data", "-", f"Menghapus {key}: {value}")
        else:
            try:
                conn.execute("INSERT INTO lists(key, value) VALUES (?,?)", (key, value))
            except sqlite3.IntegrityError:
                raise ApiError(400, "Data sudah ada.")
            add_log(conn, user, "Master Data", "-", f"Menambah {key}: {value}")
        conn.commit()


def api_logs(conn, user, body):
    with _LOCK:
        add_log(conn, user, str(body.get("aksi") or "Aktivitas"),
                str(body.get("kode") or "-"), str(body.get("keterangan") or "-"))
        conn.commit()


def api_import(conn, user, body):
    """Impor aset lama (migrasi dari versi localStorage). Admin only."""
    assets = body.get("assets")
    if not isinstance(assets, list):
        raise ApiError(400, "Payload import tidak valid.")
    imported, skipped = 0, 0
    with _LOCK:
        seq = next_seq(conn, "assets")
        for a in assets:
            if not isinstance(a, dict) or not a.get("kode"):
                skipped += 1
                continue
            if fetch_asset_row(conn, a["kode"]):
                skipped += 1
                continue
            a.setdefault("cabang", get_settings(conn).get("cabang", CABANG_LIST[0]))
            a.setdefault("riwayat", [])
            a.setdefault("opname", [])
            a.setdefault("perbaikan", [])
            a.setdefault("dokumen", [])
            a.setdefault("foto", "")
            save_asset(conn, a, seq)
            seq -= 1
            imported += 1
        if imported:
            add_log(conn, user, "Import", "-", f"Import {imported} aset lama dari browser")
        conn.commit()
    return {"imported": imported, "skipped": skipped}


# ---------------------------------------------------------------- permission map
def perm_for(method, path):
    if path.startswith("/api/auth/"):
        return None
    if path.startswith("/api/users"):
        return "users.manage"
    if path == "/api/admin/reset":
        return "settings.write"
    if path == "/api/admin/import":
        return "users.manage"
    if path in ("/api/db", "/api/upload", "/api/logs"):
        return None
    if path.startswith("/api/lists"):
        return None if method == "GET" else "master.write"
    if path == "/api/settings":
        return None if method == "GET" else "settings.write"
    if path.startswith("/api/mutations"):
        return "mutation.approve" if method == "PUT" else "mutation.create"
    if path.startswith("/api/opname-sessions"):
        # data sesi dibaca lewat /api/db; semua endpoint tulis butuh opname.write
        return "opname.write"
    if path.startswith("/api/assets"):
        if "/docs" in path:
            return "asset.update" if method in ("POST", "DELETE") else "asset.view"
        return "asset.create" if method == "POST" else "asset.update"
    return None


# ---------------------------------------------------------------- http
class Handler(BaseHTTPRequestHandler):
    server_version = "TrioInventory/2.0"
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        try:
            line = fmt % args if args else str(fmt)
        except Exception:
            line = str(fmt)
        if "/api/" in line:
            BaseHTTPRequestHandler.log_message(self, fmt, *args)

    def handle_one_request(self):
        try:
            super().handle_one_request()
        except (ConnectionResetError, BrokenPipeError, TimeoutError):
            self.close_connection = True

    # -- helpers ------------------------------------------------------
    def _send_json(self, obj, status=200, extra_headers=None):
        payload = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        for k, v in (extra_headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(payload)

    def _read_body(self):
        length = int(self.headers.get("Content-Length") or 0)
        if length > MAX_BODY:
            raise ApiError(413, "Payload terlalu besar.")
        if length == 0:
            return {}
        raw = self.rfile.read(length)
        try:
            body = json.loads(raw.decode("utf-8"))
        except Exception:
            raise ApiError(400, "Body harus JSON yang valid.")
        if not isinstance(body, dict):
            raise ApiError(400, "Body harus berupa objek JSON.")
        return body

    def _serve_file(self, full, cache=False):
        ctype = mimetypes.guess_type(full)[0] or "application/octet-stream"
        if full.endswith(".svg"):
            ctype = "image/svg+xml"
        with open(full, "rb") as fh:
            data = fh.read()
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "public, max-age=86400" if cache else "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(data)

    def _serve_static(self, path):
        if path.startswith("/uploads/"):
            rel = unquote(path[len("/uploads/"):]).lstrip("/")
            base = os.path.abspath(UPLOAD_DIR)
            full = os.path.abspath(os.path.join(base, *rel.split("/")))
            if not full.startswith(base + os.sep) or not os.path.isfile(full):
                return self.send_error(404, "Not Found")
            return self._serve_file(full, cache=True)

        if path in ("/", "/index.html"):
            rel = "index.html"
        else:
            clean = unquote(path).lstrip("/")
            if not any(clean.startswith(p) for p in ("css/", "js/", "assets/", "lib/")):
                return self.send_error(404, "Not Found")
            rel = clean
        base = os.path.abspath(ROOT)
        full = os.path.abspath(os.path.join(base, *rel.split("/")))
        if not full.startswith(base + os.sep):
            return self.send_error(403, "Forbidden")
        if not os.path.isfile(full):
            return self.send_error(404, "Not Found")
        # .js/.css/.html TIDAK boleh di-cache browser — versi app harus selalu terbaru
        self._serve_file(full, cache=full.endswith((".png", ".jpg", ".jpeg", ".gif", ".webp", ".ico", ".svg")))

    # -- api ----------------------------------------------------------
    def _db_state_response(self, conn, user, status=200, extra=None):
        out = {"ok": True, "db": build_state(conn, user), "user": public_user(
            conn.execute("SELECT * FROM users WHERE id=?", (user["id"],)).fetchone())}
        out.update(extra or {})
        return self._send_json(out, status)

    def _dispatch_api(self, method, path, body, headers):
        conn = connect()
        try:
            # --- autentikasi ---
            if path == "/api/auth/login" and method == "POST":
                token, profile = auth_login(conn, body)
                cookie = (f"{SESSION_COOKIE}={token}; Path=/; HttpOnly; SameSite=Lax; "
                          f"Max-Age={SESSION_TTL}")
                return self._send_json({"ok": True, "user": profile},
                                       extra_headers={"Set-Cookie": cookie})

            token, user = current_session_user(conn, headers)

            if path == "/api/auth/logout" and method == "POST":
                auth_logout(conn, token)
                cookie = f"{SESSION_COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0"
                return self._send_json({"ok": True},
                                       extra_headers={"Set-Cookie": cookie})

            if path == "/api/auth/me" and method == "GET":
                if not user:
                    seeded = conn.execute("SELECT v FROM meta WHERE k='seeded'").fetchone()
                    return self._send_json({"ok": True, "authenticated": False,
                                            "seeded": bool(seeded),
                                            "demoAccounts": demo_hints(conn)})
                return self._send_json({"ok": True, "authenticated": True,
                                        "user": public_user(
                                            conn.execute("SELECT * FROM users WHERE id=?",
                                                         (user["id"],)).fetchone())})

            if not user:
                raise ApiError(401, "Belum login atau sesi berakhir. Silakan login ulang.",
                               "UNAUTHENTICATED")

            if path == "/api/auth/password" and method == "PUT":
                auth_change_password(conn, user, body)
                return self._send_json({"ok": True})

            # --- izin (RBAC, ditegakkan server-side) ---
            need = perm_for(method, path)
            if need and need not in ROLE_PERMS.get(user["role"], []):
                raise ApiError(403, f"Role {ROLE_LABEL.get(user['role'])} tidak memiliki akses: {need}",
                               "FORBIDDEN")

            # --- endpoint ---
            if path == "/api/db" and method == "GET":
                return self._db_state_response(conn, user)

            if path == "/api/upload" and method == "POST":
                return self._send_json(api_upload(conn, user, body))

            if path == "/api/assets" and method == "POST":
                res = api_create_asset(conn, user, body)
                return self._db_state_response(conn, user, 201, {"kode": res["kode"]})

            m = re.match(r"^/api/assets/([^/]+)$", path)
            if m and method == "PUT":
                api_update_asset(conn, user, unquote(m.group(1)), body)
                return self._db_state_response(conn, user)

            if path == "/api/opname-sessions" and method == "POST":
                res = api_create_opname(conn, user, body)
                return self._db_state_response(conn, user, 201, {"id": res["id"]})

            m = re.match(r"^/api/opname-sessions/([^/]+)/start$", path)
            if m and method == "POST":
                api_start_opname(conn, user, unquote(m.group(1)), body)
                return self._db_state_response(conn, user)

            m = re.match(r"^/api/opname-sessions/([^/]+)/end$", path)
            if m and method == "POST":
                api_end_opname(conn, user, unquote(m.group(1)), body)
                return self._db_state_response(conn, user)

            m = re.match(r"^/api/opname-sessions/([^/]+)/cancel$", path)
            if m and method == "POST":
                api_cancel_opname(conn, user, unquote(m.group(1)), body)
                return self._db_state_response(conn, user)

            m = re.match(r"^/api/opname-sessions/([^/]+)/items/([^/]+)$", path)
            if m and method == "PUT":
                api_count_opname(conn, user, unquote(m.group(1)), unquote(m.group(2)), body)
                return self._db_state_response(conn, user)

            m = re.match(r"^/api/assets/([^/]+)/docs$", path)
            if m and method == "POST":
                api_asset_docs(conn, user, unquote(m.group(1)), body)
                return self._db_state_response(conn, user)

            m = re.match(r"^/api/assets/([^/]+)/docs/(\d+)$", path)
            if m and method == "DELETE":
                api_asset_docs(conn, user, unquote(m.group(1)), {}, index=int(m.group(2)))
                return self._db_state_response(conn, user)

            if path == "/api/mutations" and method == "POST":
                res = api_create_mutation(conn, user, body)
                return self._db_state_response(conn, user, 201, {"id": res["id"]})

            m = re.match(r"^/api/mutations/([^/]+)$", path)
            if m and method == "PUT":
                api_decide_mutation(conn, user, unquote(m.group(1)), body)
                return self._db_state_response(conn, user)

            if path == "/api/settings" and method == "PUT":
                api_settings(conn, user, body)
                return self._db_state_response(conn, user)

            m = re.match(r"^/api/lists/([^/]+)$", path)
            if m and method in ("POST", "DELETE"):
                api_list(conn, user, unquote(m.group(1)), body, delete=(method == "DELETE"))
                return self._db_state_response(conn, user)
            if m and method == "GET":
                return self._db_state_response(conn, user)

            if path == "/api/logs" and method == "POST":
                api_logs(conn, user, body)
                return self._db_state_response(conn, user)

            # --- user management ---
            if path == "/api/users" and method == "GET":
                return self._send_json({"ok": True, "users": users_list(conn)})
            if path == "/api/users" and method == "POST":
                users_create(conn, user, body)
                return self._send_json({"ok": True, "users": users_list(conn)})
            m = re.match(r"^/api/users/(\d+)$", path)
            if m and method == "PUT":
                users_update(conn, user, int(m.group(1)), body)
                return self._send_json({"ok": True, "users": users_list(conn)})
            if m and method == "DELETE":
                users_delete(conn, user, int(m.group(1)))
                return self._send_json({"ok": True, "users": users_list(conn)})

            # --- admin ---
            if path == "/api/admin/reset" and method == "POST":
                seed_demo(conn)
                return self._db_state_response(conn, user)
            if path == "/api/admin/import" and method == "POST":
                res = api_import(conn, user, body)
                return self._db_state_response(conn, user, extra=res)

            return self._send_json({"ok": False, "error": f"Endpoint tidak ditemukan: {method} {path}"}, 404)
        except ApiError as e:
            conn.rollback()
            return self._send_json({"ok": False, "error": str(e), "code": e.code}, e.status)
        except Exception as e:  # pragma: no cover
            conn.rollback()
            import traceback
            traceback.print_exc()
            return self._send_json({"ok": False, "error": f"Kesalahan server: {e}"}, 500)
        finally:
            conn.close()

    # -- verbs --------------------------------------------------------
    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path.startswith("/api/"):
            return self._dispatch_api("GET", parsed.path, {}, self.headers)
        return self._serve_static(parsed.path)

    def do_POST(self):
        parsed = urlparse(self.path)
        if parsed.path.startswith("/api/"):
            return self._dispatch_api("POST", parsed.path, self._read_body(), self.headers)
        return self.send_error(404, "Not Found")

    def do_PUT(self):
        parsed = urlparse(self.path)
        if parsed.path.startswith("/api/"):
            return self._dispatch_api("PUT", parsed.path, self._read_body(), self.headers)
        return self.send_error(404, "Not Found")

    def do_DELETE(self):
        parsed = urlparse(self.path)
        if parsed.path.startswith("/api/"):
            return self._dispatch_api("DELETE", parsed.path, self._read_body(), self.headers)
        return self.send_error(404, "Not Found")

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Allow", "GET, POST, PUT, DELETE, OPTIONS")
        self.send_header("Content-Length", "0")
        self.end_headers()


def main():
    global DATA_DIR, UPLOAD_DIR, DB_PATH
    ap = argparse.ArgumentParser(description="TRIO Inventory Control backend")
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8000)
    ap.add_argument("--data-dir", default=None)
    ap.add_argument("--upload-dir", default=None)
    args = ap.parse_args()

    if args.data_dir:
        DATA_DIR = os.path.abspath(args.data_dir)
        DB_PATH = os.path.join(DATA_DIR, "trio.db")
    if args.upload_dir:
        UPLOAD_DIR = os.path.abspath(args.upload_dir)

    os.makedirs(UPLOAD_DIR, exist_ok=True)
    init_db()

    server = ThreadingHTTPServer((args.host, args.port), Handler)
    server.daemon_threads = True
    print("=" * 64)
    print("  TRIO INVENTORY CONTROL — backend + auth berjalan")
    print(f"  URL     : http://{'localhost' if args.host == '127.0.0.1' else args.host}:{args.port}")
    print(f"  Database: {DB_PATH}")
    print(f"  Uploads : {UPLOAD_DIR}")
    print("  Akun demo (SEGERA GANTI password-nya):")
    print("    admin   / admin123   -> Administrator (semua akses)")
    print("    auditor / audit123   -> Auditor (semua kecuali konfigurasi user)")
    print("    cabang  / cabang123  -> Manajemen Cabang TM Buntok (tambah + mutasi)")
    print("  Tekan Ctrl+C untuk berhenti")
    print("=" * 64)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nBerhenti.")
        server.server_close()


if __name__ == "__main__":
    main()
