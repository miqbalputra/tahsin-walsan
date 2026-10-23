# Cadangan dan pemulihan

## Menu aplikasi

Admin dan PJ Tahfidz dapat mengunduh cadangan, mengunggah ZIP untuk
pratinjau, lalu mengetik `RESTORE` untuk mengonfirmasi pemulihan.
Pratinjau tidak menulis data domain. Log aktivitas mencatat penggunaannya.

Pemulihan memakai **merge dengan ID asli**:

- Baris yang primary key-nya sudah ada dilewati. Baris lama tidak ditimpa.
- Baris baru ditambahkan dengan pemeriksaan foreign key tetap aktif.
- Jika induk dengan ID sama memiliki isi berbeda, penambahan anak yang
  merujuk ke induk tersebut dibatalkan untuk mencegah hubungan yang keliru.
  Pengelola perlu membandingkan kedua identitas pada database terpisah.
- Seluruh penambahan berjalan dalam satu transaksi. Kegagalan validasi,
  foreign key, nilai terlalu panjang, atau kolom otomatis yang berbeda
  membatalkan perubahan parsial. Tabel non-InnoDB ditolak sebelum operasi.
- Tabel tanpa primary key tetap masuk cadangan, tetapi tidak dimasukkan
  saat merge. Pratinjau dan hasil menyebut tabel yang dilewati.
- Cadangan kondisi sebelum pemulihan dibuat terlebih dahulu. Unduh salinan
  tersebut; mengunggahnya kembali melalui menu merge **tidak menghapus**
  baris tambahan dari pemulihan sebelumnya.

Trigger aplikasi dapat memperbarui metadata revisi/histori saat ada baris
baru. Pemulihan penuh ke satu titik waktu memerlukan database terpisah
dan penggantian koneksi setelah hasilnya diperiksa oleh pengelola.

## Penyimpanan dan batas

`BACKUP_STORAGE_PATH` dapat menunjuk folder absolut khusus cadangan pada
volume persisten. Folder harus dapat ditulis oleh proses PHP. Jika opsi ini
diisi tetapi tidak tersedia, operasi berhenti; aplikasi tidak beralih diam-diam
ke penyimpanan sementara.

Jika opsi kosong, instalasi lama tetap memakai `temp_excel/` apabila dapat
ditulis, lalu folder OS sementara khusus aplikasi sebagai fallback. Kedua
lokasi tersebut dapat hilang ketika container diganti. Unduh salinan ke
penyimpanan terpisah sebelum redeploy atau penggantian server.

`BACKUP_MAX_UPLOAD_MB` membatasi ukuran unggahan (1–512 MB; default kode
100 MB). Batas `upload_max_filesize` dan `post_max_size` PHP juga berlaku.
`BACKUP_MAX_EXPANDED_MB` membatasi total isi ZIP setelah didekompresi
(default 512 MB, dapat diatur 1–2048 MB). Manifest maksimal 1 MB, maksimal
1000 berkas, dan satu record CSV maksimal 32 MB termasuk kutip/pemisah.
Ukuran record ini juga menampung jurnal impor beserta data sebelum/sesudahnya.
Cadangan yang melampaui batas aplikasi memerlukan alat database oleh pengelola.

## Format dan validasi

ZIP berisi satu CSV untuk setiap base table, `schema.sql`, dan
`manifest.json`. View dan trigger tidak dieksekusi dari arsip. Nama tabel,
kolom, jumlah baris dan checksum CSV diperiksa sebelum penambahan data.
Nama/path ZIP ganda, berkas yang tidak terdaftar, kolom asing, primary key
hilang, dan CSV terpotong ditolak. Kolom generated tidak diisi langsung;
hasil hitungan database dibandingkan dengan nilai di cadangan.

Format 3 memakai CSV tanpa escape proprietary PHP. `\N` menyatakan NULL;
nilai teks yang diawali backslash ditambah satu backslash saat ekspor dan
dikembalikan saat impor. Dengan demikian NULL, string kosong, teks `\N`,
kutip, Unicode, newline dan data biner dapat dibedakan.

Format 1/2 tetap dikenali dengan pembaca CSV legacy dan pemberitahuan.
Format lama tidak dapat membedakan NULL dari teks literal `\N`, dan ekspor
lama dapat memiliki masalah kutip/backslash. Informasi yang sudah ambigu
tidak dapat direkonstruksi otomatis. Gunakan database terpisah untuk
memeriksa cadangan lama sebelum memakainya pada aplikasi aktif.

Token konfirmasi acak dan hash arsip terikat ke sesi yang melakukan
pratinjau. Berkas yang berubah setelah pratinjau ditolak. Eksekusi tetap
mengulang validasi arsip; keberhasilan pratinjau tidak melewati validasi.

## Pemulihan penuh oleh pengelola

1. Simpan arsip asli dan cadangan kondisi aplikasi yang masih tersedia.
   Siapkan database kosong terpisah; pastikan koneksi pemulihan tidak
   menunjuk database aplikasi aktif.
2. Tinjau `schema.sql` dari cadangan tepercaya dan siapkan struktur tabel
   yang sesuai dengan versi aplikasi. Jangan mengaktifkan trigger penulisan
   sebelum isi tabel selesai dipulihkan, agar histori/revisi tidak dibuat dua kali.
3. Jalankan `analyzeRestoreZip()` lalu `executeMergeRestore()` dari
   `includes/backup_helper.php` menggunakan PDO yang secara eksplisit
   terhubung ke database baru. Dapatkan urutan tabel dari
   `discoverBackupTables($pdo)` dan gunakan token NULL `"\\N"`.
   Periksa `error`, jumlah baris, serta tabel yang dilewati pada hasil.
4. Setelah data masuk, pasang ulang view/trigger dengan migrasi versi yang
   sama: `tahsinApplyMigration()`, `autosaveApplyMigration()`, dan
   `familyApplyMigration()`, serta `participantImportApplyMigration()` jika
   versi aplikasi tersebut memakai jurnal impor, serta `participantApplyMigration()`
   jika memakai jurnal formulir peserta. Jangan menjalankan reset atau migrasi bootstrap
   yang mengganti tabel/data. Helper migrasi bersifat additive.
5. Bandingkan jumlah dan hash isi **setiap** tabel, primary key, serta relasi.
   Periksa pembacaan keluarga, presensi, histori, draft dan pengaturan.
   Baru gunakan database hasil pemulihan setelah hasilnya sesuai.

Cadangan ZIP ini mencakup database, bukan snapshot seluruh server.
Konfigurasi rahasia, file aplikasi/aset, unggahan jika ada, dan sesi PHP
memerlukan salinan serta volume penyimpanan sendiri.

## Bukti uji yang dapat diulang

```powershell
php -d extension=pdo_mysql tests/backup-integration.php
node tests/security-http-tests.cjs
```

Suite pertama membuat database sintetis baru pada `127.0.0.1:13317`,
mengisi setiap tabel, mengekspor ZIP, memulihkannya ke database kosong,
memasang ulang view/trigger, dan membandingkan hash semua isi tabel.
Kasus gagal membuktikan rollback dan pelestarian relasi. Suite HTTP
mencakup unggah, pratinjau, token lintas sesi, perubahan arsip, batal,
cadangan otomatis dan unduhan yang memerlukan login.
Hasil JSON disimpan di `.sessions/audit-20261023/backup-*/results.json`.
Pengujian tidak memakai database produksi atau mengirim pengingat.
