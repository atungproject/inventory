/* ==========================================================
   TRIO Inventory Control — app.js
   SPA (hash router) + REST backend (server.py / SQLite)
   ========================================================== */
(function () {
  'use strict';

  /* ========================================================
     0. UTIL
     ======================================================== */
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const KEY = 'trio_inventory_v1';          // cache fallback bila backend mati
  const PLACEHOLDER = 'assets/placeholder.svg';

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const num = (n) => Number(n || 0).toLocaleString('id-ID');
  const rupiah = (n) => 'Rp ' + num(n);
  const isoToday = () => new Date().toISOString().slice(0, 10);

  function fmtDate(iso, sep = '-') {
    if (!iso) return '-';
    const p = String(iso).split('-');
    if (p.length !== 3) return iso;
    return [p[2], p[1], p[0]].join(sep);
  }
  const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus',
    'September', 'Oktober', 'November', 'Desember'];
  function longDate(d = new Date()) {
    return `${HARI[d.getDay()]}, ${d.getDate()} ${BULAN[d.getMonth()]} ${d.getFullYear()}`;
  }
  function nowStamp() {
    const d = new Date();
    const p = (x) => String(x).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
  }

  function toast(msg, type = 'info') {
    const zone = $('#toastZone');
    if (!zone) return;
    const el = document.createElement('div');
    const ico = type === 'success' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info';
    el.className = 'toast ' + type;
    el.innerHTML = `<i class="fa-solid ${ico}"></i><div>${esc(msg)}</div>`;
    zone.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = '.3s'; setTimeout(() => el.remove(), 320); }, 3600);
  }
  const fail = (e) => toast((e && e.message) || 'Terjadi kesalahan.', 'error');

  function readAsDataURL(file) {
    return new Promise((resolve, reject) => {
      const fr = new FileReader();
      fr.onload = () => resolve(fr.result);
      fr.onerror = () => reject(new Error('Gagal membaca file.'));
      fr.readAsDataURL(file);
    });
  }

  /* ========================================================
     1. SEED DATA (dipakai saat database backend masih kosong)
     ======================================================== */
  const KONDISI = ['Baik', 'Rusak Ringan', 'Rusak Berat', 'Tidak Ditemukan', 'Tidak Layak'];
  const KONDISI_COLOR = {
    'Baik': '#22c55e', 'Rusak Ringan': '#f59e0b', 'Rusak Berat': '#ef4444',
    'Tidak Ditemukan': '#b91c1c', 'Tidak Layak': '#94a3b8'
  };
  const KONDISI_BADGE = {
    'Baik': 'b-green', 'Rusak Ringan': 'b-orange', 'Rusak Berat': 'b-red',
    'Tidak Ditemukan': 'b-red', 'Tidak Layak': 'b-gray'
  };

  /* ========================================================
     2. API LAYER (backend server.py + SQLite)
     ======================================================== */
  let DB = null;
  let USER = null;
  let OFFLINE = false;

  const can = (perm) => !!(USER && Array.isArray(USER.perms) && USER.perms.includes(perm));

  async function api(method, path, body) {
    let res;
    try {
      res = await fetch('/api' + path, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body)
      });
    } catch (e) {
      OFFLINE = true;
      throw new Error('Server backend tidak terjangkau. Jalankan aplikasi dengan: python server.py');
    }
    let data = null;
    try { data = await res.json(); }
    catch (e) { throw new Error('Respons server tidak valid (status ' + res.status + ').'); }
    if (!res.ok || data.ok === false) {
      OFFLINE = false;
      if (res.status === 401) showLogin((data && data.error) || 'Sesi berakhir. Silakan login kembali.');
      throw new Error((data && data.error) || ('Gagal memproses (status ' + res.status + ').'));
    }
    OFFLINE = false;
    return data;
  }

  function cacheDB() {
    try { localStorage.setItem(KEY, JSON.stringify(DB)); } catch (e) { /* cache penuh: abaikan */ }
  }
  function commit(res) {
    if (res && res.db) { DB = res.db; cacheDB(); }
    if (res && res.user) USER = res.user;
  }

  async function loadDB() {
    try {
      const r = await api('GET', '/db');
      DB = r.db;
      OFFLINE = false;
    } catch (e) {
      let cached = null;
      try {
        const raw = localStorage.getItem(KEY);
        if (raw) cached = JSON.parse(raw);
      } catch (err) { /* cache rusak */ }
      DB = (cached && cached.assets) ? cached : {
        settings: { perusahaan: 'TRIO MOTOR', tagline: 'Satu Hati, Bersama Anda', cabang: 'TM Buntok' },
        lists: { kategori: [], divisi: [], lokasi: [], sumberDana: [], pic: [] },
        assets: [], mutations: [], logs: []
      };
      OFFLINE = true;
      setTimeout(() => toast('Backend tidak terjangkau — menampilkan cache lokal. ' + e.message, 'error'), 400);
    }
    cacheDB();
  }

  /* ========================================================
     2b. AUTH (login / logout / sesi & hak akses)
     ======================================================== */
  const CABANG_OPTS = ['TM Buntok', 'TM Baru', 'TM Kuala Kapuas'];

  function showLogin(msg) {
    document.body.classList.add('login-mode');
    const root = $('#loginRoot');
    if (root) root.hidden = false;
    const err = $('#loginErr');
    if (err) { err.textContent = msg || ''; err.hidden = !msg; }
    stopScan();
  }

  function hideLogin() {
    document.body.classList.remove('login-mode');
    const root = $('#loginRoot');
    if (root) root.hidden = true;
  }

  async function doLogin(form) {
    const username = form.elements['username'].value.trim();
    const password = form.elements['password'].value;
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memeriksa...'; }
    try {
      const r = await api('POST', '/auth/login', { username, password });
      USER = r.user;
      state.users = null;
      form.reset();
      await loadDB();
      await maybeImportOldCache();
      location.hash = '#/dashboard';   // selalu mulai dari Dashboard setelah login
      enterApp();
      toast('Selamat datang, ' + USER.nama + '.', 'success');
    } catch (e) {
      showLogin(e.message);
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk'; }
    }
  }

  async function doLogout() {
    try { await api('POST', '/auth/logout'); } catch (e) { /* abaikan */ }
    USER = null;
    DB = null;
    showLogin('Anda telah keluar dari sistem.');
  }

  function enterApp() {
    hideLogin();
    state.cabangFilter = USER.role === 'cabang' ? USER.cabang : '*';
    if (DB && DB.settings && DB.settings.cabang) state.report.cabang = DB.settings.cabang;
    renderNav();
    syncChrome();
    if (!location.hash || location.hash === '#/') location.hash = '#/dashboard';
    router();
    syncChrome();
  }

  // Migrasi data lama dari versi localStorage (khusus admin, sekali per sesi)
  async function maybeImportOldCache() {
    if (!USER || USER.role !== 'admin') return;
    if (sessionStorage.getItem('trio_import_checked')) return;
    sessionStorage.setItem('trio_import_checked', '1');
    let cached = null;
    try { cached = JSON.parse(localStorage.getItem(KEY)); } catch (e) { return; }
    if (!cached || !Array.isArray(cached.assets)) return;
    const have = new Set(DB.assets.map((a) => a.kode));
    const missing = cached.assets.filter((a) => a && a.kode && !have.has(a.kode));
    if (!missing.length) return;
    if (confirm('Ditemukan ' + missing.length + ' data inventaris lama di browser ini. Gabungkan ke database server?')) {
      try {
        const r = await api('POST', '/admin/import', { assets: missing });
        toast('Berhasil mengimpor ' + r.imported + ' data inventaris lama.', 'success');
        await loadDB();
        syncChrome();
        router();
      } catch (e) { fail(e); }
    }
  }

  const getAsset = (kode) => (DB ? DB.assets.find((a) => a.kode === kode) : null);

  async function logEvent(aksi, kode, keterangan) {
    try { commit(await api('POST', '/logs', { aksi, kode, keterangan })); }
    catch (e) {
      if (DB) {
        DB.logs.unshift({ stamp: nowStamp(), user: USER ? USER.nama : 'System', aksi, kode, keterangan });
        cacheDB();
      }
    }
  }

  async function uploadFotoSrc(src) {
    if (!src) return '';
    if (src.startsWith('data:')) {
      const r = await api('POST', '/upload', { kind: 'foto', name: 'foto-inventaris', data: src });
      return r.url;
    }
    return src;
  }
  async function resolveDokumen(list) {
    const out = [];
    for (const d of (list || [])) {
      if (d.url) out.push({ nama: d.nama, url: d.url });
      else if (d.data) {
        const r = await api('POST', '/upload', { kind: 'dokumen', name: d.nama, data: d.data });
        out.push({ nama: d.nama, url: r.url });
      }
    }
    return out;
  }

  /* ========================================================
     3. RENDER HELPERS
     ======================================================== */
  const badgeKondisi = (k) => `<span class="badge ${KONDISI_BADGE[k] || 'b-gray'}">${esc(k)}</span>`;
  const badgeStatus = (s) => s === 'Aktif' ? '<span class="badge b-green">Aktif</span>' : '<span class="badge b-gray">' + esc(s) + '</span>';
  const badgeMutasi = (s) => s === 'Pending' ? '<span class="badge b-orange">Pending</span>'
    : s === 'Disetujui' ? '<span class="badge b-green">Disetujui</span>' : '<span class="badge b-red">Ditolak</span>';

  function sel(options, value, placeholder) {
    return `${placeholder ? `<option value="">${esc(placeholder)}</option>` : ''}` +
      options.map((o) => `<option value="${esc(o)}" ${String(o) === String(value) ? 'selected' : ''}>${esc(o)}</option>`).join('');
  }

  // Pastikan nilai aset selalu punya <option> agar select terkunci tetap menampilkan data asli.
  function keepOpt(options, value) {
    return (value && !options.includes(value)) ? [value, ...options] : options;
  }

  function cabangOptions() {
    const set = new Set(CABANG_OPTS);
    (DB ? DB.assets : []).forEach((a) => { if (a.cabang) set.add(a.cabang); });
    if (DB && DB.settings && DB.settings.cabang) set.add(DB.settings.cabang);
    return Array.from(set);
  }

  const PREFIX_KNOWN = { 'TM Buntok': 'BTK', 'TM Baru': 'BRU', 'TM Kuala Kapuas': 'KKP' };

  function cabangPrefix(cabang) {
    const c = String(cabang || '').trim();
    if (PREFIX_KNOWN[c]) return PREFIX_KNOWN[c];
    const words = c.split(/[^A-Za-z0-9]+/).filter((w) => w && w.toUpperCase() !== 'TM');
    if (!words.length) return 'GEN';
    const ini = words.map((w) => w[0]).join('').toUpperCase();
    return ini.length >= 2 ? ini.slice(0, 4) : words[0].slice(0, 3).toUpperCase();
  }

  // Preview kode aset berikutnya untuk cabang terpilih (server tetap sumber kebenaran)
  function nextKodePreview(cabang) {
    const pfx = cabangPrefix(cabang);
    let mx = 0;
    (DB ? DB.assets : []).forEach((a) => {
      const m = String(a.kode || '').match(new RegExp('^INV-' + pfx + '-(\\d+)$'));
      if (m) mx = Math.max(mx, parseInt(m[1], 10));
    });
    return 'INV-' + pfx + '-' + String(mx + 1).padStart(4, '0');
  }

  function updateKodeHint() {
    const el = $('#kodeHint');
    if (!el || !DB) return;
    const form = el.closest('form');
    const cab = USER.role === 'cabang' ? USER.cabang
      : ((form && form.elements['cabang'] && form.elements['cabang'].value)
        || DB.settings.cabang || CABANG_OPTS[0]);
    el.innerHTML = '<i class="fa-solid fa-barcode"></i> Kode aset otomatis: <b>' + esc(nextKodePreview(cab)) +
      '</b> <span class="muted">— urutan kode terpisah untuk tiap cabang</span>';
  }

  function visibleAssets() {
    if (!DB) return [];
    const f = state.cabangFilter;
    if (!f || f === '*') return DB.assets;
    return DB.assets.filter((a) => (a.cabang || '') === f);
  }

  function attentionData() {
    const vis = visibleAssets();
    const codes = new Set(vis.map((a) => a.kode));
    const notFound = vis.filter((a) => a.kondisi === 'Tidak Ditemukan').length;
    const rusak = vis.filter((a) => a.kondisi === 'Rusak Ringan' || a.kondisi === 'Rusak Berat').length;
    const pending = DB.mutations.filter((m) => m.status === 'Pending' && codes.has(m.kode)).length;
    return { notFound, rusak, pending, total: notFound + rusak + pending };
  }

  function qrDataUrl(text, size = 220) {
    try {
      if (window.QRCode) {
        const box = document.createElement('div');
        box.style.cssText = 'position:absolute;left:-9999px;top:-9999px';
        document.body.appendChild(box);
        new QRCode(box, { text, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
        const img = box.querySelector('img');
        const canvas = box.querySelector('canvas');
        let url = img && img.src ? img.src : (canvas ? canvas.toDataURL('image/png') : null);
        box.remove();
        if (url) return url;
      }
    } catch (e) { /* fallthrough */ }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&data=' + encodeURIComponent(text);
  }

  function printWindow(html) {
    const w = window.open('', '_blank', 'width=860,height=920');
    if (!w) { toast('Popup diblokir browser. Izinkan popup untuk mencetak.', 'error'); return; }
    w.document.write(`<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>TRIO Inventory</title>
      <style>
        body{font-family:Inter,Arial,sans-serif;color:#1f2937;padding:26px;font-size:13px}
        h1{font-size:19px;margin:0 0 4px} h2{font-size:15px;margin:18px 0 8px}
        .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #e1251b;padding-bottom:10px;margin-bottom:16px}
        .brand{color:#e1251b;font-weight:800;letter-spacing:.5px}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th,td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left}
        th{background:#f1f5f9;font-size:11px}
        .qr{width:220px;height:220px;margin:14px auto;display:block}
        .center{text-align:center}
        .muted{color:#64748b;font-size:11px}
        @media print{ body{padding:0} }
      </style></head><body>${html}
      <script>window.onload=function(){setTimeout(function(){window.print()},350)}<\/script>
      </body></html>`);
    w.document.close();
  }

  /* ========================================================
     4. MODAL
     ======================================================== */
  function showModal(title, bodyHtml, onSubmit) {
    const ov = document.createElement('div');
    ov.className = 'modal-overlay';
    ov.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-head">
          <h3>${esc(title)}</h3>
          <button type="button" class="icon-btn" data-close aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form class="modal-body">
          ${bodyHtml}
          <div class="modal-actions">
            <button type="button" class="btn btn-soft" data-close>Batal</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(ov);
    const close = () => {
      ov.remove();
      // beri tahu pemanggil (layar opname memakai event ini utk fokus ulang input scan)
      try { document.dispatchEvent(new CustomEvent('trio:modal-close')); } catch (e) { /* ignore */ }
    };
    ov.addEventListener('click', (e) => {
      if (e.target === ov || e.target.closest('[data-close]')) close();
    });
    ov.querySelector('form').addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const ok = await onSubmit(new FormData(e.target), e.target);
        if (ok !== false) close();
      } catch (err) { fail(err); }
    });
    const first = ov.querySelector('select,input,textarea');
    if (first) first.focus();
    return close;
  }

  // KonfirmasiYa/Tidak (menggantikan confirm() bawaan browser)
  function confirmDialog(message, okText) {
    return new Promise((resolve) => {
      const ov = document.createElement('div');
      ov.className = 'modal-overlay';
      ov.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true">
          <div class="modal-head">
            <h3>Konfirmasi</h3>
            <button type="button" class="icon-btn" data-close aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="modal-body">
            <p class="confirm-msg">${esc(message)}</p>
            <div class="modal-actions">
              <button type="button" class="btn btn-soft" data-close>Batal</button>
              <button type="button" class="btn btn-danger" data-ok>${esc(okText || 'Ya, Lanjutkan')}</button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(ov);
      const done = (v) => { ov.remove(); resolve(v); };
      ov.addEventListener('click', (e) => {
        if (e.target.closest('[data-ok]')) done(true);
        else if (e.target === ov || e.target.closest('[data-close]')) done(false);
      });
      const okBtn = ov.querySelector('[data-ok]');
      if (okBtn) okBtn.focus();
    });
  }

  /* ========================================================
     5. STATE + ROUTER
     ======================================================== */
  const state = {
    route: '', prevRoute: '', lastBad: '', users: null,
    cabangFilter: '*',
    list: { search: '', kategori: '', divisi: '', kondisi: '', flag: '', page: 1, per: 10 },
    detailTab: 'riwayat',
    report: { cabang: 'TM Buntok', divisi: '', kategori: '', kondisi: '', tahun: '', from: '', to: '', page: 1, per: 10 },
    trans: { status: '' },
    draft: { foto: '', dokumen: [] },
    // modul Stock Opname (sesi): filter daftar + filter item + cakupan export
    opname: {
      search: '', cabang: '', status: '', from: '', to: '', page: 1, per: 10,
      q: '', itemStatus: '', kat: '', itemPage: 1, itemPer: 20,
      hit: '', scope: 'all', sid: ''
    }
  };

  const ROUTES = {
    dashboard: { nav: 'dashboard', render: viewDashboard },
    inventaris: { nav: 'inventaris', render: viewList },
    detail: { nav: 'inventaris', render: viewDetail },
    tambah: { nav: 'inventaris', render: viewForm, perm: 'asset.create' },
    edit: { nav: 'inventaris', render: viewForm, perm: 'asset.update' },
    scan: { nav: 'opname', render: viewOpname, perm: 'opname.write' },   // alias lama -> daftar sesi
    opname: { nav: 'opname', render: viewOpname, perm: 'opname.write' },
    laporan: { nav: 'laporan', render: viewReport, perm: 'report.view' },
    transaksi: { nav: 'transaksi', render: viewTransaksi, perm: 'mutation.view' },
    master: { nav: 'master', render: viewMaster, perm: 'master.view' },
    audit: { nav: 'audit', render: viewAudit, perm: 'audit.view' },
    pengaturan: { nav: 'pengaturan', render: viewSettings, perm: 'settings.view' },
    users: { nav: 'users', render: viewUsers, perm: 'users.manage' }
  };

  function renderNav() {
    $$('#nav .nav-item').forEach((a) => {
      const p = a.dataset.perm;
      a.hidden = !!(p && !can(p));
    });
  }

  function viewDenied() {
    return `
      <div class="card card-pad">
        <div class="empty">
          <i class="fa-solid fa-lock"></i>
          <b style="font-size:15px">Akses ditolak</b>
          <div class="small muted" style="margin-top:6px">
            Role ${esc(USER ? USER.roleLabel : '')} tidak memiliki hak akses ke halaman ini.
            Hubungi administrator bila Anda memerlukannya.
          </div><br>
          <a class="btn btn-primary" href="#/dashboard"><i class="fa-solid fa-gauge-high"></i> Kembali ke Dashboard</a>
        </div>
      </div>`;
  }

  async function router() {
    if (!USER || !DB) return;
    const hash = location.hash.replace(/^#\/?/, '');
    const parts = hash.split('/').filter(Boolean);
    const route = parts[0] || 'dashboard';
    // render ulang sesi opname yang sama -> kamera tidak boleh dimatikan
    const sameSession = route === 'opname' && state.route === 'opname' &&
      parts[1] && parts[1] === state.opname.sid;
    if (!sameSession) stopScan();
    const def = ROUTES[route] || ROUTES.dashboard;
    state.route = route;

    $$('#nav .nav-item').forEach((a) => a.classList.toggle('active', a.dataset.route === def.nav));

    // hak akses: role tanpa izin -> halaman akses ditolak
    if (def.perm && !can(def.perm)) {
      $('#view').innerHTML = viewDenied();
      closeSidebar();
      window.scrollTo({ top: 0 });
      return;
    }

    if (route === 'users' && !state.users) await loadUsers();

    // reset state tab/draft saat pindah halaman (bug: state menempel antar item)
    if (route === 'detail' && state.prevRoute !== 'detail') state.detailTab = 'riwayat';
    state.prevRoute = route;

    $('#view').innerHTML = def.render(parts.slice(1));
    closeSidebar();
    window.scrollTo({ top: 0 });
    if (route === 'opname' || route === 'scan') startOpnameScreen();
  }

  function go(path) {
    const target = '#/' + path;
    if (location.hash === target) router();   // hash sama -> paksa render ulang
    else location.hash = target;
  }

  /* ========================================================
     6. VIEW: DASHBOARD
     ======================================================== */
  function viewDashboard() {
    const assets = visibleAssets();
    const total = assets.length;
    const baik = assets.filter((a) => a.kondisi === 'Baik').length;
    const rusak = assets.filter((a) => a.kondisi === 'Rusak Ringan' || a.kondisi === 'Rusak Berat').length;
    const hilang = assets.filter((a) => a.kondisi === 'Tidak Ditemukan').length;
    const att = attentionData();

    const groups = [
      ['H1 - Penjualan', (d) => d === 'H1'],
      ['H2 - Servis', (d) => d === 'H2'],
      ['H3 - Sparepart', (d) => d === 'H3'],
      ['Finance', (d) => d === 'Finance'],
      ['OPR', (d) => d === 'OPR'],
      ['Lainnya', (d) => !['H1', 'H2', 'H3', 'Finance', 'OPR'].includes(d)]
    ].map(([label, fn]) => ({ label, count: assets.filter((a) => fn(a.divisi)).length }));
    const max = Math.max(1, ...groups.map((g) => g.count));

    const segs = KONDISI.map((k) => ({
      name: k, value: assets.filter((a) => a.kondisi === k).length, color: KONDISI_COLOR[k]
    }));
    const C = 2 * Math.PI * 70;
    let off = 0;
    const arcs = segs.filter((s) => s.value > 0).map((s) => {
      const len = total ? (s.value / total) * C : 0;
      const el = `<circle cx="95" cy="95" r="70" fill="none" stroke="${s.color}" stroke-width="26" stroke-dasharray="${len.toFixed(2)} ${(C - len).toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}"/>`;
      off += len;
      return el;
    }).join('');

    const stat = (ico, cls, val, label, attrs = '') => `
      <button type="button" class="stat stat-link" data-action="attGo" data-to="inventaris" ${attrs}>
        <div class="stat-ico ${cls}"><i class="fa-solid ${ico}"></i></div>
        <div><div class="stat-num">${num(val)}</div><div class="stat-label">${label}</div></div>
      </button>`;

    return `
      <div class="welcome">
        <div>
          <h1>Selamat Datang, ${esc(USER ? USER.nama : '')}</h1>
          <p>Berikut adalah ringkasan data inventaris ${esc(state.cabangFilter === '*' ? 'Seluruh Cabang' : state.cabangFilter)}.</p>
        </div>
        <div class="date">${longDate()}</div>
      </div>

      <div class="stats">
        ${stat('fa-boxes-stacked', 'i-blue', total, 'Total Inventaris', '')}
        ${stat('fa-circle-check', 'i-green', baik, 'Kondisi Baik', 'data-kondisi="Baik"')}
        ${stat('fa-screwdriver-wrench', 'i-orange', rusak, 'Rusak', 'data-flag="rusak"')}
        ${stat('fa-location-crosshairs', 'i-red', hilang, 'Tidak Ditemukan', 'data-flag="notfound"')}
      </div>

      <div class="grid-2">
        <section class="card card-pad">
          <h3 class="card-title">Inventaris per Divisi</h3>
          <p class="muted" style="font-size:12px;margin:-8px 0 14px">Jumlah aset per divisi dan kapasitas relatif terhadap divisi terbanyak.</p>
          <div class="bars">
            ${groups.map((g) => `
              <div class="bar-row">
                <div>${esc(g.label)}</div>
                <div class="bar-track"><div class="bar-fill" style="width:${Math.round((g.count / max) * 100)}%"></div></div>
                <div class="bar-val"><b>${g.count}</b></div>
              </div>`).join('')}
          </div>
        </section>

        <section class="card card-pad">
          <h3 class="card-title">Kondisi Inventaris</h3>
          <div class="donut-wrap">
            <div class="donut">
              <svg width="190" height="190" viewBox="0 0 190 190">
                <circle cx="95" cy="95" r="70" fill="none" stroke="#eef2f7" stroke-width="26"/>
                ${arcs}
              </svg>
              <div class="donut-center"><b>${num(total)}</b><span>Total</span></div>
            </div>
            <div class="donut-legend">
              ${segs.map((s) => `
                <div class="dl-row">
                  <span class="dl-dot" style="background:${s.color}"></span>
                  <span class="dl-name">${esc(s.name)}</span>
                  <span class="dl-val">${s.value} (${total ? Math.round((s.value / total) * 100) : 0}%)</span>
                </div>`).join('')}
            </div>
          </div>
        </section>
      </div>

      <div class="grid-2">
        <section class="attention">
          <h3><i class="fa-solid fa-triangle-exclamation"></i> Perlu Perhatian</h3>
          <button type="button" class="att-item" data-action="attGo" data-to="inventaris" data-flag="notfound" ${att.notFound ? '' : 'disabled'}>
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><b>${att.notFound} barang</b> belum ditemukan pada stock opname terakhir
              <span class="att-go">Lihat Inventaris &rarr;</span></div>
          </button>
          <button type="button" class="att-item" data-action="attGo" data-to="inventaris" data-flag="rusak" ${att.rusak ? '' : 'disabled'}>
            <i class="fa-solid fa-screwdriver-wrench"></i>
            <div><b>${att.rusak} barang rusak</b> yang belum diperbaiki
              <span class="att-go">Lihat Inventaris &rarr;</span></div>
          </button>
          <button type="button" class="att-item" data-action="attGo" data-to="transaksi" data-status="Pending" ${att.pending ? '' : 'disabled'}>
            <i class="fa-solid fa-right-left"></i>
            <div><b>${att.pending} mutasi</b> menunggu approval
              <span class="att-go">Lihat Transaksi &rarr;</span></div>
          </button>
        </section>

        <section class="quick">
          <h3><i class="fa-solid fa-bolt"></i> Aksi Cepat</h3>
          ${can('asset.create') ? `<button class="btn btn-block" data-action="nav" data-to="tambah"><i class="fa-solid fa-plus"></i> Tambah Inventaris</button>` : ''}
          <button class="btn btn-block" data-action="nav" data-to="transaksi"><i class="fa-solid fa-right-left"></i> Mutasi Barang</button>
          ${can('opname.write') ? `<button class="btn btn-block" data-action="nav" data-to="opname"><i class="fa-solid fa-clipboard-check"></i> Mulai Stock Opname</button>` : ''}
          ${can('report.view') ? `<button class="btn btn-block" data-action="nav" data-to="laporan"><i class="fa-solid fa-file-lines"></i> Lihat Laporan</button>` : ''}
        </section>
      </div>`;
  }

  /* ========================================================
     7. VIEW: DAFTAR INVENTARIS
     ======================================================== */
  function filteredAssets() {
    const f = state.list;
    const q = f.search.trim().toLowerCase();
    return visibleAssets().filter((a) =>
      (!q || [a.kode, a.nama, a.merek, a.serial, a.lokasi, a.pic].join(' ').toLowerCase().includes(q)) &&
      (!f.kategori || a.kategori === f.kategori) &&
      (!f.divisi || a.divisi === f.divisi) &&
      (!f.kondisi || a.kondisi === f.kondisi) &&
      (!f.flag || (f.flag === 'rusak'
        ? (a.kondisi === 'Rusak Ringan' || a.kondisi === 'Rusak Berat')
        : f.flag === 'notfound' ? a.kondisi === 'Tidak Ditemukan' : true))
    );
  }

  function pagerHtml(page, per, total, action) {
    const pages = Math.max(1, Math.ceil(total / per));
    page = Math.min(page, pages);
    let btns = '';
    let needEll = false;
    for (let i = 1; i <= pages; i++) {
      if (i === 1 || i === pages || Math.abs(i - page) <= 1) {
        if (needEll) { btns += `<span class="pg" style="border:none;background:none">…</span>`; needEll = false; }
        btns += `<button class="pg ${i === page ? 'active' : ''}" data-action="${action}" data-page="${i}">${i}</button>`;
      } else needEll = true;
    }
    return `<div class="pager">
        <button class="pg" data-action="${action}" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}><i class="fa-solid fa-chevron-left"></i></button>
        ${btns}
        <button class="pg" data-action="${action}" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}><i class="fa-solid fa-chevron-right"></i></button>
      </div>`;
  }

  function viewList() {
    const f = state.list;
    const rows = filteredAssets();
    const pages = Math.max(1, Math.ceil(rows.length / f.per));
    f.page = Math.min(f.page, pages);
    const start = (f.page - 1) * f.per;
    const slice = rows.slice(start, start + f.per);

    const body = slice.map((a, i) => `
      <tr>
        <td class="td-num">${start + i + 1}</td>
        <td><a class="link-id" href="#/detail/${a.kode}">${a.kode}</a></td>
        <td>${esc(a.nama)}</td>
        <td>${esc(a.kategori)}</td>
        <td>${esc(a.cabang || '-')}</td>
        <td>${esc(a.lokasi)}</td>
        <td>${esc(a.divisi)}</td>
        <td>${badgeKondisi(a.kondisi)}</td>
        <td>${badgeStatus(a.status)}</td>
        <td><div class="cell-actions">
          <button class="btn btn-sm btn-light" data-action="detail" data-kode="${a.kode}" title="Lihat"><i class="fa-regular fa-eye"></i></button>
          ${can('asset.update') ? `<button class="btn btn-sm btn-light" data-action="edit" data-kode="${a.kode}" title="Edit"><i class="fa-regular fa-pen-to-square"></i></button>` : ''}
        </div></td>
      </tr>`).join('');

    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Daftar Inventaris</h1>
          <p class="page-sub">Kelola data inventaris di cabang Anda.</p>
        </div>
        ${can('asset.create') ? `<button class="btn btn-primary" data-action="nav" data-to="tambah"><i class="fa-solid fa-plus"></i> Tambah Inventaris</button>` : ''}
      </div>

      <div class="filters">
        <select data-filter="kategori">${sel(DB.lists.kategori, f.kategori, 'Semua Kategori')}</select>
        <select data-filter="divisi">${sel(DB.lists.divisi, f.divisi, 'Semua Divisi')}</select>
        <select data-filter="kondisi">${sel(KONDISI, f.kondisi, 'Semua Kondisi')}</select>
        <select data-filter="flag" title="Filter perhatian">
          <option value="">Semua Perhatian</option>
          <option value="notfound" ${f.flag === 'notfound' ? 'selected' : ''}>Belum Ditemukan</option>
          <option value="rusak" ${f.flag === 'rusak' ? 'selected' : ''}>Rusak Belum Diperbaiki</option>
        </select>
        <div class="search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" data-filter="search" value="${esc(f.search)}" placeholder="Cari nama barang, ID, atau SN...">
        </div>
        <button class="btn btn-outline" data-action="resetFilter"><i class="fa-solid fa-rotate-left"></i> Reset Filter</button>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="tbl">
            <thead><tr>
              <th>No</th><th>ID Inventaris</th><th>Nama Barang</th><th>Kategori</th><th>Cabang</th><th>Lokasi</th>
              <th>Divisi</th><th>Kondisi</th><th>Status</th><th style="text-align:right">Aksi</th>
            </tr></thead>
            <tbody>${body || `<tr><td colspan="10"><div class="empty"><i class="fa-regular fa-folder-open"></i>Tidak ada data yang cocok dengan filter.</div></td></tr>`}</tbody>
          </table>
        </div>
        <div class="table-foot">
          <span>Menampilkan ${rows.length ? start + 1 : 0} - ${Math.min(start + f.per, rows.length)} dari ${rows.length} data</span>
          ${pagerHtml(f.page, f.per, rows.length, 'pageList')}
        </div>
      </div>`;
  }

  /* ========================================================
     8. VIEW: DETAIL INVENTARIS
     ======================================================== */
  // Pratinjau besar: klik gambar besar pada detail -> overlay layar penuh
  function openPhotoZoom(src) {
    const ov = document.createElement('div');
    ov.className = 'photo-zoom-overlay';
    ov.innerHTML = `<img src="${esc(src)}" alt="Pratinjau besar">
      <button type="button" class="photo-zoom-close" aria-label="Tutup pratinjau">&times;</button>`;
    ov.addEventListener('click', () => ov.remove());
    document.body.appendChild(ov);
  }

  function viewDetail(parts) {
    const a = getAsset(parts[0]);
    if (!a) return `<div class="card card-pad"><div class="empty"><i class="fa-regular fa-circle-xmark"></i>Data tidak ditemukan.<br><br>
      <a class="btn btn-primary" href="#/inventaris">Kembali ke Daftar Inventaris</a></div></div>`;

    const tab = state.detailTab;
    const info = [
      ['Kategori', esc(a.kategori)],
      ['Merek / Tipe', esc(a.merek || '-')],
      ['Serial Number', esc(a.serial || '-')],
      ['Tahun Perolehan', a.tahun || '-'],
      ['Nilai Perolehan', rupiah(a.nilai)],
      ['Tanggal Perolehan', fmtDate(a.tgl, '/')],
      ['Cabang', esc(a.cabang || '-')],
      ['Lokasi Saat Ini', esc(a.lokasi)],
      ['Divisi', esc(a.divisi)],
      ['PIC', esc(a.pic)],
      ['Kondisi', badgeKondisi(a.kondisi)],
      ['Status', badgeStatus(a.status)],
      ['Catatan', esc(a.catatan || '-')]
    ];

    const mutasi = DB.mutations.filter((m) => m.kode === a.kode);
    let tabBody = '';

    if (tab === 'riwayat') {
      tabBody = `<div class="card"><div class="table-wrap"><table class="tbl">
        <thead><tr><th>Tanggal</th><th>Aktivitas</th><th>Keterangan</th><th>User</th></tr></thead>
        <tbody>${(a.riwayat || []).slice().reverse().map((r) => `<tr>
          <td>${fmtDate(r.tgl)}</td><td><b>${esc(r.aktivitas)}</b></td><td>${esc(r.keterangan)}</td><td>${esc(r.user)}</td>
        </tr>`).join('') || `<tr><td colspan="4"><div class="empty">Belum ada riwayat.</div></td></tr>`}</tbody>
      </table></div></div>`;
    } else if (tab === 'mutasi') {
      tabBody = `<div class="card"><div class="table-wrap"><table class="tbl">
        <thead><tr><th>ID</th><th>Tanggal</th><th>Dari</th><th>Ke</th><th>Alasan</th><th>Status</th></tr></thead>
        <tbody>${mutasi.map((m) => `<tr>
          <td><b>${m.id}</b></td><td>${fmtDate(m.tgl)}</td><td>${esc(m.dari)}</td><td>${esc(m.ke)}</td>
          <td>${esc(m.alasan)}</td><td>${badgeMutasi(m.status)}</td>
        </tr>`).join('') || `<tr><td colspan="6"><div class="empty">Belum ada mutasi untuk aset ini.</div></td></tr>`}</tbody>
      </table></div></div>`;
    } else if (tab === 'opname') {
      const orows = assetOpnameRows(a);
      const opCell = (v) => `<div>${esc(v == null || v === '' ? '-' : v)}</div>`;
      tabBody = `<div class="card"><div class="table-wrap"><table class="tbl">
        <thead><tr><th>Tanggal</th><th>Sesi Opname</th><th>Status</th><th>Lokasi</th><th>PIC</th><th>Kondisi</th><th>User</th></tr></thead>
        <tbody>${orows.map((o) => `<tr>
          <td>${fmtDate(String(o.tgl || '').slice(0, 10))}</td>
          <td>${o.sesi === '-' ? '-' : `<a class="link-id" href="#/opname/${esc(o.sesi)}">${esc(o.sesi)}</a>`}${o.nama ? `<div class="small muted">${esc(o.nama)}</div>` : ''}</td>
          <td>${o.status === 'Ditemukan' ? '<span class="badge b-green">Ditemukan</span>'
        : o.status === 'Tidak Ditemukan' ? '<span class="badge b-red">Tidak Ditemukan</span>'
          : '<span class="badge b-gray">' + esc(o.status || '-') + '</span>'}</td>
          <td>${opCell(o.lokasi)}</td><td>${opCell(o.pic)}</td>
          <td>${o.kondisi ? badgeKondisi(o.kondisi) : '-'}</td><td>${esc(o.user || '-')}</td>
        </tr>`).join('') || `<tr><td colspan="7"><div class="empty">Belum pernah di-stock-opname.</div></td></tr>`}</tbody>
      </table></div></div>`;
    } else if (tab === 'perbaikan') {
      tabBody = `<div class="card"><div class="table-wrap"><table class="tbl">
        <thead><tr><th>Tanggal</th><th>Keluhan</th><th>Vendor</th><th>Status</th><th>User</th></tr></thead>
        <tbody>${(a.perbaikan || []).slice().reverse().map((p) => `<tr>
          <td>${fmtDate(p.tgl)}</td><td>${esc(p.keluhan)}</td><td>${esc(p.vendor)}</td>
          <td><span class="badge ${p.status === 'Selesai' ? 'b-green' : 'b-orange'}">${esc(p.status)}</span></td><td>${esc(p.user)}</td>
        </tr>`).join('') || `<tr><td colspan="5"><div class="empty">Tidak ada riwayat perbaikan.</div></td></tr>`}</tbody>
      </table></div></div>`;
    } else {
      tabBody = `<div class="card card-pad">
        <div class="doc-list">
          ${(a.dokumen || []).map((d, i) => `<div class="doc-item">
            <i class="fa-regular fa-file-pdf"></i>
            <span class="nm">${esc(d.nama)}</span>
            ${d.url ? `<a class="btn btn-sm btn-light" href="${esc(d.url)}" download="${esc(d.nama)}" title="Download"><i class="fa-solid fa-download"></i></a>`
          : '<span class="muted small">contoh</span>'}
            ${can('asset.update') ? `<button class="rm" data-action="rmDoc" data-kode="${a.kode}" data-i="${i}" title="Hapus"><i class="fa-regular fa-trash-can"></i></button>` : ''}
          </div>`).join('') || '<div class="empty">Belum ada dokumen.</div>'}
        </div>
        ${can('asset.update') ? `<div style="margin-top:14px"><label class="upload" for="docFile">
          <i class="fa-solid fa-cloud-arrow-up"></i>Upload dokumen (PDF, JPG, maks 5MB)
          <input type="file" id="docFile" data-kode="${a.kode}" accept=".pdf,.jpg,.jpeg,.png" hidden>
        </label></div>` : ''}
      </div>`;
    }

    const photo = a.foto || PLACEHOLDER;
    const qr = qrDataUrl(a.kode, 140);

    return `
      <a class="back-link" href="#/inventaris"><i class="fa-solid fa-chevron-left"></i> Kembali</a>
      <div class="detail-head">
        <h1>Detail Inventaris</h1>
        <div class="row">
          ${can('asset.update') ? `<button class="btn btn-light" data-action="edit" data-kode="${a.kode}"><i class="fa-regular fa-pen-to-square"></i> Edit</button>` : ''}
          <button class="btn btn-outline" data-action="printQr" data-kode="${a.kode}"><i class="fa-solid fa-print"></i> Cetak QR</button>
        </div>
      </div>

      <div class="card card-pad">
        <div class="detail-grid">
          <div>
            <div class="photo-box">
              <img src="${photo}" alt="Foto ${esc(a.nama)}" data-action="zoomPhoto" title="Klik untuk pratinjau besar">
              <div class="photo-thumbs">
                <button type="button" class="th active" data-action="detailImg" data-src="${photo}" title="Pratinjau foto barang"><img src="${photo}" alt=""></button>
                <button type="button" class="th qr-th" data-action="detailImg" data-src="${qr}" title="Pratinjau QR Code"><img src="${qr}" alt="QR ${a.kode}" style="width:100%;height:100%;object-fit:contain"></button>
              </div>
            </div>
          </div>
          <div>
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
              <div>
                <div class="row" style="gap:8px;margin-bottom:4px">
                  <h2 style="margin:0;font-size:19px">${a.kode}</h2>${badgeStatus(a.status)}
                </div>
                <div style="font-size:16px;font-weight:600">${esc(a.nama)}</div>
              </div>
              <div>${badgeKondisi(a.kondisi)}</div>
            </div>
            <div class="info-list" style="margin-top:12px">
              ${info.map(([k, v]) => `<div class="info-row"><div class="info-k">${k}</div><div class="info-v">${v}</div></div>`).join('')}
            </div>
          </div>
        </div>

        <div class="tabs">
          <button class="tab ${tab === 'riwayat' ? 'active' : ''}" data-action="tab" data-tab="riwayat">Riwayat</button>
          <button class="tab ${tab === 'mutasi' ? 'active' : ''}" data-action="tab" data-tab="mutasi">Mutasi</button>
          <button class="tab ${tab === 'opname' ? 'active' : ''}" data-action="tab" data-tab="opname">Stock Opname</button>
          <button class="tab ${tab === 'perbaikan' ? 'active' : ''}" data-action="tab" data-tab="perbaikan">Perbaikan</button>
          <button class="tab ${tab === 'dokumen' ? 'active' : ''}" data-action="tab" data-tab="dokumen">Dokumen</button>
        </div>
        <div class="tab-body">${tabBody}</div>
      </div>`;
  }

  /* ========================================================
     9. VIEW: FORM TAMBAH / EDIT
     ======================================================== */
  function viewForm(parts) {
    const isEdit = state.route === 'edit';
    const a = isEdit ? getAsset(parts[0]) : null;
    if (isEdit && !a) return `<div class="card card-pad"><div class="empty">Data tidak ditemukan.</div></div>`;
    const d = a || {
      nama: '', kategori: '', merek: '', serial: '', tahun: new Date().getFullYear(), nilai: '', tgl: '',
      lokasi: '', divisi: '', pic: '', kondisi: 'Baik', status: 'Aktif', catatan: ''
    };
    state.draft = { foto: a ? a.foto : '', dokumen: a ? (a.dokumen || []).slice() : [] };
    // Cabang selalu terkunci: saat edit = cabang aset, saat tambah = cabang yang dipilih user.
    const defaultCabang = isEdit
      ? d.cabang
      : (state.cabangFilter !== '*' ? state.cabangFilter : (DB.settings.cabang || CABANG_OPTS[0]));

    const f = (name, label, value, opts = {}) => `
      <div class="field ${opts.span ? 'span-2' : ''}">
        <label>${label}${opts.req ? ' <span class="req">*</span>' : ''}</label>
        ${opts.type === 'select'
        ? `<select name="${name}" ${opts.req ? 'data-req="1"' : ''} ${opts.disabled ? 'disabled' : ''}>${sel(opts.options, value, opts.placeholder)}</select>`
        : opts.type === 'textarea'
          ? `<textarea name="${name}" placeholder="${esc(opts.ph || '')}">${esc(value)}</textarea>`
          : `<input type="${opts.type || 'text'}" name="${name}" value="${esc(value)}" placeholder="${esc(opts.ph || '')}" ${opts.inputmode ? `inputmode="${opts.inputmode}"` : ''} ${opts.req ? 'data-req="1"' : ''} ${opts.disabled ? 'disabled' : ''}>`}
        ${opts.hint ? `<span class="hint">${opts.hint}</span>` : ''}
      </div>`;

    return `
      <a class="back-link" href="#/inventaris"><i class="fa-solid fa-chevron-left"></i> Kembali</a>
      <div class="page-head">
        <div>
          <h1 class="page-title">${isEdit ? 'Edit Inventaris' : 'Tambah Inventaris'}</h1>
          <p class="page-sub">${isEdit ? 'Perbarui data aset ' + esc(a.kode) + '.' : 'Lengkapi data aset baru milik cabang Anda.'}</p>
        </div>
      </div>

      <form id="assetForm" class="form-grid" data-kode="${isEdit ? a.kode : ''}">
        <section class="card card-pad">
          <h3 class="form-section-title"><span style="color:var(--red)">1.</span> Informasi Umum</h3>
          ${isEdit ? '' : `<div class="hint" id="kodeHint" style="margin:-6px 0 10px"><i class="fa-solid fa-barcode"></i> Kode aset otomatis: <b>${esc(nextKodePreview(defaultCabang))}</b> <span class="muted">— urutan kode terpisah untuk tiap cabang</span></div>`}
          <div class="fields">
            ${f('nama', 'Nama Barang', d.nama, { req: 1, ph: 'Contoh: Laptop Lenovo', disabled: isEdit })}
            ${f('kategori', 'Kategori', d.kategori, { type: 'select', options: DB.lists.kategori, placeholder: 'Pilih Kategori', req: 1 })}
            ${f('merek', 'Merk / Tipe', d.merek, { ph: 'Contoh: ThinkPad E14' })}
            ${f('serial', 'Serial Number', d.serial, { ph: 'Contoh: PF3JHBK3' })}
            ${f('nilai', 'Nilai Perolehan', (d.nilai === '' || d.nilai === null || d.nilai === undefined) ? '' : num(d.nilai), { type: 'text', ph: 'Contoh: 1.500.000', inputmode: 'numeric' })}
            ${f('tgl', 'Tanggal Perolehan', d.tgl, { type: 'date', disabled: isEdit && USER.role !== 'admin' })}
            ${f('cabang', 'Cabang', defaultCabang, { type: 'select', options: cabangOptions(), req: 1, disabled: true })}
            ${f('lokasi', 'Lokasi', d.lokasi, { type: 'select', options: keepOpt(DB.lists.lokasi, d.lokasi), placeholder: 'Pilih Lokasi', req: 1, disabled: isEdit })}
            ${f('divisi', 'Divisi', d.divisi, { type: 'select', options: keepOpt(DB.lists.divisi, d.divisi), placeholder: 'Pilih Divisi', req: 1, disabled: isEdit })}
            ${f('pic', 'PIC', d.pic, { type: 'select', options: keepOpt(DB.lists.pic, d.pic), placeholder: 'Pilih PIC', req: 1, disabled: isEdit })}
            ${f('kondisi', 'Kondisi', d.kondisi, { type: 'select', options: KONDISI, req: 1, disabled: isEdit })}
            ${f('status', 'Status', d.status, { type: 'select', options: ['Aktif', 'Nonaktif'], req: 1, disabled: isEdit && USER.role !== 'admin' })}
            ${f('catatan', 'Catatan', d.catatan, { type: 'textarea', ph: 'Tambahkan catatan (opsional)' })}
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
            <a class="btn btn-soft" href="#/inventaris">Batal</a>
          </div>
        </section>

        <div>
          <section class="card card-pad side-block">
            <h4>Foto Barang${isEdit ? '' : ' <span class="req">*</span>'}</h4>
            <label class="upload" for="photoInput">
              <i class="fa-solid fa-cloud-arrow-up"></i>
              Klik untuk upload foto atau drag &amp; drop<br>
              <span class="small">(JPG, PNG, maks 2MB)</span>
              <input type="file" id="photoInput" accept="image/*" hidden>
            </label>
            <div id="photoPreview">${photoPreviewHtml(state.draft.foto)}</div>
          </section>

          <section class="card card-pad side-block">
            <h4>Dokumen Pendukung</h4>
            <label class="upload" for="docInput">
              <i class="fa-solid fa-cloud-arrow-up"></i>
              Upload dokumen<br><span class="small">(PDF, JPG, maks 5MB)</span>
              <input type="file" id="docInput" accept=".pdf,.jpg,.jpeg,.png" hidden>
            </label>
            <div class="doc-list" id="docList">${docListHtml()}</div>
          </section>
        </div>
      </form>`;
  }

  function photoPreviewHtml(src) {
    if (!src) return '';
    return `<div class="upload-preview"><img src="${src}" alt="preview">
      <button type="button" class="rm" data-action="rmPhoto" title="Hapus foto"><i class="fa-solid fa-xmark"></i></button></div>`;
  }
  function docListHtml() {
    return state.draft.dokumen.map((d, i) => `<div class="doc-item">
      <i class="fa-regular fa-file-lines"></i><span class="nm">${esc(d.nama)}</span>
      ${d.url ? '<span class="badge b-green">tersimpan</span>' : '<span class="badge b-orange">baru</span>'}
      <button type="button" class="rm" data-action="rmDraftDoc" data-i="${i}"><i class="fa-regular fa-trash-can"></i></button>
    </div>`).join('');
  }

  function handlePhotoFile(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { toast('Ukuran foto maksimal 2MB.', 'error'); input.value = ''; return; }
    readAsDataURL(file).then((dataUrl) => {
      state.draft.foto = dataUrl;
      $('#photoPreview').innerHTML = photoPreviewHtml(state.draft.foto);
      toast('Foto siap diunggah (akan tersimpan saat Anda menekan Simpan).', 'success');
    }).catch(fail);
  }
  function handleDraftDocFile(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { toast('Ukuran dokumen maksimal 5MB.', 'error'); input.value = ''; return; }
    readAsDataURL(file).then((dataUrl) => {
      state.draft.dokumen.push({ nama: file.name, data: dataUrl });
      $('#docList').innerHTML = docListHtml();
      toast('Dokumen ditambahkan (akan terunggah saat Simpan).', 'success');
    }).catch(fail);
  }

  async function saveAssetForm(form) {
    const reqs = $$('[data-req]', form);
    for (const el of reqs) {
      if (!String(el.value).trim()) { el.focus(); toast('Harap lengkapi field bertanda *.', 'error'); return; }
    }
    if (!form.dataset.kode && !state.draft.foto) {
      toast('Foto barang wajib diunggah.', 'error');
      return;
    }
    const v = (n) => (form.elements[n] ? form.elements[n].value.trim() : '');
    const kode = form.dataset.kode;
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...'; }

    try {
      const foto = await uploadFotoSrc(state.draft.foto);
      const dokumen = await resolveDokumen(state.draft.dokumen);
      const payload = {
        nama: v('nama'), kategori: v('kategori'), merek: v('merek'), serial: v('serial') || '-',
        nilai: Number(String(v('nilai')).replace(/\D/g, '') || 0), tgl: v('tgl') || isoToday(),
        lokasi: v('lokasi'), divisi: v('divisi') || '-',
        pic: v('pic') || '-', kondisi: v('kondisi'), status: v('status'), catatan: v('catatan') || '-',
        cabang: v('cabang') || '', foto, dokumen
      };
      if (kode) {
        const r = await api('PUT', '/assets/' + encodeURIComponent(kode), payload);
        commit(r);
        toast('Perubahan berhasil disimpan.', 'success');
        go('detail/' + kode);
      } else {
        const r = await api('POST', '/assets', payload);
        commit(r);
        toast(`Inventaris ${r.kode} berhasil ditambahkan.`, 'success');
        go('detail/' + r.kode);
      }
    } catch (e) {
      fail(e);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan'; }
    }
  }

  /* ========================================================
     10. VIEW: SCAN QR (jsQR — works on Chromium/Firefox/Edge)
     ======================================================== */
  let scan = { stream: null, timer: null, active: false, canvas: null, ctx: null, gen: 0 };

  function startScan() {
    const hint = $('#scanHint');
    if (hint) hint.textContent = 'Tekan "Gunakan Kamera" untuk mulai, atau gunakan Input Manual / Upload Gambar QR.';
  }

  // Layar penghitungan sesi: siapkan hint kamera, selalu fokus input barcode,
  // dan bawa item terakhir tersorot ke tengah layar (FR-7/FR-8).
  function startOpnameScreen() {
    startScan();
    const hit = state.opname.hit;
    if (hit) {
      const row = document.getElementById('row-' + hit);
      if (row && typeof row.scrollIntoView === 'function') {
        try { row.scrollIntoView({ block: 'center' }); } catch (e) { /* jsdom / browser lama */ }
      }
    }
    // kamera masih menyala -> pasang kembali ke elemen video baru hasil render ulang
    if (scan.active && scan.stream) {
      const video = $('#scanVideo');
      if (video && video.srcObject !== scan.stream) {
        if (scan.timer) { clearTimeout(scan.timer); scan.timer = null; }
        video.srcObject = scan.stream;
        try { const p = video.play(); if (p && p.catch) p.catch(() => {}); } catch (e) { /* ignore */ }
        const hint = $('#scanHint');
        if (window.jsQR) startJsQRLoop(video, hint);
        else if ('BarcodeDetector' in window) startDetectorLoop(video, hint);
      }
    }
    const inp = $('#opnameBarcode');
    const ae = document.activeElement;
    if (inp && (!ae || ae === document.body || ae.id === 'opnameBarcode')) inp.focus();
  }

  function stopScan() {
    scan.active = false;
    scan.gen++;                                   // matikan semua loop decode yang berjalan
    if (scan.timer) { clearTimeout(scan.timer); scan.timer = null; }
    if (scan.stream) { scan.stream.getTracks().forEach((t) => t.stop()); scan.stream = null; }
    const video = $('#scanVideo');
    if (video) { try { video.srcObject = null; } catch (e) { /* ignore */ } }
    const btn = $('#camBtn');
    if (btn) btn.innerHTML = '<i class="fa-solid fa-camera"></i> Gunakan Kamera';
  }

  // Kamera / upload QR pada layar penghitungan: sorot barang, jangan buka form aset.
  // Selalu return false supaya loop decode kamera tetap berjalan untuk pindai berikutnya.
  function handleDecoded(raw) {
    const kode = String(raw || '').trim().toUpperCase();
    if (!kode) return false;
    if (state.route === 'opname' && currentSession()) {
      if (state.lastBad !== kode) {          // QR sama berulang: jangan proses dua kali
        state.lastBad = kode;
        opnameHit(kode);
      }
      return false;
    }
    stopScan();
    toast('Buka sesi Stock Opname untuk memindai barang.', 'info');
    go('opname');
    return true;
  }

  async function toggleCam() {
    if (scan.active) { stopScan(); return; }
    const hint = $('#scanHint');
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia)
        throw new Error('Browser tidak mendukung kamera.');
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } });
      scan.stream = stream;
      const video = $('#scanVideo');
      video.srcObject = stream;
      await video.play();
      scan.active = true;
      state.lastBad = '';
      const btn = $('#camBtn');
      if (btn) btn.innerHTML = '<i class="fa-solid fa-stop"></i> Matikan Kamera';
      if (hint) hint.textContent = 'Kamera aktif — arahkan ke QR Code inventaris';

      if (window.jsQR) startJsQRLoop(video, hint);
      else if ('BarcodeDetector' in window) startDetectorLoop(video, hint);
      else if (hint) hint.textContent = 'Decoder QR tidak tersedia — gunakan Input Manual.';
    } catch (e) {
      toast('Kamera tidak dapat diakses: ' + e.message, 'error');
      if (hint) hint.textContent = 'Kamera tidak tersedia — gunakan Input Manual atau Upload Gambar QR.';
      stopScan();
    }
  }

  function startJsQRLoop(video, hint) {
    const gen = ++scan.gen;
    const canvas = document.createElement('canvas');
    scan.canvas = canvas;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    scan.ctx = ctx;
    const tick = () => {
      if (!scan.active || gen !== scan.gen) return;
      try {
        if (video.readyState === 4 && video.videoWidth > 0 && ctx) {
          const w = video.videoWidth, h = video.videoHeight;
          if (canvas.width !== w) { canvas.width = w; canvas.height = h; }
          ctx.drawImage(video, 0, 0, w, h);
          const img = ctx.getImageData(0, 0, w, h);
          const code = window.jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' });
          if (code && code.data) {
            if (handleDecoded(code.data)) return;
            scan.timer = setTimeout(tick, 900);   // QR tak dikenal: jeda biar tidak spam
            return;
          }
        }
      } catch (e) { /* frame error: lanjut */ }
      scan.timer = setTimeout(tick, 220);
    };
    if (hint) hint.textContent = 'Kamera aktif (jsQR) — arahkan ke QR Code inventaris';
    tick();
  }

  function startDetectorLoop(video, hint) {
    const gen = ++scan.gen;
    scan.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
    const tick = async () => {
      if (!scan.active || gen !== scan.gen) return;
      try {
        const codes = await scan.detector.detect(video);
        if (codes && codes.length) {
          if (handleDecoded(codes[0].rawValue)) return;
          scan.timer = setTimeout(tick, 900);
          return;
        }
      } catch (e) { /* lanjut */ }
      scan.timer = setTimeout(tick, 350);
    };
    if (hint) hint.textContent = 'Kamera aktif (BarcodeDetector) — arahkan ke QR Code';
    tick();
  }

  function decodeQrFile(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    input.value = '';
    const fr = new FileReader();
    fr.onload = () => {
      const img = new Image();
      img.onload = () => {
        try {
          const c = document.createElement('canvas');
          c.width = img.naturalWidth; c.height = img.naturalHeight;
          const ctx = c.getContext('2d', { willReadFrequently: true });
          ctx.drawImage(img, 0, 0);
          const d = ctx.getImageData(0, 0, c.width, c.height);
          const res = window.jsQR ? window.jsQR(d.data, d.width, d.height, { inversionAttempts: 'attemptBoth' }) : null;
          if (res && res.data) handleDecoded(res.data);
          else toast('QR tidak dapat dibaca dari gambar tersebut.', 'error');
        } catch (e) { toast('Gagal memproses gambar: ' + e.message, 'error'); }
      };
      img.onerror = () => toast('File gambar tidak valid.', 'error');
      img.src = fr.result;
    };
    fr.readAsDataURL(file);
  }

  /* ========================================================
     11. VIEW: STOCK OPNAME (SESI)
     Alur: buat sesi (snapshot inventaris cabang) -> mulai ->
           periksa (scan / cari / popup) -> akhiri -> laporan.
     ======================================================== */
  function opnameSessions() { return (DB && DB.opnameSessions) || []; }
  function getSession(id) { return opnameSessions().find((s) => s.id === id) || null; }
  function currentSession() {
    if (state.route !== 'opname' || !state.opname.sid) return null;
    return getSession(state.opname.sid);
  }

  function badgeOpname(st) {
    const map = { Draft: 'b-gray', Berjalan: 'b-blue', Selesai: 'b-green', Dibatalkan: 'b-red' };
    return `<span class="badge ${map[st] || 'b-gray'}">${esc(st)}</span>`;
  }

  // Ringkasan identik dengan sisi server (opname_summary / opnameSummary)
  function opnameAwal(i, key) {
    const v = i[key + '_awal'];
    return v === undefined || v === null ? i[key] : v;
  }

  // Lokasi/PIC/kondisi berbeda dari nilai awal snapshot (akan ditulis balik ke aset)
  function opnameChanged(i) {
    return i.lokasi !== opnameAwal(i, 'lokasi') || i.pic !== opnameAwal(i, 'pic')
      || i.kondisi !== opnameAwal(i, 'kondisi');
  }

  function opnameSummary(items) {
    const list = items || [];
    let dicek = 0, ditemukan = 0, tidak = 0, berubah = 0;
    list.forEach((i) => {
      if (i.dicek) dicek++;
      if (i.status === 'Ditemukan') ditemukan++;
      else if (i.status === 'Tidak Ditemukan') tidak++;
      if (opnameChanged(i)) berubah++;
    });
    return { total: list.length, dicek, belum: list.length - dicek, ditemukan,
      tidak_ditemukan: tidak, berubah };
  }

  // Ringkasan sesi Selesai memakai ringkasan server; sesi lain (dan data lama
  // yang masih memakai format ringkasan lama) dihitung ulang di sisi klien.
  function opnameFinalSummary(s) {
    const sum = s && s.summary;
    return (s && s.status === 'Selesai' && sum && typeof sum.ditemukan === 'number')
      ? sum : opnameSummary((s && s.items) || []);
  }

  function opnameListRows() {
    const f = state.opname;
    const q = f.search.trim().toLowerCase();
    return opnameSessions().filter((s) =>
      (!q || [s.id, s.nama, s.cabang].join(' ').toLowerCase().indexOf(q) >= 0) &&
      (!f.cabang || s.cabang === f.cabang) &&
      (!f.status || s.status === f.status) &&
      (!f.from || String(s.tanggal || '') >= f.from) &&
      (!f.to || String(s.tanggal || '') <= f.to));
  }

  function opnameItems(s) {
    const f = state.opname;
    const q = f.q.trim().toLowerCase();
    return ((s && s.items) || []).filter((i) =>
      (!q || [i.kode, i.nama, i.barcode, i.sku].join(' ').toLowerCase().indexOf(q) >= 0) &&
      (!f.itemStatus || i.status === f.itemStatus) &&
      (!f.kat || i.kategori === f.kat));
  }

  // Baris riwayat opname untuk tab "Stock Opname" pada detail aset:
  // hasil sesi Selesai + item yang sudah dicek pada sesi berjalan + data lama.
  function assetOpnameRows(a) {
    const rows = [];
    opnameSessions().forEach((s) => {
      (s.items || []).forEach((i) => {
        if (i.kode !== a.kode) return;
        if (s.status === 'Selesai' || i.dicek) {
          rows.push({
            tgl: (s.status === 'Selesai' ? s.ended_at : i.counted_at) || s.tanggal || '',
            sesi: s.id, nama: s.nama, status: i.status,
            lokasi: i.lokasi, pic: i.pic, kondisi: i.kondisi,
            user: i.counted_by || s.user || '-'
          });
        }
      });
    });
    (a.opname || []).forEach((o) => {
      rows.push({ tgl: o.tgl, sesi: '-', nama: 'Data lama', status: o.status,
        lokasi: o.lokasi, pic: o.pic, kondisi: o.kondisi, user: o.user || '-' });
    });
    rows.sort((x, y) => String(y.tgl || '').localeCompare(String(x.tgl || '')));
    return rows;
  }

  function viewOpname(parts) {
    const p0 = parts[0] || '';
    const sid = p0 === 'baru' ? '' : p0;
    if (state.opname.sid !== sid) {
      // pindah halaman sesi -> reset sorotan & filter item
      Object.assign(state.opname, { sid, hit: '', q: '', itemStatus: '', kat: '', itemPage: 1 });
    }
    if (!p0) return viewOpnameList();
    if (p0 === 'baru') return viewOpnameCreate();
    const s = getSession(p0);
    if (!s) {
      return `<div class="card card-pad"><div class="empty"><i class="fa-regular fa-circle-xmark"></i>Sesi opname tidak ditemukan.<br><br>
        <a class="btn btn-primary" href="#/opname">Kembali ke Daftar Stock Opname</a></div></div>`;
    }
    return viewOpnameDetail(s);
  }

  function viewOpnameList() {
    const f = state.opname;
    const rows = opnameListRows();
    const start = (f.page - 1) * f.per;
    const pageRows = rows.slice(start, start + f.per);
    const cabList = cabangOptions().filter((o) => o !== 'Semua Cabang');
    const statusList = ['Draft', 'Berjalan', 'Selesai', 'Dibatalkan'];
    const opt = (v, label, val) => `<option value="${esc(v)}" ${v === val ? 'selected' : ''}>${esc(label)}</option>`;

    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Stock Opname</h1>
          <p class="page-sub">Sesi stock opname per cabang — buat sesi, hitung barang, lalu unduh laporan.</p>
        </div>
        ${can('opname.write') ? `<button class="btn btn-primary" data-action="opnameNew"><i class="fa-solid fa-plus"></i> Buat Opname Baru</button>` : ''}
      </div>

      <div class="card">
        <div class="filters">
          <div class="search"><i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" data-opfilter="search" value="${esc(f.search)}" placeholder="Cari nama sesi / ID / cabang...">
          </div>
          <select data-opfilter="cabang" aria-label="Filter cabang">
            ${opt('', 'Semua Cabang', f.cabang)}${cabList.map((c) => opt(c, c, f.cabang)).join('')}
          </select>
          <select data-opfilter="status" aria-label="Filter status">
            ${opt('', 'Semua Status', f.status)}${statusList.map((c) => opt(c, c, f.status)).join('')}
          </select>
          <input type="date" data-opfilter="from" value="${esc(f.from)}" aria-label="Tanggal dari" title="Tanggal dari">
          <input type="date" data-opfilter="to" value="${esc(f.to)}" aria-label="Tanggal sampai" title="Tanggal sampai">
          <button class="btn btn-light" data-action="resetOpname"><i class="fa-solid fa-rotate-left"></i> Reset</button>
        </div>

        <div class="table-wrap"><table class="tbl opname-tbl">
          <thead><tr>
            <th>ID Sesi</th><th>Nama Sesi</th><th>Cabang</th><th>Tanggal</th>
            <th>Status</th><th>Progres Hitung</th><th style="text-align:right">Aksi</th>
          </tr></thead>
          <tbody>${pageRows.map((s) => {
            const sum = opnameSummary(s.items);
            const pct = sum.total ? Math.round((sum.dicek / sum.total) * 100) : 0;
            return `<tr>
              <td><b>${esc(s.id)}</b></td>
              <td>${esc(s.nama)}${s.catatan && s.catatan !== '-' ? `<div class="small muted">${esc(s.catatan)}</div>` : ''}</td>
              <td>${esc(s.cabang)}</td>
              <td style="white-space:nowrap">${fmtDate(s.tanggal, '/')}</td>
              <td>${badgeOpname(s.status)}</td>
              <td><div class="prog-cell">
                <div class="mini-prog"><span style="width:${pct}%"></span></div>
                <span class="small muted">${sum.dicek}/${sum.total} (${pct}%)</span>
              </div></td>
              <td><div class="cell-actions">
                <a class="btn btn-sm btn-light" href="#/opname/${esc(s.id)}">Buka <i class="fa-solid fa-chevron-right"></i></a>
              </div></td>
            </tr>`;
          }).join('') || `<tr><td colspan="7"><div class="empty"><i class="fa-regular fa-folder-open"></i> ${opnameSessions().length ? 'Tidak ada sesi yang cocok dengan filter.' : 'Belum ada sesi opname.'}<br><br>
            ${can('opname.write') ? '<a class="btn btn-primary" href="#/opname/baru"><i class="fa-solid fa-plus"></i> Buat Opname Baru</a>' : ''}</div></td></tr>`}</tbody>
        </table></div>
        <div class="table-foot">
          <span>Menampilkan ${pageRows.length ? start + 1 : 0} - ${Math.min(start + f.per, rows.length)} dari ${rows.length} sesi</span>
          ${pagerHtml(f.page, f.per, rows.length, 'pageOpname')}
        </div>
      </div>`;
  }

  function viewOpnameCreate() {
    const locked = !!(USER && USER.cabang && USER.cabang !== '*');
    const opts = cabangOptions().filter((o) => o !== 'Semua Cabang');
    let def = locked ? USER.cabang
      : (state.cabangFilter && state.cabangFilter !== '*' ? state.cabangFilter
        : (DB.settings.cabang || (opts[0] || '')));
    if (def && !opts.includes(def)) opts.unshift(def);
    if (!def && opts.length) def = opts[0];

    return `
      <a class="back-link" href="#/opname"><i class="fa-solid fa-chevron-left"></i> Kembali ke Daftar Stock Opname</a>
      <div class="page-head" style="display:block">
        <h1 class="page-title">Buat Sesi Stock Opname</h1>
        <p class="page-sub">Sesi baru otomatis menyalin seluruh inventaris cabang (snapshot) pada saat dibuat.</p>
      </div>

      <form id="opnameSessionForm" class="form-grid">
        <div>
          <section class="card card-pad side-block">
            <h3 class="form-section-title">Alur Sesi</h3>
            <ol class="op-steps">
              <li><b>Draft</b> — sesi dibuat, snapshot inventaris cabang tersimpan.</li>
              <li><b>Berjalan</b> — penghitungan dibuka (scan / cari / popup periksa).</li>
              <li><b>Selesai</b> — sesi terkunci, laporan PDF &amp; Excel bisa diunduh kapan saja.</li>
            </ol>
            <div class="small muted" style="margin-top:10px">
              Hanya satu sesi <b>Berjalan</b> per cabang. Snapshot menyimpan Lokasi, PIC, dan kondisi tiap barang;
              perubahan dari hasil opname baru ditulis ke <b>Data Inventaris</b> saat sesi diakhiri.
            </div>
          </section>
        </div>

        <section class="card card-pad">
          <div class="fields">
            <div class="field">
              <label>Cabang <span class="req">*</span></label>
              <select name="cabang" data-req="1" ${locked ? 'disabled' : ''}>
                ${opts.map((c) => `<option value="${esc(c)}" ${c === def ? 'selected' : ''}>${esc(c)}</option>`).join('')}
              </select>
              <div class="small muted">${locked ? 'Sesi dibuat untuk cabang Anda sendiri.' : 'Pilih cabang yang akan diopname.'}</div>
            </div>
            <div class="field">
              <label>Nama Sesi <span class="req">*</span></label>
              <input type="text" name="nama" data-req="1" maxlength="80" placeholder="Contoh: Opname Awal Tahun 2026">
            </div>
            <div class="field">
              <label>Tanggal Opname <span class="req">*</span></label>
              <input type="date" name="tanggal" data-req="1" value="${isoToday()}">
            </div>
            <div class="field span-2">
              <label>Catatan</label>
              <textarea name="catatan" placeholder="Keterangan tambahan (opsional)"></textarea>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Buat Sesi &amp; Lanjut</button>
            <a class="btn btn-soft" href="#/opname">Batal</a>
          </div>
        </section>
      </form>`;
  }

  function opnameSumHtml(sum) {
    const cell = (label, val, cls) => `<div class="sum-cell"><span>${esc(label)}</span><b class="${cls || ''}">${num(val)}</b></div>`;
    return `<div class="sum-grid">
      ${cell('Total Item', sum.total)}
      ${cell('Sudah Dicek', sum.dicek, 'c-green')}
      ${cell('Belum Dicek', sum.belum, 'c-gray')}
      ${cell('Ditemukan', sum.ditemukan, 'c-green')}
      ${cell('Tidak Ditemukan', sum.tidak_ditemukan, 'c-red')}
      ${cell('Data Berubah', sum.berubah, 'c-orange')}
    </div>`;
  }

  function viewOpnameDetail(s) {
    const f = state.opname;
    const st = s.status;
    const counting = st === 'Berjalan';
    const all = s.items || [];
    const rows = opnameItems(s);
    const sum = opnameFinalSummary(s);
    const pct = sum.total ? Math.round((sum.dicek / sum.total) * 100) : 0;

    // FR-12: sesi mengendap >= 3 hari -> peringatan akhiri paksa
    let staleNote = '';
    if (counting && s.started_at) {
      const days = Math.floor((Date.now() - new Date(String(s.started_at).slice(0, 10) + 'T00:00:00').getTime()) / 86400000);
      if (days >= 3) {
        staleNote = `<div class="warn-banner" role="alert"><i class="fa-solid fa-triangle-exclamation"></i>
          Sesi ini sudah berjalan <b>${days} hari</b>. Periksa kelengkapan hitungan lalu akhiri sesi agar data tidak mengendap.</div>`;
      }
    }

    const actions = [];
    if (st === 'Draft') {
      actions.push(`<button class="btn btn-primary" data-action="opnameStart"><i class="fa-solid fa-play"></i> Mulai Opname</button>`);
      actions.push(`<button class="btn btn-soft" data-action="opnameCancel"><i class="fa-solid fa-ban"></i> Batalkan</button>`);
    } else if (st === 'Berjalan') {
      actions.push(`<button class="btn btn-danger" data-action="opnameEnd"><i class="fa-solid fa-flag-checkered"></i> Akhiri Opname</button>`);
      actions.push(`<button class="btn btn-soft" data-action="opnameCancel"><i class="fa-solid fa-ban"></i> Batalkan</button>`);
    } else if (st === 'Selesai') {
      actions.push(`<button class="btn btn-outline" data-action="exportOpnameXlsx"><i class="fa-solid fa-file-excel"></i> Export Excel</button>`);
      actions.push(`<button class="btn btn-outline" data-action="exportOpnamePdf"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>`);
    }

    const meta = `<div class="info-list">
      ${[['Cabang', esc(s.cabang)], ['Tanggal Opname', fmtDate(s.tanggal, '/')],
      ['Penanggung Jawab', esc(s.user || '-')], ['Dibuat', esc(s.created_at || '-')],
      ['Waktu Mulai', esc(s.started_at || '-')], ['Waktu Selesai', esc(s.ended_at || '-')],
      ['Status', badgeOpname(st)], ['Catatan', esc(s.catatan || '-')]]
      .map(([k, v]) => `<div class="info-row"><div class="info-k">${k}</div><div class="info-v">${v}</div></div>`).join('')}
    </div>`;

    const progress = `
      <section class="card card-pad">
        <div class="op-prog-top"><b>${sum.dicek}</b> dari <b>${sum.total}</b> item sudah dicek (${pct}%)</div>
        <div class="prog-track"><div class="prog-fill" style="width:${pct}%"></div></div>
        <div class="op-chips">
          <span class="chip c-green"><i class="fa-solid fa-circle-check"></i> Ditemukan ${num(sum.ditemukan)}</span>
          <span class="chip c-red"><i class="fa-solid fa-circle-xmark"></i> Tidak Ditemukan ${num(sum.tidak_ditemukan)}</span>
          <span class="chip c-gray"><i class="fa-regular fa-circle"></i> Belum dicek ${num(sum.belum)}</span>
          <span class="chip c-orange"><i class="fa-solid fa-arrows-rotate"></i> Data berubah ${num(sum.berubah)}</span>
        </div>
      </section>`;

    const scanBlock = counting ? `
      <section class="card card-pad">
        <h3 class="card-title">Pemindaian Barcode</h3>
        <div class="camera" id="camera">
          <video id="scanVideo" playsinline muted></video>
          <div class="scan-frame"><i class="fa-solid fa-qrcode"></i></div>
          <div class="scan-code" id="scanCode">${esc(all[0] ? all[0].kode : 'INV-BTK-0001')}</div>
          <div class="scan-hint" id="scanHint">Tekan "Gunakan Kamera" untuk mulai, atau ketik barcode di bawah.</div>
        </div>
        <div class="scan-actions">
          <button class="btn btn-primary" id="camBtn" data-action="toggleCam"><i class="fa-solid fa-camera"></i> Gunakan Kamera</button>
          <button class="btn btn-light" data-action="qrUpload"><i class="fa-regular fa-image"></i> Upload Gambar QR</button>
          <input type="file" id="qrImage" accept="image/*" hidden>
        </div>
        <div class="row" style="margin-top:12px">
          <input type="text" id="opnameBarcode" autocomplete="off" placeholder="Scan / ketik barcode (ID inventaris) lalu tekan Enter">
          <button class="btn btn-primary" data-action="opnameHit"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>
        <div class="small muted" style="margin-top:8px">Input barcode selalu aktif — pindai barang berikutnya langsung bisa dilakukan.</div>
      </section>` : '';

    const exportBlock = st === 'Selesai' ? `
      <section class="card card-pad">
        <h3 class="card-title">Unduh Laporan Sesi</h3>
        <div class="scope-row" role="radiogroup" aria-label="Cakupan laporan">
          <label class="radio"><input type="radio" name="opnameScope" value="all" ${f.scope !== 'diff' ? 'checked' : ''}> Semua item</label>
          <label class="radio"><input type="radio" name="opnameScope" value="diff" ${f.scope === 'diff' ? 'checked' : ''}> Hanya selisih</label>
        </div>
        <div class="small muted" style="margin-top:6px">Laporan memuat kop sesi (cabang, nama &amp; tanggal sesi, waktu mulai/akhir, penanggung jawab), tabel barang, dan ringkasan.</div>
        <div class="scan-actions" style="margin-top:12px">
          <button class="btn btn-primary" data-action="exportOpnameXlsx"><i class="fa-solid fa-file-excel"></i> Export Excel (.xlsx)</button>
          <button class="btn btn-outline" data-action="exportOpnamePdf"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
        </div>
      </section>` : '';

    const filters = `
      <div class="filters">
        <div class="search"><i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" data-opfilter="q" value="${esc(f.q)}" placeholder="Cari nama / SKU / barcode...">
        </div>
        <select data-opfilter="itemStatus" aria-label="Filter status item">
          <option value="" ${f.itemStatus === '' ? 'selected' : ''}>Semua Status</option>
          <option value="Belum" ${f.itemStatus === 'Belum' ? 'selected' : ''}>Belum Diperiksa</option>
          <option value="Ditemukan" ${f.itemStatus === 'Ditemukan' ? 'selected' : ''}>Ditemukan</option>
          <option value="Tidak Ditemukan" ${f.itemStatus === 'Tidak Ditemukan' ? 'selected' : ''}>Tidak Ditemukan</option>
        </select>
        <select data-opfilter="kat" aria-label="Filter kategori">
          <option value="" ${f.kat === '' ? 'selected' : ''}>Semua Kategori</option>
          ${(DB.lists.kategori || []).map((k) => `<option value="${esc(k)}" ${f.kat === k ? 'selected' : ''}>${esc(k)}</option>`).join('')}
        </select>
        <button class="btn btn-light" data-action="resetOpnameItem"><i class="fa-solid fa-rotate-left"></i> Reset</button>
        <span class="small muted" style="margin-left:auto">${rows.length} dari ${all.length} item</span>
      </div>`;

    const iper = f.itemPer;
    const istart = (Math.max(1, Math.min(f.itemPage, Math.max(1, Math.ceil(rows.length / iper)))) - 1) * iper;
    const pageItems = rows.slice(istart, istart + iper);

    // Nilai Lokasi/PIC/Kondisi item: tampilkan nilai kerja + nilai awal bila berubah
    const cellVal = (i, key, asBadge) => {
      const cur = i[key];
      const awal = opnameAwal(i, key);
      const txt = cur == null || cur === '' ? '-' : cur;
      const head = asBadge ? badgeKondisi(txt) : esc(txt);
      return `<div>${head}</div>${cur !== awal
        ? `<div class="small muted">awal: ${esc(awal == null || awal === '' ? '-' : awal)}</div>` : ''}`;
    };

    const body = pageItems.map((i, n) => {
      const no = istart + n + 1;
      const hit = f.hit === i.kode ? ' row-hit' : '';
      const statusBadge = i.status === 'Ditemukan' ? '<span class="badge b-green">Ditemukan</span>'
        : i.status === 'Tidak Ditemukan' ? '<span class="badge b-red">Tidak Ditemukan</span>'
          : '<span class="badge b-gray">Belum</span>';
      const aksi = counting
        ? (i.status === 'Belum'
          ? `<button class="btn btn-sm btn-success" data-action="opnameCheck" data-kode="${esc(i.kode)}"><i class="fa-solid fa-check"></i> Periksa</button>
             <button class="btn btn-sm btn-outline" data-action="opnameMissing" data-kode="${esc(i.kode)}"><i class="fa-solid fa-xmark"></i> Tidak Ada</button>`
          : `<button class="btn btn-sm btn-soft" data-action="opnameCheck" data-kode="${esc(i.kode)}"><i class="fa-solid fa-pen"></i> Ubah</button>
             <button class="btn btn-sm btn-soft" data-action="opnameUncheck" data-kode="${esc(i.kode)}"><i class="fa-solid fa-rotate-left"></i> Batalkan</button>`)
        : '<span class="small muted">-</span>';
      return `<tr id="row-${esc(i.kode)}" class="${hit.trim()}">
        <td class="td-num">${no}</td>
        <td><b>${esc(i.kode)}</b></td>
        <td>${esc(i.nama)}</td>
        <td>${esc(i.kategori)}</td>
        <td>${cellVal(i, 'lokasi')}</td>
        <td>${cellVal(i, 'pic')}</td>
        <td>${cellVal(i, 'kondisi', true)}</td>
        <td>${statusBadge}</td>
        <td><div class="cell-actions">${aksi}</div></td>
      </tr>`;
    }).join('');

    const table = `
      <div class="card">
        ${filters}
        <div class="table-wrap"><table class="tbl opname-tbl">
          <thead><tr>
            <th>No</th><th>SKU / ID</th><th>Nama Barang</th><th>Kategori</th>
            <th>Lokasi</th><th>PIC</th><th>Kondisi</th><th>Status</th><th style="text-align:right">Aksi</th>
          </tr></thead>
          <tbody>${body || `<tr><td colspan="9"><div class="empty"><i class="fa-regular fa-folder-open"></i>${
        all.length ? 'Tidak ada item yang cocok dengan filter.' : 'Belum ada inventaris pada cabang ini saat sesi dibuat.'}</div></td></tr>`}</tbody>
        </table></div>
        <div class="table-foot">
          <span>Menampilkan ${pageItems.length ? istart + 1 : 0} - ${Math.min(istart + iper, rows.length)} dari ${rows.length} item</span>
          ${pagerHtml(Math.max(1, Math.min(f.itemPage, Math.max(1, Math.ceil(rows.length / iper)))), iper, rows.length, 'pageOpnameItems')}
        </div>
      </div>`;

    return `
      <a class="back-link" href="#/opname"><i class="fa-solid fa-chevron-left"></i> Kembali ke Daftar Stock Opname</a>
      <div class="page-head">
        <div>
          <h1 class="page-title">${esc(s.nama)} ${badgeOpname(st)}</h1>
          <p class="page-sub">${esc(s.id)} · ${esc(s.cabang)} · ${fmtDate(s.tanggal, '/')}${counting ? ' · <b class="c-blue">Sedang berjalan</b>' : ''}</p>
        </div>
        <div class="page-actions">${actions.join('')}</div>
      </div>

      ${staleNote}
      ${st === 'Dibatalkan' ? `<div class="warn-banner"><i class="fa-solid fa-ban"></i> Sesi ini dibatalkan — hitungan tidak disimpan dan tidak bisa dilanjutkan.</div>` : ''}

      <div class="detail-grid dg-2" style="margin-bottom:14px">
        <section class="card card-pad"><h3 class="card-title">Informasi Sesi</h3>${meta}</section>
        <section class="card card-pad">
          <h3 class="card-title">Progres Penghitungan</h3>
          ${progress}
          ${st === 'Selesai' ? opnameSumHtml(sum) : ''}
        </section>
      </div>

      ${scanBlock}
      ${exportBlock}
      ${table}`;
  }

  /* ---- aksi layar penghitungan ---- */

  function refreshKeepScroll() {
    const y = window.scrollY || 0;
    router();
    try { window.scrollTo(0, y); } catch (e) { /* ignore */ }
  }

  // Sorot barang hasil scan/manual: bersihkan filter supaya item selalu terlihat,
  // lalu buka popup pemeriksaan (pilih Ditemukan / Tidak Ditemukan + cek data barang).
  function applyOpnameHit(it) {
    const op = state.opname;
    op.q = ''; op.itemStatus = ''; op.kat = '';
    op.hit = it.kode;
    const list = opnameItems(currentSession());
    const idx = list.findIndex((x) => x.kode === it.kode);
    op.itemPage = idx >= 0 ? Math.floor(idx / op.itemPer) + 1 : 1;
    refreshKeepScroll();
    openOpnameItemModal(it.kode);
  }

  function openOpnamePick(matches) {
    showModal('Barcode Ganda', `
      <div class="field">
        <label>Pilih barang yang dimaksud <span class="req">*</span></label>
        <div class="radio-col">${matches.map((i, n) => `<label class="radio">
          <input type="radio" name="kodePilih" value="${esc(i.kode)}" ${n === 0 ? 'checked' : ''}>
          <span>${esc(i.kode)} — ${esc(i.nama)}</span></label>`).join('')}</div>
      </div>
      <p class="small muted" style="margin-top:8px">Barcode ini melekat pada ${matches.length} barang dalam sesi ini.</p>`,
    (fd) => {
      const k = fd.get('kodePilih');
      const it = matches.find((x) => x.kode === k);
      if (!it) { toast('Pilih salah satu barang terlebih dahulu.', 'error'); return false; }
      applyOpnameHit(it);
      return true;
    });
  }

  // Popup pemeriksaan barang pada sesi Berjalan: pilih Ditemukan / Tidak Ditemukan,
  // lalu periksa/update Lokasi, PIC, dan kondisi barang. Hasilnya disimpan ke sesi
  // opname dan ditulis ke tabel Data Inventaris saat sesi diakhiri (bukan saat ini).
  function openOpnameItemModal(kode) {
    const s = currentSession();
    if (!s) { toast('Buka sesi Stock Opname terlebih dahulu.', 'error'); return; }
    if (s.status !== 'Berjalan') {
      toast('Sesi opname tidak dalam status Berjalan — penghitungan terkunci.', 'error');
      return;
    }
    const it = (s.items || []).find((i) => i.kode === kode);
    if (!it) { toast('Item opname tidak ditemukan.', 'error'); return; }
    const asset = getAsset(kode);
    const cur = {
      lokasi: it.lokasi || (asset && asset.lokasi) || '',
      pic: it.pic || (asset && asset.pic) || '',
      kondisi: it.kondisi || (asset && asset.kondisi) || ''
    };
    const st0 = it.status === 'Tidak Ditemukan' ? 'Tidak Ditemukan' : 'Ditemukan';
    const opt = (list, val) => {
      const arr = (list || []).slice();
      if (val && arr.indexOf(val) < 0) arr.unshift(val);
      return arr.map((v) => `<option value="${esc(v)}" ${v === val ? 'selected' : ''}>${esc(v)}</option>`).join('');
    };
    const foto = (asset && asset.foto) || '';
    const body = `
      <div class="opname-card">
        ${foto ? `<img src="${esc(foto)}" alt="Foto ${esc(it.nama)}">`
        : '<span class="opm-thumb"><i class="fa-solid fa-box-open"></i></span>'}
        <div class="opm-info">
          <b>${esc(it.nama)}</b>
          <div class="small muted">${esc(it.kode)} · ${esc(it.kategori)} · ${esc(s.cabang)}</div>
          <div class="small muted">Status sesi saat ini: ${esc(it.status)}</div>
        </div>
      </div>
      <div class="field">
        <label>Hasil pemeriksaan <span class="req">*</span></label>
        <div class="radio-col">
          <label class="radio"><input type="radio" name="opHasil" value="Ditemukan" ${st0 === 'Ditemukan' ? 'checked' : ''}>
            <span><i class="fa-solid fa-circle-check"></i> Ditemukan</span></label>
          <label class="radio"><input type="radio" name="opHasil" value="Tidak Ditemukan" ${st0 === 'Tidak Ditemukan' ? 'checked' : ''}>
            <span><i class="fa-solid fa-circle-xmark"></i> Tidak Ditemukan</span></label>
        </div>
      </div>
      <div class="fields" data-op-fields>
        <div class="field"><label>Lokasi Barang</label>
          <select name="lokasi" aria-label="Lokasi barang">${opt(DB.lists.lokasi, cur.lokasi)}</select></div>
        <div class="field"><label>PIC</label>
          <select name="pic" aria-label="PIC barang">${opt(DB.lists.pic, cur.pic)}</select></div>
        <div class="field span-2"><label>Kondisi Barang</label>
          <select name="kondisi" aria-label="Kondisi barang">${opt(KONDISI, cur.kondisi)}</select></div>
      </div>
      <p class="small muted" data-op-note hidden>
        Barang dinyatakan <b>Tidak Ditemukan</b> — Lokasi &amp; PIC tidak diubah, dan kondisi aset diatur
        <b>Tidak Ditemukan</b> pada Data Inventaris saat sesi diakhiri.
      </p>
      <p class="small muted">
        Perubahan Lokasi / PIC / kondisi disimpan ke <b>Data Inventaris</b> saat sesi diakhiri.
        Membatalkan sesi tidak mengubah data apa pun.
      </p>`;

    showModal('Periksa Barang', body, (fd) => {
      const status = fd.get('opHasil') || 'Ditemukan';
      if (status === 'Tidak Ditemukan') { opnameCount(kode, { status }); return; }
      const lokasi = String(fd.get('lokasi') || '').trim();
      const pic = String(fd.get('pic') || '').trim();
      const kondisi = String(fd.get('kondisi') || '').trim();
      if (!lokasi) { toast('Lokasi wajib diisi.', 'error'); return false; }
      if (!pic) { toast('PIC wajib diisi.', 'error'); return false; }
      if (KONDISI.indexOf(kondisi) < 0) { toast('Kondisi tidak valid.', 'error'); return false; }
      opnameCount(kode, { status, lokasi, pic, kondisi });
    });

    // radio Ditemukan/Tidak Ditemukan -> sembunyikan form data bila barang tidak ada
    const overlays = document.querySelectorAll('.modal-overlay');
    const ov = overlays[overlays.length - 1];
    if (!ov) return;
    const fields = ov.querySelector('[data-op-fields]');
    const note = ov.querySelector('[data-op-note]');
    const sync = () => {
      const sel = ov.querySelector('input[name="opHasil"]:checked');
      const notFound = sel ? sel.value === 'Tidak Ditemukan' : false;
      if (fields) fields.hidden = notFound;
      if (note) note.hidden = !notFound;
    };
    ov.addEventListener('change', sync);
    sync();
  }

  function opnameHit(raw) {
    const code = String(raw || '').trim().toUpperCase();
    const s = currentSession();
    if (!s) { toast('Buka sesi Stock Opname terlebih dahulu.', 'error'); return false; }
    if (!code) { toast('Masukkan barcode terlebih dahulu.', 'error'); return false; }
    if (s.status !== 'Berjalan') {
      toast('Sesi opname tidak dalam status Berjalan — penghitungan terkunci.', 'error');
      return false;
    }
    const matches = (s.items || []).filter((i) =>
      String(i.barcode || '').toUpperCase() === code || String(i.kode || '').toUpperCase() === code);
    if (!matches.length) {
      const known = getAsset(code);
      toast(known ? `Barang ${code} bukan bagian dari sesi ${s.id}.` : `ID "${code}" tidak terdaftar pada sesi ini.`, 'error');
      const hint = $('#scanHint');
      if (hint) hint.textContent = `Barcode "${code}" tidak ada dalam sesi ini.`;
      return false;
    }
    if (matches.length > 1) { openOpnamePick(matches); return true; }
    applyOpnameHit(matches[0]);
    return true;
  }

  async function opnameCount(kode, payload) {
    const sid = state.opname.sid;
    if (!sid) return;
    try {
      const r = await api('PUT', '/opname-sessions/' + encodeURIComponent(sid) + '/items/' + encodeURIComponent(kode), payload);
      commit(r);
    } catch (e) { fail(e); }
    refreshKeepScroll();
  }

  async function saveOpnameSession(form) {
    const el = (n) => form.elements[n];
    const cabang = el('cabang') ? el('cabang').value : '';
    const nama = (el('nama') ? el('nama').value : '').trim();
    const tanggal = el('tanggal') ? el('tanggal').value : '';
    const catatan = (el('catatan') ? el('catatan').value : '').trim();
    if (!cabang) { toast('Cabang wajib dipilih.', 'error'); return; }
    if (!nama) { toast('Nama sesi opname wajib diisi.', 'error'); return; }
    if (!tanggal) { toast('Tanggal opname wajib diisi.', 'error'); return; }
    const running = opnameSessions().find((x) => x.cabang === cabang && x.status === 'Berjalan');
    if (running) {
      toast(`Masih ada sesi opname berjalan pada cabang ini (${running.id}). Akhiri sesi terlebih dahulu.`, 'error');
      return;
    }
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...'; }
    try {
      const r = await api('POST', '/opname-sessions', { cabang, nama, tanggal, catatan });
      commit(r);
      const id = r.id || '';
      toast('Sesi opname ' + id + ' dibuat.', 'success');
      go('opname/' + id);
    } catch (e) {
      fail(e);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-plus"></i> Buat Sesi &amp; Lanjut'; }
    }
  }

  async function opnameStart() {
    const s = currentSession();
    if (!s) return;
    try {
      const r = await api('POST', '/opname-sessions/' + encodeURIComponent(s.id) + '/start', {});
      commit(r);
      toast('Sesi ' + s.id + ' dimulai — selamat menghitung.', 'success');
    } catch (e) { fail(e); }
    router();
  }

  // Modal ringkasan sebelum sesi dikunci (FR-16)
  function summaryConfirm(title, bodyHtml, okText) {
    return new Promise((resolve) => {
      const ov = document.createElement('div');
      ov.className = 'modal-overlay';
      ov.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true">
          <div class="modal-head">
            <h3>${esc(title)}</h3>
            <button type="button" class="icon-btn" data-close aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="modal-body">
            ${bodyHtml}
            <div class="modal-actions">
              <button type="button" class="btn btn-soft" data-close>Batal</button>
              <button type="button" class="btn btn-danger" data-ok>${esc(okText || 'Ya, Lanjutkan')}</button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(ov);
      const done = (v) => { ov.remove(); resolve(v); };
      ov.addEventListener('click', (e) => {
        if (e.target.closest('[data-ok]')) done(true);
        else if (e.target === ov || e.target.closest('[data-close]')) done(false);
      });
      const okBtn = ov.querySelector('[data-ok]');
      if (okBtn) okBtn.focus();
    });
  }

  async function opnameEnd() {
    const s = currentSession();
    if (!s || s.status !== 'Berjalan') return;
    const sum = opnameSummary(s.items);
    const ok = await summaryConfirm('Akhiri Sesi Opname?', `
      ${opnameSumHtml(sum)}
      <p class="small muted" style="margin-top:10px">
        Item yang <b>belum dicek</b> akan ditandai <b>Tidak Ditemukan</b>, dan kondisi asetnya di
        <b>Data Inventaris</b> ikut diubah menjadi <b>Tidak Ditemukan</b>.<br>
        Seluruh perubahan <b>Lokasi / PIC / kondisi</b> pada item Ditemukan juga akan ditulis ke
        <b>Data Inventaris</b>. Membatalkan sesi (tanpa mengakhiri) tidak mengubah data apa pun.<br>
        Setelah diakhiri, sesi terkunci dan tidak bisa diubah lagi — laporan tetap bisa diunduh kapan saja.
      </p>`, 'Ya, Akhiri Sesi');
    if (!ok) return;
    try {
      const r = await api('POST', '/opname-sessions/' + encodeURIComponent(s.id) + '/end', {});
      commit(r);
      toast('Sesi ' + s.id + ' diakhiri. Hasil opname sudah ditulis ke Data Inventaris.', 'success');
    } catch (e) { fail(e); }
    router();
  }

  async function opnameCancel() {
    const s = currentSession();
    if (!s) return;
    const ok = await confirmDialog(
      `Batalkan sesi opname ${s.id} (${s.nama})? Seluruh hitungan pada sesi ini dibuang dan sesi tidak bisa dipakai lagi.`,
      'Ya, Batalkan');
    if (!ok) return;
    try {
      const r = await api('POST', '/opname-sessions/' + encodeURIComponent(s.id) + '/cancel', {});
      commit(r);
      toast('Sesi ' + s.id + ' dibatalkan.', 'info');
    } catch (e) { fail(e); }
    router();
  }

  async function handleDetailDoc(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    const kode = input.dataset.kode;
    input.value = '';
    if (file.size > 5 * 1024 * 1024) { toast('Ukuran dokumen maksimal 5MB.', 'error'); return; }
    try {
      const dataUrl = await readAsDataURL(file);
      const up = await api('POST', '/upload', { kind: 'dokumen', name: file.name, data: dataUrl });
      const r = await api('POST', '/assets/' + encodeURIComponent(kode) + '/docs', { nama: file.name, url: up.url });
      commit(r);
      toast('Dokumen terunggah.', 'success');
      router();
    } catch (e) { fail(e); }
  }

  /* ========================================================
     12. VIEW: LAPORAN
     ======================================================== */
  function reportRows() {
    const r = state.report;
    return DB.assets.filter((a) =>
      (!r.cabang || r.cabang === 'Semua Cabang' || (a.cabang || '') === r.cabang) &&
      (!r.divisi || a.divisi === r.divisi) &&
      (!r.kategori || a.kategori === r.kategori) &&
      (!r.kondisi || a.kondisi === r.kondisi) &&
      (!r.tahun || String(a.tahun) === String(r.tahun)) &&
      (!r.from || a.tgl >= r.from) &&
      (!r.to || a.tgl <= r.to)
    );
  }

  function viewReport() {
    const r = state.report;
    const rows = reportRows();
    const pages = Math.max(1, Math.ceil(rows.length / r.per));
    r.page = Math.min(r.page, pages);
    const start = (r.page - 1) * r.per;
    const slice = rows.slice(start, start + r.per);
    const years = Array.from(new Set(DB.assets.map((a) => String(a.tahun)))).sort().reverse();

    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Laporan Inventaris</h1>
          <p class="page-sub">Hasil inventaris berdasarkan filter yang dipilih.</p>
        </div>
        <div class="row">
          <button class="btn btn-success" data-action="exportExcel"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
          <button class="btn btn-primary" data-action="exportPdf"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
        </div>
      </div>

      <div class="card">
        <div class="report-filters">
          <div class="rf"><label>Cabang</label>
            <select data-rfilter="cabang">${sel(['TM Buntok', 'TM Baru', 'TM Kuala Kapuas', 'Semua Cabang'], r.cabang)}</select></div>
          <div class="rf"><label>Divisi</label>
            <select data-rfilter="divisi">${sel(DB.lists.divisi, r.divisi, 'Semua')}</select></div>
          <div class="rf"><label>Kategori</label>
            <select data-rfilter="kategori">${sel(DB.lists.kategori, r.kategori, 'Semua')}</select></div>
          <div class="rf"><label>Kondisi</label>
            <select data-rfilter="kondisi">${sel(KONDISI, r.kondisi, 'Semua')}</select></div>
          <div class="rf"><label>Tahun</label>
            <select data-rfilter="tahun">${sel(years, r.tahun, 'Semua')}</select></div>
        </div>

        <div class="period-row">
          <div class="rf"><label>Periode</label><input type="date" data-rfilter="from" value="${r.from}"></div>
          <div class="period-sep">s/d</div>
          <div class="rf"><label>&nbsp;</label><input type="date" data-rfilter="to" value="${r.to}"></div>
          <button class="btn btn-primary" data-action="applyReport"><i class="fa-solid fa-magnifying-glass"></i> Tampilkan</button>
          <button class="btn btn-light" data-action="resetReport">Reset</button>
        </div>

        <div class="table-wrap">
          <table class="tbl">
            <thead><tr><th>No</th><th>ID Inventaris</th><th>Nama Barang</th><th>Lokasi</th><th>Divisi</th><th>Kondisi</th><th>Status</th></tr></thead>
            <tbody>${slice.map((a, i) => `<tr>
              <td class="td-num">${start + i + 1}</td>
              <td><a class="link-id" href="#/detail/${a.kode}">${a.kode}</a></td>
              <td>${esc(a.nama)}</td><td>${esc(a.lokasi)}</td><td>${esc(a.divisi)}</td>
              <td>${badgeKondisi(a.kondisi)}</td><td>${badgeStatus(a.status)}</td>
            </tr>`).join('') || `<tr><td colspan="7"><div class="empty"><i class="fa-regular fa-folder-open"></i>Tidak ada data pada periode ini.</div></td></tr>`}</tbody>
          </table>
        </div>
        <div class="table-foot">
          <span>Menampilkan ${rows.length ? start + 1 : 0} - ${Math.min(start + r.per, rows.length)} dari ${rows.length} data</span>
          ${pagerHtml(r.page, r.per, rows.length, 'pageReport')}
        </div>
      </div>`;
  }

  function reportFilterDescription() {
    const r = state.report;
    const bits = [`Cabang: ${r.cabang}`];
    if (r.divisi) bits.push('Divisi: ' + r.divisi);
    if (r.kategori) bits.push('Kategori: ' + r.kategori);
    if (r.kondisi) bits.push('Kondisi: ' + r.kondisi);
    if (r.tahun) bits.push('Tahun: ' + r.tahun);
    if (r.from || r.to) bits.push(`Periode: ${r.from ? fmtDate(r.from, '/') : '...'} s/d ${r.to ? fmtDate(r.to, '/') : '...'}`);
    return bits.join(' · ');
  }

  function downloadCsv(rows, header, filename, title, desc) {
    const csv = [
      [title],
      ['Filter', desc],
      ['Dicetak', nowStamp()],
      [],
      header,
      ...rows
    ].map((line) => line.map((c) => `"${String(c == null ? '' : c).replace(/"/g, '""')}"`).join(';')).join('\r\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  async function exportExcel(forceAll) {
    const rows = forceAll ? DB.assets.slice() : reportRows();
    const desc = forceAll ? 'Seluruh data inventaris (backup)' : reportFilterDescription();
    downloadCsv(
      rows.map((a) => [a.kode, a.nama, a.kategori, a.merek, a.lokasi, a.divisi, a.pic, a.kondisi, a.status, a.tahun, a.nilai]),
      ['ID Inventaris', 'Nama Barang', 'Kategori', 'Merek/Tipe', 'Lokasi', 'Divisi', 'PIC', 'Kondisi', 'Status', 'Tahun', 'Nilai Perolehan'],
      `laporan-inventaris-${isoToday()}.csv`,
      'TRIO INVENTORY CONTROL - LAPORAN INVENTARIS', desc
    );
    await logEvent('Laporan', '-', `Export Excel (${rows.length} data${forceAll ? ', backup' : ''})`);
    toast(`Export Excel berhasil — ${rows.length} baris.`, 'success');
  }

  function exportPdf() {
    const rows = reportRows();
    const html = `
      <div class="head">
        <div><div class="brand">${esc(DB.settings.perusahaan)}</div><div class="muted">${esc(DB.settings.tagline)}</div></div>
        <div class="center"><h1>Laporan Inventaris</h1><div class="muted">TRIO Inventory Control</div></div>
        <div class="center muted">${esc(DB.settings.cabang)}<br>${fmtDate(isoToday(), '/')}</div>
      </div>
      <p class="muted">Filter: ${esc(reportFilterDescription())} · Total ${rows.length} data · Dicetak oleh ${esc(USER ? USER.nama : '-')}</p>
      <table>
        <thead><tr><th>No</th><th>ID Inventaris</th><th>Nama Barang</th><th>Lokasi</th><th>Divisi</th><th>Kondisi</th><th>Status</th><th>Nilai</th></tr></thead>
        <tbody>${rows.map((a, i) => `<tr>
          <td>${i + 1}</td><td>${a.kode}</td><td>${esc(a.nama)}</td><td>${esc(a.lokasi)}</td>
          <td>${esc(a.divisi)}</td><td>${esc(a.kondisi)}</td><td>${esc(a.status)}</td><td>${rupiah(a.nilai)}</td>
        </tr>`).join('')}</tbody>
      </table>
      <p class="muted">Total nilai perolehan: <b>${rupiah(rows.reduce((s, a) => s + Number(a.nilai || 0), 0))}</b></p>`;
    printWindow(html);
    logEvent('Laporan', '-', `Export PDF (${rows.length} data)`);
  }

  /* ========================================================
     13b. EXPORT LAPORAN STOCK OPNAME (sesi)
     .xlsx asli (zip STORE + XML) & PDF lewat jendela cetak.
     ======================================================== */
  function utf8Bytes(str) {
    const out = [];
    const s = String(str == null ? '' : str);
    for (let i = 0; i < s.length; i++) {
      let c = s.charCodeAt(i);
      if (c < 0x80) out.push(c);
      else if (c < 0x800) out.push(0xC0 | (c >> 6), 0x80 | (c & 63));
      else if (c >= 0xD800 && c <= 0xDBFF && i + 1 < s.length) {
        const c2 = s.charCodeAt(++i);
        const cp = 0x10000 + ((c - 0xD800) << 10) + (c2 - 0xDC00);
        out.push(0xF0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
      } else out.push(0xE0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    }
    return new Uint8Array(out);
  }

  const CRC_TABLE = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
      let c = n;
      for (let k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[n] = c >>> 0;
    }
    return t;
  })();

  function crc32(u8) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < u8.length; i++) c = CRC_TABLE[(c ^ u8[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  }

  function zipColName(n) {
    let s = '';
    while (n > 0) {
      const m = (n - 1) % 26;
      s = String.fromCharCode(65 + m) + s;
      n = Math.floor((n - m - 1) / 26);
    }
    return s;
  }

  function xmlEsc(s) {
    return String(s == null ? '' : s)
      .replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]))
      // eslint-disable-next-line no-control-regex
      .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '');
  }

  // Zip tanpa kompresi (method STORE) — cukup untuk Excel/LibreOffice/Google Sheets
  function zipStore(files) {
    const DOS_TIME = (12 << 11);              // 12:00:00
    const DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1;   // 1 Jan 2026
    const chunks = [];
    const central = [];
    let offset = 0;
    files.forEach((f) => {
      const nameB = utf8Bytes(f.name);
      const data = f.data;
      const crc = crc32(data);

      const lh = new Uint8Array(30 + nameB.length);
      const dv = new DataView(lh.buffer);
      dv.setUint32(0, 0x04034b50, true);
      dv.setUint16(4, 20, true);        // versi butuh
      dv.setUint16(6, 0x0800, true);    // flag: nama file UTF-8
      dv.setUint16(8, 0, true);         // metode STORE
      dv.setUint16(10, DOS_TIME, true);
      dv.setUint16(12, DOS_DATE, true);
      dv.setUint32(14, crc, true);
      dv.setUint32(18, data.length, true);
      dv.setUint32(22, data.length, true);
      dv.setUint16(26, nameB.length, true);
      dv.setUint16(28, 0, true);
      lh.set(nameB, 30);
      chunks.push(lh, data);

      const ch = new Uint8Array(46 + nameB.length);
      const cv = new DataView(ch.buffer);
      cv.setUint32(0, 0x02014b50, true);
      cv.setUint16(4, 20, true);        // versi dibuat
      cv.setUint16(6, 20, true);        // versi butuh
      cv.setUint16(8, 0x0800, true);
      cv.setUint16(10, 0, true);
      cv.setUint16(12, DOS_TIME, true);
      cv.setUint16(14, DOS_DATE, true);
      cv.setUint32(16, crc, true);
      cv.setUint32(20, data.length, true);
      cv.setUint32(24, data.length, true);
      cv.setUint16(28, nameB.length, true);
      cv.setUint32(42, offset, true);   // offset lokal
      ch.set(nameB, 46);
      central.push(ch);
      offset += lh.length + data.length;
    });

    const cdSize = central.reduce((a, c) => a + c.length, 0);
    const eocd = new Uint8Array(22);
    const ev = new DataView(eocd.buffer);
    ev.setUint32(0, 0x06054b50, true);
    ev.setUint16(8, files.length, true);
    ev.setUint16(10, files.length, true);
    ev.setUint32(12, cdSize, true);
    ev.setUint32(16, offset, true);
    ev.setUint16(20, 0, true);

    const all = [...chunks, ...central, eocd];
    const total = all.reduce((a, x) => a + x.length, 0);
    const out = new Uint8Array(total);
    let p = 0;
    all.forEach((x) => { out.set(x, p); p += x.length; });
    return out;
  }

  // rows (array of array) -> bytes .xlsx (tanpa header relasional mewah)
  function buildXlsx(rows, sheetName) {
    const sheetRows = rows.map((row, r) => {
      const cells = row.map((v, c) => {
        const ref = zipColName(c + 1) + (r + 1);
        if (typeof v === 'number' && isFinite(v)) return `<c r="${ref}"><v>${v}</v></c>`;
        return `<c r="${ref}" t="inlineStr"><is><t xml:space="preserve">${xmlEsc(v)}</t></is></c>`;
      }).join('');
      return `<row r="${r + 1}">${cells}</row>`;
    }).join('');

    const files = [
      { name: '[Content_Types].xml', data: utf8Bytes(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
        '<Default Extension="xml" ContentType="application/xml"/>' +
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
        '</Types>') },
      { name: '_rels/.rels', data: utf8Bytes(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
        '</Relationships>') },
      { name: 'xl/workbook.xml', data: utf8Bytes(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' +
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
        `<sheets><sheet name="${xmlEsc(sheetName || 'Laporan')}" sheetId="1" r:id="rId1"/></sheets></workbook>`) },
      { name: 'xl/_rels/workbook.xml.rels', data: utf8Bytes(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' +
        '</Relationships>') },
      { name: 'xl/worksheets/sheet1.xml', data: utf8Bytes(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
        `<sheetData>${sheetRows}</sheetData></worksheet>`) }
    ];
    return zipStore(files);
  }

  function downloadBytes(bytes, filename, mime) {
    const blob = new Blob([bytes], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  const OPNAME_HEADER = ['No', 'SKU', 'Barcode', 'Nama Barang', 'Kategori',
    'Lokasi', 'PIC', 'Kondisi', 'Status', 'Dihitung Oleh'];

  // Item sesuai cakupan pilihan: semua item atau hanya selisih (FR-24) —
  // selisih = barang tidak ditemukan ATAU data lokasi/PIC/kondisi berubah.
  function opnameScopeItems(s) {
    const all = s.items || [];
    if (state.opname.scope === 'diff') {
      return all.filter((i) => i.status === 'Tidak Ditemukan' || opnameChanged(i));
    }
    return all;
  }

  function opnameScopeLabel() {
    return state.opname.scope === 'diff' ? 'Hanya selisih' : 'Semua item';
  }

  function opnameReportData(s) {
    const items = opnameScopeItems(s);
    const sum = opnameFinalSummary(s);
    const head = [
      ['TRIO INVENTORY CONTROL - LAPORAN STOCK OPNAME'],
      ['Cabang', s.cabang],
      ['Sesi Opname', `${s.nama} (${s.id})`],
      ['Tanggal Opname', fmtDate(s.tanggal, '/')],
      ['Waktu Mulai', s.started_at || '-'],
      ['Waktu Selesai', s.ended_at || '-'],
      ['Penanggung Jawab', s.user || '-'],
      ['Cakupan', opnameScopeLabel()],
      ['Dicetak', `${nowStamp()} oleh ${USER ? USER.nama : '-'}`],
      []
    ];
    const rows = items.map((i, n) => [
      n + 1, i.sku || i.kode, i.barcode || i.kode, i.nama, i.kategori,
      i.lokasi || '-', i.pic || '-', i.kondisi || '-', i.status, i.counted_by || '-'
    ]);
    const foot = [
      [],
      ['Ringkasan (seluruh item sesi)'],
      ['Total item', sum.total],
      ['Sudah dicek', sum.dicek],
      ['Belum dicek', sum.belum],
      ['Ditemukan', sum.ditemukan],
      ['Tidak ditemukan', sum.tidak_ditemukan],
      ['Data berubah', sum.berubah]
    ];
    return { head, rows, foot, sum, count: items.length };
  }

  async function exportOpnameXlsx() {
    const s = currentSession();
    if (!s || s.status !== 'Selesai') { toast('Laporan hanya tersedia untuk sesi yang sudah diakhiri.', 'error'); return; }
    try {
      const d = opnameReportData(s);
      const bytes = buildXlsx([...d.head, OPNAME_HEADER, ...d.rows, ...d.foot], 'Laporan Opname');
      downloadBytes(bytes, `laporan-opname-${s.id}-${isoToday()}.xlsx`,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      await logEvent('Stock Opname', s.id,
        `Export Excel (${d.count} baris, cakupan ${opnameScopeLabel().toLowerCase()})`);
      toast(`Export Excel berhasil — ${d.count} baris.`, 'success');
    } catch (e) { fail(e); }
  }

  function exportOpnamePdf() {
    const s = currentSession();
    if (!s || s.status !== 'Selesai') { toast('Laporan hanya tersedia untuk sesi yang sudah diakhiri.', 'error'); return; }
    try {
      const d = opnameReportData(s);
      const sum = d.sum;
      const html = `
        <div class="head">
          <div><div class="brand">${esc(DB.settings.perusahaan)}</div><div class="muted">${esc(DB.settings.tagline)}</div></div>
          <div class="center"><h1>Laporan Stock Opname</h1><div class="muted">TRIO Inventory Control</div></div>
          <div class="center muted">${esc(s.cabang)}<br>${fmtDate(s.tanggal, '/')}</div>
        </div>
        <p class="muted">
          Sesi: <b>${esc(s.nama)}</b> (${esc(s.id)}) · Penanggung Jawab: <b>${esc(s.user || '-')}</b><br>
          Mulai: ${esc(s.started_at || '-')} · Selesai: ${esc(s.ended_at || '-')} ·
          Cakupan: ${esc(opnameScopeLabel())} · Dicetak: ${nowStamp()} oleh ${esc(USER ? USER.nama : '-')}
        </p>
        <table>
          <thead><tr>${OPNAME_HEADER.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>
          <tbody>${d.rows.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>
        <h2>Ringkasan (seluruh item sesi)</h2>
        <table>
          <tbody>
            <tr><th>Total item</th><td>${num(sum.total)}</td><th>Sudah dicek</th><td>${num(sum.dicek)}</td></tr>
            <tr><th>Belum dicek</th><td>${num(sum.belum)}</td><th>Ditemukan</th><td>${num(sum.ditemukan)}</td></tr>
            <tr><th>Tidak ditemukan</th><td>${num(sum.tidak_ditemukan)}</td><th>Data berubah</th><td>${num(sum.berubah)}</td></tr>
            <tr><th>Baris laporan</th><td>${num(d.count)}</td><th>Cakupan</th><td>${esc(opnameScopeLabel())}</td></tr>
          </tbody>
        </table>`;
      printWindow(html);
      logEvent('Stock Opname', s.id, `Export PDF (${d.count} baris, cakupan ${opnameScopeLabel().toLowerCase()})`);
      toast('Laporan PDF dibuka pada jendela cetak.', 'success');
    } catch (e) { fail(e); }
  }

  // Hook uji integrasi: membangun byte .xlsx murni di sisi klien
  if (typeof window !== 'undefined') window.__TRIO_XLSX__ = buildXlsx;

  /* ========================================================
     13. VIEW: TRANSAKSI / MUTASI
     ======================================================== */
  function viewTransaksi() {
    const pending = DB.mutations.filter((m) => m.status === 'Pending').length;
    const disetujui = DB.mutations.filter((m) => m.status === 'Disetujui').length;
    const ditolak = DB.mutations.filter((m) => m.status === 'Ditolak').length;
    const tStatus = state.trans.status;
    const mutasiRows = DB.mutations
      .filter((m) => !tStatus || m.status === tStatus)
      .slice()
      .sort((a, b) => b.tgl.localeCompare(a.tgl));

    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Transaksi &amp; Mutasi Barang</h1>
          <p class="page-sub">Pantau dan setujui perpindahan aset antar lokasi.</p>
        </div>
        <button class="btn btn-primary" data-action="newMutation"><i class="fa-solid fa-plus"></i> Ajukan Mutasi</button>
      </div>

      <div class="mini-stats">
        <div class="mini"><b style="color:var(--orange)">${pending}</b><span>Menunggu Approval</span></div>
        <div class="mini"><b style="color:var(--green)">${disetujui}</b><span>Disetujui</span></div>
        <div class="mini"><b style="color:var(--red)">${ditolak}</b><span>Ditolak</span></div>
      </div>

      <div class="filters">
        <select data-tfilter="status" title="Filter status mutasi">${sel(['Pending', 'Disetujui', 'Ditolak'], tStatus, 'Semua Status Mutasi')}</select>
        ${tStatus ? `<button class="btn btn-outline" data-action="resetTrans"><i class="fa-solid fa-rotate-left"></i> Tampilkan Semua</button>` : ''}
      </div>

      <div class="card">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>ID</th><th>Tanggal</th><th>ID Inventaris</th><th>Dari</th><th>Ke</th><th>Pengaju</th><th>Status</th><th style="text-align:right">Aksi</th></tr></thead>
          <tbody>${mutasiRows.map((m) => `
            <tr>
              <td><b>${m.id}</b></td>
              <td>${fmtDate(m.tgl)}</td>
              <td><a class="link-id" href="#/detail/${m.kode}">${m.kode}</a></td>
              <td>${esc(m.dari)}</td><td>${esc(m.ke)}</td><td>${esc(m.user)}</td>
              <td>${badgeMutasi(m.status)}</td>
              <td><div class="cell-actions">
                ${m.status === 'Pending'
        ? (can('mutation.approve') ? `
                  <button class="btn btn-sm btn-success" data-action="mutasi" data-id="${m.id}" data-set="Disetujui" title="Setujui"><i class="fa-solid fa-check"></i></button>
                  <button class="btn btn-sm btn-light" data-action="mutasi" data-id="${m.id}" data-set="Ditolak" title="Tolak"><i class="fa-solid fa-xmark"></i></button>` : '<span class="muted small">Menunggu approval</span>')
        : '<span class="muted small">Selesai</span>'}
              </div></td>
            </tr>`).join('')}</tbody>
        </table></div>
      </div>`;
  }

  async function decideMutation(id, result) {
    try {
      const r = await api('PUT', '/mutations/' + encodeURIComponent(id), { status: result });
      commit(r);
      toast(`Mutasi ${id} ${result.toLowerCase()}.`, result === 'Disetujui' ? 'success' : 'info');
      router();
    } catch (e) { fail(e); }
  }

  function mutasiKeHtml(asset) {
    const own = asset ? asset.cabang : '';
    const cabangs = cabangOptions().filter((c) => c && c !== own);
    return `<option value="">Pilih lokasi tujuan</option>` +
      `<optgroup label="Lokasi / Ruangan">${DB.lists.lokasi.map((o) => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}</optgroup>` +
      `<optgroup label="Pindah Cabang">${cabangs.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('')}</optgroup>`;
  }

  function refreshMutasiKe(form) {
    const ke = form.elements['ke'];
    const kodeSel = form.elements['kode'];
    if (!ke || !kodeSel) return;
    const prev = ke.value;
    ke.innerHTML = mutasiKeHtml(getAsset(kodeSel.value));
    if (Array.from(ke.options).some((o) => o.value === prev)) ke.value = prev;
  }

  function openMutationModal() {
    const assetOpts = DB.assets.map((a) =>
      `<option value="${a.kode}">${a.kode} — ${esc(a.nama)} (${esc(a.lokasi)})</option>`).join('');
    const first = DB.assets.length ? getAsset(DB.assets[0].kode) : null;
    showModal('Ajukan Mutasi Barang', `
      <div class="field">
        <label>ID Inventaris <span class="req">*</span></label>
        <select name="kode" data-req required>${assetOpts}</select>
      </div>
      <div class="field">
        <label>Pindah Ke Lokasi / Cabang <span class="req">*</span></label>
        <select name="ke" data-req required>${mutasiKeHtml(first)}</select>
        <span class="hint">Pilih ruangan dalam cabang yang sama, atau cabang lain untuk pindah cabang (berlaku setelah approval).</span>
      </div>
      <div class="field">
        <label>Divisi Baru</label>
        <select name="divisi">
          <option value="">Tetap — tidak berubah</option>
          ${DB.lists.divisi.map((o) => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}
        </select>
      </div>
      <div class="field">
        <label>PIC Baru</label>
        <select name="pic">
          <option value="">Tetap — tidak berubah</option>
          ${DB.lists.pic.map((o) => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}
        </select>
      </div>
      <div class="field">
        <label>Alasan</label>
        <textarea name="alasan" placeholder="Contoh: Kebutuhan rapat penjualan"></textarea>
      </div>`,
      async (fd) => {
        const kode = String(fd.get('kode') || '');
        const ke = String(fd.get('ke') || '');
        const alasan = String(fd.get('alasan') || '').trim() || '-';
        if (!kode || !ke) { toast('Lengkapi ID inventaris dan lokasi tujuan.', 'error'); return false; }
        const payload = { kode, ke, alasan };
        const divisi = String(fd.get('divisi') || '').trim();
        const pic = String(fd.get('pic') || '').trim();
        if (divisi) payload.divisi = divisi;
        if (pic) payload.pic = pic;
        const r = await api('POST', '/mutations', payload);
        commit(r);
        toast(`Mutasi ${r.id} diajukan dan menunggu approval.`, 'success');
        router();
        return true;
      });
  }

  /* ========================================================
     14. VIEW: MASTER DATA
     ======================================================== */
  function viewMaster() {
    const block = (key, title, icon) => `
      <section class="card card-pad">
        <h3 class="card-title"><i class="fa-solid ${icon}" style="color:var(--red);margin-right:6px"></i>${title}</h3>
        <div class="chip-list">
          ${DB.lists[key].map((v) => `<span class="chip">${esc(v)}
            ${can('master.write') ? `<button data-action="rmListItem" data-list="${key}" data-val="${esc(v)}" title="Hapus"><i class="fa-solid fa-xmark"></i></button>` : ''}</span>`).join('') || '<span class="muted small">Belum ada data.</span>'}
        </div>
        ${can('master.write') ? `<div class="inline-add">
          <input type="text" id="add_${key}" placeholder="Tambah ${title.toLowerCase()} baru...">
          <button class="btn btn-primary btn-sm" data-action="addListItem" data-list="${key}"><i class="fa-solid fa-plus"></i> Tambah</button>
        </div>` : ''}
      </section>`;

    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Master Data</h1>
          <p class="page-sub">Kelola referensi data yang digunakan pada formulir inventaris.</p>
        </div>
      </div>
      <div class="master-grid">
        ${block('kategori', 'Kategori Barang', 'fa-tags')}
        ${block('divisi', 'Divisi', 'fa-sitemap')}
        ${block('lokasi', 'Lokasi / Ruangan', 'fa-location-dot')}
        ${block('pic', 'Daftar PIC', 'fa-user-tie')}
      </div>`;
  }

  /* ========================================================
     15. VIEW: AUDIT TRAIL
     ======================================================== */
  function viewAudit() {
    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Audit Trail</h1>
          <p class="page-sub">Jejak aktivitas pengguna pada sistem inventaris (tersimpan di server).</p>
        </div>
        <button class="btn btn-outline" data-action="exportAudit"><i class="fa-solid fa-download"></i> Export CSV</button>
      </div>
      <div class="card">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>Waktu</th><th>User</th><th>Aktivitas</th><th>ID Inventaris</th><th>Keterangan</th></tr></thead>
          <tbody>${DB.logs.map((l) => `<tr>
            <td style="white-space:nowrap">${esc(l.stamp)}</td>
            <td><b>${esc(l.user)}</b></td>
            <td><span class="badge b-blue">${esc(l.aksi)}</span></td>
            <td>${l.kode && l.kode !== '-' ? `<a class="link-id" href="#/detail/${l.kode}">${esc(l.kode)}</a>` : '-'}</td>
            <td>${esc(l.keterangan)}</td>
          </tr>`).join('') || `<tr><td colspan="5"><div class="empty">Belum ada aktivitas.</div></td></tr>`}</tbody>
        </table></div>
      </div>`;
  }

  function exportAudit() {
    downloadCsv(
      DB.logs.map((l) => [l.stamp, l.user, l.aksi, l.kode, l.keterangan]),
      ['Waktu', 'User', 'Aktivitas', 'ID Inventaris', 'Keterangan'],
      `audit-trail-${isoToday()}.csv`,
      'TRIO INVENTORY CONTROL - AUDIT TRAIL', 'Seluruh aktivitas sistem'
    );
    toast('Audit trail diexport.', 'success');
  }

  /* ========================================================
     15b. VIEW: USER & HAK AKSES (khusus admin)
     ======================================================== */
  const ROLE_OPTS = [['admin', 'Administrator'], ['audit', 'Auditor'], ['cabang', 'Manajemen Cabang']];

  const roleBadge = (role) => role === 'admin' ? '<span class="badge b-red">Administrator</span>'
    : role === 'audit' ? '<span class="badge b-blue">Auditor</span>'
      : '<span class="badge b-orange">Manajemen Cabang</span>';

  async function loadUsers() {
    try {
      const r = await api('GET', '/users');
      state.users = r.users || [];
    } catch (e) {
      fail(e);
      state.users = [];
    }
  }

  function viewUsers() {
    const rows = state.users || [];
    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">User &amp; Hak Akses</h1>
          <p class="page-sub">Kelola akun pengguna dan hak aksesnya berdasarkan role &amp; cabang.</p>
        </div>
        <button class="btn btn-primary" data-action="userNew"><i class="fa-solid fa-user-plus"></i> Tambah User</button>
      </div>

      <div class="card">
        <div class="table-wrap"><table class="tbl">
          <thead><tr><th>No</th><th>Username</th><th>Nama</th><th>Role</th><th>Cabang</th><th>Status</th><th style="text-align:right">Aksi</th></tr></thead>
          <tbody>${rows.map((u, i) => `<tr>
            <td class="td-num">${i + 1}</td>
            <td><b>${esc(u.username)}</b></td>
            <td>${esc(u.nama)}</td>
            <td>${roleBadge(u.role)}</td>
            <td>${esc(u.cabang === '*' ? 'Semua Cabang' : u.cabang)}</td>
            <td>${u.aktif ? '<span class="badge b-green">Aktif</span>' : '<span class="badge b-gray">Nonaktif</span>'}</td>
            <td><div class="cell-actions">
              <button class="btn btn-sm btn-light" data-action="userEdit" data-id="${u.id}" title="Edit"><i class="fa-regular fa-pen-to-square"></i></button>
              <button class="btn btn-sm btn-light" data-action="userDelete" data-id="${u.id}" title="Hapus"><i class="fa-regular fa-trash-can"></i></button>
            </div></td>
          </tr>`).join('') || `<tr><td colspan="7"><div class="empty">Belum ada user.</div></td></tr>`}</tbody>
        </table></div>
      </div>

      <div class="card card-pad mt-16">
        <h3 class="card-title">Keterangan Hak Akses</h3>
        <div class="info-list">
          ${[
            ['Administrator', 'Semua akses: kelola inventaris, mutasi + approval, stock opname, laporan, master data, pengaturan, dan konfigurasi user & login.'],
            ['Auditor', 'Semua akses kecuali konfigurasi user & login — memantau seluruh cabang, mengisi stock opname, dan laporan.'],
            ['Manajemen Cabang', 'Hanya melihat, menambahkan, dan mengajukan mutasi inventaris milik cabangnya sendiri (tanpa approval).']
          ].map(([k, v]) => `<div class="info-row"><div class="info-k">${k}</div><div class="info-v">${v}</div></div>`).join('')}
        </div>
      </div>`;
  }

  function openUserModal(user) {
    const isNew = !user;
    showModal(isNew ? 'Tambah User' : 'Edit User: ' + user.username, `
      <div class="field">
        <label>Username <span class="req">*</span></label>
        <input name="username" value="${esc(user ? user.username : '')}" ${isNew ? '' : 'disabled'} placeholder="huruf kecil, contoh: budi.s">
        ${isNew ? '<span class="hint">3-30 karakter: huruf kecil, angka, titik, strip, underscore.</span>' : ''}
      </div>
      <div class="field">
        <label>Nama Lengkap <span class="req">*</span></label>
        <input name="nama" value="${esc(user ? user.nama : '')}">
      </div>
      <div class="field">
        <label>Role <span class="req">*</span></label>
        <select name="role">${ROLE_OPTS.map(([v, l]) => `<option value="${v}" ${user && user.role === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
      <div class="field">
        <label>Cabang</label>
        <select name="cabang">${['*', ...CABANG_OPTS].map((c) => `<option value="${esc(c)}" ${((user ? user.cabang : '*') === c) ? 'selected' : ''}>${c === '*' ? 'Semua Cabang' : esc(c)}</option>`).join('')}</select>
        <span class="hint">Wajib dipilih untuk role Manajemen Cabang.</span>
      </div>
      <div class="field">
        <label>${isNew ? 'Password' : 'Password Baru'}</label>
        <input name="password" type="password" autocomplete="new-password" placeholder="${isNew ? 'minimal 6 karakter' : 'kosongkan bila tidak diganti'}">
      </div>
      <div class="field">
        <label>Status</label>
        <select name="aktif">
          <option value="1" ${!user || user.aktif ? 'selected' : ''}>Aktif</option>
          <option value="0" ${user && !user.aktif ? 'selected' : ''}>Nonaktif</option>
        </select>
      </div>`,
      async (fd) => {
        const payload = {
          username: String(fd.get('username') || '').trim(),
          nama: String(fd.get('nama') || '').trim(),
          role: String(fd.get('role') || ''),
          cabang: String(fd.get('cabang') || '*'),
          password: String(fd.get('password') || ''),
          aktif: String(fd.get('aktif')) === '1'
        };
        if (isNew) {
          if (!payload.username || !payload.nama) { toast('Username dan nama wajib diisi.', 'error'); return false; }
          if (payload.password.length < 6) { toast('Password minimal 6 karakter.', 'error'); return false; }
          const r = await api('POST', '/users', payload);
          state.users = r.users;
          toast('User ' + payload.username + ' berhasil dibuat.', 'success');
        } else {
          const r = await api('PUT', '/users/' + user.id, payload);
          state.users = r.users;
          toast('User ' + user.username + ' diperbarui.', 'success');
        }
        router();
        return true;
      });
  }

  async function deleteUser(id, username) {
    if (!confirm('Hapus user "' + username + '"? Sesi loginnya ikut dicabut.')) return;
    try {
      const r = await api('DELETE', '/users/' + id);
      state.users = r.users;
      toast('User ' + username + ' dihapus.', 'info');
      router();
    } catch (e) { fail(e); }
  }

  function openPasswordModal() {
    showModal('Ganti Password', `
      <div class="field">
        <label>Password Lama <span class="req">*</span></label>
        <input name="oldPassword" type="password" autocomplete="current-password">
      </div>
      <div class="field">
        <label>Password Baru <span class="req">*</span></label>
        <input name="newPassword" type="password" autocomplete="new-password">
        <span class="hint">Minimal 6 karakter.</span>
      </div>
      <div class="field">
        <label>Ulangi Password Baru <span class="req">*</span></label>
        <input name="confirmPassword" type="password" autocomplete="new-password">
      </div>`,
      async (fd) => {
        const oldP = String(fd.get('oldPassword') || '');
        const newP = String(fd.get('newPassword') || '');
        const conf = String(fd.get('confirmPassword') || '');
        if (newP.length < 6) { toast('Password baru minimal 6 karakter.', 'error'); return false; }
        if (newP !== conf) { toast('Konfirmasi password tidak sama.', 'error'); return false; }
        await api('PUT', '/auth/password', { oldPassword: oldP, newPassword: newP });
        toast('Password berhasil diubah.', 'success');
        return true;
      });
  }

  /* ========================================================
     16. VIEW: PENGATURAN
     ======================================================== */
  function viewSettings() {
    const s = DB.settings;
    return `
      <div class="page-head">
        <div>
          <h1 class="page-title">Pengaturan</h1>
          <p class="page-sub">Sesuaikan profil aplikasi, akun, dan backup data.</p>
        </div>
        <div class="row">
          <span class="badge ${OFFLINE ? 'b-red' : 'b-green'}">${OFFLINE ? 'Backend Terputus' : 'Backend Terhubung'}</span>
        </div>
      </div>
      <form id="settingsForm" class="grid-2a">
        <section class="card card-pad">
          <h3 class="form-section-title"><span style="color:var(--red)">1.</span> Profil Aplikasi</h3>
          <div class="fields">
            <div class="field"><label>Nama Perusahaan</label><input name="perusahaan" value="${esc(s.perusahaan)}"></div>
            <div class="field"><label>Tagline</label><input name="tagline" value="${esc(s.tagline)}"></div>
            <div class="field span-2"><label>Cabang Default (untuk aset baru)</label>
              <select name="cabang">${sel(cabangOptions(), s.cabang)}</select></div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
          </div>
          <div class="acc-box">
            <div class="acc-row"><span>Username</span><b>${esc(USER.username)}</b></div>
            <div class="acc-row"><span>Nama</span><b>${esc(USER.nama)}</b></div>
            <div class="acc-row"><span>Role</span><b>${esc(USER.roleLabel)}</b></div>
            <div class="acc-row"><span>Cabang</span><b>${esc(USER.cabang === '*' ? 'Semua Cabang' : USER.cabang)}</b></div>
            <div class="acc-row"><span>Password</span>
              <b><button type="button" class="btn btn-light btn-sm" data-action="changePw"><i class="fa-solid fa-key"></i> Ganti Password</button></b></div>
          </div>
        </section>

        <section class="card card-pad">
          <h3 class="form-section-title"><span style="color:var(--red)">2.</span> Data &amp; Backup</h3>
          <p class="small muted">Data tersimpan di server (<b>data/trio.db</b> SQLite), bukan di browser.
            Gunakan export untuk backup berkala.</p>
          <div class="row" style="margin:14px 0">
            <div class="mini" style="flex:1"><b>${DB.assets.length}</b><span>Item Inventaris</span></div>
            <div class="mini" style="flex:1"><b>${DB.mutations.length}</b><span>Transaksi</span></div>
            <div class="mini" style="flex:1"><b>${DB.logs.length}</b><span>Log Audit</span></div>
          </div>
          <div class="row">
            <button class="btn btn-success" type="button" data-action="backupAll"><i class="fa-solid fa-file-excel"></i> Backup Inventaris</button>
            <button class="btn btn-light" type="button" data-action="resetData"><i class="fa-solid fa-rotate-left"></i> Reset Data Demo</button>
          </div>
        </section>
      </form>`;
  }

  async function saveSettings(form) {
    try {
      const r = await api('PUT', '/settings', {
        perusahaan: form.elements['perusahaan'].value.trim() || 'TRIO MOTOR',
        tagline: form.elements['tagline'].value.trim(),
        cabang: form.elements['cabang'].value
      });
      commit(r);
      state.report.cabang = DB.settings.cabang;
      syncChrome();
      toast('Pengaturan tersimpan di server.', 'success');
      router();
    } catch (e) { fail(e); }
  }

  async function resetData() {
    if (!confirm('Reset seluruh data kembali ke data demo? Semua perubahan di server akan hilang.')) return;
    try {
      const r = await api('POST', '/admin/reset');
      commit(r);
      syncChrome();
      router();
      toast('Data demo dipulihkan.', 'success');
    } catch (e) { fail(e); }
  }

  /* ========================================================
     17. CHROME (topbar / sidebar / notifikasi)
     ======================================================== */
  function syncChrome() {
    if (!USER) return;
    const initials = String(USER.nama || USER.username || '').split(/\s+/).map((x) => x[0]).slice(0, 2).join('').toUpperCase();
    $('#topUserName').textContent = USER.nama;
    $('#topUserRole').textContent = USER.roleLabel || USER.role;
    $('#topAvatar').textContent = initials || 'U';
    $('#sideUserName').textContent = USER.nama;
    $('#sideUserRole').textContent = USER.roleLabel || USER.role;
    $('#sideAvatar').textContent = initials || 'U';
    $('#footYear').textContent = new Date().getFullYear();

    // pemilih cabang di topbar: role Cabang dikunci pada cabangnya sendiri
    const cs = $('#cabangSelect');
    if (USER.role === 'cabang') {
      cs.innerHTML = `<option value="${esc(USER.cabang)}">${esc(USER.cabang)}</option>`;
      cs.value = USER.cabang;
      cs.disabled = true;
      state.cabangFilter = USER.cabang;
    } else {
      const opts = cabangOptions();
      if (!opts.includes('Semua Cabang')) opts.unshift('Semua Cabang');
      const val = state.cabangFilter === '*' ? 'Semua Cabang' : state.cabangFilter;
      if (!opts.includes(val)) opts.push(val);
      cs.innerHTML = opts.map((o) => `<option value="${esc(o)}">${esc(o)}</option>`).join('');
      cs.value = val;
      cs.disabled = false;
    }

    if (!DB) return;
    const att = attentionData();
    const badge = $('#notifBadge');
    badge.textContent = att.total;
    badge.style.display = att.total ? 'flex' : 'none';

    $('#notifPanel').innerHTML = `
      <div class="notif-head">Perlu Perhatian</div>
      ${att.notFound ? notifItem('fa-location-crosshairs', `${att.notFound} barang belum ditemukan`, 'Hasil stock opname terakhir', 'inventaris', 'data-flag="notfound"') : ''}
      ${att.rusak ? notifItem('fa-screwdriver-wrench', `${att.rusak} barang rusak belum diperbaiki`, 'Menunggu tindakan perbaikan', 'inventaris', 'data-flag="rusak"') : ''}
      ${att.pending ? notifItem('fa-right-left', `${att.pending} mutasi menunggu approval`, 'Transaksi perpindahan aset', 'transaksi', 'data-status="Pending"') : ''}
      ${att.total === 0 ? '<div class="notif-empty">Semua data dalam kondisi baik. 🎉</div>' : ''}`;
  }
  function notifItem(ico, title, sub, to, extra = '') {
    return `<a class="notif-item" href="#/${to}" data-action="attGo" data-to="${to}" ${extra}><i class="fa-solid ${ico}"></i><div>${esc(title)}<small>${esc(sub)}</small></div></a>`;
  }

  function openSidebar() {
    const sb = $('#sidebar');
    sb.classList.remove('closed');
    sb.classList.add('open');
    $('#scrim').classList.add('show');
  }
  function closeSidebar() { $('#sidebar').classList.remove('open'); $('#scrim').classList.remove('show'); }
  function toggleSidebar() {
    const sb = $('#sidebar');
    const mobile = !!(window.matchMedia && window.matchMedia('(max-width:860px)').matches);
    if (mobile) {
      if (sb.classList.contains('open')) closeSidebar();
      else openSidebar();
    } else if (sb.classList.contains('closed')) {
      sb.classList.remove('closed');   // layar besar: burger membuka lagi sidebar yang ditutup
    } else {
      sb.classList.add('closed');      // layar besar: burger menutup sidebar
      closeSidebar();
    }
  }

  /* ========================================================
     18. PRINT QR
     ======================================================== */
  async function printQr(kode) {
    const a = getAsset(kode);
    if (!a) return;
    const img = qrDataUrl(a.kode, 260);
    printWindow(`
      <div class="center">
        <img class="qr" src="${img}" alt="QR ${a.kode}">
        <div style="font-size:17px;font-weight:800;letter-spacing:1px">${a.kode}</div>
      </div>`);
    await logEvent('Cetak QR', a.kode, 'Mencetak label QR inventaris');
  }

  /* ========================================================
     19. EVENTS
     ======================================================== */
  function bindEvents() {
    // popup tertutup -> kembalikan fokus ke input barcode layar opname agar scan
    // berikutnya (scanner manual / ketik) tetap langsung bisa dilakukan
    document.addEventListener('trio:modal-close', () => {
      const inp = document.getElementById('opnameBarcode');
      const ae = document.activeElement;
      if (inp && (!ae || ae === document.body || ae.id === 'opnameBarcode')) inp.focus();
    });

    document.addEventListener('click', async (e) => {
      if (!DB) return;

      if (!e.target.closest('.notif-wrap')) $('#notifPanel').hidden = true;
      if (e.target.closest('.notif-item')) $('#notifPanel').hidden = true;

      // link nav ke hash yang sama -> paksa render ulang (bug fix)
      const anchor = e.target.closest('a[href^="#/"]');
      if (anchor && anchor.getAttribute('href') === location.hash) {
        e.preventDefault();
        router();
      }

      const t = e.target.closest('[data-action]');
      if (!t) return;
      const act = t.dataset.action;

      switch (act) {
        case 'nav': go(t.dataset.to); break;
        case 'attGo': {
          e.preventDefault();
          const to = t.dataset.to || 'inventaris';
          if (to === 'transaksi') {
            state.trans.status = t.dataset.status || 'Pending';
          } else {
            // reset seluruh filter lalu terapkan status perhatian yang diminta
            state.list = {
              search: '', kategori: '', divisi: '',
              kondisi: t.dataset.kondisi || '', flag: t.dataset.flag || '',
              page: 1, per: 10
            };
          }
          go(to);
          break;
        }
        case 'detail': state.detailTab = 'riwayat'; go('detail/' + t.dataset.kode); break;
        case 'edit': go('edit/' + t.dataset.kode); break;
        case 'opnameNew': go('opname/baru'); break;
        case 'opnameStart': await opnameStart(); break;
        case 'opnameEnd': await opnameEnd(); break;
        case 'opnameCancel': await opnameCancel(); break;
        case 'opnameCheck': openOpnameItemModal(t.dataset.kode); break;
        case 'opnameMissing': await opnameCount(t.dataset.kode, { status: 'Tidak Ditemukan' }); break;
        case 'opnameUncheck': await opnameCount(t.dataset.kode, { status: 'Belum' }); break;
        case 'opnameHit': {
          const inp = $('#opnameBarcode');
          const val = inp ? inp.value : '';
          if (inp) inp.value = '';
          opnameHit(val);
          break;
        }
        case 'resetOpname':
          Object.assign(state.opname, { search: '', cabang: '', status: '', from: '', to: '', page: 1 });
          router();
          break;
        case 'resetOpnameItem':
          Object.assign(state.opname, { q: '', itemStatus: '', kat: '', itemPage: 1, hit: '' });
          router();
          break;
        case 'pageOpname': state.opname.page = Number(t.dataset.page); router(); break;
        case 'pageOpnameItems': state.opname.itemPage = Number(t.dataset.page); router(); break;
        case 'exportOpnameXlsx': await exportOpnameXlsx(); break;
        case 'exportOpnamePdf': exportOpnamePdf(); break;
        case 'tab': state.detailTab = t.dataset.tab; router(); break;
        case 'detailImg': {
          const box = t.closest('.photo-box');
          if (box) {
            const main = box.querySelector('img');
            if (main) main.src = t.dataset.src;
            box.querySelectorAll('.photo-thumbs .th').forEach((x) => x.classList.remove('active'));
            const th = t.closest('.th');
            if (th) th.classList.add('active');
          }
          break;
        }
        case 'zoomPhoto': openPhotoZoom(t.src); break;
        case 'pageList': state.list.page = Number(t.dataset.page); router(); break;
        case 'pageReport': state.report.page = Number(t.dataset.page); router(); break;
        case 'resetFilter': state.list = { search: '', kategori: '', divisi: '', kondisi: '', flag: '', page: 1, per: 10 }; router(); break;
        case 'resetTrans': state.trans.status = ''; router(); break;
        case 'applyReport': state.report.page = 1; router(); toast('Filter laporan diterapkan.', 'success'); break;
        case 'resetReport': state.report = { cabang: DB.settings.cabang, divisi: '', kategori: '', kondisi: '', tahun: '', from: '', to: '', page: 1, per: 10 }; router(); break;
        case 'exportExcel': await exportExcel(false); break;
        case 'backupAll': await exportExcel(true); break;
        case 'exportPdf': exportPdf(); break;
        case 'exportAudit': exportAudit(); break;
        case 'printQr': await printQr(t.dataset.kode); break;
        case 'toggleCam': toggleCam(); break;
        case 'qrUpload': { const inp = $('#qrImage'); if (inp) inp.click(); break; }
        case 'mutasi': await decideMutation(t.dataset.id, t.dataset.set); break;
        case 'newMutation': openMutationModal(); break;
        case 'rmPhoto': {
          state.draft.foto = '';
          const p1 = $('#photoPreview'); if (p1) p1.innerHTML = '';
          break;
        }
        case 'rmDraftDoc': state.draft.dokumen.splice(Number(t.dataset.i), 1); $('#docList').innerHTML = docListHtml(); break;
        case 'rmDoc': {
          try {
            const r = await api('DELETE', '/assets/' + encodeURIComponent(t.dataset.kode) + '/docs/' + Number(t.dataset.i));
            commit(r);
            toast('Dokumen dihapus.', 'info');
            router();
          } catch (err) { fail(err); }
          break;
        }
        case 'addListItem': {
          const key = t.dataset.list;
          const input = $('#add_' + key);
          const val = (input.value || '').trim();
          if (!val) { toast('Masukkan nama terlebih dahulu.', 'error'); return; }
          try {
            const r = await api('POST', '/lists/' + key, { value: val });
            commit(r);
            router();
            toast('Data ditambahkan.', 'success');
          } catch (err) { fail(err); }
          break;
        }
        case 'rmListItem': {
          try {
            const r = await api('DELETE', '/lists/' + t.dataset.list, { value: t.dataset.val });
            commit(r);
            router();
            toast('Data dihapus.', 'info');
          } catch (err) { fail(err); }
          break;
        }
        case 'resetData': await resetData(); break;
        case 'logout': {
          const ok = await confirmDialog(
            'Anda yakin ingin keluar dari sistem? Anda perlu login kembali untuk mengakses TRIO Inventory Control.',
            'Ya, Keluar');
          if (ok) await doLogout();
          break;
        }
        case 'changePw': openPasswordModal(); break;
        case 'userNew': openUserModal(null); break;
        case 'userEdit': openUserModal((state.users || []).find((u) => String(u.id) === t.dataset.id)); break;
        case 'userDelete': {
          const u = (state.users || []).find((x) => String(x.id) === t.dataset.id);
          if (u) await deleteUser(u.id, u.username);
          break;
        }
      }
    });

    // filter pencarian list (debounce)
    document.addEventListener('input', (e) => {
      const el = e.target;
      // Nilai Perolehan: format titik baca (1.500.000) sambil mengetik
      if (el.name === 'nilai' && el.form && el.form.id === 'assetForm') {
        const digits = String(el.value).replace(/\D/g, '');
        el.value = digits ? Number(digits).toLocaleString('id-ID') : '';
        try { el.setSelectionRange(el.value.length, el.value.length); } catch (err) { /* ignore */ }
        return;
      }
      if (!DB || !el.dataset) return;

      // filter modul opname (daftar sesi & item)
      if (el.dataset.opfilter) {
        state.opname[el.dataset.opfilter] = el.value;
        const isSearch = el.tagName === 'INPUT' && (el.type === 'search' || el.type === 'text');
        if (!isSearch) {           // select / date: langsung render ulang
          if (el.dataset.opfilter === 'search') state.opname.page = 1;
          else state.opname.itemPage = 1;
          router();
          return;
        }
        const key = el.dataset.opfilter;
        if (key === 'search') state.opname.page = 1;
        else state.opname.itemPage = 1;
        clearTimeout(el._t);
        el._t = setTimeout(() => {
          const pos = el.selectionStart;
          router();
          const again = $(`[data-opfilter="${key}"]`);
          if (again) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (err) { /* ignore */ } }
        }, 320);
        return;
      }
      if (!el.dataset.filter) return;
      state.list[el.dataset.filter] = el.value;
      state.list.page = 1;
      if (el.dataset.filter === 'search') {
        clearTimeout(el._t);
        el._t = setTimeout(() => {
          const pos = el.selectionStart;
          router();
          const again = $('[data-filter="search"]');
          if (again) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (err) { /* ignore */ } }
        }, 320);
      }
    });

    document.addEventListener('change', async (e) => {
      const el = e.target;
      if (!DB) return;

      if (el.dataset && el.dataset.opfilter && el.tagName === 'SELECT') {
        state.opname[el.dataset.opfilter] = el.value;
        state.opname.page = 1;
        state.opname.itemPage = 1;
        router();
      }
      if (el.name === 'opnameScope') {
        state.opname.scope = el.value;
        router();
      }
      if (el.dataset && el.dataset.filter && el.tagName === 'SELECT') {
        state.list[el.dataset.filter] = el.value;
        state.list.page = 1;
        router();
      }
      if (el.dataset && el.dataset.rfilter) state.report[el.dataset.rfilter] = el.value;
      if (el.dataset && el.dataset.tfilter && el.tagName === 'SELECT') {
        state.trans[el.dataset.tfilter] = el.value;
        router();
      }

      if (el.id === 'photoInput') handlePhotoFile(el);
      if (el.id === 'docInput') handleDraftDocFile(el);
      if (el.id === 'docFile') await handleDetailDoc(el);
      if (el.id === 'qrImage') decodeQrFile(el);

      if (el.id === 'cabangSelect') {
        state.cabangFilter = el.value === 'Semua Cabang' ? '*' : el.value;
        toast('Menampilkan cabang: ' + el.value, 'info');
        router();
        syncChrome();
      }

      // preview kode aset mengikuti cabang yang dipilih di form tambah
      if (el.name === 'cabang' && el.form && el.form.id === 'assetForm') updateKodeHint();
      // opsi tujuan mutasi mengikuti aset yang dipilih (cabang sendiri disembunyikan)
      if (el.name === 'kode' && el.form && el.form.closest('.modal-overlay')) refreshMutasiKe(el.form);
    });

    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (form.id === 'loginForm') { e.preventDefault(); doLogin(form); }
      if (form.id === 'assetForm') { e.preventDefault(); saveAssetForm(form); }
      if (form.id === 'opnameSessionForm') { e.preventDefault(); saveOpnameSession(form); }
      if (form.id === 'settingsForm') { e.preventDefault(); saveSettings(form); }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.id === 'opnameBarcode') {
        e.preventDefault();
        const val = e.target.value;
        e.target.value = '';
        opnameHit(val);
      }
      if (e.key === 'Enter' && e.target.id === 'globalSearch') {
        e.preventDefault();
        state.list.search = e.target.value;
        state.list.page = 1;
        go('inventaris');
        setTimeout(() => { const el = $('[data-filter="search"]'); if (el) el.focus(); }, 80);
      }
      if (e.key === 'Enter' && e.target.id && e.target.id.startsWith('add_')) {
        e.preventDefault();
        const btn = document.querySelector(`[data-action="addListItem"][data-list="${e.target.id.slice(4)}"]`);
        if (btn) btn.click();
      }
      if (e.key === 'Escape') {
        closeSidebar();
        $('#notifPanel').hidden = true;
        document.querySelectorAll('.photo-zoom-overlay').forEach((o) => o.remove());
      }
    });

    $('#burger').addEventListener('click', toggleSidebar);
    $('#scrim').addEventListener('click', closeSidebar);
    $('#notifBtn').addEventListener('click', (e) => {
      e.stopPropagation();
      const p = $('#notifPanel');
      p.hidden = !p.hidden;
    });
  }

  /* ========================================================
     20. INIT
     ======================================================== */
  (async function init() {
    bindEvents();
    let me = null;
    try { me = await api('GET', '/auth/me'); } catch (e) { me = null; }
    if (me && me.authenticated && me.user) {
      USER = me.user;
      await loadDB();
      enterApp();
    } else {
      const hint = $('#loginHint');
      if (hint && me && Array.isArray(me.demoAccounts) && me.demoAccounts.length) {
        hint.innerHTML = 'Akun demo: ' + me.demoAccounts.map((a) => `<b>${esc(a)}</b>`).join(' ') +
          '<br>Segera ganti password setelah masuk.';
      }
      showLogin(me && me.seeded === false ? 'Database belum terisi. Hentikan lalu jalankan ulang server.' : '');
    }
    window.addEventListener('hashchange', () => { if (USER && DB) { router(); syncChrome(); } });
    window.__TRIO_READY__ = true;
  })();
})();
