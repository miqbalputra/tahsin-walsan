# Formulir presensi lama: versi sumber dan pengiriman ulang

Halaman kompatibilitas `ustadz/form-presensi.php?legacy=1` tetap menyediakan
simpan otomatis per peserta, finalisasi satu halaqoh, dan pembatalan.
Controller, tampilan dan antrean permintaan dipisahkan. Alur autosave modern
tetap menjadi pilihan default jika struktur dan konfigurasi mendukungnya.

## Persiapan tanpa mengganti data existing

1. Cadangkan database dan buktikan pemulihannya pada database terpisah.
2. Siapkan migrasi pelaporan/autosave sesuai rilis dan jurnal versi presensi.
   Lihat [ATTENDANCE_RECOVERY.md](ATTENDANCE_RECOVERY.md).
3. Periksa dry-run, lalu siapkan jurnal formulir pada staging:

   ```powershell
   php migrate_attendance_forms.php
   php migrate_attendance_forms.php --apply
   ```

4. Bandingkan tabel domain sebelum/sesudah. Migrasi hanya menambah tabel
   InnoDB `presensi_form_operations`; tidak mengganti atau mengosongkan tabel.
   GET formulir tidak melakukan migrasi. Bila jurnal belum siap, halaman
   menjelaskan persiapan yang diperlukan dan penyimpanan ditolak.

Migrasi formulir dapat diulang dan menolak dijalankan di dalam transaksi data.
Dry-run tidak membuka koneksi database aplikasi. Jalankan penerimaan pada
runtime rilis sebelum menerapkan migrasi pada aplikasi aktif.

## Perlindungan penyimpanan

- Formulir terikat akun, sesi, halaqoh dan tanggal selama 30 menit, maksimum
  delapan tab. Token CSRF tetap wajib. Halaman/API memeriksa ulang peran.
- Fingerprint mencakup sumber sesi lengkap, verifikasi, revisi histori,
  revisi resmi serta draft server. Konteks mengikat identitas peserta,
  roster sesi, penugasan/program dan kalender. Hash tidak berisi data sumber
  yang bisa dibaca oleh guru lain.
- Sumber yang berubah setelah halaman dibuka menghasilkan HTTP 409 sebelum
  satu pun peserta ditulis. Finalisasi ditolak seluruhnya jika daftar atau
  salah satu versi tidak sesuai. Formulir lama tidak dapat menimpa koreksi
  admin, autosave lain, draft baru, atau menghapus versi yang lebih baru.
- Roster memakai penugasan historis yang sah. Pengarsipan hari ini tidak
  menghapus keanggotaan yang berlaku pada sesi lama; koreksi roster pada sesi
  yang sama mengharuskan formulir dibuka ulang.
- Perubahan materi hanya menulis nilai yang maknanya berubah. Tanggal, ID,
  kolom tambahan, teks/spasi dan kolom lama yang tidak dipakai tetap tersedia.
  NULL, biner dan decimal pada sumber lengkap juga masuk jurnal versi.
- Blank menjadi Alfa hanya melalui finalisasi eksplisit setelah batas waktu
  sesi, sesuai aturan existing. Draft server yang muncul sejak formulir
  dibuka menolak finalisasi, sehingga tidak diganti Alfa.
- Pembatalan hanya menghapus catatan halaqoh/sesi yang diizinkan setelah versi
  diperiksa. Trigger menyimpan salinan lengkap; draft server tidak dihapus.
  Pemulihan oleh pengelola mengikuti panduan versi presensi.

Setiap pengiriman mempunyai ID operasi dan request hash. Receipt, snapshot
sumber sebelum/sesudah, presensi, histori dan revisi disimpan dalam satu
transaksi. Pengiriman ulang yang sama mengembalikan receipt tanpa menulis
ulang; penggunaan ID untuk isian berbeda ditolak. Kegagalan jurnal
membatalkan seluruh perubahan. Informasi SQL tidak dipaparkan pada halaman.

Endpoint `ustadz/api-save-single.php` kini membutuhkan token formulir,
fingerprint dan ID operasi dari halaman terbaru. Klien lama yang hanya
mengirim ID peserta/tanggal ditolak; tidak ada jalur cadangan tanpa kontrol
versi. Akses langsung endpoint tetap memerlukan autentikasi dan CSRF.

## Pengalaman saat koneksi atau validasi gagal

- Permintaan per peserta berurutan. Isian berikutnya menunggu hasil
  permintaan sebelumnya dan memakai revisi yang sudah diakui server.
- Hasil pengiriman yang tidak pasti mempertahankan payload, ID operasi dan
  versi asal. Tombol **Ulangi pengiriman yang sama** memulihkan receipt.
  Pengubahan diam-diam dengan ID operasi baru tidak dilakukan.
- Konflik menghentikan simpan otomatis/finalisasi. Isian tetap terlihat dan
  tautan membuka formulir terbaru pada tab terpisah untuk membandingkan.
- Isian tidak lengkap belum tersimpan; label tidak menyatakan sebaliknya.
  Tautan dan pemilihan tanggal meminta konfirmasi sebelum melepas perubahan
  yang belum diakui; penolakan mempertahankan isian/tanggal. Peringatan native
  saat menutup/reload tab juga didaftarkan, dengan dukungan yang bergantung
  kebijakan browser. Formulir kompatibilitas belum mempunyai draft offline
  tersendiri; alur modern tetap menyediakan penyimpanan perangkat.
- Input array, UTF-8 rusak, karakter kontrol, teks berlebihan, jilid di luar
  pilihan dan rentang terbalik ditolak. EBTA Iqro tetap didukung. ID catatan
  nol pada data legacy dibedakan dari keputusan baseline NULL.
- Pesan dan nama anak di-escape. Input memiliki label yang terhubung, dan
  radio status/hasil tetap bisa difokuskan dengan keyboard.

## Backup dan bukti penerimaan

Backup penuh mencakup `presensi_form_operations`. Setelah pemulihan ke
database kosong, pasang ulang migrasi aplikasi dan jurnal versi, kemudian
`attendanceFormApplyMigration()`. Bandingkan isi seluruh tabel sebelum
menggunakan hasil pemulihan. Receipt lama tidak diputar ulang sebagai SQL.

```powershell
php -d extension=pdo_mysql tests/attendance-form-integration.php
node tests/legacy-attendance-http-tests.cjs
node tests/legacy-attendance-queue-tests.cjs
php -d extension=pdo_mysql tests/backup-integration.php
```

Suite membuat database `tahsin_test_*` baru di `127.0.0.1:13317`. Uji
reproduksi awal membuktikan API lama dapat menimpa sumber yang sudah berubah;
regresi sekarang memeriksa penolakan. Runtime produksi, semua perangkat,
seluruh fitur dan pemulihan cadangan produksi nyata masih memerlukan
penerimaan terpisah. Tidak ada pengujian ini yang menggunakan data produksi.
