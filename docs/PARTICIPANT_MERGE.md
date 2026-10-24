# Penggabungan peserta duplikat

## Persiapan

Jalankan pada staging setelah cadangan tersedia, kemudian pada produksi saat rilis:

```powershell
php migrate_participant_merge.php
php migrate_participant_merge.php --apply
```

Dry-run tidak menghubungi database. Apply memasang jurnal `participant_changes`
jika belum ada, lalu tabel `participant_merge_aliases`. Migrasi bersifat additive,
dapat diulang, serta menolak transaksi aktif agar DDL tidak melakukan commit
pekerjaan lain. Tidak ada peserta/presensi/anak yang diubah oleh migrasi.

## Alur admin

1. Buka **Data Peserta → Gabung Duplikat**. Hanya admin dapat membuka dan
   menyimpan penggabungan. Nama berulang ditampilkan sebagai kandidat untuk
   ditinjau, bukan bukti bahwa orangnya sama. Saran dipaginasi 20 kelompok.
2. Cari nama/nomor HP, pilih peserta yang dipertahankan, lalu peserta sumber.
   Daftar kandidat dipaginasi 50 peserta; pencarian dan pilihan tetap dibawa
   pada tautan halaman. Pratinjau belum mengubah data.
3. Periksa biodata lengkap, ID anak, keanggotaan dan pilihan data aktif.
   Biodata default berasal dari peserta yang dipertahankan. Nomor HP, alamat,
   kategori, informasi pengajar luar, status aktif dan catatan lanjut Tahsin
   dapat dipilih masing-masing dari salah satu identitas. ID dan waktu
   pembuatan peserta yang dipertahankan tetap digunakan; sumber utuh dijurnal.
4. Jika kedua peserta mempunyai halaqoh berbeda pada program yang sama,
   pilih penempatan peserta dipertahankan, sumber, atau gabungan keduanya.
   Program Bapak/Ibu ditangani terpisah. Keanggotaan lama tidak dihapus;
   duplikasi aktif pada halaqoh yang sama diarsipkan pada ID membership lama.
5. Jika ada dua capaian awal atau draft dengan payload berbeda pada akun,
   halaqoh dan sesi yang sama, pilih versi yang akan aktif. Kedua versi asal
   tetap tersimpan dan dapat dibaca pada riwayat penggabungan.
6. Centang konfirmasi bahwa kedua identitas adalah orang yang sama, lalu
   simpan. Hasil membuka jurnal sebelum/sesudah. Membatalkan pratinjau tidak
   mengubah data. Halaman dan formulir tidak memerlukan JavaScript.

## Identitas dan riwayat

Anak dipindahkan dengan UPDATE ID wali; ID anak, nama dan kelas tetap sama.
Nama anak yang cocok tidak otomatis disatukan karena bisa mewakili orang
berbeda. Penghapusan anak yang memang keliru dilakukan secara eksplisit
setelah pemeriksaan melalui formulir peserta, dengan jurnal dan pemeriksaan
referensi. Semua baris presensi tetap memakai ID, tanggal dan payload asal.
Baris berbeda pada minggu yang sama mengikuti verifikasi kanonis aplikasi.

Membership dan interval histori tetap tersimpan. Perpindahan menutup interval
lama dan membuka interval yang sesuai saat ini. Interval terdahulu tetap
memiliki waktu, pengampu dan kategori historisnya. Peserta berstatus Arsip
tidak diaktifkan diam-diam. Aturan alumni seluruh anak Lulus tetap berlaku.

Capaian awal hanya memiliki satu baris aktif per peserta. Versi yang tidak
dipilih disimpan dalam snapshot jurnal, bukan dibiarkan menjadi referensi ke
ID yang sudah dihapus. Bukti `INIT_CAPAIAN` tetap menahan catatan untuk
verifikasi setelah ID sumber dipindahkan.

Draft yang dipilih dan draft yang hanya ada di satu identitas dipindahkan ke
ID hasil. Nomor revisi dinaikkan dan versi konteks dibuat kedaluwarsa agar
draft tidak otomatis menimpa presensi resmi. Guru harus meninjau ulang.
Payload, waktu dan revisi asal kedua versi tersedia dalam jurnal. Revisi
resmi yang berbenturan digabung secara monoton; tidak ada baris revisi/draft
yang tertinggal di ID sumber. Receipt autosave dan activity log lama tetap
menjadi bukti operasi asal; isi JSON/lognya tidak ditulis ulang.

ID sumber dihapus hanya setelah data dipindahkan dalam transaksi yang sama.
Pengalihannya dicatat pada `participant_merge_aliases`. Jika hasil digabung
lagi, seluruh ID lama langsung menunjuk ID terakhir. Tautan editor/riwayat
lama dapat dibuka kembali; tautan arsip lama membuka pemeriksaan editor,
bukan langsung mengarsipkan peserta hasil. Formulir lama yang masih terbuka
ditolak dan meminta pengguna meninjau identitas hasil.

## Perlindungan

- Mapping master keluarga, bridge anak, UUID wali/anak/enrollment/kelas dan
  mode Data Induk melarang penggabungan lokal, termasuk konfigurasi parsial
  atau view keluarga yang hilang. Pemetaan guardian yang masih menunggu
  keputusan juga harus diselesaikan terlebih dahulu.
- Service memeriksa ulang peran admin, menggunakan lock bersama impor/formulir,
  engine InnoDB, SQL mode ketat dan row lock. Fingerprint mencakup kedua
  identitas, anak, membership, presensi, draft, revisi, baseline, verifikasi,
  jurnal terkait serta pengalihan. Perubahan sejak pratinjau menolak operasi.
- Pratinjau terikat akun/sesi, berlaku 30 menit dan memakai nonce acak terpisah
  dari CSRF. Pengiriman ulang operasi selesai mengembalikan receipt. Mulai
  pasangan berikutnya tidak mengulang penggabungan sebelumnya.
- Foreign key yang belum memiliki aturan pemindahan, referensi konvensional
  `wali_id`/`wali_santri_id` yang tidak dikenal, serta relasi gabungan anak
  ditolak sebelum menulis. Tidak ada penonaktifan foreign key.
- Pengalokasian ID baru menolak nomor yang masih dipakai pengalihan lama.
  Restore merge juga menolak menghidupkan ID sumber kembali, pengalihan ke
  peserta hilang atau pengalihan atas ID peserta yang masih hidup.
- Snapshot pratinjau maksimal 4 MB dan gabungan jurnal sebelum/sesudah 8 MB.
  Kelebihan ukuran atau kegagalan jurnal/alias membatalkan semua perubahan,
  termasuk penghapusan sumber dan trigger histori. SQL mode dan lock dipulihkan.

## Pemulihan dan batas pemeriksaan

Riwayat dan unduhan JSON privat mencakup kedua biodata asal, semua anak,
membership, baseline, draft serta baris terkait sebelum/sesudah. Metadata
jurnal lama dipindahkan ke peserta hasil; isi snapshot jurnal lama tidak diubah.
Hanya admin/PJ yang dapat membaca riwayat. Jurnal berisi data pribadi, ikut
backup, dan bukan tombol undo otomatis. Pemulihan manual perlu memeriksa
relasi serta perubahan sesudahnya pada database terpisah terlebih dahulu.

Suite lokal memakai database sintetis di `127.0.0.1:13317`:

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-merge-integration.php
node tests/security-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/backup-integration.php
```

Artefak service: `.sessions/audit-20261023/participant-merge-*/results.json`.
Pemeriksaan HTTP tidak membuktikan tampilan browser, Apache, PHP produksi,
atau pemulihan backup produksi nyata. Draft offline yang hanya tersimpan pada
perangkat pengguna belum dapat dicatat oleh server; alurnya masih termasuk
audit autosave/PWA yang harus diselesaikan sebelum rilis keseluruhan.
