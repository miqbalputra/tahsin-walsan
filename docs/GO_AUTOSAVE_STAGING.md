# Autosave Go dan pengunci penulisan di staging

Status 22 Oktober 2026: implementasi dan uji lokal selesai, **produksi belum dialihkan**. Startup Go, gateway PHP dan CLI migrasi menolak database yang namanya tidak berawalan `tahsin_test_autosave_`. Ini bukan rilis backend tulis produksi.

## Alur dan kompatibilitas

Editor tetap mengirim ke `ustadz/api-autosave.php`. Login, role guru, CSRF, jadwal, penugasan dan kelengkapan migrasi diperiksa PHP. Pada kelompok/catatan yang dialihkan, PHP meneruskan POST bertanda tangan ke layanan internal `/api/v1/autosave`; Go memeriksa penugasan dan aturan penyimpanan kembali di dalam transaksi. GET, antarmuka Vue/PHP, format IndexedDB dan ID operasi tidak diganti.

Go memakai akun tersendiri dan satu transaksi untuk catatan resmi, revisi melalui trigger lama, draft, tanda terima operasi dan audit. Snapshot belum lengkap tetap draft, tanpa menghapus versi laporan sebelumnya. Hadir yang lengkap menjadi resmi bersama capaian. Finalisasi memeriksa semua peserta/revisi, tanpa mengubah pilihan kosong menjadi Alfa.

Gateway PHP mempertahankan algoritma hash tanda terima lama atas permintaan asli: urutan key, tipe scalar dan field tambahan tetap dihitung; token CSRF dikecualikan. Payload diteruskan setelah normalisasi PHP, lalu divalidasi lagi oleh Go. Hash kompatibilitas ini **hanya dipercaya dari gateway bertanda tangan**, bukan dari header browser. HMAC versi tulis mengikat metode, path, query, guru, role, waktu, generasi, hash permintaan dan SHA-256 seluruh body. Kunci tulis wajib berbeda dari kunci baca. Ini juga mempertahankan perilaku casting PHP tanpa menebak serialisasi angka lintas bahasa.

Retry operasi PHP yang sudah commit dikenali Go, dan retry operasi Go dikenali PHP setelah pengembalian. Respons lama tetap tanda terima operasi tersebut; ia tidak diubah menjadi snapshot terkini yang berbeda. Penanganan respons terlambat dan isian lokal baru tetap dilakukan controller autosave yang sama.

## Pengunci di database

`writer_fence_helper.php` memasang tabel kontrol dan trigger tambahan di staging, tanpa menulis ulang presensi atau menghapus trigger revisi/history lama:

- `tahsin_writer_policy`: identitas akun Go, versi dan generasi global pengalihan.
- `tahsin_writer_fences`: satu pemilik `php`, `go` atau `paused` per pasangan **wali–sesi Ahad kanonis**, termasuk ketika wali muncul di kelompok lain.
- `tahsin_writer_groups`: pemilik finalisasi kelompok/sesi.
- `tahsin_writer_locks`: mutex per peserta/sesi; Go hanya mendapat UPDATE pada kolom `mutex` yang tidak memuat kepemilikan.
- `tahsin_writer_audit` dan `tahsin_writer_registry`: jejak pengalihan dan hash definisi trigger yang diperiksa gateway.

Mutasi memperoleh shared lock pada baris policy sampai transaksi berakhir. Operator pengalihan harus memperoleh exclusive lock pada baris yang sama sebelum mengubah pemilik/generasi. Dengan demikian transaksi lama harus selesai atau dibatalkan terlebih dahulu. Lock peserta pada Go mengurutkan perubahan dan finalisasi yang bertabrakan.

Trigger sebelum mutasi melindungi presensi, draft, revisi, tanda terima dan finalisasi, termasuk SQL yang berasal dari jalur PHP lama. Identitas penulis berasal dari akun koneksi MySQL (`USER()`), bukan session variable yang bisa diganti klien. Akun aplikasi hanya mendapat SELECT pada tabel kontrol; perubahan pemilik dilakukan operator dengan kredensial terpisah.

**Batas konservatif tahap staging:** selama ada scope Go/paused, perubahan metadata di seluruh database ditolak. Ini mencakup akun, roster, kelompok, peserta, anak, verifikasi, keluarga, pengaturan dan tabel bisnis lain yang ditemukan saat instalasi. Login dan audit biasa tetap berjalan; log `INIT_CAPAIAN` juga ditahan. Langkah ini melindungi jalur koreksi, merge, arsip, cascade delete dan impor lama tanpa membiarkan perubahan konteks tersembunyi. Ini belum merupakan kebijakan administrasi per kelompok yang siap produksi. Jalur DDL/SQL manual adalah lingkup operator dan dilarang berjalan bersamaan dengan latihan pengalihan.

Permintaan saat pause, generasi berubah, kegagalan transport atau penolakan trigger menghasilkan status **503 yang dapat dicoba ulang**. Antrean/ID operasi tidak dibersihkan. Perubahan revisi yang memang berasal dari perangkat lain tetap konflik 409 dengan entry server. SQL metadata lama dapat menampilkan kesalahan pada halaman administrasi; aktivasi produksi memerlukan penanganan alur administrasi tersebut terlebih dahulu.

## Konfigurasi dan operator

`TAHSIN_NEXT_WRITE_ENABLED=0` adalah default. Untuk latihan, konfigurasi PHP dan Go memerlukan flag `1`, daftar `TAHSIN_NEXT_WRITE_HALAQOH_IDS` yang eksplisit dan `TAHSIN_NEXT_WRITE_SHARED_SECRET` terpisah minimal 32 karakter. Go juga memerlukan `TAHSIN_NEXT_WRITE_DB_HOST`, `PORT`, `DATABASE`, `USERNAME`, `PASSWORD`, dan `TLS` secara eksplisit; tidak mengambil kredensial `DB_*` PHP atau akun baca Go.

Akun Go hanya boleh memiliki SELECT dalam satu database staging, INSERT/UPDATE pada `presensi`, `tahsin_autosave_drafts`, `tahsin_autosave_sessions`, INSERT pada `tahsin_autosave_operations` dan `activity_logs`, serta **UPDATE(mutex)** pada `tahsin_writer_locks`. Izin kolom mutex diperlukan untuk `SELECT mutex ... FOR UPDATE` pada MySQL; Go tidak mendapat UPDATE pada ID, tanggal, pemilik atau generasi. Policy, pemilik dan kelompok dibaca dengan shared lock yang cukup dengan SELECT. Hak DDL, role, grant, mutasi kepemilikan, DELETE tanda terima dan akses lintas database ditolak saat startup. Trigger yang sudah terpasang memperbarui revisi; tidak perlu memberi Go hak TRIGGER atau DDL. Akun baca Go tetap SELECT-only dan terpisah.

CLI operator `scripts/writer-staging.php` memakai `TAHSIN_STAGING_DB_HOST`, `PORT`, `DATABASE`, `USERNAME`, `PASSWORD` tersendiri, tanpa fallback konfigurasi produksi:

```text
php scripts/writer-staging.php install --go-user=AKUN_GO_STAGING
php scripts/writer-staging.php inspect
php scripts/writer-staging.php transfer --teacher=ID_GURU --group=ID_KELOMPOK --date=YYYY-MM-DD --owner=go
php scripts/writer-staging.php transfer --teacher=ID_GURU --group=ID_KELOMPOK --date=YYYY-MM-DD --owner=paused
php scripts/writer-staging.php transfer --teacher=ID_GURU --group=ID_KELOMPOK --date=YYYY-MM-DD --owner=php
```

Jangan memakai penonaktifan flag sebagai pengembalian backend tulis. Kembalikan kepemilikan ke PHP secara terkoordinasi **pada database terkini**, baru matikan layanan Go. Jangan restore backup lama ke database hidup. Scope peserta yang bertumpang tindih tidak boleh dikembalikan ke pemilik berbeda dari kelompok Go lain; operator harus merekonsiliasinya dahulu. Perubahan metadata sebaiknya menunggu seluruh scope kembali ke PHP.

## Bukti lokal

1. Integrasi Go/PHP pada fixture MariaDB: tanda terima PHP → Go dan Go → PHP, operasi yang responsnya hilang, rotasi CSRF, penyimpanan resmi sekali, draft parsial mempertahankan laporan, pemakaian ulang ID ditolak, dua perangkat bersamaan, finalisasi, pause, generasi lama dan pengembalian.
2. Kegagalan tanda terima sengaja disuntikkan **sesudah** penulisan presensi/draft. Catatan, revisi dan draft kembali persis ke keadaan sebelumnya, tanpa tanda terima sukses palsu.
3. Mutasi PHP langsung dan perubahan metadata ditolak ketika Go memiliki scope. Akun Go tidak dapat memperbarui policy. Exclusive lock operator terbukti menunggu transaksi DML PHP lama sampai commit.
4. **11 pemeriksaan HTTP** melalui login/CSRF PHP: forwarding Go, retry identik, pembacaan PHP, kelompok PHP lain tetap dapat menulis, penolakan pengirim PHP lama dengan revisi terkini, pause 503 dan tanda terima Go setelah pengembalian ke PHP.
5. **39 pemeriksaan browser autosave** berjalan melalui Vue → PHP → Go: input tanpa blur, jeda pengiriman 600 ms/maksimum 2 detik, draft perangkat lain, respons hilang/terlambat, offline/reload, login kembali, konflik, ringkasan/finalisasi, IndexedDB gagal dan lebar HP tanpa error runtime.
6. Kontrak baca PHP/Go terdahulu tetap cocok pada 9 skenario setelah pembaca dipakai ulang dalam transaksi tulis. Unit Go, vet, 26 pemeriksaan gateway PHP, 147 regresi laporan dan 25 pemeriksaan autosave PHP tetap lulus.
7. Uji host Coolify pada 22 Oktober 2026 memakai MySQL **8.4.11** dan image DB yang sama dengan produksi, tetapi container, datadir, port loopback, akun, dan database sintetis terpisah. Integrasi PHP autosave lulus 25 assertion; suite writer Go/PHP memeriksa handoff transaksi lama, retry tanda terima lintas backend, batas grant, kegagalan rollback, konflik perangkat, finalisasi, pause, dan pengembalian. Dump database sintetis sebelum restore dan hasil restore cocok persis untuk skema, data, dan trigger. Replay tanda terima Go dari salinan restore mengembalikan respons asli; jumlah tanda terima dan satu catatan resmi tetap masing-masing satu. Database produksi dan volume produksinya tidak dipakai oleh uji ini.

Fixture baru dibuat dengan `TAHSIN_TEST_DB_PORT=13319`, `tests/autosave-integration.php NAMA_DB_UNIK`, lalu `tests/go-write-fixture.php NAMA_DB_UNIK`. Semuanya menolak nama database produksi dan memeriksa akun fiktif `admin_test`. Gunakan instance lokal yang dimiliki sendiri tanpa grant PUBLIC tulis; jangan melonggarkan pemeriksaan Go untuk menyesuaikan default grant MariaDB.

Set `TAHSIN_WRITE_MYSQL_TEST=1`, konfigurasi writer ke akun fixture, `TAHSIN_TEST_PHP_BINARY` ke PHP CLI dan `TAHSIN_WRITE_BRIDGE_FILE` ke path absolut `tests/go-write-bridge.php`, lalu jalankan `go test ./...`. Pengujian integrasi memerlukan fixture baru, tidak didesain untuk mengulang ID operasi tetap pada fixture yang sudah dipakai.

`tests/run-go-writer-local.ps1 -Suite http` menjalankan runtime privat localhost. Suite `browser` memerlukan fixture Ibu baru yang belum diisi, build Vue yang sudah tersedia dan Playwright/Chrome pada runtime pengembangan. Kedua suite menghentikan proses uji dan memulihkan environment proses setelah selesai. Bukti/screenshot lokal berada di `.sessions/`, tidak masuk Git.

**Gerbang yang masih terbuka:** kebijakan administrasi untuk pilot, salinan backup independen, inventaris antrean perangkat, uji beban dan pilot guru. Validasi writer pada image MySQL 8.4 yang cocok dengan home server serta backup/restore/replay tanda terima Go sudah lulus pada database sintetis terisolasi; ini belum membuktikan prosedur pemulihan produksi, ketersediaan backup luar server, atau kelayakan cutover. Batas nama database sintetis belum boleh dihapus untuk melewati gerbang yang tersisa. Tidak ada penggantian database, impor keluarga atau pengalihan guru produksi pada pekerjaan ini.

Rujukan: [rencana migrasi](RENCANA_MIGRASI_GO_VUE.md), [runbook pemulihan](MIGRATION_SAFETY_RUNBOOK.md), [locking reads MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).
