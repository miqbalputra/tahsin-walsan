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

## Perubahan dan bukti batch rapor

- `print-rapor.php` tidak lagi menjalankan isi template database melalui
  `eval`. Desain memakai HTML/CSS dengan penanda data yang diisi sesudah
  validasi; data peserta, alasan, capaian dan identitas pengesah di-escape.
- Akses dan hitungan rapor memakai assembler rekap bersama: kepemilikan
  sesi, duplikasi, catatan konflik, libur dan akhir periode diterapkan sama.
  Guru yang mengganti ID peserta pada URL tidak dapat membaca kelompok lain.
- Assembler bersama juga menutup jalur bocor melalui `record` dan capaian
  terakhir ketika sumber terpilih berasal dari kelompok guru lain, meskipun
  roster sesi berada pada guru yang sedang masuk. Catatan ditandai konflik
  untuk ditinjau; hak admin atas sumber tersebut tetap tersedia.
- Pengaturan rapor memiliki desain standar siap pakai, label formulir,
  pratinjau dengan peserta yang benar-benar tersedia, pesan gagal dan PRG.
  Penyimpanan memakai transaksi/upsert agar kunci yang belum disemai ikut
  tersimpan. Setiap template yang diganti dicadangkan secara utuh.
- Template PHP standar lama dikenali tanpa dieksekusi. Template kustom lama
  yang belum cocok tetap tersimpan dan dapat diunduh admin sebagai teks;
  rapor menggunakan desain standar sementara dengan pemberitahuan.
  **Sebelum rilis, periksa template produksi dan sesuaikan desain kustom
  yang masih memakai PHP.** Belum ada bukti kesamaan visual desain produksi.
- Lulus: 48 assertion renderer/validasi rapor, 153 regresi pelaporan,
  42 assertion akun/data, dan 59 pemeriksaan HTTP. Suite HTTP mencakup
  pembatasan kelompok, penolakan template PHP, XSS, CSRF, penyimpanan kunci
  baru, unduhan cadangan, serta kesamaan hash isi lima tabel domain sebelum
  dan sesudah operasi rapor. Semua memakai fixture sintetis lokal.
- Regresi integrasi setelah perubahan assembler: 41 assertion pelaporan
  MySQL/MariaDB, 39 pengingat/finalisasi, serta 20 integrasi pengingat lulus.
- Build CSS/vendor berhasil. Bukti lokal ini belum membuktikan tampilan
  browser, runtime PHP 8.2 pada image, atau aturan Apache produksi.
- Lint 100 berkas PHP bersih dan `git diff --check` lulus.
- Pada checkpoint batch rapor, perubahan belum didorong ke remote dan belum
  dideploy.

## Perubahan dan bukti batch backup/pemulihan

- Helper ekspor dan restore dipisahkan dari controller. Ekspor memakai
  snapshot InnoDB, pembacaan baris tanpa buffering seluruh tabel, CSV dengan
  aturan escape konsisten, serta format 3 yang membedakan NULL dan teks `\N`.
- Semua CSV diperiksa terhadap checksum, manifest, jumlah baris, struktur
  kutip, header, primary key dan kolom wajib. ZIP diproses dengan batas
  dekompresi serta record; isi tidak diekstrak atau SQL arsip dieksekusi.
- Restore memvalidasi ulang sebelum transaksi, mempertahankan primary key
  existing, memakai SQL mode ketat, menjaga ID nol, dan memeriksa kolom
  generated setelah dihitung database. Foreign key tetap aktif.
- Referensi baru ke ID induk dengan isi berbeda dibatalkan, termasuk
  composite key dan nomor dengan padding. Ini mencegah penggabungan anak
  ke identitas lain hanya karena nomor ID sama.
- Token acak dan hash berkas terikat ke sesi pratinjau. Nama arsip acak,
  izin file privat, pembatalan unggahan, serta validasi input array diperbaiki.
  Konfirmasi memakai formulir berlabel tanpa ketergantungan pada modal.
- Folder persisten dapat dipilih melalui `BACKUP_STORAGE_PATH`. Konfigurasi
  folder yang gagal tidak diam-diam beralih ke penyimpanan sementara.
  UI menjelaskan bahwa merge tidak membatalkan seluruh pemulihan sebelumnya.
- Pengujian pemulihan lokal mengisi setiap tabel operasional, termasuk
  keluarga Bapak/Ibu, draft/revisi autosave, histori, keputusan, log dan
  pengaturan. Seluruh isi cocok setelah restore ke database kosong dan
  pemasangan ulang view/trigger. Ini **belum membuktikan restore cadangan
  produksi nyata**; pemeriksaan itu tetap menjadi gate rilis.
- Pemeriksaan HTTP mencakup ekspor, unggah, pratinjau, lintas sesi, perubahan
  arsip, pembatalan, restore, replay, cadangan otomatis dan akses file privat.
- Lulus: 98 assertion backup/pemulihan pada 30 tabel sintetis yang seluruhnya
  berisi data, 42 assertion akun/data, dan 85 pemeriksaan HTTP. Lint 103 berkas
  PHP dan build CSS/vendor bersih. Hash pemulihan tersimpan pada artefak lokal
  `.sessions/audit-20261023/backup-*/results.json`.
- Rincian batas, perilaku format legacy dan prosedur pemulihan tersedia
  pada [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md). Belum ada deployment batch ini.

## Perubahan dan bukti batch impor CSV

- Impor dipisahkan menjadi parsing, pratinjau server, pilihan pengguna,
  transaksi dan jurnal. UTF-8/BOM, koma/titik koma, enam kolom legacy,
  multiline dan sel di atas 1000 byte didukung tanpa pemotongan diam-diam.
- Pengulangan CSV melewati anak yang sudah cocok. Nama/telepon ambigu
  memerlukan pilihan. Pembaruan kelas mempertahankan ID anak, menolak
  kelas bertentangan dan membedakan anak baru bernama sama pada kelas berbeda.
- Biodata existing dipertahankan secara default. Penggantian eksplisit
  menyimpan snapshot lama; kolom opsional yang tidak disertakan tidak
  mengosongkan nilai existing. Impor ulang tidak membatalkan arsip manual.
- Mapping master keluarga/UUID anak dan wali dilindungi bahkan ketika
  integrasi dimatikan atau view belum siap. Kelompok tersebut dapat dilewati
  sementara keluarga lokal lain tetap diproses. Tidak ada panggilan API pusat.
- Peran diperiksa ulang di service, preview memiliki fingerprint, nonce dan
  masa berlaku, serta seluruh data/jurnal disimpan dalam transaksi InnoDB.
  Error database setelah insert pertama dan batas ukuran jurnal membuktikan
  rollback. Receipt mencegah pengulangan operasi menambah data lagi.
- Riwayat memiliki halaman sebelum/sesudah yang dapat dibaca dan pagination,
  selain unduhan JSON privat. Snapshot yang tidak berubah memakai penanda
  ringkas; keluarga yang dilewati tetap disebut. Jurnal tidak menghapus data.
- JSON/atribut halaman peserta dan pesan query diperbaiki untuk mencegah
  HTML/JavaScript dari nama anak, nama wali atau parameter pesan dieksekusi.
- Parser CSV dan pengaturan transaksi/SQL mode dipakai bersama backup.
  Jurnal impor dibatasi 12 MB; record backup 32 MB menampungnya termasuk
  quote CSV. Pemulihan kini mencakup jurnal di antara 31 tabel uji terisi,
  dengan hash penuh yang sama setelah restore dan pemasangan ulang migrasi.
- Lulus: 76 assertion impor, 98 assertion pemulihan, 42 assertion akun/data,
  serta 118 pemeriksaan HTTP. Suite impor dan backup juga dijalankan dengan
  `memory_limit=256M`. Hasil tersimpan pada artefak `.sessions/audit-20261023/`.
- Diperlukan migrasi additive `migrate_participant_import.php --apply` sebelum
  fitur rilis. Panduan ada pada [PARTICIPANT_IMPORT.md](PARTICIPANT_IMPORT.md).
- **Belum dideploy.** Pada checkpoint impor ini, formulir peserta lama masih
  dapat mengganti ID anak; perbaikannya dicatat pada batch berikutnya.
  Penggabungan duplikat belum menangani semua draft/baseline serta mapping
  pusat. Tampilan browser dan pemulihan cadangan produksi nyata belum terbukti.

## Impor Santri Baru versi lama — 30 Oktober 2026

- Pemadanan wali sekarang menolak beberapa pemilik dengan nama dan nomor HP
  yang sama, kecocokan berdasarkan nama saja, dan wali yang dikelola master
  keluarga/Data Induk. Anak yang muncul ulang dalam CSV diproses sekali.
  Entri nomor HP berisi karakter tanpa angka, nama keluarga tanpa nomor yang
  berulang, dan nama halaqoh yang ganda/tidak konsisten tidak dipilih diam-diam.
- Pratinjau terikat sesi, akun, isi file dan sidik sumber data. Setelah
  pratinjau, perubahan wali/anak/halaqoh menyebabkan konfirmasi ditolak sampai
  pengguna meninjau ulang. Saat menyimpan, halaman memakai mutex yang sama
  dengan formulir/impor peserta, mengunci data sumber, memeriksa peran lagi,
  dan memastikan tabel pemicu transaksi mendukung rollback.
- `INSERT IGNORE` pada penempatan baru dihapus; kesalahan relasi sekarang
  membatalkan transaksi. Kunci dan mode SQL dipulihkan. File unggahan hanya
  dihapus setelah transaksi sukses.
- Pemeriksaan fixture lokal sintetis lulus: 7 assertion preview memastikan
  pemadanan ambigu ditolak, duplikat tidak ditambahkan, rencana grup yang
  bertentangan tidak memilih halaqoh, dan preview tidak menulis database.
  Pemeriksaan HTTP lokal juga membuktikan preview basi ditolak tanpa membuat
  anak, percobaan ulang setelah pratinjau baru berhasil satu kali tanpa
  mengubah presensi lama, serta keluarga baru mendapat satu anak dan satu
  penempatan halaqoh. Seluruhnya memakai database fixture; tidak ada akses
  produksi.
- Belum menambahkan migrasi atau jurnal baru untuk halaman lama ini.
  Pemulihan data produksi nyata, seluruh matriks peran, dan penerimaan runtime
  tetap menjadi syarat audit menyeluruh.

## Sinkronisasi arsip Data Induk — 30 Oktober 2026

- Temuan: event arsip wali hanya menonaktifkan akun, meninggalkan relasi
  halaqoh aktif. Arsip siswa juga tidak masuk ke perhitungan kelayakan roster,
  sehingga masih dapat ditawarkan sebagai peserta sekolah aktif.
- Arsip wali kini menutup semua relasi aktif dengan alasan tetap; arsip anak
  menutup relasi hanya jika tak ada anak lain yang masih sekolah. Saudara
  dengan kelas kosong diperlakukan konservatif agar tidak menyebabkan arsip
  otomatis yang salah. Snapshot aktif tidak membatalkan arsip manual wali.
- Pembacaan peserta, calon anggota dan view keluarga menghormati status arsip
  Data Induk saat kolom migrasinya tersedia. Skema lama tetap membaca dengan
  aturan semula.
- Operasi gagal sebelum menulis bila kolom arsip atau tabel InnoDB yang
  diperlukan belum siap. Seluruh pengarsipan bersifat soft archive. Presensi,
  identitas baris, relasi dan histori roster tetap utuh.
- Verifikasi fixture database uji terisolasi lulus 13 pemeriksaan: skema lama
  gagal aman tanpa penulisan sebelum migrasi; satu dari
  dua saudara diarsipkan tanpa mengeluarkan wali; anak terakhir menutup relasi;
  anak lokal berkelas kosong mempertahankan relasi; event wali berulang
  idempoten; presensi dan identitas relasi cocok byte-for-byte; interval
  histori ditutup; view keluarga serta calon keanggotaan membaca status anak
  arsip. Regresi keanggotaan yang ada lulus 49 pemeriksaan.
- Hasil fixture: `.sessions/audit-20261030/data-induk-archive-checkpoint.json`.
  Belum ada perubahan produksi/deployment. Audit aplikasi keseluruhan masih
  berjalan dan pemulihan cadangan produksi nyata belum dibuktikan.

## Perubahan dan bukti batch formulir peserta

- Mutasi formulir dipisahkan ke `participant_helper.php`. Tambah/edit/arsip
  memakai halaman HTML native, label input, retensi isian pada error dan
  redirect setelah simpan. Filter daftar juga dapat digunakan tanpa JavaScript.
- Anak yang diedit mempertahankan ID. Daftar anak yang terpotong ditolak,
  bukan dianggap ingin dihapus. Penghapusan spesifik perlu konfirmasi dan
  ditolak bila anak direferensikan foreign key, termasuk CASCADE/composite.
  Salinan sebelum penghapusan tersimpan dalam jurnal.
- Nilai NULL dan spasi existing yang tidak diedit dipertahankan. Formulir
  lengkap dengan 100 anak diperiksa tanpa pergantian ID atau penambahan ganda.
- Penempatan Bapak/Ibu dipisah. Perubahan satu program mempertahankan program
  lain; memilih halaqoh yang sama tidak menutup/membuka interval roster lagi.
  Daftar peserta menampilkan keanggotaan aktif yang tersedia, dipaginasi 50
  peserta dengan filter dipertahankan pada tautan halaman. Seluruh anak dapat
  dibaca, termasuk kelas NULL dan daftar panjang tanpa pemotongan agregat SQL.
- Arsip hanya mengubah status serta menutup keanggotaan. Presensi, anak dan
  draft dipertahankan. Aktivasi kembali eksplisit memerlukan anak sekolah
  aktif; aturan seluruh anak Lulus tetap berlaku. Catatan Lanjut Tahsin lama
  dipertahankan.
- Mapping keluarga/UUID wali/anak dan mode Data Induk dilindungi langsung.
  Perlindungan tetap berlaku saat konfigurasi pusat belum lengkap, integrasi
  dimatikan atau view keluarga hilang. Pengaturan lokal tetap dapat diedit.
- Fingerprint, token sesi, pemeriksaan peran terkini, lock bersama impor dan
  transaksi menolak formulir basi, token lintas sesi, serta pengulangan operasi.
  Kegagalan penyimpanan jurnal membatalkan seluruh mutasi dan trigger histori.
- Migrasi additive `migrate_participant_management.php --apply` menambah jurnal
  sebelum/sesudah. Riwayat memiliki pagination, rincian yang dapat dibaca dan
  unduhan JSON privat. Panduan ada di
  [PARTICIPANT_MANAGEMENT.md](PARTICIPANT_MANAGEMENT.md).
- Lulus: 48 assertion service peserta, 76 assertion impor, 42 assertion akun/data,
  dan 143 pemeriksaan HTTP. Pengujian backup
  mencakup jurnal di antara 32 tabel terisi; 98 assertion pemulihan dan hash
  seluruh tabel lulus. Pengujian PHP memakai `memory_limit=256M`; lint 117
  berkas dan build aset lulus. Artefak berada di `.sessions/audit-20261023/`.
- **Belum dideploy.** Pemeriksaan browser, Apache/runtime PHP 8.2, pemulihan
  cadangan produksi nyata, penggabungan duplikat dan fitur lain dalam matriks
  tetap harus diselesaikan sebelum klaim audit lengkap atau rilis keseluruhan.

## Perubahan dan bukti batch penggabungan peserta

- Front controller penggabungan dipisah dari service dan renderer. Halaman
  native memakai pencarian, pagination kandidat/nama berulang, pilihan sumber
  biodata, program Bapak/Ibu, baseline serta draft yang berbenturan. Nama sama
  ditampilkan sebagai kandidat untuk ditinjau, bukan bukti identitas sama.
- Semua anak berpindah dengan ID, nama dan kelas yang sama. Membership lama
  diarsipkan saat tidak aktif/duplikat, bukan dihapus. Seluruh presensi dan
  interval historis tetap tercatat pada identitas hasil.
- Benturan capaian awal dan draft memerlukan pilihan; kedua versi asal
  disimpan dalam jurnal dan dapat dibaca di halaman riwayat. Draft terpilih
  dipindahkan dengan revisi lebih baru dan konteks kedaluwarsa untuk ditinjau
  ulang. Revisi resmi digabung secara monoton. Bukti INIT_CAPAIAN tetap
  meminta verifikasi setelah ID wali berubah.
- Jurnal mengikutkan biodata sumber lengkap dan data terkait sebelum/sesudah.
  Riwayat juga menampilkan hitungan perpindahan dan baseline/draft hasil.
  Jurnal terdahulu dipindahkan metadata identitasnya tanpa menulis ulang isi
  snapshot; hash isi digunakan agar rantai merge tidak menggandakan jurnal.
- Pengalihan ID diratakan saat hasil digabung lagi. Editor/riwayat lama tetap
  dapat dibuka; tautan arsip lama membuka pemeriksaan editor hasil. Pengiriman
  ulang memperoleh receipt, sementara operator dapat memilih pasangan baru.
- Mapping master keluarga, bridge anak, guardian/student/enrollment/class
  Data Induk, konfigurasi parsial dan pemetaan guardian pending dilindungi.
  Relasi FK/non-FK yang belum memiliki aturan pemindahan dan relasi gabungan
  anak ditolak sebelum menulis. Foreign key tidak dinonaktifkan.
- Restore merge menolak menghidupkan ID sumber atau mengalihkan peserta yang
  masih hidup. Cadangan/pemulihan kini mencakup 33 tabel terisi, termasuk
  jurnal penggabungan dan pengalihan ID, dengan hash seluruh tabel sama.
- Lulus: 47 assertion merge, 48 assertion formulir, 76 assertion impor,
  42 assertion akun/data, 102 assertion backup dan 174 pemeriksaan HTTP.
  Lint 122 berkas PHP, build aset, dan dry-run CLI migrasi lulus. Pengujian
  service/backup memakai `memory_limit=256M`. Seluruh database pengujian
  memakai fixture sintetis loopback; artefak di `.sessions/audit-20261023/`.
- Migrasi additive `migrate_participant_merge.php --apply` diperlukan sebelum
  rilis. Perilaku dan pemulihan dijelaskan pada
  [PARTICIPANT_MERGE.md](PARTICIPANT_MERGE.md).
- **Belum dideploy.** Pemeriksaan browser dan alur draft offline yang hanya
  ada di perangkat pengguna masih terbuka, bersama cakupan fitur lainnya,
  Apache/PHP 8.2, serta pemulihan cadangan produksi nyata. Hasil ini belum
  membuktikan seluruh aplikasi selesai diaudit.

## Perubahan dan bukti batch kenaikan kelas — 29 Oktober 2026

- Query promosi lama tidak mengambil `wali_santri_id` yang kemudian dipakai
  untuk pengarsipan wali. Pengiriman ulang dapat menaikkan anak lagi dan
  koreksi Lulus dapat menimpa kelas anak yang sebenarnya sudah berubah.
- Tiga mutasi kelas dipisahkan dari template ke `student_progress_helper.php`.
  Token sesi menyimpan fingerprint anak/status wali yang ditampilkan. Receipt
  database mencegah pengiriman ulang mempromosikan dua kali; formulir basi,
  ID di luar pilihan, array rusak, dan peran yang tidak berhak ditolak.
- Snapshot peserta, seluruh anak, dan membership sebelum/sesudah disimpan
  pada jurnal existing `participant_changes` dalam transaksi yang sama. Jurnal
  kedua yang sengaja digagalkan membuktikan rollback seluruh kelas, status
  arsip, membership, interval roster, serta jurnal pertama.
- Promosi memakai ID wali yang benar. Wali yang masih memiliki anak sekolah
  aktif tidak ikut diarsipkan. Koreksi kelas tidak mengaktifkan kembali wali
  atau membership secara otomatis. Kelas NULL/tidak dikenal tidak ditebak.
- Mapping keluarga/Data Induk tetap dilindungi saat integrasi dimatikan.
  Riwayat memiliki label khusus perubahan kelas dan rincian sebelum/sesudah.
  Anak dengan beberapa halaqoh hanya tampil sekali pada daftar promosi.
- Formulir dibatasi 200 anak per proses. Kelas Mustawa pengganti tetap tersedia
  ketika semua anak sudah Lulus. Penyimpanan ditolak jika jurnal belum siap.
  Panduan persiapan dan koreksi tersedia di [STUDENT_PROGRESS.md](STUDENT_PROGRESS.md).
- Lulus: 28 assertion service/pelestarian data, 199 pemeriksaan HTTP (termasuk
  validasi array API dari batch 27 Oktober), 48 assertion formulir peserta,
  102 assertion backup/pemulihan, 43 integrasi keluarga, dan 153 regresi
  pelaporan. Fixture baru memakai server loopback khusus;
  database produksi tidak diakses atau dimigrasi.
- Pilihan hapus Alfa pada formulir libur kini tidak dicentang secara default.
  Uji HTTP memastikan penyimpanan libur default mempertahankan seluruh
  presensi. Pada checkpoint ini, penghapusan Alfa eksplisit, hapus presensi
  admin/guru, dan reset masih memerlukan jurnal/pemulihan khusus. Tindak lanjut
  ada pada batch versi presensi di bawah; audit seluruh fitur tetap berjalan.
- Belum ada deployment batch ini. Penerimaan browser, seluruh matriks fitur,
  dan uji pemulihan cadangan produksi nyata tetap diperlukan.

## Versi presensi dan pemulihan — 29 Oktober 2026

- Semua jalur UPDATE/DELETE presensi kini menyimpan versi sebelum tindakan
  melalui trigger InnoDB. UPDATE juga menyimpan versi sesudah tindakan. NULL,
  teks/spasi, kolom tambahan, biner, decimal dan generated dipertahankan dengan
  encoding base64. Identitas pelaku dan UTC offset ikut disimpan.
- Penghapusan laporan admin/guru memakai fingerprint dari row yang benar-benar
  ditampilkan dan pemeriksaan penugasan sesi. Reset, penimpaan presensi,
  perubahan capaian, cleanup Alfa, dan pemindahan presensi saat merge menolak
  penulisan jika jurnal/trigger yang sesuai struktur tabel belum siap.
- Halaqoh yang hanya tersisa pada snapshot penghapusan tetap dianggap memiliki
  riwayat, sehingga tidak dihapus atau diganti programnya melalui aplikasi.
- Halaman admin/PJ `presensi-riwayat.php` memiliki daftar berhalaman,
  perbandingan versi, unduhan privat, token sesi, CSRF dan konfirmasi pemulihan.
  Recovery mempertahankan ID, memverifikasi ulang setiap nilai, dan memiliki
  receipt atomik. Pengiriman ulang tidak membuat salinan ganda.
- ID yang sudah digunakan, catatan pengganti dalam sesi, pratinjau basi,
  perubahan identitas/penugasan, dan snapshot dari instance database lain
  menolak pemulihan otomatis. Salinan tetap dapat diperiksa pengelola.
- Kalender libur memakai validasi server dan transaksi bersama pembersihan
  Alfa. Snapshot kalender pada activity_logs wajib tersimpan; kegagalan log
  membatalkan kalender, cleanup, jurnal presensi dan revisi. Hari/tanggal atau
  input array rusak tidak masuk ke operasi penghapusan.
  Halaman libur tidak lagi menjalankan DDL/CREATE TABLE pada request pengguna.
- Ditemukan pula output header/sidebar riwayat guru dikirim sebelum mutasi.
  Pengujian penolakan hak akses mengungkap status 422 menjadi 200 dan warning
  headers-sent. Controller kini dijalankan sebelum layout. Pesan notifikasi
  dan filter tanggal juga diamankan dari HTML/array.
- Lulus: 27 assertion service/versi/pelestarian data, 28 pemeriksaan HTTP
  pemulihan, 199 HTTP regresi, 44 akun/data, 47 merge, 25 autosave database,
  41 pelaporan MySQL/MariaDB, dan 20 integrasi pengingat. Backup/pemulihan lulus
  102 assertion dengan 35 tabel sintetis terisi dan hash penuh yang sama
  sesudah restore terpisah serta pemasangan ulang view/trigger. Build aset,
  19 pemeriksaan URL aset, serta 55 service worker lulus.
- Migrasi additive `migrate_attendance_history.php --apply` diperlukan paling
  akhir setelah migrasi struktur lainnya. Migrasi ini baru diterapkan pada
  fixture loopback; tidak ada akses/migrasi atau deployment produksi.
  Panduan ada di [ATTENDANCE_RECOVERY.md](ATTENDANCE_RECOVERY.md).
- Audit belum selesai: perilaku tampilan browser/perangkat, konflik pada
  input legacy/full-form, akses capaian, pengelolaan anggota per program,
  cakupan fitur lainnya, runtime Apache/PHP produksi dan pemulihan backup
  produksi nyata masih perlu bukti penerimaan.

## Akses dan editor capaian — 30 Oktober 2026

- Katalog capaian admin/guru memakai assembler sesi pelaporan yang sama,
  menggantikan query latest-H global. Uji regresi awal membuktikan guru dapat
  melihat bacaan dari halaqoh lain pada peserta yang sama; kedua rute capaian
  kini memproyeksikan sumber sesuai penugasan. Baseline global hanya tampil
  untuk guru dengan penempatan aktif tunggal yang sesuai.
- Formulir native terpisah menggantikan mutasi modal tanpa kontrol versi.
  ID/tanggal sumber terikat token akun/sesi, CSRF dan fingerprint. Formulir
  basi ditolak; isian pengguna tetap tersedia. Receipt atomik mencegah
  pengiriman ulang mengubah data atau waktu baseline dua kali.
- Editor hanya menulis kolom materi yang berubah, mempertahankan ID,
  tanggal, status, alasan, kolom tambahan, nilai NULL/biner dan draft.
  Salinan identik satu halaqoh/sesi dikoreksi bersama; sumber konflik/lintas
  halaqoh memerlukan keputusan Rekap Lengkap. Keputusan asal tetap tersimpan
  dan menjadi perlu diperiksa ulang jika sumber terpilih diubah.
- Capaian awal tetap terpisah tanpa presensi palsu. Jurnal peserta menyimpan
  seluruh versi baseline/sumber sesi/keputusan sebelum-sesudah secara biner
  aman dan dapat dibandingkan/diunduh. Kegagalan jurnal membatalkan seluruh
  perubahan, termasuk histori presensi dan revisi autosave.
- Katalog memakai output escaped, pencarian terlingkup dan pagination server.
  Pesan kegagalan database tidak lagi dipaparkan kepada pengguna. Editor
  menolak input array, rentang salah, teks kontrol dan CSRF tidak sah.
- Lulus: 47 assertion service/pelestarian capaian, 22 HTTP capaian,
  160 regresi pelaporan, 41 integrasi laporan MySQL/MariaDB, 199 HTTP keamanan,
  44 akun/data, 19 URL aset dan 55 service worker. Backup/pemulihan lulus
  102 assertion; isi 35 tabel cocok sesudah restore, termasuk snapshot nyata
  perubahan baseline. Build aset dan lint 36 file PHP perubahan lulus.
  Katalog/editor diperiksa secara visual di browser desktop dan lebar 390px;
  ini belum menggantikan penerimaan seluruh fitur/perangkat. Sidebar existing
  masih memerlukan pemeriksaan saat resize desktop ke ponsel tanpa reload.
  Seluruh pengujian memakai database sintetis loopback terpisah. Panduan:
  [ACHIEVEMENT_EDITING.md](ACHIEVEMENT_EDITING.md).
- Belum ada deployment atau migrasi produksi. Audit keseluruhan belum selesai:
  konflik input legacy/full-form, anggota per program, browser/perangkat,
  runtime Apache/PHP produksi serta pemulihan backup produksi nyata masih
  memerlukan pemeriksaan/penerimaan.

## Konflik formulir presensi kompatibilitas — 30 Oktober 2026

- Uji reproduksi membuktikan endpoint lama menimpa halaman/ayat yang sudah
  diubah setelah GET. Formulir kini mengikat versi sumber/roster/jadwal,
  dilengkapi CSRF dan receipt per pengiriman. Konflik menghasilkan 409
  sebelum penulisan; finalisasi menolak seluruh batch jika satu versi basi.
- Controller dijalankan sebelum header/sidebar. Kesalahan mempunyai status
  HTTP yang benar; isian gagal tetap tersedia dan dapat dibandingkan dengan
  formulir terbaru. Nama anak dan pesan tidak lagi dirender sebagai HTML mentah.
- Kode controller, tampilan dan antrean client dipisahkan. Permintaan satu
  peserta berurutan, finalisasi menunggu hasilnya, dan kiriman yang hasilnya
  belum pasti dipertahankan untuk retry dengan ID/payload/versi yang sama.
  Input yang belum lengkap tidak diberi label tersimpan; browser memberi
  peringatan sebelum meninggalkan isian yang belum diakui.
- Penulisan hanya mengubah kolom yang maknanya berubah. Snapshot lengkap
  sebelum/sesudah dan receipt ikut transaksi, sehingga kegagalan jurnal
  membatalkan seluruh perubahan dan revisi. Draft server tidak dihapus.
  Input array, kontrol, panjang/rentang/jilid tidak sah ditolak; EBTA Iqro
  didukung kedua alur. Verifikasi membedakan ID presensi nol dari NULL.
- Migrasi additive `migrate_attendance_forms.php --apply` menambah jurnal
  pengiriman tanpa mengganti tabel domain. GET tidak menjalankan DDL.
  Endpoint lama yang tidak mengirim versi/token ditolak. Panduan lengkap:
  [ATTENDANCE_FORMS.md](ATTENDANCE_FORMS.md).
- Lulus: 29 assertion service/pelestarian formulir, 22 HTTP formulir lama,
  17 urutan pengiriman/retry/navigasi, 28 autosave database dan 165 regresi
  pelaporan. Regresi batch juga lulus 28 HTTP pemulihan, 199 HTTP keamanan,
  44 akun/data dan 41 integrasi pelaporan. Backup/pemulihan lulus 102
  assertion dengan 36 tabel terisi, termasuk jurnal formulir, dan hash penuh
  sama sesudah pemulihan. Build, 19 URL aset, 57 service worker, lint 43 file
  PHP perubahan dan diff check lulus. Pemeriksaan histori/aktor dilakukan
  sekali per operasi atomik, dan deteksi dugaan baseline dibaca sekaligus
  untuk sumber formulir, untuk mengurangi query berulang pada finalisasi.
- Browser fixture membuktikan simpan otomatis Hadir, dialog pembatalan,
  finalisasi dengan pengiriman sendiri, status input belum lengkap, dan
  perlindungan tautan saat input belum tersimpan. Layout diperiksa pada
  desktop serta lebar 390px; tombol finalisasi dirapikan setelah pemeriksaan
  visual. Console tidak menunjukkan error pada alur akhir. Bukti lokal:
  `.sessions/audit-20261030/legacy-finalized-desktop.png`.
- Tidak ada akses, migrasi atau deployment produksi. Audit menyeluruh tetap berjalan,
  terutama seluruh matriks browser/fitur,
  Apache/PHP produksi, dan pemulihan backup produksi nyata.

## Keanggotaan per program — 30 Oktober 2026

- Controller lama menerima ID penempatan tanpa versi sumber, tidak memeriksa
  penempatan ganda dalam program yang sama, dan menyaring calon anggota
  secara global. Keanggotaan Bapak dapat menyembunyikan calon yang valid
  untuk Ibu. Alur sekarang memisahkan katalog, validasi, transaksi, controller
  dan tampilan; calon disaring per program, dengan konfirmasi identitas serta
  sumber sekolah sebelum simpan. Program dibaca dari kolom halaqoh,
  terpisah dari kesiapan trigger autosave.
- Sumber master keluarga dibaca dari tabel wali/relasi/anak. View yang belum
  tersedia tidak menyebabkan fallback ke anak lokal yang sudah tidak aktif.
  Formulir peserta memakai pemeriksaan kelayakan yang sama untuk penempatan
  baru. Riwayat lama serta pilihan mempertahankan penempatan tidak diubah
  secara otomatis. Daftar mempunyai pencarian, pagination dan satu baris
  per peserta; daftar anak tidak lagi dipotong oleh GROUP_CONCAT.
- Token sesi, CSRF, fingerprint seluruh sumber dan mutex bersama dengan
  formulir peserta mencegah pengiriman ulang serta penempatan bersamaan yang
  bertentangan. Hak admin/PJ diperiksa ulang di dalam transaksi. Perubahan
  relasi, interval roster dan jurnal sebelum/sesudah dilakukan atomik;
  kegagalan jurnal membatalkan seluruh perubahan.
- Penonaktifan mengarsipkan relasi target, termasuk relasi ganda lama,
  tanpa menghapus biodata, anak, presensi, capaian awal, draft atau penempatan
  program lain. Aktivasi kembali mempertahankan ID relasi lama, termasuk
  nol. Snapshot menyimpan NULL, kolom tambahan dan data biner secara tepat;
  riwayat menampilkan sumber anak pada saat tindakan dari snapshot tersebut.
  Panduan: [MEMBERSHIPS.md](MEMBERSHIPS.md). Tidak ada migrasi baru khusus
  halaman anggota dan GET tidak menjalankan DDL.
- Lulus 49 pemeriksaan service/pelestarian dan 25 HTTP keanggotaan, termasuk
  dua proses bersamaan, formulir basi, rollback jurnal, role dicabut,
  arsip/aktivasi kembali dan sumber keluarga. Regresi lulus 48 pengelolaan
  peserta, 199 HTTP keamanan, 44 akun/data dan 165 pelaporan. Backup dengan
  tindakan keanggotaan nyata lulus 102 pemeriksaan untuk 36 tabel; hash penuh
  sama setelah pemulihan pada database terpisah. Bukti lokal:
  `.sessions/audit-20261023/backup-25160-70cfcc19/results.json`.
- Build, lint 52 file PHP perubahan, 19 URL aset, 57 service worker dan
  diff check lulus. Browser fixture membuktikan tambah anggota, hasil simpan,
  riwayat sumber sekolah sebelum/sesudah dan daftar pada desktop serta
  390px tanpa overflow horizontal. Console tidak mencatat warning/error.
  Bukti `.sessions/audit-20261030/membership-verified.png` dan
  `membership-mobile.png`; checkpoint `membership-checkpoint.json`.
- Pada checkpoint batch anggota, perubahan masih lokal dan belum dipush.
  Pada 30 Oktober 2026, branch audit didorong ke remote tanpa deployment.
  Tidak ada akses atau perubahan pada database produksi. Audit menyeluruh
  masih berjalan:
  impor lama, seluruh matriks peran/fitur/browser, perilaku navigasi saat
  ukuran layar berubah, Apache/PHP produksi serta pemulihan cadangan
  produksi nyata belum seluruhnya diverifikasi.

## Pembatas percobaan login dan CI — 30 Oktober 2026

- Temuan: hitungan percobaan login dibaca sebelum password diperiksa lalu
  kegagalan ditulis sesudahnya tanpa penguncian. Request bersamaan untuk akun
  atau alamat IP yang sama dapat melewati ambang lima percobaan.
- Login kini mengunci username dan IP dalam urutan stabil, lalu mengunci ID
  akun untuk username yang ekuivalen menurut collation database. Hitungan
  diperiksa ulang di dalam penguncian akun. Kegagalan dicatat atomik terhadap
  ambang; login sukses menghapus catatan terkait. Jika lock tak tersedia,
  server menolak permintaan dengan 503 sebelum perubahan akun.
- Tidak ada migrasi baru. Verifikasi database sintetis lulus 53 pemeriksaan,
  termasuk benturan lock antar koneksi, batas lima kegagalan, blokir berikutnya,
  dan penghapusan hitungan pada login valid. Pemeriksaan HTTP lulus 205 kasus,
  termasuk enam pengiriman gagal melalui halaman login.
- Audit CI menemukan lint semua berkas PHP, build aset, suite autosave,
  reporting MySQL dan arsip Data Induk belum dipanggil otomatis. Workflow kini
  menjalankan lint pada PHP yang dilacak Git, membangun aset dan menolak
  perbedaan hasil build, serta memasukkan suite yang terlewat. `npm run build`
  berhasil lokal; Tailwind memberi peringatan bahwa `caniuse-lite` sudah lama.
- Suite tambahan pada branch ini lulus: 41 integrasi laporan MySQL/MariaDB
  dan 28 pemeriksaan autosave. Seluruh DB pengujian bernama `tahsin_test_*`;
  database produksi tidak disentuh. PHPUnit tidak dapat dijalankan lokal karena
  Composer tidak terpasang; runner tetap dikonfigurasi pada CI.
- Belum dideploy. Validasi runtime produksi dan pemulihan cadangan produksi
  nyata tetap terbuka.

## Determinisme tes pengingat mingguan — 30 Oktober 2026

- Actions untuk commit `d83dcde` gagal pada langkah suite HTTP pengingat
  mingguan. Reproduksi lokal menemukan tes bergantung pada hari sistem,
  mengirim formulir tanpa token/versi sumber terbaru, memakai fixture tanpa
  tabel jurnal formulir, serta mencari teks konfirmasi yang sudah berubah.
- Router fixture tes kini memberi jam tetap hanya untuk server PHP lokal yang
  memakai router tersebut. `tahsinNow()` mengabaikan konstanta itu pada PHP-FPM
  dan proses CLI, sehingga waktu aplikasi produksi tidak dapat digeser melalui
  request HTTP. Runner HTTP kini memilih port kosong, memastikan koneksi hanya
  ke database loopback bernama `tahsin_test_*`, dan menghentikan server fixture
  setelah suite.
- Menunggu CI commit `d83dcde` selesai untuk memastikan pola kegagalan sebelum
  perbaikan, lalu validasi ulang suite dengan fixture database lokal baru:
  20 pemeriksaan integrasi dan 13 pemeriksaan HTTP lulus. Status CI untuk
  perbaikan commit `37a98ed` lulus pada event push dan pull request.

## Perlindungan ID peserta hasil penggabungan — 30 Oktober 2026

- Temuan: sesudah dua wali digabung, ID sumber disimpan sebagai alias ke wali
  yang dipertahankan. Pemeriksaan agar ID alias tidak digunakan ulang sudah
  ada pada formulir admin dan impor peserta baru, tetapi belum pada impor
  keluarga, sinkronisasi roster Data Induk, dan impor santri lama. Reset
  penghitung ID setelah pemulihan dapat membuat wali baru memakai ID alias.
- Pemeriksaan ID baru kini dipusatkan pada helper yang mensyaratkan transaksi.
  Semua jalur pembuatan wali menggunakan helper itu; benturan membatalkan
  transaksi lengkap dan penyimpanan ulang memperoleh ID berikutnya.
- Uji sintetis memaksa `AUTO_INCREMENT` menabrak alias. Impor keluarga
  membatalkan seluruh baris keluarga dan mencoba ulang dengan ID baru. Regresi
  lulus: 46 keluarga, 48 pengelolaan peserta, 76 impor peserta, dan 47
  penggabungan peserta. Database produksi tidak diakses.
- Sinkronisasi Data Induk dan halaman impor santri lama masih memerlukan
  penerimaan end-to-end tersendiri; kode keduanya kini memakai penjaga ID yang
  sama. Audit fitur dan pemulihan backup produksi masih terbuka.

## Penutupan akses HTTP skrip migrasi — 30 Oktober 2026

- Temuan: sebagian migrasi lama hanya dilindungi aturan `.htaccess`, beberapa
  dapat dipanggil oleh admin melalui web, dan sejumlah skrip menghapus berkas
  migrasinya sendiri setelah dijalankan. Konfigurasi web yang tidak membaca
  `.htaccess` dapat menjalankan DDL melalui permintaan HTTP.
- Semua 19 skrip `migrate*.php` kini menolak SAPI web sebelum membuka koneksi
  database. Mode apply untuk migrasi Data Induk dan indeks hanya menerima CLI
  (`--apply` atau environment yang didokumentasikan); migrasi login-attempt
  tidak lagi meminta penghapusan berkas sumber.
- Uji HTTP langsung memakai PHP built-in server tanpa router aplikasi maupun
  `.htaccess`: 78 pemeriksaan pada GET dan POST untuk seluruh 19 skrip lulus.
  Uji ini tidak membuka koneksi database. Lint 11 berkas PHP yang berubah dan
  `git diff --check` lulus.
- Perubahan ini melindungi skema dari permintaan web; migrasi tetap perlu
  dijalankan eksplisit lewat CLI setelah backup dan pemeriksaan staging.

## Sinkronisasi roster Data Induk — 30 Oktober 2026

- Temuan: status anak selain `active` diperlakukan sebagai arsip, ID kelas
  yang belum ada di snapshot referensi menghapus kelas lokal, relasi ayah
  ganda memilih wali berdasarkan urutan API, dan format telepon/nama yang
  berbeda tanda baca bisa membuat identitas lokal diduplikasi.
- Sinkronisasi kini memvalidasi seluruh payload sebelum menulis. Status yang
  tidak dikenal, referensi kelas yang belum tersedia, relasi ayah ganda, dan
  nilai di luar kapasitas kolom membatalkan sinkronisasi tanpa mengubah roster.
  Kolom kelas/enrollment yang tidak dikirim mempertahankan mapping existing.
- Pencocokan nama dan telepon dinormalisasi. Satu kandidat menjadi konflik
  untuk ditinjau; beberapa kandidat dicatat tanpa memilih atau membuat wali/
  anak duplikat. Tombol persetujuan dinonaktifkan untuk konflik tanpa satu
  kandidat tunggal.
- Penulisan roster menunggu mutex yang sama dengan perubahan peserta lain,
  mengunci sumber lokal, menggunakan SQL mode ketat, dan tetap atomik. Mapping
  ID gabungan yang bentrok atau kegagalan insert anak membatalkan seluruh sync.
- Suite integrasi loopback baru mencakup konflik identitas, payload/status/
  kelas tidak valid, beberapa ayah aktif, alias ID hasil merge, rollback,
  kelas yang tidak dikirim, serta kesamaan presensi dan keanggotaan. Semua
  fixture memakai database sintetis `tahsin_test_*`; belum mengakses produksi.

## Escape output halaman bersama dan Log Aktivitas — 30 Oktober 2026

- Pemeriksaan statis menemukan kolom `action` dan `ip_address` dari tabel log,
  serta nama profil pada navigasi/dashboard, dirender tanpa encoding HTML.
  Data nama profil bisa diubah melalui pengelolaan akun; nilai HTML di sana
  dapat menjalankan script setiap kali pengguna membuka halaman.
- Nilai dinamis pada layout bersama, dashboard, dan seluruh kolom log kini
  melalui `appEscape()`. Daftar rekap presensi juga meng-escape filter, hasil,
  URL dan nilai lama dari tabel. ID/hitungan yang dipakai sebagai angka turut
  dicast. Regresi HTTP menyisipkan nilai sintetis berbentuk HTML pada profil,
  tindakan, IP, dan halaman bacaan, lalu memastikan hasilnya tampil sebagai
  teks aman.
- Perubahan ini hanya mengubah rendering; tidak menulis ulang atau menghapus
  isi log lama. Verifikasi penuh menunggu CI karena MariaDB fixture lokal
  sedang tidak tersedia.
- CI `41006415667` lulus untuk lint, build aset, PHPUnit, dan seluruh PHP
  integration suites, lalu gagal pada step tes HTTP sinkronisasi Data Induk.
  Fixture pemeriksaan telah dilengkapi relasi yang memang mengaktifkan kasus
  pencocokan wali unik dan ambigu; CI ulang diperlukan untuk memastikan suite
  Data Induk lulus bersama regresi rendering baru.

CI ulang `41007338210` dan `41007344208` pada commit `c5c97e3` kemudian lulus,
termasuk suite integrasi PHP, roster Data Induk, dan pemeriksaan HTTP keamanan.

## Escape output halaman guru dan cetak PDF — 30 Oktober 2026

- Audit lanjutan menemukan nilai basis data dan nama profil yang dicetak tanpa
  encoding di dashboard guru, daftar laporan, riwayat presensi, dan tampilan
  cetak PDF. Nilai-nilai itu kini di-escape; ID angka dicast, query tautan
  disusun dengan `http_build_query()`, dan tautan WhatsApp memakai nomor yang
  hanya berisi digit serta `rel="noopener noreferrer"`.
- Regresi HTTP menambah muatan XSS sintetis untuk laporan/riwayat guru, profil
  guru, dan halaman cetak PDF. Perubahan ini hanya memengaruhi cara HTML
  ditampilkan; tidak mengubah baris atau isi basis data.
- CI `41007867963` dan `41007871806` lulus lint, build aset, PHPUnit, serta
  integrasi PHP/MariaDB, lalu gagal pada suite HTTP keamanan. Log rinci GitHub
  memerlukan hak admin repository dan tidak dapat dibaca dari sesi ini; tes
  kini menerbitkan nama assertion gagal sebagai anotasi untuk diagnosis ulang.
- Kegagalan menunjukkan daftar laporan guru mengembalikan pesan error pada
  skema lama tanpa kolom arsip keanggotaan. Filter kini memakai pemeriksaan
  kompatibilitas yang sama dengan formulir presensi; dashboard guru juga
  mengikuti pola tersebut. Fixture HTTP menyiapkan tabel pengumuman yang
  dibutuhkan dashboard.
- Perbaikan pada `d20119d` lulus penuh pada CI `41008666763` dan `41008673243`,
  termasuk suite integrasi MariaDB sintetis dan seluruh tes HTTP.
- Tidak ada migrasi atau penulisan ke basis data produksi dalam proses audit
  ini; pemulihan backup produksi belum diverifikasi.

## Validasi arsip alumni dan output Naik Kelas — 30 Oktober 2026

- Audit request menemukan `wali_ids` pada aksi arsip alumni langsung dikirim ke
  `array_map()`. Nilai teks atau array bertingkat dapat menyebabkan TypeError
  sebelum transaksi dimulai. Kini hanya daftar ID positif yang valid diterima;
  format rusak mendapat HTTP 422 sebelum operasi data, dan batch dibatasi
  maksimal 1.000 wali.
- ID dari basis data yang ditanam ke formulir Naik Kelas dicast sebagai angka.
  Daftar ID pada blok JSON script memakai flag `JSON_HEX_*` dan kegagalan encode
  tidak disembunyikan.
- Regresi HTTP mengirim payload scalar dan array bertingkat, lalu membandingkan
  hash tabel wali, anak, halaqoh, membership, dan presensi sebelum/sesudah.
  Pemeriksaan lokal lulus untuk lint PHP, 48 tes rapor, 204 tes tahsin/reminder,
  dan 78 tes HTTP migrasi. CI `41009495401` lulus, termasuk fixture MariaDB,
  suite keamanan HTTP, dan tes integrasi lainnya.

## Menjalankan pemeriksaan lokal

Database uji harus berjalan hanya pada `127.0.0.1:13317`, dengan akun fixture
yang digunakan suite existing. Jangan arahkan suite ini ke server produksi.
Driver `pdo_mysql` harus tersedia; pada instalasi Windows yang belum
mengaktifkannya, gunakan flag `-d extension=pdo_mysql` seperti di bawah.

```powershell
php tests/run-tahsin-tests.php
php tests/rapor-tests.php
php tests/tahsin-reminder-tests.php
php -d extension=pdo_mysql tests/security-integration.php
php -d extension=pdo_mysql tests/backup-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/attendance-history-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/attendance-form-integration.php
node tests/legacy-attendance-http-tests.cjs
node tests/legacy-attendance-queue-tests.cjs
node tests/attendance-history-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-import-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-management-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/membership-integration.php
node tests/membership-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/student-progress-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/achievement-integration.php
node tests/achievement-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-merge-integration.php
node tests/security-http-tests.cjs
node tests/migration-http-tests.cjs
node tests/data-induk-sync-http-tests.cjs
node tests/service-worker-tests.cjs
node tests/n8n-reminder-workflow.cjs
php -d extension=pdo_mysql tests/tahsin-mysql-integration.php
php -d extension=pdo_mysql tests/tahsin-reminder-mysql-integration.php
php -d extension=pdo_mysql tests/family-integration.php
php -d extension=pdo_mysql tests/data-induk-archive-integration.php
php -d extension=pdo_mysql tests/autosave-integration.php
npm run build
```

Suite HTTP membuat database sintetis baru dan server loopback sementara,
kemudian menghentikan server setelah selesai. Ia tidak memakai URL aplikasi
aktif, mengubah database produksi, atau mengirim pengingat ke pengguna.
Fixture database disimpan untuk diagnosis; belum ada penghapusan otomatis.

## Perbaikan API 27 Oktober 2026

- Audit menemukan parameter query array pada Hermes API dapat mencapai fungsi
  string/tanggal PHP sebelum divalidasi. Request dengan parameter berbentuk
  array kini ditolak sebagai HTTP 400 setelah autentikasi dan sebelum koneksi
  database.
- Pembatasan request Hermes dan pengingat n8n kini mengunci keseluruhan proses
  baca/periksa/tulis. Request serentak tidak dapat memakai hitungan lama yang
  sama. Kegagalan penyimpanan limiter menghasilkan HTTP 503; hitungan yang
  rusak dipulihkan dengan memulai jendela baru.
- Pemeriksaan terisolasi mencakup batas normal, pemisahan IP/scope, konfigurasi
  tidak valid, pemulihan hitungan rusak, dan 16 proses bersamaan untuk batas
  empat request. Uji HTTP menambahkan kasus array pada action dan tanggal.
- Tidak ada koneksi atau perubahan pada database aplikasi aktif. Suite HTTP
  yang memerlukan database tidak dijalankan karena port fixture lokal
  `127.0.0.1:13317` tidak aktif. Audit penerimaan fitur keseluruhan masih
  berlangsung.

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

## Validasi scope sinkronisasi Data Induk — 30 Oktober 2026

- Audit menemukan scope yang salah pada sinkronisasi Data Induk diam-diam
  diperluas menjadi `all`. Salah ketik atau input form berbentuk array dapat
  memicu sinkronisasi penuh. Scope kini harus cocok persis dengan salah satu
  pilihan yang didukung dan diperiksa sebelum skema, konfigurasi, lock, atau
  catatan sinkronisasi disentuh.
- Halaman admin tidak lagi mengubah field menjadi string/angka sebelum validasi.
  Scope yang hilang atau berbentuk array, ID konflik yang bukan angka positif,
  dan aksi tidak dikenal ditolak dengan HTTP 422. Konflik tidak dibuka sebagai
  transaksi untuk permintaan yang formatnya tidak valid.
- Regresi membandingkan hash peserta, anak, keanggotaan, presensi, status sync,
  riwayat sync, dan konflik sebelum/sesudah scope salah. Tes HTTP juga mencakup
  scope array/hilang dan ID konflik array. Database seluruh tes tetap sintetis.
- Lint PHP untuk tiga file dan pemeriksaan sintaks JavaScript serta `git diff
  --check` lulus lokal. CI untuk batch ini belum berjalan; tidak ada migrasi,
  koneksi atau penulisan ke basis data produksi.
