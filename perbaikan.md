# Rencana Perbaikan — Presensi Tahsin Bapak

Sumber: audit menyeluruh 2026-10-24 (cabang `codex/application-audit-20261023`).
Kode temuan merujuk ke laporan audit: K = kritis, T = tinggi, S = sedang, R = rendah.

> **Keputusan pemilik aplikasi (2026-10-24):** rotasi password 22 akun ustadz **tidak dilakukan**.
> Temuan K1 (password plaintext di repo publik) dicatat di Fase 3 sebagai tindakan opsional
> (menghapus file dari repo berikutnya) tanpa rotasi kredensial.

Cara pakai: kerjakan berurutan per fase. Centang `[x]` saat selesai dan catat tanggal.
Setiap butir punya langkah konkret dan cara verifikasi.

---

## Ringkasan prioritas

| Fase | Fokus | Temuan | Perkiraan |
|------|-------|--------|-----------|
| 0 | Tindakan segera (hari ini) | K2, K3, K4, T1 | ±3–4 jam |
| 1 | Kebocoran data & pengerasan akses file/API | T2, T3, S1, S2 | ±1 hari |
| 2 | Kualitas kode & correctness | S3–S9, R-kecil | ±2–3 hari |
| 3 | Opsional / jangka panjang | K1 (tanpa rotasi), R lainnya | sesuai kesempatan |

---

## FASE 0 — Segera (hari ini) — ✅ SELESAI 2026-10-24

### 0.1 [x] K2 — Amankan API key n8n yang terekspos
**Keputusan pemilik (2026-10-24): rotasi key n8n TIDAK dilakukan.**
Langkah yang dijalankan:
1. ~~Rotasi key~~ — dilewati sesuai keputusan pemilik.
2. [x] File `Reminder Harian….json` dipindah ke `D:\Google Antigravity\arsip-tahsin\` (luar repo/webroot).
3. [x] `.htaccess` FilesMatch: blokir semua `.json` kecuali `manifest.json` (pola `^(?!manifest\.json$)[^/]*\.json$`), menggantikan pola lama `n8n.*\.json`.
4. [x] `docker/apache.conf` FilesMatch diberi aturan json/csv yang sama.
5. [x] `.dockerignore`: ditambah `Reminder*.json`, `data_santri_baru.csv`, `Bapak Wali Santri Baru.xlsx`, `*.pdf`, `assets/go-vue/`.
Verifikasi: regex FilesMatch diuji 19/19 kasus (manifest/sw/template lolos; csv/json/sql/md sensitif terblokir). Rotasi key tetap disarankan di kemudian hari.

### 0.2 [x] K3 — Tutup kebocoran `.csv` data pribadi
1. [x] `data_santri_baru.csv` dipindah ke `D:\Google Antigravity\arsip-tahsin\`.
2. [x] `.htaccess`: blokir `.csv` kecuali `template_*.csv` (`^(?!template_)[^/]*\.csv$`); template impor tetap bisa diunduh (terverifikasi tautan di `import-peserta.php:196` dan `import-santri-baru.php:471`).
3. [x] Aturan sama di `docker/apache.conf`.
4. [x] `.htaccess` `Require all denied` dibuat di `backup/`, `outputs/`, `temp_excel/`.
5. [x] `.dockerignore` diperbarui.

### 0.3 [x] K4 — Perbaiki XSS reflected di capaian.php
- [x] `capaian.php:208` → `<?= appEscape($message) ?>`.
- [x] `capaian.php:213` → `<?= appEscape($error) ?>`.

### 0.4 [x] T1 — Batasi penetapan role admin
- [x] `includes/user_helper.php`: `userRequireManager()` kini mengembalikan role aktor; `saveManagedUser()` menolak non-admin yang menetapkan role `admin` atau mengubah akun admin; `deleteManagedUser()` menolak non-admin menghapus akun admin.
- [x] `users.php`: opsi `Admin` pada dropdown hanya tampil untuk sesi admin.
- [x] `tests/security-integration.php`: +3 assertion (pj buat admin, pj promosi diri, diikuti uji hapus admin oleh pj) — semuanya lolos.
Verifikasi: `php -d extension=pdo_mysql tests/security-integration.php` → PASS 44 assertion.
Uji regresi Fase 0 (semua lolos): run-tahsin-tests 153, rapor 48, reminder 39+153, security 44, backup 102, participant-import 76, participant-management 48, participant-merge 47, family 43.

---

## FASE 1 — Kebocoran data & pengerasan akses (±1 hari) — ✅ SELESAI 2026-10-24

### 1.1 [x] T2 — Kosongkan webroot dari dump database & arsip data nyata
1. [x] Dipindah ke `D:\Google Antigravity\arsip-tahsin\`: `db_tahsinbpk.sql`, `import_database_final.sql`, `import_database_lengkap_v2.sql`, `Mustawa_Akhwat.xlsx`, `Mustawa_Ikhwan.xlsx`, `Bapak Wali Santri Baru.xlsx`, `Presensi Tahsin Bapak V1.zip`, `Presensi Tahsin Bapak.zip`, isi `backup/` (folder `arsip-tahsin/backup-folder/`). Tidak ada satu pun yang ter-track git (terverifikasi `git ls-files`).
2. [x] `DEPLOYMENT_INFO.md` & `DEPLOYMENT_CPANEL.md` kini memuat daftar eksklusi eksplisit (dump, xlsx, zip, csv non-template, json non-manifest, pdf, folder internal).
3. [x] `.dockerignore` mengecualikan `*.pdf`, `Reminder*.json`, `data_santri_baru.csv`, xlsx lokal, `assets/go-vue/`.

### 1.2 [x] T3 — Batasi backup berisi hash password + purge otomatis
1. [x] `createBackupZip()` mendapat parameter `$maskPasswords`; unduhan export oleh non-admin menuliskan kolom `users.password` kosong (safety backup pre-restore tetap memakai hash asli sesuai fungsinya memulihkan data).
2. [x] Unduhan arsip (`?download=`) kini khusus admin (arsip temp_excel memuat hash asli).
3. [x] `purgeSafetyBackups()`: dipanggil setiap halaman backup dibuka — simpan maksimal 3 arsip pre-restore dan hapus yang berumur > 7 hari.
4. [x] `.env.example` memberi catatan agar `BACKUP_STORAGE_PATH` menunjuk path di luar webroot.
5. [x] Catatan di UI menyesuaikan (PJ diberi tahu salinannya tanpa kata sandi akun).
Verifikasi: `tests/backup-integration.php` → PASS 102 assertion.

### 1.3 [x] S1/S2 — Kencangkan kedua API (hermes + reminder)
1. [x] `api/hermes.php`: key hanya via header `X-API-Key` (fallback `$_GET/$_POST` dihapus).
2. [x] CORS hanya untuk origin di env `HERMES_ALLOWED_ORIGINS`; tanpa env → tanpa header CORS.
3. [x] Rate limit per-IP 120 req/menit via `apiRateLimitAllow()` di `includes/security_helper.php` (dipakai hermes + reminder).
4. [x] Action `users` tanpa `no_hp`; action `logs` tanpa `ip_address`; action `schema` nonaktif kecuali `HERMES_EXPOSE_SCHEMA=1`.
5. [x] `includes/tahsin_reminder_api.php`: key hanya via header.
6. [x] README + `api/HERMES_API.md` + `.env.example` diperbarui (contoh header-only, opsi baru).
Verifikasi: `node tests/security-http-tests.cjs` → PASS 184 assertion (termasuk 4 baru: key query ditolak, header tetap jalan, CORS tertutup, schema 403, users tanpa no_hp); `tests/tahsin-reminder-http-checks.cjs` → PASS 13; `tests/n8n-reminder-workflow.cjs` → PASS 17.

---

## FASE 2 — Kualitas kode & correctness (±2–3 hari) — ✅ SELESAI 2026-10-24

### 2.1 [x] S3 — Hentikan kebocoran pesan exception DB ke pengguna
- [x] `libur.php`: 3 catch kini memakai `reportApplicationError()` + pesan generik; action `delete` dibungkus try/catch (sebelumnya telanjang).
- [x] `capaian.php`: `RuntimeException` (pesan validasi disengaja) tetap tampil; exception lain → pesan generik + log.
- [x] `naik-kelas.php`: 4 blok catch mengarah ke `reportApplicationError()` + pesan generik; blok `unarchive` dipisah `RuntimeException` (pesan validasi) vs `Throwable` (generik).
- [x] `keluarga.php`: `RuntimeException` tampil; `Throwable` → pesan generik + log.
- [x] `import-santri-baru.php`: pesan error redirect tidak lagi memuat `$e->getMessage()`.

### 2.2 [x] S4 (parsial S7) — Batas ukuran & baris impor santri baru
- [x] `import-santri-baru.php`: konstanta `IMPORT_BARU_MAX_BYTES` (5 MB) + `IMPORT_BARU_MAX_ROWS` (5.000), selaras `import-peserta.php`.
- [x] Upload ditolak bila > 5 MB; apply ditolak bila baris > 5.000; pratinjau GET terpotong dengan pesan.
- Catatan: penyatuan alur impor (menghapus `import-santri-baru.php`) ditangguhkan sebagai item Fase 3 — kedua alur tetap aktif sesuai kebutuhan pengguna saat ini; sisi keamanan (batas + pesan aman) sudah disamakan.

### 2.3 [x] S6 — Sanitasi formula injection export Excel
- [x] `export-excel.php`: helper `excelSafeCell()` — escape HTML + prefix `'` untuk nilai berawalan `=`, `+`, `-`, `@`; diterapkan ke seluruh sel data.

### 2.4 [x] S8 — Opsi pembersihan draft autosave dari perangkat + TTL
- [x] `DraftStore` mendapat `delete()`, `clearUser()`, `purgeExpired()` (30 hari).
- [x] Kedua handler logout (halaman editor & widget recovery) kini menawarkan "Hapus juga salinan draft presensi dari perangkat ini?" — draft terkirim ke server dulu sebelum salinan perangkat dihapus.
- [x] Purge TTL 30 hari berjalan otomatis setiap store dibuka (editor & recovery).
- [x] Duplikasi `csrf_token` di flight body/finalize dihapus (token hanya ditambahkan saat pengiriman, tidak ikut terarsip di perangkat).

### 2.5 [x] S9 — Dead code `assets/go-vue/`
- [x] Folder dihapus (tidak direferensikan PHP mana pun; sudah dikecualikan `.dockerignore` sejak Fase 0).

### 2.6 [x] Butir kecil lain
- [x] Hash versi SW diregenerasi (`node scripts/build-public-assets.cjs`) setelah perubahan `tahsin-autosave.js`.
- Verifikasi Fase 2 (semua lolos): lint 6 file PHP + sintaks JS; run-tahsin 153, rapor 48, reminder 39, security-integration 44, backup 102, participant-management 48, participant-merge 47, family 43, participant-import 76, security-HTTP 184, autosave-storage 25, service-worker 55, asset 19.

---

## FASE 3 — Opsional / jangka panjang — ✅ SEBAGIAN SELESAI 2026-10-24

### 3.1 [x] K1 (tanpa rotasi) — Hentikan penyebaran file daftar akun
Keputusan pemilik: **tanpa rotasi password**. Tindakan yang dijalankan:
1. [x] `git rm DAFTAR_AKUN_USTADZ.md LIST_WA_AKUN_USTADZ.md` — keluar dari index & working tree (commit menyusul oleh pemilik).
2. [x] Salinan kedua file diarsipkan ke `D:\Google Antigravity\arsip-tahsin\` agar referensi operasional tidak hilang.
3. [x] Blokir `.md` di `.htaccess` sudah ada; `.dockerignore` mengecualikan file data.
Catatan: salinan lama tetap ada di riwayat GitHub (di luar cakupan keputusan ini).

### 3.2 [x] R — Hapus kredensial default dari seed & dokumentasi
- [x] `database.sql`: seed admin kini kolom password **kosong** (tidak bisa login) + instruksi pembuatan admin pertama via `password_hash()`.
- [x] `database_production.sql`: perlakuan sama.
- [x] `DEPLOYMENT_INFO.md` & `DEPLOYMENT_CPANEL.md`: pasangan `admin`/`admin123` dihapus, diganti prosedur pembuatan admin pertama.

### 3.3 [x] R — manifest.json icon
- [x] Deklarasi `192x192` yang tidak akurat dihapus; tersisa ikon 512×512 dengan `purpose: "any"`. Ikon 192 px & `maskable` tersisa sebagai perbaikan lanjutan bila dibutuhkan.

### 3.4 [ ] R — Deduplikasi kode (ditunda)
- [ ] `laporan.php` vs `ustadz/laporan.php` → ekstrak bagian bersama ke helper.
- [ ] Jadwalkan penghapusan `templates/rapor_template.php` (legacy fallback) dan 18 file `migrate_*.php` setelah tidak diperlukan.

### 3.5 [ ] R — Operasional (ditunda, dengan alasan)
- [ ] `upload_max_filesize` 512 MB dipertahankan karena fitur restore ZIP memang menerima hingga 512 MB; turunkan hanya jika kebijakan berubah.
- [ ] `health.php` menyembunyikan `APP_VERSION` — menunggu keputusan label rilis.
- [ ] Idle-timeout sesi (mis. 7 hari tanpa aktivitas) di samping umur 30 hari.

### 3.6 [ ] S4 lanjutan — Satukan alur impor (ditunda)
- [ ] Alihkan tautan sidebar `import-santri-baru.php` → `import-peserta.php`; tambahkan banner alur lama akan dihentikan.
- [ ] Setelah tidak ada pengguna aktif: hapus `import-santri-baru.php` atau refactor ke `includes/participant_import_helper.php` (jurnal + lock + fingerprint).
Batas keamanan (5 MB / 5.000 baris / pesan aman) sudah diterapkan di kedua alur pada Fase 2.2.

---

## Verifikasi akhir per fase

Setelah setiap fase, jalankan rangkaian uji yang sudah ada agar tidak ada regresi:

```bash
# Unit + integrasi PHP
composer test        # phpunit + tests/run-tahsin-tests.php

# Uji HTTP keamanan (server PHP lokal dengan fixture)
node tests/security-http-tests.cjs
node tests/service-worker-tests.cjs
node tests/autosave-storage-tests.cjs
```

Dan uji manual cepat: login admin/pj/ustadz, input presensi + autosave, export Excel,
cetak rapor, backup-restore (pratinjau → restore di DB uji).

---

## Daftar temuan → status

| Kode | Ringkas | Fase | Status |
|------|---------|------|--------|
| K1 | Password plaintext di repo publik | 3.1 (tanpa rotasi — keputusan pemilik) | **selesai 2026-10-24** (git rm; riwayat GitHub tetap ada) |
| K2 | API key n8n terekspos | 0.1 (tanpa rotasi — keputusan pemilik) | **selesai 2026-10-24** |
| K3 | CSV data pribadi dapat diunduh | 0.2 | **selesai 2026-10-24** |
| K4 | XSS capaian.php | 0.3 | **selesai 2026-10-24** |
| T1 | pj_tahfidz bisa buat admin | 0.4 | **selesai 2026-10-24** |
| T2 | Dump DB di webroot | 1.1 | **selesai 2026-10-24** |
| T3 | Backup berisi hash password | 1.2 | **selesai 2026-10-24** |
| S1/S2 | API terlalu terbuka | 1.3 | **selesai 2026-10-24** |
| S3 | Pesan exception bocor | 2.1 | **selesai 2026-10-24** |
| S4/S7 | Dua alur impor + tanpa batas upload | 2.2 (batas selesai; penyatuan alur → 3.6) | **selesai 2026-10-24** |
| S6 | Formula injection Excel | 2.3 | **selesai 2026-10-24** |
| S8 | Draft menetap di IndexedDB | 2.4 | **selesai 2026-10-24** |
| S9 | go-vue dead code ter-ship | 2.5 | **selesai 2026-10-24** |
| R | Seed default & dokumentasi admin | 3.2 | **selesai 2026-10-24** |
| R | Manifest icon | 3.3 (deklarasi diperbaiki; ikon 192/maskable menyusul) | **selesai 2026-10-24** |
| R | Deduplikasi kode | 3.4 | ditunda |
| R | Operasional (upload limit, idle timeout, APP_VERSION) | 3.5 | ditunda |

---

## Catatan penutup (2026-10-24)

- Semua perubahan ada di working tree cabang `codex/application-audit-20261023` — **belum di-commit**; silakan tinjau `git status`/`git diff` lalu commit.
- Berkas data sensitif dipindah (bukan dihapus) ke `D:\Google Antigravity\arsip-tahsin\`:
  dump SQL, xlsx, zip lama, `Reminder*.json`, `data_santri_baru.csv`, `backup/`, dan salinan dua file daftar akun.
- **Menunggu tindakan pemilik di server produksi** (tidak dapat dilakukan dari repo):
  1. Set `N8N_API_KEY`/`HERMES_API_KEY` tetap seperti sekarang — tapi ingat key n8n lama masih tertulis di arsip workflow; rotasi tetap disarankan bila kebijakan berubah.
  2. Deploy ulang agar `.htaccess`/`docker/apache.conf` baru aktif; verifikasi `https://host/data_santri_baru.csv` → 403 dan `manifest.json` → 200.
  3. Jalankan migrasi/aksi yang menyentuh data hanya setelah backup; restore selalu merge-only.
- Uji regresi total setelah seluruh fase: PHP (security 44+44, HTTP 184, tahsin 153, rapor 48, reminder 39, backup 102, import 76, management 48, merge 47, family 43, aset 19) dan Node (SW 55, autosave-storage 25). Semua lolos.
