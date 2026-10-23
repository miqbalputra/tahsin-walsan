# Audit aplikasi Presensi Tahsin

Dimulai 23 Oktober 2026. Status: **berjalan**. Aplikasi belum dinyatakan
selesai diaudit atau seluruh fiturnya terbukti berfungsi.

## Hasil yang harus dibuktikan

| Kebutuhan | Bukti penerimaan | Status |
| --- | --- | --- |
| Kode rapi dan mudah dikembangkan | Tanggung jawab autentikasi, validasi, operasi data, dan tampilan dipisahkan; panduan pengembangan serta perintah pemeriksaan dapat diulang | Berjalan |
| Hardening | Pengujian akses tanpa login, matriks peran, CSRF, input rusak, kebocoran rahasia, upload, API, sesi, dan konfigurasi web | Berjalan |
| Data existing utuh | Cadangan dapat dipulihkan pada database terpisah; identitas, relasi, dan isi data dibandingkan sebelum/sesudah perubahan; tidak ada reset produksi | Belum lengkap |
| Mudah dipakai orang awam | Tugas utama diperiksa pada layar ponsel dan desktop; label, keyboard, pesan gagal, konfirmasi, status simpan, serta keadaan kosong/offline jelas | Belum lengkap |
| Semua fitur berfungsi | Seluruh kelompok fitur di bawah memiliki bukti uji normal, gagal, dan batas akses sesuai peran | Belum lengkap |
| Rilis aman | Build dan health check berhasil; pemeriksaan aplikasi aktif dan data setelah rilis; cara pemulihan dicatat | Belum dilakukan untuk audit ini |

## Cakupan fitur dan pemeriksaan

| Kelompok | Alur yang harus diperiksa | Status |
| --- | --- | --- |
| Akun dan sesi | Masuk/keluar, pembatasan percobaan, pergantian hak akses/kata sandi, sesi akun yang dihapus, CRUD pengguna | Berjalan |
| Peserta dan keluarga | Tambah/edit/arsip, anak bersaudara, keluarga Bapak/Ibu, impor/pratinjau/konflik, penggabungan peserta | Belum lengkap |
| Halaqoh | Tambah/edit/hapus, perubahan pengampu/program, anggota aktif/arsip, pelestarian histori | Belum lengkap |
| Presensi dan capaian | Hadir/Sakit/Izin/Alfa, rentang bacaan, catatan awal, pembatasan guru pada kelompoknya, edit/hapus yang diizinkan | Belum lengkap |
| Autosave | Draft parsial, pemulihan lintas halaman, offline, retry, konflik, pengiriman ganda, finalisasi | Baseline tersedia; perlu pemeriksaan HTTP dan tampilan |
| Pelaporan | Dashboard tiap peran, rekap lengkap/halaqoh, persentase, keaktifan, pemantauan input, laporan bulanan, filter dan pagination | Belum lengkap |
| Ekspor dan cetak | Excel, PDF/print, rapor, pengaturan rapor, data kosong dan karakter khusus | Belum lengkap |
| Operasional | Libur, pengumuman, log, backup/pratinjau/restore merge, naik kelas dan arsip alumni | Belum lengkap |
| Integrasi | Hermes, pengingat n8n, Data Induk, autentikasi dan pembatasan data, kegagalan jaringan | Belum lengkap |
| Aksesibilitas dan PWA | Label, fokus, navigasi keyboard, dialog, responsif, cache, offline, logout dan privasi | Berjalan |
| Infrastruktur | PHP/Apache, file privat, container, upload, konfigurasi rahasia, deployment dan pemulihan | Berjalan |

`next/` adalah prototipe yang belum dilacak Git dan dikecualikan dari image
aplikasi aktif. Kondisinya dicatat sebagai pekerjaan pengguna; audit rilis
PHP tidak mengganti prototipe tersebut.

## Temuan awal yang terverifikasi pada kode

1. `users.php` mengambil seluruh kolom `users` dan mengirimkannya ke tombol
   edit dalam HTML, termasuk hash kata sandi. Gunakan daftar kolom publik.
2. Hak akses hanya dibaca dari sesi 30 hari; penghapusan akun atau perubahan
   peran/kata sandi belum membatalkan akses sesi yang sudah aktif.
3. Input CSRF berbentuk array dapat menyebabkan TypeError. Beberapa formulir
   berhenti dengan pesan teknis berbahasa Inggris tanpa langkah pemulihan.
4. Pengelolaan akun belum memvalidasi peran dan panjang isian di server;
   kegagalan update/hapus dapat menjadi halaman error, dan kesalahan tambah
   akun belum ditampilkan pada formulir.
5. Penghapusan halaqoh menonaktifkan pemeriksaan foreign key dan dapat
   meninggalkan referensi histori. Sudah diganti dengan transaksi yang hanya
   menghapus halaqoh kosong, dengan uji pelestarian isi dan relasi data.
6. Service worker mencoba cache respons umum dan menghapus cache aplikasi
   lain pada origin yang sama. Cache perlu dibatasi pada aset publik aplikasi.
7. Direktori internal dan berkas konfigurasi/workflow perlu aturan penolakan
   akses HTTP yang konsisten, termasuk pada deployment cPanel.

## Dasar keselamatan dan baseline

- Snapshot kode sebelum audit: commit `ab3e6b7`; arsip independen tersimpan
  di direktori cadangan lokal Codex.
- Pengujian database memakai server lokal `127.0.0.1:13317` dan nama
  `tahsin_test_*`; kredensial/database produksi tidak digunakan.
- Baseline awal: 147 assertion pelaporan dan 39 assertion pengingat lulus;
  43 assertion integrasi keluarga lulus. Hasil lain dicatat setelah selesai.
- Temuan dan status harus diperbarui berdasarkan hasil nyata. Tes yang
  hanya mencakup satu kelompok fitur tidak membuktikan seluruh aplikasi.

## Perubahan dan bukti batch pertama

- Layanan pengelolaan pengguna dan halaqoh sudah dipisahkan dari template.
  Validasi server, transaksi, perlindungan admin terakhir, dan pengecekan
  referensi menggantikan penulisan SQL langsung di halaman.
- Proyeksi pengguna hanya memuat kolom publik. Sesi halaman serta dua API
  presensi memeriksa akun/peran terkini dan perubahan kata sandi.
- Kesalahan CSRF pada formulir utama memiliki status dan langkah pemulihan;
  input array ditolak tanpa TypeError. Login memiliki label input dan tombol
  tampil/sembunyikan kata sandi yang dapat dibaca teknologi bantu.
- Cache PWA dibatasi pada aset publik dan scope aplikasi. Halaman/data akun,
  respons API, dan POST tidak diambil dari cache; draft IndexedDB tetap mandiri.
- Aturan akses berkas internal diperketat pada Apache dan router pengembangan.
  Pengujian HTTP router lokal **belum membuktikan konfigurasi Apache**.
- Regresi awal lulus: 147 pelaporan, 39 pengingat, 41 integrasi pelaporan,
  20 integrasi pengingat, 43 integrasi keluarga, 25 integrasi autosave,
  serta PHPUnit 2 tes/3 assertion Data Induk.
- Regresi batch ini: 42 assertion akun/data, 38 pemeriksaan HTTP, dan
  13 assertion service worker lulus. Workflow n8n lulus 17 skenario tanpa
  mengirim pesan. Audit npm dependency produksi melaporkan nol kerentanan
  yang dikenal; hasil ini tidak membuktikan seluruh aplikasi aman.
- Lint 98 berkas PHP bersih setelah penambahan helper halaqoh dan perbaikan
  koneksi database. Build CSS dan vendor berhasil; CSS diperbarui lagi jika
  perubahan tampilan menambah kelas.
- Perintah terpisah untuk menyalakan pratinjau visual lokal ditolak pemeriksaan
  otomatis dengan alasan `blocked by policy`. Pemeriksaan visual interaktif
  belum selesai; pengujian HTTP menggunakan fixture lokal tersendiri.
- Belum ada perubahan produksi maupun deployment untuk audit ini.
- Checkpoint kode berada pada branch lokal `codex/application-audit-20261023`;
  branch produksi `main` tetap menunjuk rilis sebelum audit.

## Menjalankan pemeriksaan lokal

Database uji harus berjalan hanya pada `127.0.0.1:13317`, dengan akun fixture
yang digunakan suite existing. Jangan arahkan suite ini ke server produksi.
Driver `pdo_mysql` harus tersedia; pada instalasi Windows yang belum
mengaktifkannya, gunakan flag `-d extension=pdo_mysql` seperti di bawah.

```powershell
php tests/run-tahsin-tests.php
php tests/tahsin-reminder-tests.php
php -d extension=pdo_mysql tests/security-integration.php
node tests/security-http-tests.cjs
node tests/service-worker-tests.cjs
node tests/n8n-reminder-workflow.cjs
php -d extension=pdo_mysql tests/tahsin-mysql-integration.php
php -d extension=pdo_mysql tests/tahsin-reminder-mysql-integration.php
php -d extension=pdo_mysql tests/family-integration.php
php -d extension=pdo_mysql tests/autosave-integration.php
npm run build
```

Suite HTTP membuat database sintetis baru dan server loopback sementara,
kemudian menghentikan server setelah selesai. Ia tidak memakai URL aplikasi
aktif, mengubah database produksi, atau mengirim pengingat ke pengguna.
Fixture database disimpan untuk diagnosis; belum ada penghapusan otomatis.

## Urutan pekerjaan

1. Perbaiki fondasi akun, sesi, validasi permintaan, privasi respons/cache,
   serta perlindungan file internal; tambahkan regresi yang relevan.
2. Audit dan perbaiki transaksi CRUD, histori, impor/restore dan penghapusan;
   buktikan pelestarian isi serta relasi di database terpisah.
3. Uji seluruh endpoint dan matriks peran; rapikan penanganan error serta
   duplikasi logika secara bertahap.
4. Jalankan alur pengguna melalui browser pada data uji, perbaiki aksesibilitas
   dan kemudahan pemakaian, termasuk layar kecil dan keadaan offline.
5. Lakukan audit penerimaan seluruh baris cakupan sebelum rilis, kemudian
   verifikasi runtime dan data aplikasi aktif.
