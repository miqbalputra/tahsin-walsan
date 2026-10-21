# Implementasi awal runtime Go + Vue produksi

Paket produksi terpisah dari paket staging: `docker/Dockerfile.go-vue-production` dan `compose.go-vue-production.yml`. Compose tetap memakai hostname dan schema MySQL yang dikonfigurasi, tidak menyalin tabel, tidak membuka port database, dan menyimpan berkas aplikasi pada volume `tahsin_app_data`. Startup Go tidak menjalankan DDL.

## Yang sudah ada di kode ini

- Image satu service Go + Vue 3/TypeScript dengan fallback route SPA yang tidak mengambil alih `/api/*`, `/assets/*`, atau berkas statis yang hilang.
- Service worker dan manifest PWA Go berada di `next/frontend/public/`; `sw.js` dan `manifest.json` PHP di root tetap utuh selama transisi.
- Pemeriksaan health/readiness. Readiness menolak traffic sampai tabel lama dan migrasi aplikasi yang diwajibkan tersedia.
- `APP_PRODUCTION_READY=0` menolak semua API Go dan endpoint autosave lama walaupun proxy keliru mengarahkan traffic ke service yang belum sehat.
- Login memakai `users.password` yang sudah ada, memverifikasi prefix bcrypt PHP `$2y$`/`$2b$` tanpa menulis hash, lalu membuat cookie sesi server-side. Pengguna perlu login ulang satu kali saat cutover.
- Perintah eksplisit `tahsin-migrate` hanya membuat tabel aditif `tahsin_app_sessions`; `CREATE TABLE IF NOT EXISTS` aman diulang dan tidak mengubah tabel/data lama.
- Endpoint Go membaca sesi guru memakai reader yang sudah diuji terhadap kontrak PHP autosave. Endpoint `/ustadz/api-autosave.php` tetap tersedia sebagai alias dan mempertahankan hash tanda terima untuk antrean operasi IndexedDB lama.
- Penulis runtime Go tidak memakai tabel writer staging. Ia memakai tabel revision per peserta untuk mengunci penyimpanan satu peserta pada satu sesi; mode staging dan guard synthetic database tetap terpisah.
- Roster guru memakai `family_children` jika view integrasi keluarga tersedia, lalu fallback ke `santri_detail`.
- File versi PHP, Compose staging, migration lama, ID, dan data tidak diubah oleh pekerjaan ini.

## Environment

Lihat [.env.go-vue.example](../.env.go-vue.example). `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_CHARSET`, `APP_TIMEZONE` dan rahasia Hermes, n8n, serta Data Induk tetap dibawa ke target Go. `DB_DATABASE` tetap menunjuk schema lama. `DB_USERNAME`/`DB_PASSWORD` harus menjadi akun aplikasi Go khusus dengan DML yang diperlukan tetapi tanpa DDL, trigger, atau grant. Simpan kredensial PHP saat ini di resource Coolify lama untuk rollback.

`MIGRATION_DB_USERNAME` dan `MIGRATION_DB_PASSWORD` diperlukan sementara ketika operator menjalankan `tahsin-migrate`, dan harus memakai akun yang boleh membuat tabel. Jangan memasukkannya ke environment runtime app. `TAHSIN_NEXT_*` tidak digunakan oleh runtime produksi.

## Batas kesiapan

Image ini **belum memenuhi syarat redeploy produksi**. Login, fondasi sesi guru, autosave, dan serving Vue sudah ada, tetapi port modul admin/PJ/kepsek, CRUD anak/keluarga/kelompok/pengguna, laporan/rapor/ekspor, backup-restore merge-only, Hermes, reminder, Data Induk, dan semua kontrak API lama masih harus dipindahkan serta diuji paritasnya. Handler untuk modul yang belum dipindahkan tidak boleh dianggap pengganti aplikasi PHP.

Jangan pilih Compose produksi untuk mengganti traffic aplikasi aktif sampai seluruh modul dan integrasi itu selesai, pemeriksaan kesamaan data/laporan pada clone lulus, kredensial DML diverifikasi, antrean perangkat ditangani, serta backup independen dan latihan restore/replay binlog memenuhi gerbang di [runbook keselamatan migrasi](MIGRATION_SAFETY_RUNBOOK.md). Belum ada koneksi atau perubahan ke database produksi dari build ini.
