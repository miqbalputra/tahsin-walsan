# Implementasi awal runtime Go + Vue produksi

Paket produksi terpisah dari paket staging: `docker/Dockerfile.go-vue-production` dan `compose.go-vue-production.yml`. Compose tetap memakai hostname dan schema MySQL yang dikonfigurasi, tidak menyalin tabel, tidak membuka port database, dan menyimpan berkas aplikasi pada volume `tahsin_app_data`. Startup Go tidak menjalankan DDL.

## Yang sudah ada di kode ini

- Image satu service Go + Vue 3/TypeScript dengan fallback route SPA yang tidak mengambil alih `/api/*`, `/assets/*`, atau berkas statis yang hilang.
- Service worker dan manifest PWA Go berada di `next/frontend/public/`; `sw.js` dan `manifest.json` PHP di root tetap utuh selama transisi.
- Pemeriksaan health/readiness. Readiness menolak traffic sampai tabel lama dan migrasi aplikasi yang diwajibkan tersedia.
- Pada runtime produksi, `APP_EXPECTED_DB_DATABASE` ditetapkan ke `db_tahsinbapak`; Go menolak startup bila `DB_DATABASE` kosong atau berbeda dari schema yang disetujui.
- `APP_PRODUCTION_READY=0` menolak semua API Go dan endpoint autosave lama walaupun proxy keliru mengarahkan traffic ke service yang belum sehat.
- Login memakai `users.password` yang sudah ada, memverifikasi prefix bcrypt PHP `$2y$`/`$2b$` tanpa menulis hash, lalu membuat cookie sesi server-side. Pengguna perlu login ulang satu kali saat cutover.
- Perintah eksplisit `tahsin-migrate` hanya membuat tabel aditif `tahsin_app_sessions`; `CREATE TABLE IF NOT EXISTS` aman diulang dan tidak mengubah tabel/data lama.
- Endpoint Go membaca sesi guru memakai reader yang sudah diuji terhadap kontrak PHP autosave. Endpoint `/ustadz/api-autosave.php` tetap tersedia sebagai alias dan mempertahankan hash tanda terima untuk antrean operasi IndexedDB lama.
- Penulis runtime Go tidak memakai tabel writer staging. Ia memakai tabel revision per peserta untuk mengunci penyimpanan satu peserta pada satu sesi; mode staging dan guard synthetic database tetap terpisah.
- Laporan presensi dasar telah dipindahkan sebagai API berfilter dan berhalaman, dengan batas role serta penghapusan yang mencatat audit, menaikkan revisi autosave, dan membatalkan status sesi.
- Roster guru memakai `family_children` jika view integrasi keluarga tersedia, lalu fallback ke `santri_detail`.
- File versi PHP, Compose staging, migration lama, ID, dan data tidak diubah oleh pekerjaan ini.

## Environment

Lihat [.env.go-vue.example](../.env.go-vue.example). Pada server aktif, `DB_DATABASE` adalah schema `db_tahsinbapak`; nama resource/database Coolify `db_tahsinbpk` berbeda dan bukan nilai schema. Pertahankan `DB_HOST` internal, port, schema, charset, timezone, dan rahasia integrasi yang ada pada resource Go terpisah. `DB_USERNAME`/`DB_PASSWORD` harus menjadi akun aplikasi Go khusus dengan DML yang diperlukan tetapi tanpa DDL, trigger, atau grant. Simpan kredensial PHP saat ini di resource Coolify lama untuk rollback.

`MIGRATION_DB_USERNAME` dan `MIGRATION_DB_PASSWORD` diperlukan sementara ketika operator menjalankan `tahsin-migrate`, dan harus memakai akun yang boleh membuat tabel. Jangan memasukkannya ke environment runtime app. `TAHSIN_NEXT_*` tidak digunakan oleh runtime produksi.

Akun runtime dibuat terpisah oleh operator database dan dibatasi ke host/jaringan container aplikasi. Pola grant yang diperlukan pada schema lama:

```sql
CREATE USER 'tahsin_go_app'@'<host-atau-subnet-aplikasi>' IDENTIFIED BY '<secret-di-Coolify>';
GRANT SELECT, INSERT, UPDATE, DELETE ON `db_tahsinbapak`.* TO 'tahsin_go_app'@'<host-atau-subnet-aplikasi>';
```

Ganti nama schema dan host sesuai resource lama; jangan memakai `*.*`, `WITH GRANT OPTION`, atau kredensial migrasi untuk runtime. Jika MySQL mewajibkan TLS, pasang CA sebagai file read-only di container dan isi `DB_TLS_CA_FILE` dengan path file tersebut.

## Batas kesiapan

Image ini **belum memenuhi syarat redeploy produksi**. Login, fondasi sesi guru, autosave, laporan presensi dasar, baca keluarga, serta tambah/edit dasar kelompok sudah ada, tetapi port dashboard dan pemantauan Tahsin penuh, CRUD anak/keluarga/anggota/pengguna, capaian/rapor/ekspor, backup-restore merge-only, Hermes, reminder, Data Induk, dan kontrak API lama masih harus dipindahkan serta diuji paritasnya. Handler untuk modul yang belum dipindahkan tidak boleh dianggap pengganti aplikasi PHP.

Status modul agar keputusan cutover terukur:

| Modul | Status Go + Vue | Syarat sebelum produksi |
|---|---|---|
| Login dan role | Dasar tersedia | Uji semua role, hash password PHP, sesi, CSRF, dan akses endpoint pada clone |
| Input guru dan autosave | Adapter lama dan writer Go tersedia | Uji antrean IndexedDB lama, offline, konflik, dua perangkat, serta laporan pada clone |
| Daftar keluarga | Baca saja | Port tambah/edit/arsip keluarga-anak dan aturan Data Induk |
| Kelompok | Daftar dan tambah/edit dasar tersedia; hapus dan kelola anggota belum ada | Uji program Bapak/Ibu, pengajar, konflik histori/draft, serta port keanggotaan |
| Peserta/anak dan pengguna | Belum dipindahkan | Port CRUD/import/arsip dengan histori, ID, dan hak akses tetap |
| Dashboard, histori, capaian, rapor, rekap, ekspor | Laporan presensi dasar saja | Cocokkan hasil PHP dan Go untuk periode/filter yang sama |
| Libur, pengumuman, log, pengaturan | Belum dipindahkan | Port operasi dan audit yang saat ini dipakai |
| Backup dan restore | Belum dipindahkan | Implementasikan pratinjau dan merge-only, lalu latihan restore di clone |
| Hermes, n8n, pengingat, Data Induk | Belum dipindahkan | Pertahankan kontrak, rahasia, retry, dan uji endpoint lama |
| Backup produksi dan pemulihan | Gerbang operasional | Salinan di luar home server, restore drill, dan replay delta/binlog yang teruji |

Karena modul-modul tersebut belum lengkap, mengganti resource aplikasi aktif sekarang akan menghilangkan akses operasional ke fitur yang masih dipakai meskipun isi database tidak terhapus. Jangan membuka `APP_PRODUCTION_READY` atau mengganti domain/proxy ke Go sebelum semua baris tabel di atas lulus uji.

Jangan pilih Compose produksi untuk mengganti traffic aplikasi aktif sampai seluruh modul dan integrasi itu selesai, pemeriksaan kesamaan data/laporan pada clone lulus, kredensial DML diverifikasi, antrean perangkat ditangani, serta backup independen dan latihan restore/replay binlog memenuhi gerbang di [runbook keselamatan migrasi](MIGRATION_SAFETY_RUNBOOK.md). Belum ada koneksi atau perubahan ke database produksi dari build ini.
