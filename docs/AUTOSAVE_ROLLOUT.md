# Autosave Tahsin Bapak dan Ibu

## Perilaku

Guru masuk melalui Hari Ini, memilih kelompok, lalu mengisi status seluruh peserta dalam daftar. Detail capaian dibuka per peserta. Tidak ada tombol Simpan. Input dan perubahan pilihan ditulis ke IndexedDB, kemudian dikirim setelah jeda 600 ms, dengan batas tunggu 2 detik selama pengetikan berlanjut. Keluar kolom, menutup detail, dan membuka ringkasan mempercepat pengiriman.

Draft parsial disimpan di tabel terpisah dari `presensi`. Data Hadir resmi hanya ditulis setelah materi, rentang, dan Lulus/Ulang lengkap; perubahan sementara yang belum lengkap mempertahankan catatan resmi sebelumnya. Status Sakit/Izin memerlukan alasan. Alfa harus dipilih secara eksplisit pada alur baru. Finalisasi tidak mengubah isian kosong menjadi Alfa.

Draft server hanya dapat diambil oleh akun guru yang memiliki penugasan; draft lokal dipisahkan per akun/program/kelompok/sesi/peserta/tab. Server memberikan revisi dan tanda terima operasi agar pengiriman ulang tidak menggandakan catatan. Konflik perlu dipilih secara eksplisit. Halaman Hari Ini, Akun, dan halaman guru lainnya juga memeriksa serta mengirim antrean akun yang sedang login; tanggal pertemuan asli dipertahankan. Trigger revisi menangkap perubahan pada `presensi` dari jalur lama, koreksi admin, penghapusan, dan pemindahan; fingerprint juga mencakup verifikasi dan konteks roster.

## Aktivasi

1. Backup database dan validasi pemulihan pada staging. Migrasi reporting/histori lama tetap digunakan bila sudah terpasang.
2. Jalankan `php migrate_autosave.php` untuk dry-run tanpa koneksi/mutasi database.
3. Jalankan `php migrate_autosave.php --apply` menggunakan environment database staging terlebih dahulu. Membutuhkan hak ALTER/CREATE TABLE/CREATE TRIGGER. Skrip menambah kolom `halaqoh.program_code`, empat tabel, dan tiga trigger; tidak mengubah atau menghapus catatan lama. Aman dijalankan ulang.
4. Di Manajemen Halaqoh, pilih program Bapak atau Ibu. Peserta dan guru tetap dikelola melalui halaman admin yang ada. Peserta Ibu memiliki baris/ID sendiri; jangan mengganti identitas peserta Bapak menjadi Ibu.
5. Untuk pilot, set `TAHSIN_AUTOSAVE_HALAQOH_IDS=1,3` menggunakan ID kelompok yang benar. Hanya kelompok tersebut memakai form baru. Tanpa daftar pilot, semua kelompok dengan skema siap menggunakan form baru. `TAHSIN_AUTOSAVE_ENABLED=0` mematikan alur baru dan API autosave tanpa menghapus draft/data.
6. Jalankan pilot 1–2 kelompok bersama 3–5 guru selama minimal dua pertemuan. Ukur waktu pendataan, galat, antrean yang tertahan, serta koreksi yang dibutuhkan. Perluas setelah tidak ada masalah integritas kritis.

Migrasi produksi dan pilot manusia belum dilakukan oleh implementasi lokal ini. Pembaruan service worker menunggu tab lama ditutup, sehingga tidak memaksa reload editor aktif. Jika perlu kembali ke versi stabil, gunakan pengaturan fitur; jangan menghapus tabel draft atau trigger saat masih ada antrean.

## Batas dukungan dan kompatibilitas

- Kalender pertemuan tetap mengikuti aturan Ahad/libur yang sudah ada. Konfirmasikan kecocokan jadwal Ibu sebelum pilot; rilis ini tidak menebak jadwal baru atau membuat peserta dari file pribadi secara otomatis.
- Kolom internal legacy `nama_bapak` tetap digunakan untuk kompatibilitas laporan; formulir admin menampilkan Nama Peserta (Bapak / Ibu). Penanda program berada pada kelompok. Sinkronisasi Data Induk yang ada tetap memakai pemetaan ayah; perluasan kontrak relasi ibu di pusat merupakan pekerjaan terpisah dan tidak diaktifkan diam-diam.
- Membuka sesi pertama kali membutuhkan internet. Setelah sesi dimuat, input dapat ditampung ketika koneksi putus. Membuka ulang halaman membutuhkan jaringan; draft dipulihkan setelah login/halaman dapat dimuat kembali.
- Draft di HP hanya dianggap tersimpan setelah transaksi IndexedDB selesai. Browser/penyimpanan perangkat tetap dapat dihapus pengguna atau sistem; server yang sudah mengakui draft menjadi titik pemulihan lintas perangkat. Tidak ada jaminan terhadap ketukan terakhir sebelum penulisan selesai atau perangkat mati mendadak.
- Pengiriman ulang berjalan saat aplikasi terbuka/aktif, dengan batas jeda 30 detik; tidak mengandalkan proses latar belakang atau beforeunload. Login berakhir mempertahankan antrean dan memerlukan login/muat ulang sebelum pengiriman dilanjutkan.
- Alur lama dan POST lama tetap tersedia selama transisi. Parameter `legacy=1` membuka form lama untuk fallback daring; perilaku finalisasi lama tetap ditampilkan jelas di form tersebut.
- Pusat laporan lintas aplikasi belum dibangun. Laporan Tahsin yang ada tetap membaca catatan resmi, tidak membaca draft parsial.

## API

`GET ustadz/api-autosave.php?halaqoh_id=...&tanggal=YYYY-MM-DD` mengembalikan konteks program/sesi, roster yang boleh diakses, keadaan draft/resmi, revisi, kelengkapan, dan waktu selesai sesi.

`POST` JSON memakai action `save` atau `finalize`, sesi login guru, dan `csrf_token`. Save menyertakan `operation_id`, `halaqoh_id`, `tanggal`, `program`, `wali_id`, `payload`, `context_revision`, `official_revision`, dan `draft_revision`. Finalize menyertakan konteks serta map revisi resmi semua peserta. Respons sukses memuat `status=success` dan `data`. Kode 401 berarti login berakhir; 403 berarti akses/CSRF ditolak; 409 berarti revisi/konteks perlu ditinjau; 422 berarti format tidak valid. Gangguan jaringan/server diulang dengan ID operasi yang sama.

## Pengujian lokal terisolasi

- `php tests/run-tahsin-tests.php` untuk regresi perhitungan laporan.
- `php tests/tahsin-mysql-integration.php tahsin_test_<nama_baru>` untuk integrasi lama.
- Suite browser laporan lama dapat menggunakan `TAHSIN_TEST_REPORT_END=2026-10-14` agar fixture historis tidak mendapatkan pekan kosong tambahan ketika tanggal berjalan berubah. Permintaan periode khusus tetap diuji sesuai rentangnya; pengingat mengikuti sesi aktual server.
- `php tests/autosave-integration.php tahsin_test_autosave_<nama_baru>` untuk membuat fixture baru di MySQL/MariaDB localhost port 13317 dan menguji migrasi, draft, transaksi, versi, serta finalisasi. Kredensial di skrip hanya untuk database uji lokal yang sudah digunakan proyek; skrip tidak memuat konfigurasi database aplikasi.
- Jalankan PHP staging dengan router lokal yang mengarahkan `DB_DATABASE` ke fixture tersebut. Set `TAHSIN_TEST_URL` ke localhost dan `TAHSIN_TEST_DATE` dari keluaran fixture, lalu jalankan `node tests/autosave-browser.cjs` dengan Playwright pada `NODE_PATH`.
- PHP lokal yang belum memuat PDO MySQL dapat dijalankan dengan `-d extension=php_pdo_mysql.dll`; image produksi sudah memasang ekstensi tersebut.

Pengujian otomatis tidak menggantikan pilot pada HP dan jaringan guru yang sebenarnya.
