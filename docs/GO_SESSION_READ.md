# Kontrak baca sesi guru Go

Status 22 Oktober 2026: kode staging tersedia, mati secara default. Ini belum mengalihkan pembacaan editor atau penulisan produksi. Editor Vue/PHP tetap memakai `ustadz/api-autosave.php` untuk GET dan POST.

`GET /api/go-vue-teacher-session.php?halaqoh_id=ID&tanggal=YYYY-MM-DD` memvalidasi sesi PHP, role `ustadz`, daftar kelompok pilot, skema reporting/autosave lengkap dan penugasan guru. PHP menandatangani identitas, role, metode, path dan query menuju `GET /api/v1/teacher-session` di layanan Go internal. Guru tidak dapat memilih identitas lewat query. Endpoint keluarga admin/PJ tetap menolak guru, dan endpoint sesi guru menolak admin/PJ.

Go memeriksa ulang penugasan berdasarkan batas histori roster hari Ahad. Guru lama dapat membaca sesi yang memang menjadi penugasannya; guru yang baru ditugaskan tidak otomatis mendapat roster historis itu. Periode sebelum pelacakan memakai aturan roster tercatat PHP. Status aktif peserta saat ini tidak menghapus keanggotaan historis yang sah.

Pembacaan memakai akun SELECT-only yang diperiksa saat startup, transaksi READ ONLY dengan REPEATABLE READ, pool yang sama dengan pratinjau keluarga dan batas waktu tiga detik. Timezone setiap koneksi disamakan dengan PHP (`+07:00`), termasuk kolom TIMESTAMP. Tidak ada DDL, impor, pencatatan resmi, pengubahan draft, tanda terima operasi baru, finalisasi atau pembersihan IndexedDB.

Respons `status: success` dan `data` mengikuti `autosaveSessionState`: program, sesi kanonis, context revision, aktif/libur, payload draft dan resmi, official/draft revision, kelengkapan, konflik, draft kedaluwarsa, waktu tersimpan dan penanda sesi selesai. Hash mempertahankan urutan objek PHP, tipe angka/null, Unicode, slash dan timestamp. Angka revisi yang sama saja belum cukup; nilai hash dibandingkan secara langsung.

## Konfigurasi staging

```env
TAHSIN_NEXT_ENABLED=1
TAHSIN_NEXT_DATA_SOURCE=mysql
TAHSIN_NEXT_TEACHER_READ_ENABLED=0
TAHSIN_NEXT_TEACHER_READ_HALAQOH_IDS=
```

Untuk uji terbatas, set flag baca guru ke `1` dan isi daftar ID yang valid/tidak kosong pada **PHP dan Go**, menggunakan konfigurasi koneksi/kunci privat pada [panduan layanan](GO_VUE_MIGRATION.md). Flag tampilan Vue tetap terpisah. Go menolak startup jika baca guru diminta dalam mode demo atau daftar kelompok tidak valid. Default kosong tidak berarti semua kelompok.

Akun SELECT-only tidak dapat melihat trigger MySQL; jangan memberikan hak TRIGGER untuk mengatasi hal ini. Gateway PHP memakai akun aplikasi yang sudah ada untuk memastikan seluruh trigger reporting/autosave terpasang sebelum meneruskan permintaan. Go juga memeriksa versi pelacakan dan membaca tabel kontrak yang dibutuhkan. Skema yang belum siap menghasilkan kegagalan, tanpa membuat tabel atau mengembalikan keadaan kosong palsu. Perubahan skema bersamaan tidak didukung selama pemeriksaan ini.

Jalur ini ditujukan untuk membandingkan hasil di staging, bukan mengganti URL API autosave pada boot editor. Backend gagal menghasilkan 503 pada pratinjau, sementara pengisian PHP tetap tersedia. Mengarahkan editor ke Go memerlukan tahap rilis dan pengujian tersendiri.

## Bukti dan pengulangan uji

- Unit Go menguji pembatas endpoint/role/kelompok, pemalsuan identitas/query, seluruh metode tulis, kegagalan SQL, kalender dan hash acuan yang dihasilkan PHP.
- Fixture lokal MariaDB pada port 13319 membandingkan **seluruh respons** pada 9 skenario: pemilik historis, guru baru/keliru, Ibu selesai, draft parsial, tanggal libur, roster lama sebelum pelacakan, roster kosong dan tanggal mendatang. Di dalamnya terdapat keputusan verifikasi, catatan ganda, dugaan capaian awal, Unicode dan draft yang kedaluwarsa setelah pergantian guru.
- **27 pemeriksaan HTTP** lewat login PHP nyata pada fixture: keadaan Go sama dengan API PHP, akses, flag mati, kelompok di luar pilot, header tanpa cache dan larangan POST.
- **26 pemeriksaan gateway PHP**, **147 regresi laporan**, `go test ./...` dan `go vet ./...` lulus. Integrasi autosave PHP fixture tetap lulus 25 pemeriksaan.

Menyiapkan fixture memerlukan instance pengembangan yang dimiliki sendiri, tanpa data produksi. `tests/autosave-integration.php` membuat database baru dengan prefix `tahsin_test_autosave_`; `tests/go-session-fixture.php` hanya menerima prefix/port itu dan memastikan akun fiktif `admin_test`. Skrip kedua membuat akun baca fiktif dan menulis hasil PHP ke `.sessions/go-session-expected.json`, bukan ke Git. Default hak tulis PUBLIC pada sebagian MariaDB dapat membuat startup Go ditolak; siapkan instance uji tanpa hak itu, jangan melonggarkan pemeriksaan Go atau mengubah grant produksi.

```text
php tests/autosave-integration.php tahsin_test_autosave_NAMA_UNIK
php tests/go-session-fixture.php tahsin_test_autosave_NAMA_UNIK
```

Set `TAHSIN_TEST_DB_PORT=13319` sebelum menyiapkan fixture. Untuk `TestTeacherPHPContractIntegration`, isi `TAHSIN_SESSION_MYSQL_TEST=1`, `TAHSIN_SESSION_EXPECTED_FILE` dengan path absolut hasil PHP, serta `TAHSIN_NEXT_DB_*` menggunakan akun baca fixture. Tes menolak host/port/prefix lain. Jalankan `go test ./...` dari `next/backend`.

Untuk HTTP, jalankan Go pada loopback 18091 dengan daftar kelompok fiktif `11,12,13`, lalu PHP development server pada loopback 18085 menggunakan `tests/go-session-router.php` dan `TAHSIN_TEST_DB_NAME`. Jalankan `node tests/go-session-http.cjs` dengan path hasil PHP yang sama. Router tidak dapat berjalan melalui PHP web biasa dan selalu menunjuk database uji loopback.

Kesamaan endpoint baru ini sudah diuji pada MariaDB lokal; pemeriksaan MySQL 8.4/image home server sebelumnya memakai kode tahap sebelumnya. Kode baca sesi baru perlu diuji lagi pada image dan salinan MySQL target sebelum aktivasi. Tidak ada perubahan produksi pada tahap ini. Penulisan Go, pembatas semua jalur tulis, pengembalian, uji beban dan pilot guru tetap pekerjaan berikutnya dalam [rencana migrasi](RENCANA_MIGRASI_GO_VUE.md).

Referensi: [transaksi Go](https://go.dev/doc/database/execute-transactions), [konfigurasi timezone driver MySQL](https://github.com/go-sql-driver/mysql/tree/v1.10.1#system-variables).
