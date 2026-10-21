/* Local drafts and immutable request receipts are intentionally independent of UI rendering. */
(() => {
  'use strict';
  const clone = value => JSON.parse(JSON.stringify(value));
  const uuid = () => globalThis.crypto?.randomUUID?.() || 'op_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
  const labels = { H: 'Hadir', S: 'Sakit', I: 'Izin', A: 'Alfa' };
  function missing(p) {
    if (!p.status) return ['status kehadiran'];
    if (p.status === 'S' || p.status === 'I') return p.alasan.trim() ? [] : ['alasan/keterangan'];
    if (p.status !== 'H') return [];
    const out = [];
    if (!['Iqro', 'Al Quran'].includes(p.jenis_materi)) out.push('jenis materi');
    if (p.jenis_materi === 'Iqro' && !/^[1-6]$/.test(p.jilid)) out.push('jilid 1–6');
    ['halaman_dari', 'halaman_sampai'].forEach(k => { if (!/^[1-9][0-9]{0,3}$/.test(p[k])) out.push(k.replaceAll('_', ' ')); });
    if (+p.halaman_dari > +p.halaman_sampai && (p.jenis_materi === 'Iqro' || p.nama_surat_dari === p.nama_surat_sampai)) out.push('rentang bacaan berurutan');
    if (p.jenis_materi === 'Al Quran') ['nama_surat_dari', 'nama_surat_sampai'].forEach(k => { if (!p[k].trim()) out.push(k.replaceAll('_', ' ')); });
    if (p.jenis_materi === 'Al Quran') {
      const range = p.nama_surat_dari === p.nama_surat_sampai ? p.nama_surat_dari : p.nama_surat_dari + ' s/d ' + p.nama_surat_sampai;
      if (Array.from(range).length > 100) out.push('nama surat lebih singkat');
    }
    if (!['Lulus', 'Ulang'].includes(p.hasil_talaqqi)) out.push('hasil Lulus/Ulang');
    return out;
  }

  class DraftStore {
    constructor() { this.db = null; }
    async open() {
      this.db = await new Promise((resolve, reject) => {
        if (!globalThis.indexedDB) return reject(new Error('Penyimpanan HP tidak tersedia.'));
        const request = indexedDB.open('tahsin-autosave-v1', 1);
        let expired = false;
        const timer = setTimeout(() => { expired = true; reject(new Error('Penyimpanan HP belum dapat dibuka.')); }, 4000);
        request.onupgradeneeded = () => {
          const store = request.result.createObjectStore('drafts', { keyPath: 'key' });
          store.createIndex('scope', 'scope');
        };
        request.onsuccess = () => { clearTimeout(timer); if (expired) request.result.close(); else resolve(request.result); };
        request.onerror = () => { clearTimeout(timer); reject(request.error); };
        request.onblocked = () => { clearTimeout(timer); expired = true; reject(new Error('Tutup tab versi lama untuk membuka penyimpanan HP.')); };
      });
      this.db.onversionchange = () => { this.db.close(); this.db = null; };
    }
    async read(scope) {
      if (!this.db) throw new Error('Penyimpanan HP tidak tersedia.');
      return new Promise((resolve, reject) => {
        const tx = this.db.transaction('drafts', 'readonly');
        const req = tx.objectStore('drafts').index('scope').getAll(scope);
        let result;
        req.onsuccess = () => { result = req.result; };
        tx.oncomplete = () => resolve(result);
        tx.onerror = tx.onabort = () => reject(tx.error || new Error('Draft belum dapat dibaca.'));
      });
    }
    async put(value) {
      if (!this.db) throw new Error('Penyimpanan HP tidak tersedia.');
      return new Promise((resolve, reject) => {
        const tx = this.db.transaction('drafts', 'readwrite');
        tx.objectStore('drafts').put(value);
        // A successful request is insufficient: wait for the transaction commit.
        tx.oncomplete = () => resolve();
        tx.onerror = tx.onabort = () => reject(tx.error || new Error('Draft belum tersimpan di HP.'));
      });
    }
    async removeRecovered(key, sequence) {
      if (!this.db) return;
      return new Promise((resolve, reject) => {
        const tx = this.db.transaction('drafts', 'readwrite'), store = tx.objectStore('drafts');
        const req = store.get(key);
        req.onsuccess = () => { if (req.result?.sequence === sequence) store.delete(key); };
        tx.oncomplete = () => resolve();
        tx.onerror = tx.onabort = () => reject(tx.error);
      });
    }
    async readUser(userId) {
      if (!this.db) throw new Error('Penyimpanan HP tidak tersedia.');
      return new Promise((resolve, reject) => {
        const tx = this.db.transaction('drafts', 'readonly');
        const prefix = String(userId) + ':';
        const req = tx.objectStore('drafts').index('scope').getAll(IDBKeyRange.bound(prefix, prefix + '\uffff'));
        let result = [];
        req.onsuccess = () => { result = req.result; };
        tx.oncomplete = () => resolve(result);
        tx.onerror = tx.onabort = () => reject(tx.error);
      });
    }
    async update(key, callback) {
      if (!this.db) throw new Error('Penyimpanan HP tidak tersedia.');
      return new Promise((resolve, reject) => {
        const tx = this.db.transaction('drafts', 'readwrite'), store = tx.objectStore('drafts');
        const req = store.get(key); let result = null;
        req.onsuccess = () => {
          try { result = callback(req.result); if (result) store.put(result); }
          catch (_) { tx.abort(); }
        };
        tx.oncomplete = () => resolve(result);
        tx.onerror = tx.onabort = () => reject(tx.error || new Error('Draft belum dapat diperbarui.'));
      });
    }
  }

  // Non-editor pages recover pending operations across this user's sessions.
  // Conditional IDB updates never replace a newer snapshot written in another tab.
  async function recoverPending(boot, root) {
    const store = new DraftStore(); let busy = false, paused = false, timer, delay = 1000;
    const show = (message, records = []) => {
      root.hidden = false; root.textContent = message;
      const unique = new Map(records.map(r => [r.scope, r]));
      for (const record of unique.values()) {
        const parts = record.scope.split(':'), link = document.createElement('a');
        link.href = boot.form + '?' + new URLSearchParams({id: parts[2], tanggal: record.tanggal || parts[3]});
        link.textContent = ` Buka sesi ${parts[3]} →`; link.style.display = 'block'; root.append(link);
      }
    };
    try { await store.open(); } catch (_) { show('Penyimpanan HP belum dapat dibaca. Buka sesi untuk memeriksa draft dan status server.'); return; }
    const request = async (url, options = {}) => {
      const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 12000);
      try {
        const response = await fetch(url, {credentials:'same-origin', cache:'no-store', ...options, signal:controller.signal});
        const result = await response.json();
        if (!response.ok || result.status !== 'success') { const e = new Error(result.message || 'Pengiriman belum berhasil.'); e.status = response.status; e.details = result.details; throw e; }
        return result.data;
      } finally { clearTimeout(timeout); }
    };
    const run = async () => {
      if (busy || paused || document.hidden) return;
      busy = true; clearTimeout(timer); let pending = [];
      try {
        pending = (await store.readUser(boot.user_id)).filter(r => r.dirty);
        if (!pending.length) { root.hidden = true; delay = 1000; return; }
        show(`${pending.length} perubahan di HP sedang diperiksa dan dikirim otomatis.`, pending);
        for (const saved of pending) {
          if (saved.conflict) continue;
          const parts = saved.scope.split(':'), groupId = +parts[2], date = saved.tanggal || parts[3];
          let body = saved.flight?.body;
          if (!body) {
            const url = new URL(boot.api, location.href); url.search = new URLSearchParams({halaqoh_id: groupId, tanggal: date});
            const state = await request(url), entry = state.entries.find(e => e.wali_id === saved.wali_id);
            if (!entry || entry.blocked || entry.official_revision !== saved.entry.official_revision || entry.draft_revision !== saved.entry.draft_revision || entry.context_revision !== saved.entry.context_revision) {
              await store.update(saved.key, current => current?.sequence === saved.sequence ? {...current, conflict:true} : null); continue;
            }
            body = {action:'save', operation_id:uuid(), halaqoh_id:groupId, tanggal:date, program:parts[1], wali_id:saved.wali_id,
              payload:clone(saved.payload), context_revision:entry.context_revision, official_revision:entry.official_revision, draft_revision:entry.draft_revision};
          }
          const claimed = await store.update(saved.key, current => {
            if (!current?.dirty || current.sequence !== saved.sequence) return null;
            return {...current, flight:current.flight || {sequence:current.sequence,body}};
          });
          if (!claimed) continue;
          try {
            const result = await request(boot.api, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...claimed.flight.body, csrf_token:boot.csrf_token})});
            await store.update(saved.key, current => {
              if (!current || current.flight?.body.operation_id !== claimed.flight.body.operation_id) return null;
              const dirty = current.sequence !== claimed.flight.sequence;
              return {...current, entry:result, payload:dirty ? current.payload : result.payload, dirty, flight:null, conflict:false};
            });
          } catch (e) {
            if ([409,422,413].includes(e.status)) {
              await store.update(saved.key, current => current?.flight?.body.operation_id === claimed.flight.body.operation_id ? {...current, conflict:true} : null);
            } else throw e;
          }
        }
        pending = (await store.readUser(boot.user_id)).filter(r => r.dirty);
        if (!pending.length) { show('✓ Seluruh perubahan tertunda sudah diterima server.'); delay = 1000; }
        else { show(`${pending.length} perubahan masih tertahan. Buka sesi untuk meninjau draft atau konflik.`, pending); timer = setTimeout(run, Math.min(30000, delay)); delay = Math.min(30000, delay * 2); }
      } catch (e) {
        if ([401,403].includes(e.status)) { paused = true; show('Login atau sesi keamanan berubah. Masuk kembali atau muat ulang untuk melanjutkan pengiriman.', pending); }
        else { show('Perubahan di HP tetap tersedia. Pengiriman akan dicoba lagi ketika koneksi tersedia.', pending); timer = setTimeout(run, delay); delay = Math.min(30000, delay * 2); }
      } finally { busy = false; }
    };
    window.addEventListener('online', run); window.addEventListener('focus', run);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) run(); });
    document.addEventListener('click', async e => {
      const link = e.target.closest('a[href]');
      if (!link || !link.getAttribute('href').includes('logout.php') || e.defaultPrevented) return;
      e.preventDefault();
      try {
        const pending = (await store.readUser(boot.user_id)).filter(r => r.dirty);
        if (!pending.length || confirm('Masih ada perubahan di HP yang belum diterima server. Draft tetap terikat akun ini. Keluar sekarang?')) location.href = link.href;
      } catch (_) {
        if (confirm('Status draft di HP belum dapat diperiksa. Tetap keluar dari akun?')) location.href = link.href;
      }
    });
    await run();
  }

  class AutosaveApp {
    constructor(root, boot) {
      this.root = root; this.boot = boot; this.rows = new Map(); this.store = new DraftStore();
      this.scope = [boot.user_id, boot.state.program, boot.halaqoh_id, boot.state.session_date].join(':');
      root.inert = true;
      try { this.client = sessionStorage.getItem('tahsin-autosave-client') || uuid(); sessionStorage.setItem('tahsin-autosave-client', this.client); } catch (_) { this.client = uuid(); }
      this.active = 0; this.paused = false; this.query = ''; this.filter = 'all'; this.openId = null; this.completed = boot.state.completed_at;
      for (const entry of boot.state.entries) {
        const el = root.querySelector(`[data-person="${entry.wali_id}"]`);
        if (!el) continue;
        this.rows.set(entry.wali_id, { id: entry.wali_id, el, entry: clone(entry), payload: clone(entry.payload),
          sequence: 0, persistedSequence: -1, dirty: false, flight: null, inFlight: false, due: false,
          retryDelay: 1000, localChain: Promise.resolve(), localError: false,
          conflict: entry.state === 'conflict', serverEntry: clone(entry), error: '', key: this.scope + ':' + entry.wali_id + ':' + this.client });
      }
    }
    snapshot(row) {
      return { key: row.key, scope: this.scope, wali_id: row.id, tanggal: this.boot.tanggal, saved_at: Date.now(), payload: clone(row.payload), entry: clone(row.entry), sequence: row.sequence,
        dirty: row.dirty, flight: row.flight ? clone(row.flight) : null, conflict: row.conflict };
    }
    persist(row) {
      row.localChain = row.localChain.then(async () => {
        const value = this.snapshot(row);
        try {
          await this.store.put(value);
          row.persistedSequence = Math.max(row.persistedSequence, value.sequence); row.localError = false;
        } catch (_) { row.localError = true; }
        this.render(row);
      });
      return row.localChain;
    }
    async init() {
      this.bind();
      try {
        await this.store.open();
        const records = await this.store.read(this.scope);
        for (const row of this.rows.values()) {
          const candidates = records.filter(s => s.wali_id === row.id && s.dirty).sort((a, b) => (b.saved_at || 0) - (a.saved_at || 0));
          const saved = candidates.find(s => s.key === row.key) || candidates[0];
          if (!saved) continue;
          if (saved.key !== row.key) row.recoveredSource = { key: saved.key, sequence: saved.sequence };
          row.payload = clone(saved.payload); row.sequence = saved.sequence || 0; row.persistedSequence = row.sequence;
          row.dirty = true; row.flight = saved.flight; row.entry = clone(saved.entry);
          const changed = row.entry.official_revision !== row.serverEntry.official_revision || row.entry.draft_revision !== row.serverEntry.draft_revision || row.entry.context_revision !== row.serverEntry.context_revision;
          row.conflict = saved.conflict || (!row.flight && changed) || row.serverEntry.blocked;
          if (row.conflict) row.error = 'Ada perubahan server sejak draft di HP dibuat. Tinjau kedua versi.';
        }
      } catch (_) { this.banner('Penyimpanan HP belum tersedia. Autosave ke server tetap dicoba; perhatikan status setiap peserta.'); this.rows.forEach(r => { r.localError = true; }); }
      this.rows.forEach(row => { this.hydrate(row); this.render(row); if (row.dirty && !row.conflict) this.schedule(row, true); });
      this.refreshOverview();
      this.root.inert = false;
      this.root.dataset.ready = 'true';
    }
    bind() {
      this.root.addEventListener('input', e => {
        if (e.target.matches('[data-search]')) { this.query = e.target.value.toLocaleLowerCase('id'); this.applyFilter(); return; }
        if (e.target.matches('[data-field]')) this.fieldChange(e.target);
      });
      this.root.addEventListener('change', e => { if (e.target.matches('[data-field]')) this.fieldChange(e.target); });
      this.root.addEventListener('focusout', e => { const row = this.rowFor(e.target); if (row) this.schedule(row, true); });
      this.root.addEventListener('click', e => {
        const button = e.target.closest('button'); if (!button) return;
        const row = this.rowFor(button);
        if (button.hasAttribute('data-status') && row) {
          this.change(row, 'status', button.dataset.status);
          const reference = this.boot.references?.[row.id];
          if (button.dataset.status === 'H' && !row.payload.jenis_materi && reference) {
            ['jenis_materi', 'jilid', 'nama_surat_dari', 'nama_surat_sampai'].forEach(key => this.change(row, key, reference[key] || ''));
            this.hydrate(row);
          }
          this.toggle(row, ['H', 'S', 'I'].includes(row.payload.status));
        } else if (button.hasAttribute('data-result') && row) this.change(row, 'hasil_talaqqi', button.dataset.result);
        else if (button.hasAttribute('data-toggle') && row) this.toggle(row, this.openId !== row.id);
        else if (button.hasAttribute('data-next') && row) {
          this.schedule(row, true);
          const visible = [...this.rows.values()].filter(r => !r.el.hidden);
          const next = visible[visible.indexOf(row) + 1];
          if (next) { this.toggle(next, true); next.el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
          else this.showSummary();
        } else if (button.hasAttribute('data-filter')) { this.filter = button.dataset.filter; this.applyFilter(); }
        else if (button.hasAttribute('data-retry') && row) { row.error = ''; this.schedule(row, true); }
        else if (button.hasAttribute('data-resolve') && row) this.resolve(row, button.dataset.resolve);
        else if (button.hasAttribute('data-show-summary')) this.showSummary();
        else if (button.hasAttribute('data-finalize')) this.finalize();
      });
      const dateForm = this.root.querySelector('.tas-date');
      dateForm?.addEventListener('submit', async e => {
        e.preventDefault(); await Promise.all([...this.rows.values()].map(r => this.persist(r)));
        if ([...this.rows.values()].some(r => r.localError && r.dirty) && !confirm('Ada perubahan belum tersimpan di HP. Tetap pindah tanggal?')) return;
        dateForm.submit();
      });
      document.addEventListener('visibilitychange', () => { if (document.hidden) this.flush(); else this.resume(); });
      window.addEventListener('online', () => this.resume());
      window.addEventListener('focus', () => this.resume());
      window.visualViewport?.addEventListener('resize', () => {
        const keyboard = visualViewport.height < innerHeight * .75 && document.activeElement?.matches('[data-field], [data-search]');
        this.root.classList.toggle('tas-keyboard', !!keyboard);
      });
      document.addEventListener('click', async e => {
        const link = e.target.closest('a[href]');
        if (!link || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || link.target === '_blank' || new URL(link.href).origin !== location.origin) return;
        if ([...this.rows.values()].some(r => r.dirty || r.inFlight)) {
          e.preventDefault();
          if (link.getAttribute('href').includes('logout.php') && !confirm('Ada perubahan menunggu server. Draft tetap terikat akun ini di HP. Keluar sekarang?')) return;
          this.flush();
          await Promise.all([...this.rows.values()].map(r => this.persist(r)));
          if ([...this.rows.values()].some(r => r.dirty && r.localError) && !confirm('Ada perubahan belum tersimpan di HP. Tetap meninggalkan halaman?')) return;
          location.href = link.href;
        }
      });
    }
    rowFor(el) { const node = el.closest('[data-person]'); return node ? this.rows.get(+node.dataset.person) : null; }
    fieldChange(input) { const row = this.rowFor(input); if (row) this.change(row, input.dataset.field, input.value); }
    change(row, field, value) {
      if (!this.boot.state.active || row.payload[field] === value) return;
      row.payload[field] = value; row.sequence++; row.dirty = true; row.error = ''; this.completed = null;
      if (row.validationError) { row.conflict = false; row.validationError = false; row.flight = null; }
      this.persist(row); this.render(row); this.schedule(row);
    }
    hydrate(row) { row.el.querySelectorAll('[data-field]').forEach(input => { input.value = row.payload[input.dataset.field] || ''; }); }
    toggle(row, open) {
      if (this.openId && this.openId !== row.id) {
        const previous = this.rows.get(this.openId); if (previous) { previous.el.querySelector('.tas-detail').hidden = true; previous.el.querySelector('[data-toggle]').setAttribute('aria-expanded', 'false'); this.schedule(previous, true); }
      }
      this.openId = open ? row.id : null;
      row.el.querySelector('.tas-detail').hidden = !open;
      row.el.querySelector('[data-toggle]').setAttribute('aria-expanded', String(open));
      if (!open) this.schedule(row, true);
    }
    schedule(row, now = false) {
      if (!row.dirty || row.conflict || this.paused || !this.boot.state.active) return;
      clearTimeout(row.debounceTimer);
      if (now) { clearTimeout(row.maxTimer); row.maxTimer = null; row.due = true; this.pump(); return; }
      row.debounceTimer = setTimeout(() => this.schedule(row, true), 600);
      if (!row.maxTimer) row.maxTimer = setTimeout(() => this.schedule(row, true), 2000);
    }
    flush() { this.rows.forEach(row => this.schedule(row, true)); }
    pump() {
      if (this.paused) return;
      for (const row of this.rows.values()) {
        if (this.active >= 2) break;
        if (row.due && row.dirty && !row.inFlight && !row.conflict) this.send(row);
      }
    }
    async request(body) {
      body = { ...body, csrf_token: this.boot.csrf_token };
      const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 12000);
      try {
        const response = await fetch(this.boot.api, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), signal: controller.signal, keepalive: true });
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Server belum dapat dihubungi. Pengiriman akan dicoba lagi.'); }
        if (!response.ok || result.status !== 'success') { const error = new Error(result.message || 'Belum tersimpan.'); error.status = response.status; error.details = result.details; throw error; }
        return result.data;
      } finally { clearTimeout(timer); }
    }
    async send(row) {
      this.active++; row.inFlight = true; row.due = false; clearTimeout(row.retryTimer);
      if (!row.flight) row.flight = { sequence: row.sequence, body: {
        action: 'save', operation_id: uuid(), csrf_token: this.boot.csrf_token, halaqoh_id: this.boot.halaqoh_id,
        tanggal: this.boot.tanggal, program: this.boot.state.program, wali_id: row.id, payload: clone(row.payload),
        context_revision: row.entry.context_revision, official_revision: row.entry.official_revision, draft_revision: row.entry.draft_revision,
      } };
      await this.persist(row); this.render(row);
      try {
        const flight = clone(row.flight); const data = await this.request(flight.body);
        row.entry = clone(data); row.serverEntry = clone(data); row.flight = null; row.retryDelay = 1000; row.error = '';
        row.dirty = row.sequence !== flight.sequence;
        if (!row.dirty) row.payload = clone(data.payload);
        await this.persist(row);
        if (!row.dirty && row.recoveredSource) {
          try { await this.store.removeRecovered(row.recoveredSource.key, row.recoveredSource.sequence); row.recoveredSource = null; } catch (_) { /* Keep the recoverable source on storage failure. */ }
        }
        if (row.dirty) this.schedule(row, true);
      } catch (error) {
        row.error = error.message;
        if ([401, 403].includes(error.status)) {
          this.paused = true; this.banner(error.message + ' Setelah login atau memuat ulang halaman, perubahan akan dipulihkan.', true);
        } else if (error.status === 409 || error.status === 422 || error.status === 413) {
          row.conflict = true;
          row.validationError = error.status !== 409;
          if (error.details?.entry) row.serverEntry = error.details.entry;
          else row.serverEntry = null;
          await this.persist(row); this.toggle(row, true);
        } else {
          row.retryTimer = setTimeout(() => this.schedule(row, true), row.retryDelay);
          row.retryDelay = Math.min(30000, row.retryDelay * 2);
        }
      } finally {
        row.inFlight = false; this.active--; this.render(row); this.pump();
      }
    }
    async resume() {
      if (this.refreshing || this.paused || document.hidden) return;
      this.refreshing = true;
      try {
        const url = new URL(this.boot.api, location.href);
        url.search = new URLSearchParams({ halaqoh_id: this.boot.halaqoh_id, tanggal: this.boot.tanggal });
        const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 10000);
        let response;
        try { response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal }); } finally { clearTimeout(timer); }
        if (!response.ok) { if ([401,403].includes(response.status)) { this.paused = true; this.banner('Sesi login berubah. Masuk kembali atau muat ulang halaman untuk memulihkan draft.', true); } return; }
        const result = await response.json(); if (result.status !== 'success') return;
        for (const fresh of result.data.entries) {
          const row = this.rows.get(fresh.wali_id); if (!row || row.inFlight) continue;
          row.serverEntry = clone(fresh);
          if (row.dirty) {
            if (!row.flight && (row.entry.draft_revision !== fresh.draft_revision || row.entry.official_revision !== fresh.official_revision || row.entry.context_revision !== fresh.context_revision)) {
              row.conflict = true; row.error = 'Data server sudah berubah. Tinjau kedua versi.';
            }
          } else {
            row.entry = clone(fresh); row.payload = clone(fresh.payload); row.conflict = fresh.state === 'conflict'; this.hydrate(row);
          }
          this.render(row);
        }
        if (result.data.context_revision !== this.boot.state.context_revision) this.banner('Roster atau program berubah. Muat ulang halaman; draft di HP dipertahankan.');
      } catch (_) { /* Local drafts remain available during network outages. */ }
      finally { this.refreshing = false; this.flush(); }
    }
    async resolve(row, choice) {
      const fresh = row.serverEntry;
      if (!fresh || fresh.blocked) { this.banner('Minta admin meninjau penempatan atau catatan yang berubah. Draft tetap tersedia.'); return; }
      if (choice === 'server') row.payload = clone(fresh.stale_draft ? fresh.official_payload : fresh.payload);
      row.entry = clone(fresh); row.flight = null; row.conflict = false; row.error = ''; row.sequence++; row.dirty = true;
      this.hydrate(row); await this.persist(row); this.render(row); this.schedule(row, true);
    }
    render(row) {
      const p = row.payload, absent = p.status === 'S' || p.status === 'I', gaps = missing(p);
      row.el.querySelectorAll('[data-status]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.status === p.status)));
      row.el.querySelectorAll('[data-result]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.result === p.hasil_talaqqi)));
      row.el.querySelector('[data-achievement]').hidden = p.status !== 'H'; row.el.querySelector('[data-reason]').hidden = !absent;
      row.el.querySelectorAll('[data-iqro]').forEach(el => { el.hidden = p.jenis_materi !== 'Iqro'; });
      row.el.querySelectorAll('[data-quran]').forEach(el => { el.hidden = p.jenis_materi !== 'Al Quran'; });
      row.el.querySelector('[data-range-start]').textContent = p.jenis_materi === 'Al Quran' ? 'Ayat dari' : 'Halaman dari';
      row.el.querySelector('[data-range-end]').textContent = p.jenis_materi === 'Al Quran' ? 'Ayat sampai' : 'Halaman sampai';
      row.el.querySelector('[data-detail-label]').textContent = absent ? 'Keterangan' : 'Capaian hari ini';
      row.el.querySelector('[data-missing]').textContent = p.status && gaps.length ? 'Lengkapi: ' + gaps.join(', ') + '.' : '';
      row.el.querySelector('[data-official-note]').hidden = !(row.entry.official_complete && (row.dirty || gaps.length));
      row.el.querySelector('[data-completeness]').textContent = row.conflict ? 'Perlu ditinjau' : (!p.status ? 'Belum dicatat' : gaps.length ? 'Belum lengkap' : row.dirty ? 'Menunggu server' : 'Lengkap');
      let status = 'Belum dicatat';
      if (row.conflict) status = 'Perlu ditinjau · draft dipertahankan';
      else if (this.paused && row.dirty) status = 'Menunggu login · draft dipertahankan';
      else if (row.inFlight) status = 'Menyimpan…';
      else if (row.dirty) status = row.persistedSequence === row.sequence && !row.localError ? 'Tersimpan di HP · menunggu koneksi' : 'Menyimpan di HP…';
      else if (row.entry.state === 'draft') status = 'Draft tersimpan · belum lengkap';
      else if (row.entry.state === 'official') {
        const time = row.entry.updated_at ? new Date(row.entry.updated_at.replace(' ', 'T') + '+07:00').toLocaleTimeString('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }) : '';
        status = '✓ Tersimpan otomatis' + (time ? ' · ' + time : '');
      }
      if (row.localError) status += ' · Belum tersimpan di HP';
      row.el.querySelector('[data-save-status]').textContent = status;
      row.el.querySelector('[data-retry]').hidden = !row.dirty || row.conflict || this.paused || row.inFlight;
      row.el.querySelector('[data-conflict]').hidden = !row.conflict;
      row.el.querySelector('[data-conflict-message]').textContent = row.error || 'Draft dan catatan resmi berbeda. Tinjau versi yang ingin digunakan.';
      const serverPayload = row.serverEntry?.stale_draft ? row.serverEntry.official_payload : row.serverEntry?.payload;
      row.el.querySelector('[data-server-version]').textContent = serverPayload ? 'Versi server: ' + this.describe(serverPayload) : 'Muat ulang halaman atau hubungi admin untuk meninjau keadaan server.';
      row.el.querySelectorAll('[data-resolve]').forEach(b => { b.hidden = !row.serverEntry || row.serverEntry.blocked; });
      row.el.querySelectorAll('[data-field],[data-status],[data-result]').forEach(el => { el.disabled = !this.boot.state.active; });
      row.el.dataset.savingState = row.conflict ? 'conflict' : row.dirty ? 'pending' : row.entry.state;
      this.refreshOverview();
    }
    describe(p) { return [labels[p.status] || 'Belum dicatat', p.status === 'H' ? [p.jenis_materi, p.jilid, p.nama_surat_dari, p.halaman_dari && `${p.halaman_dari}–${p.halaman_sampai}`, p.hasil_talaqqi].filter(Boolean).join(' · ') : p.alasan].filter(Boolean).join(' · '); }
    applyFilter() {
      let count = 0;
      this.rows.forEach(row => {
        row.el.hidden = !(row.el.dataset.searchName.toLocaleLowerCase('id').includes(this.query) && (this.filter === 'all' || row.dirty || row.conflict || row.entry.state !== 'official'));
        if (!row.el.hidden) count++;
      });
      this.root.querySelectorAll('[data-filter]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.filter === this.filter)));
      this.root.querySelector('[data-no-results]').hidden = count !== 0;
    }
    refreshOverview() {
      const rows = [...this.rows.values()];
      const complete = rows.filter(r => r.entry.state === 'official' && !r.dirty && !r.conflict).length;
      const pending = rows.filter(r => r.dirty && !r.conflict).length;
      const drafts = rows.filter(r => !r.dirty && r.entry.state === 'draft' && !r.conflict).length;
      const conflicts = rows.filter(r => r.conflict).length;
      this.root.querySelector('[data-progress]').textContent = `${complete} dari ${rows.length} peserta lengkap`;
      this.root.querySelector('[data-pending]').textContent = [pending && `${pending} perubahan menunggu server`, drafts && `${drafts} draft belum lengkap`, conflicts && `${conflicts} perlu ditinjau`].filter(Boolean).join(' · ');
      this.root.querySelector('[data-bottom-state]').textContent = this.completed ? '✓ Sesi selesai' : pending ? `${pending} perubahan menunggu server` : 'Perubahan disimpan otomatis';
      const counts = { H: 0, S: 0, I: 0, A: 0 };
      rows.forEach(r => { if (r.entry.official_complete && counts[r.entry.official_payload.status] !== undefined) counts[r.entry.official_payload.status]++; });
      this.root.querySelector('[data-summary-counts]').textContent = Object.keys(counts).map(k => `${labels[k]}: ${counts[k]}`).join(' · ');
      this.root.querySelector('[data-summary-state]').textContent = `${rows.length - complete} belum tuntas · ${pending} menunggu server · ${drafts} draft · ${conflicts} konflik. Angka kehadiran memakai catatan resmi server.`;
      this.root.querySelector('[data-finalize]').disabled = !rows.length || complete !== rows.length || this.paused || !this.boot.state.active || !!this.completed;
    }
    showSummary() {
      this.flush(); const el = this.root.querySelector('[data-summary]'); el.hidden = false; this.refreshOverview(); el.focus(); el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    async finalize() {
      if ([...this.rows.values()].some(r => r.dirty || r.inFlight || r.conflict)) return;
      const button = this.root.querySelector('[data-finalize]'); button.disabled = true;
      try {
        const revisions = {}; this.rows.forEach(r => { revisions[r.id] = r.entry.official_revision; });
        const result = await this.request({ action: 'finalize', halaqoh_id: this.boot.halaqoh_id, tanggal: this.boot.tanggal,
          csrf_token: this.boot.csrf_token, context_revision: this.boot.state.context_revision, revisions });
        this.completed = true; this.banner(result.message);
      } catch (error) { this.banner(error.message); await this.resume(); }
      this.refreshOverview();
    }
    banner(message, login = false) {
      const el = this.root.querySelector('[data-banner]'); el.hidden = false; el.textContent = message;
      if (login) { const a = document.createElement('a'); a.href = '../login.php'; a.textContent = ' Buka Login'; el.append(a); }
    }
  }

  if (typeof module !== 'undefined' && module.exports) module.exports = { missing, DraftStore };
  if (typeof document === 'undefined') return;
  // Both renderers use the exact same draft keys, immutable flights and retry engine.
  // A weak map prevents two renderers from mounting senders on the same editor.
  const mounted = new WeakMap();
  const mountEditor = (root, boot) => {
    if (mounted.has(root)) return mounted.get(root);
    const app = new AutosaveApp(root, boot);
    const ready = app.init().catch(error => {
      root.inert = true;
      app.banner('Halaman belum dapat disiapkan. Muat ulang; draft sebelumnya tetap tersedia.');
      throw error;
    });
    mounted.set(root, ready);
    return ready;
  };
  window.TahsinAutosave = Object.freeze({ mountEditor });
  window.dispatchEvent(new Event('tahsin-autosave-ready'));
  const root = document.getElementById('tahsin-autosave'), data = document.getElementById('tahsin-autosave-data');
  if (root && data && !root.hasAttribute('data-vue-editor')) {
    mountEditor(root, JSON.parse(data.textContent)).catch(() => {});
  } else {
    const pending = document.getElementById('tahsin-pending-recovery'), boot = document.getElementById('tahsin-pending-data');
    if (pending && boot) recoverPending(JSON.parse(boot.textContent), pending).catch(() => { pending.hidden = false; pending.textContent = 'Draft di HP belum dapat diperiksa. Buka kembali sesi untuk memulihkan perubahan.'; });
  }
})();
