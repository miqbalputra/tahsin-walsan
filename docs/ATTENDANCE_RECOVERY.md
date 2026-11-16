# Versi lama dan pemulihan presensi

`presensi_history` menyimpan salinan sebelum UPDATE/DELETE dan salinan
sesudah UPDATE melalui trigger InnoDB. Penghapusan dari laporan admin,
riwayat guru, reset presensi, pembersihan Alfa libur, pengubahan capaian,
dan pemindahan presensi saat merge peserta memakai perlindungan yang sama.
Versi sebelum jurnal dipasang tidak direkayasa atau dibuat ulang.

## Persiapan

1. Simpan cadangan kondisi existing dan uji pemulihannya pada database
   terpisah. Hentikan penulisan staging selama pemasangan struktur.
2. Pasang migrasi aplikasi lainnya terlebih dahulu, termasuk reporting,
   autosave, kalender libur dan jurnal peserta yang diperlukan rilis.
3. Periksa dry-run lalu jalankan pada staging:

   ```powershell
   php migrate_attendance_history.php
   php migrate_attendance_history.php --apply
   ```

4. Pastikan akun database memiliki izin TRIGGER serta akses untuk memeriksa
   definisinya di information_schema. Periksa `attendanceHistoryReady($pdo)`.
   Halaman Libur membaca tabel existing dan tidak lagi menjalankan CREATE TABLE
   pada request pengguna; struktur kalender disiapkan oleh migrasi.
5. Bandingkan isi tabel domain sebelum/sesudah pemasangan, lalu jalankan
   pengujian staging dan prosedur rilis. Tidak ada perintah reset database.

Migrasi hanya menambah dua tabel dan trigger versi. Definisi yang lama tetap
aktif sampai dua definisi baru berhasil dipasang dan metadata dialihkan.
Pemasangan ulang tidak mengganti instance key atau menghapus jurnal. Jika
struktur presensi berubah, pasang ulang migrasi riwayat setelah perubahan
struktur. Operasi pengubahan/penghapusan aplikasi ditolak bila jurnal atau
trigger yang sesuai belum tersedia. GET biasa tetap dapat membaca laporan.

## Salinan yang dipertahankan

Setiap kolom presensi, termasuk kolom tambahan dan generated, diambil dari
row database yang benar-benar akan berubah. Base64 membedakan NULL, string
kosong, spasi, teks Unicode dan data biner. Snapshot tidak menjalankan SQL
atau template dari isinya. Akun pelaku, identitas peserta/halaqoh, dan UTC
offset juga dicatat. Kegagalan menulis jurnal membatalkan mutasi presensi.

Jurnal tidak dihapus lewat aplikasi. Halaqoh yang hanya tersisa di salinan
presensi lama tetap dianggap memiliki riwayat, agar ID/programnya tidak
dipakai ulang melalui penghapusan kelompok. Jurnal kalender libur menyimpan
sebelum/sesudah pada activity_logs; penyimpanan log itu juga wajib berhasil
dalam transaksi yang sama dengan perubahan kalender dan pembersihan Alfa.

## Pemulihan melalui aplikasi

Admin/PJ membuka **Riwayat Pemulihan Presensi** dari laporan atau Libur.
Halaman membandingkan versi sebelum tindakan, sesudah tindakan, dan catatan
saat ini. Salinan JSON lengkap dapat diunduh hanya oleh pengelola.

- Pratinjau dan token terikat sesi selama 30 menit, maksimum delapan tab.
  Pengiriman membutuhkan CSRF serta konfirmasi pemeriksaan data.
- Role diperiksa kembali dari database. Guru tetap dapat membatalkan catatan
  dalam penugasannya, sedangkan pemulihan dilakukan admin/PJ.
- UPDATE dipulihkan pada ID existing yang sama. DELETE dipulihkan dengan ID
  aslinya, termasuk ID nol pada data legacy. Kolom generated dihitung ulang
  oleh database dan diverifikasi terhadap snapshot. Kolom baru tidak dibuang.
- Record yang berubah setelah pratinjau, ID yang sudah dipakai kembali,
  catatan pengganti pada sesi yang sama, perubahan identitas/penugasan, dan
  versi dari instance database lain menolak pemulihan otomatis.
- Salinan lama tetap tersedia jika pemulihan ditolak. Periksa rekonsiliasi
  melalui database terpisah; jangan mengganti identitas hanya untuk melewati
  pemeriksaan. Riwayat sumber yang ID pesertanya sudah digabung tidak otomatis
  dialihkan ke ID lain.
- Jejak pemulihan tersimpan atomik dan memiliki receipt. Kiriman ulang versi
  yang sama mengembalikan hasil sebelumnya. Jika data kembali dihapus setelah
  pemulihan, pilih versi penghapusan yang baru.
- Revisi resmi bertambah melalui trigger autosave existing; draft database
  tidak dihapus. Draft offline/perangkat yang basi tetap perlu ditinjau.

Pemulihan memakai UTC offset snapshot agar nilai TIMESTAMP menunjuk waktu
yang sama. Koneksi kembali ke zona waktu dan SQL mode semula setelah operasi.
Semua nilai yang ditulis dibandingkan lagi dengan snapshot sebelum commit.

## Cadangan penuh dan bukti lokal

Backup ZIP mencakup jurnal dan instance key. Setelah restore ke database
kosong, pasang ulang migrasi aplikasi lalu migrasi riwayat paling akhir.
Trigger jurnal hanya menangkap UPDATE/DELETE; impor baris baru ke database
kosong tidak menggandakan jejak histori. Restore merge mempertahankan PK
existing dan bukan tombol undo seluruh aktivitas.

Pemeriksaan memakai database sintetis baru hanya di `127.0.0.1:13317`:

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/attendance-history-integration.php
node tests/attendance-history-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/backup-integration.php
```

Cakupan mencakup bytes/NULL/decimal/generated, ID nol, UTC offset, instance
lain, penolakan penugasan guru lain, pratinjau basi, replay, CSRF, token lintas
sesi, XSS, kegagalan jurnal, rollback kalender+Alfa+revisi, kesiapan trigger,
dan hash seluruh tabel setelah pemulihan penuh. Database produksi tidak
dipakai pengujian ini. Pemeriksaan browser, konfigurasi Apache/PHP produksi,
serta pemulihan cadangan produksi nyata tetap menjadi penerimaan rilis.
