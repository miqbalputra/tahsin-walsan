# Pemeriksaan data ganda dan pembatalan merge

## Arti temuan

Menu **Data Peserta → Periksa Data Ganda** tersedia untuk admin/PJ. Pemeriksaan
tidak mengubah data domain. Nama/nomor HP yang sama hanya menghasilkan kandidat;
nomor keluarga bersama dan nama anak sama bukan bukti identitas sama.

- Identitas: nama Unicode/spasi/tanda baca dinormalisasi; nomor `08`, `628`, dan
  `+628` disetarakan. Anak menjadi bukti tambahan. Admin memeriksa dua ID sebelum
  merge, atau mencatat alasan bahwa keduanya orang berbeda. Bila sumber berubah,
  penandaan tersebut tidak menyembunyikan temuan baru.
- Keanggotaan: lebih dari satu keanggotaan aktif per peserta/program. Tinjau
  formulir peserta dan pilih satu halaqoh. Penempatan lama diarsipkan dengan waktu
  perubahan; jangan mengarang histori sebelum pencatatan histori tersedia.
- Presensi: beberapa baris pada peserta/program/pekan yang sama, termasuk
  kombinasi halaqoh/tanggal yang persis sama. Pilih catatan resmi setelah konfirmasi
  guru. Semua baris asal dan keputusan terdahulu tetap disimpan. Pembatalan keputusan
  menambahkan keputusan baru yang mengembalikan pilihan sebelumnya atau status
  belum diverifikasi. Sumber yang berubah menolak pembatalan dari pratinjau lama.
- Draft: pemeriksaan membandingkan draft server dengan revisi resmi/konteks saat
  ini. Konflik draft dibedakan dari identitas ganda. Draft yang hanya berada di HP
  tidak dapat dibaca atau dicadangkan oleh server.

Program Bapak dan Ibu diselesaikan terpisah pada autosave, formulir kompatibilitas,
editor capaian, dan rekap. Keputusan legacy tanpa program tetap dibaca jika lingkup
sumbernya dapat ditentukan; fingerprint yang tidak cocok tetap meminta verifikasi.
Penghitung revisi lama dipertahankan untuk kompatibilitas. Penghitung tambahan per
program mencegah perubahan Program Ibu membuat draft Program Bapak kedaluwarsa.

## Persiapan rilis

1. Simpan backup penuh melalui fasilitas existing di penyimpanan privat persisten.
   Uji restore pada database kosong terpisah dan bandingkan isi/ID/relasi seluruh
   tabel. Jangan memakai restore merge sebagai pengganti undo.
2. Pastikan migrasi reporting, autosave, keluarga, jurnal peserta/merge, dan
   riwayat pemulihan presensi existing sudah tersedia. Siapkan jendela tanpa
   penulisan selama perubahan struktur. Diperlukan tabel InnoDB dan izin TRIGGER.
3. Periksa dry-run berikut, lalu apply di staging terlebih dahulu:

   ```powershell
   php migrate_data_quality.php
   php migrate_data_quality.php --apply
   ```

   Migrasi hanya menambah metadata pada keputusan existing, indeks operasi unik,
   tabel review/receipt undo/revisi per program, serta trigger revisi tambahan.
   Tidak ada UPDATE/DELETE peserta, anak, keanggotaan, atau presensi legacy.
   Pengulangan aman; kegagalan DDL perlu diselesaikan dengan menjalankan ulang
   migrasi. Tidak ada DDL dalam request pengguna.
4. Jalankan audit hanya-baca pada staging, kemudian produksi dengan koneksi yang
   diperiksa pengelola. CLI tidak otomatis membaca `.env`; jika diperlukan,
   aktifkan `APP_LOAD_ENV_FILE=1` untuk memakai konfigurasi aplikasi yang sama.

   ```powershell
   php scripts/audit-data-quality.php
   ```

   Exit `0`: tidak ada temuan pending; `2`: ada temuan untuk ditinjau; `1`: audit
   gagal. JSON berisi data pribadi. Simpan di luar webroot. Script menggunakan
   transaksi hanya-baca dan aturan deteksi yang sama dengan halaman admin.
5. Aktifkan versi aplikasi/aset hasil build. Merge baru ditolak jika migrasi quality
   atau jurnal pemulihan belum siap. Jangan melakukan pembersihan/merge massal.

## Merge dan undo

Hanya admin dapat merge/undo. Data Induk/master keluarga tetap melarang merge lokal,
termasuk jika integrasinya dinonaktifkan tetapi pemetaan identitas masih ada.

Merge baru menyimpan `undo_state` versi 2 sebelum/sesudah, dengan nilai base64,
primary key, struktur kolom, zona waktu numerik, dan instance key jurnal database.
Nilai NULL, string kosong, data biner dan kolom tambahan dibedakan. Jurnal tampilan
menyajikan data biner sebagai heksadesimal; nilai asli ada di `undo_state`.
ID anak/presensi tetap sama. Snapshot sebelum/sesudah maksimal 16 MB gabungan;
kelebihan ukuran membatalkan transaksi. Pratinjau pemilihan pasangan tetap dibatasi
4 MB. Semua penulisan menggunakan pemeriksaan referensi, lock, dan SQL mode ketat.

Dari **Riwayat Perubahan Peserta → Pratinjau pembatalan merge**:

1. Tanpa perubahan lanjutan, periksa ID asal lalu konfirmasi. Sumber dihidupkan
   kembali dengan ID aslinya; hanya pengalihan operasi tersebut yang dilepas.
2. Jika ada perubahan lanjutan, bandingkan sebelum merge, sesudah merge, dan saat
   ini. Setiap baris baru wajib ditempatkan pada salah satu identitas. Untuk edit,
   pilih identitas yang menerima perubahan, atau kembalikan nilai sebelum merge.
   Untuk penghapusan, pilih pertahankan penghapusan atau pulihkan baris asal.
   Kondisi saat ini tetap diarsipkan dalam jurnal undo meskipun admin memilih
   mengembalikan nilai lama.
3. Jurnal lama tidak dihapus atau ditulis ulang; metadata kepemilikannya mengikuti
   asalnya. Revisi tidak pernah diturunkan. Kedua draft asal dipulihkan, ditandai
   konflik, dan wajib ditinjau sebelum menjadi catatan resmi.

Pratinjau terikat akun/sesi selama 30 menit, maksimum delapan tab, dilindungi CSRF.
Semua data diperiksa ulang dalam transaksi. Kiriman ulang formulir yang sama
mengembalikan receipt. Perubahan bersamaan, ID yang dipakai identitas lain, struktur
baru, referensi yang belum didukung, perubahan pemetaan master, atau instance
database berbeda menghentikan operasi. Foreign key tidak dimatikan; cascade
penghapusan tidak digunakan. Pemeriksaan akhir membandingkan seluruh hasil dengan
pilihan/snapshot sebelum commit. Kegagalan jurnal/receipt membatalkan semua perubahan.

Merge berantai harus dibatalkan dari operasi terakhir terlebih dahulu. Riwayat
merge versi lama yang tidak memiliki snapshot lengkap hanya menyediakan ekspor;
pemulihannya memerlukan database terpisah. Jangan merekayasa snapshot lama agar
dianggap memenuhi syarat undo otomatis.

Semua tabel baru otomatis ikut backup penuh existing. Restore ke database terpisah
mempertahankan instance key; pasang ulang migrasi quality dan riwayat pemulihan
setelah impor tanpa trigger penulisan. Pengaman restore legacy terhadap ID sumber
yang masih dialihkan tidak dilonggarkan oleh fitur undo.

## Draft HP dan pesan konflik

Draft tertunda/konflik tidak dihapus oleh pembersihan otomatis 30 hari. Salinan yang
sudah diterima server dapat dibersihkan. Penghapusan eksplisit draft perangkat saat
logout tetap merupakan pilihan pengguna. Backup server tidak mencakup IndexedDB HP;
gunakan **Unduh cadangan isian perangkat** sebelum mengganti/membersihkan perangkat.

Layar guru membedakan isian HP, draft server, dan catatan resmi. Konflik penempatan,
catatan berbeda, tanggal libur, dan capaian awal menjelaskan perlunya bantuan admin.
Guru tidak dapat menimpa konflik tersebut. Ringkasan bawah menampilkan konflik atau
perubahan tertunda sampai benar-benar selesai. Draft ID lama setelah merge tetap
dipertahankan sebagai draft yang perlu ditinjau; tidak dialihkan dan dikirim otomatis.

## Bukti uji

Semua fixture menggunakan database sintetis baru pada `127.0.0.1:13317`:

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/data-quality-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-merge-integration.php
php -d extension=pdo_mysql -d memory_limit=512M tests/backup-integration.php
node tests/autosave-storage-tests.cjs
node tests/data-quality-browser.cjs
```

Uji browser memerlukan Playwright pada jalur modul Node dan Chromium. Di Windows,
default executable adalah Chrome pada Program Files; dapat diubah melalui
`TAHSIN_TEST_CHROME`. Artefak screenshot/hasil ada di `.sessions/quality-browser-*`.
Uji mencakup Purwanto dengan dua halaqoh, pemisahan program, pemilihan/pembatalan
catatan resmi, bytes/NULL/decimal, merge/undo, perubahan lanjutan, konkurensi,
kegagalan receipt, rantai merge, backup penuh, CSRF, token lintas sesi, serta akses
guru/PJ. Hasil lokal tidak membuktikan audit atau pemulihan produksi aktual.
