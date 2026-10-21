# Paket staging Go + Vue untuk Coolify

Paket ini menyiapkan candidate PHP–Vue dan Go dalam satu resource Compose tanpa mengganti resource aplikasi aktif. Pilih file `compose.go-vue-staging.yml` secara eksplisit sebagai Compose file; namanya sengaja berbeda dari nama auto-discovery. Jangan isi variabel `TAHSIN_STAGING_*` dengan kredensial atau database produksi.

## Batas aman bawaan

- Kedua container menolak database yang namanya bukan `tahsin_test_autosave_*`; backend Go juga menolak bila PHP dan Go menunjuk database berbeda.
- Go API, Vue guru, dan Go writer mati secara default. Go memakai demo mode, dan PHP tetap menjadi satu-satunya penulis.
- PHP dan Go hanya memiliki `expose` internal; file tidak memetakan `ports` ke host. Pasang domain hanya ke service `app` bila perlu menguji lewat browser. Service `go` tetap tanpa domain publik.
- File ini tidak membuat, mengubah, atau mengimpor database. Tidak ada definisi MySQL baru di stack ini.

## Menyiapkan resource

1. Buat database clone/fixture staging pada server yang sama dengan Coolify. Gunakan nama seperti `tahsin_test_autosave_go_vue_staging` dan akun PHP staging yang hanya memiliki hak yang dibutuhkan aplikasi.
2. Buat akun Go staging terpisah yang hanya memiliki `SELECT` pada database staging. Jangan pakai akun PHP atau akun produksi.
3. Buat resource Compose candidate baru dari branch staging, pilih `compose.go-vue-staging.yml`, dan pastikan destination server sama dengan database staging.
4. Jika database berdiri sebagai resource Coolify terpisah, aktifkan **Connect To Predefined Network** pada resource Compose hanya setelah memastikan keduanya berada pada server dan network destination yang sama. Isi `TAHSIN_STAGING_DB_HOST` dengan **Internal URL/hostname** database yang ditampilkan Coolify, bukan `localhost`; isi port yang tercantum di sana.
5. Masukkan variabel `TAHSIN_STAGING_DB_HOST`, `TAHSIN_STAGING_DB_DATABASE`, `TAHSIN_STAGING_DB_USERNAME`, `TAHSIN_STAGING_DB_PASSWORD`, `TAHSIN_STAGING_GO_READ_USERNAME`, dan `TAHSIN_STAGING_GO_READ_PASSWORD` di Environment Variables Coolify. Password hanya disimpan di Coolify, tidak di Git.
6. Deploy sebagai resource terpisah. Domain, jika dipakai, diarahkan ke service `app` port internal `80`. Jangan pasang domain pada service `go`.

Untuk menggunakan Go read-only pada clone staging, ubah `TAHSIN_NEXT_ENABLED` menjadi `1`, `TAHSIN_NEXT_DATA_SOURCE` menjadi `mysql`, dan isi `TAHSIN_NEXT_SHARED_SECRET` dengan secret acak minimal 32 karakter di kedua service yang memerlukan tanda tangan. Gunakan kelompok yang memang ada di clone dan izinkan fitur hanya untuk kelompok uji. Guard staging tetap menolak database produksi dan writer Go.

## Validasi sebelum deploy

- `go test ./...` dari `next/backend` dan `node tests/staging-entrypoint-guard.cjs` dari root repo.
- `docker compose -f compose.go-vue-staging.yml config` dengan env staging sintetis. Semua variabel `TAHSIN_STAGING_*` harus menunjuk clone/fixture staging; untuk pemeriksaan konfigurasi gunakan nilai dummy, bukan kredensial produksi.
- Jalankan image candidate dan periksa `/health.php`, login test, aset Vue, serta `/healthz` internal Go. Pastikan PHP tetap dapat bekerja bila Go berhenti saat semua flag Go mati.
- Sebelum pilot, ikuti gerbang backup dan pemulihan di [runbook keselamatan migrasi](MIGRATION_SAFETY_RUNBOOK.md). Paket staging ini bukan persetujuan untuk cutover atau mengaktifkan penulisan Go di produksi.

## Jaringan Coolify

Coolify memberi satu Compose resource jaringan privatnya sendiri; service `app` memanggil backend menggunakan nama service `go` dan port internal `8080`. Compose ini tidak menambahkan jaringan Docker custom. Untuk mencapai resource database Coolify lain, aktifkan koneksi ke predefined network dari UI dan verifikasi hostname internal resource. Sumber: [Compose networking](https://coolify.io/docs/applications/builds/docker-compose) dan [networking di Coolify](https://coolify.io/docs/core/networking-in-coolify).
