# Perubahan kelas dan pelestarian data

`naik-kelas.php` memakai `includes/student_progress_helper.php` untuk menaikkan
kelas, meluluskan anak terpilih, dan mengembalikan kelas anak yang masih Lulus.
Data peserta tetap memakai primary key lama. Presensi, capaian awal, dan draft
tidak dipindahkan atau ditulis ulang oleh proses ini.

## Aturan penyimpanan

- Formulir terikat pada akun/sesi selama 30 menit. Delapan formulir terakhir
  dapat digunakan dari tab yang berbeda. Maksimum 200 anak per pengiriman.
- Setiap anak dibandingkan dengan nama, ID wali, kelas, dan status wali yang
  ditampilkan ketika formulir dibuka. Data yang sudah berubah menyebabkan
  seluruh pengiriman ditolak; operator perlu memeriksa formulir terbaru.
- Nomor operasi disimpan pada jurnal database. Pengiriman ulang dengan
  pilihan yang sama mengembalikan receipt tanpa menaikkan kelas lagi.
- Kelas yang dikenali untuk promosi adalah Mustawa 1 sampai 6, dengan akhiran
  Ikhwan/Akhwat opsional. Kelas kosong, tidak dikenal, atau di luar rentang
  dipertahankan dan permintaannya ditolak.
- Koreksi kelas hanya berlaku untuk anak yang masih Lulus. Daftar pengganti
  menyediakan jenjang Mustawa meskipun tidak ada anak aktif lagi, serta kelas
  lokal existing yang masih muat pada kolom database.
- Akun diperiksa kembali dari database. Identitas master keluarga/Data Induk
  dilindungi, termasuk mapping yang tetap ada saat integrasi dimatikan.
- Lock yang sama dengan formulir/impor peserta, row lock, SQL mode ketat,
  dan transaksi InnoDB melindungi seluruh pilihan sebagai satu operasi.

## Riwayat dan pemulihan

Jurnal `participant_changes` menyimpan snapshot peserta, semua anaknya dan
keanggotaan sebelum/sesudah. Aksi diberi label Naikkan kelas, Luluskan anak,
atau Kembalikan kelas anak pada `peserta-riwayat.php`. Salinan JSON juga dapat
diunduh admin/PJ. Kegagalan menyimpan salah satu jurnal membatalkan seluruh
kelas, arsip, interval roster, dan jurnal lain dalam pengiriman yang sama.

Apabila semua anak sudah Lulus, wali dan membership diarsipkan. Wali yang
masih memiliki anak sekolah aktif tetap aktif. Koreksi kelas tidak otomatis
mengaktifkan wali/membership lama; aktivasi dan penempatan perlu ditinjau
secara eksplisit. Untuk membetulkan promosi yang keliru, gunakan nilai kelas
sebelum perubahan pada riwayat sebagai acuan formulir peserta. Restore merge
backup mempertahankan baris existing dan bukan mekanisme membatalkan promosi.

## Persiapan rilis

Tidak ada skema baru khusus kelas. Jurnal dari
`migrate_participant_management.php --apply` dan kolom arsip membership harus
sudah tersedia pada staging. Jika jurnal belum ada, penyimpanan kelas ditolak.
Migrasi existing tersebut harus mengikuti prosedur backup dan pemulihan
terpisah sebelum produksi diperbarui.

Pemeriksaan lokal memakai fixture sintetis baru pada `127.0.0.1:13317`:

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/student-progress-integration.php
node tests/security-http-tests.cjs
```

Pemeriksaan ini mencakup pengiriman ulang, formulir basi, ID yang dipaksakan,
mapping pusat, hak akses, koreksi lulusan, kakak/adik, kegagalan jurnal setelah
jurnal pertama disimpan, dan kesamaan data presensi/draft/capaian. Hasilnya
tidak menggantikan uji pemulihan cadangan produksi nyata atau penerimaan
seluruh fitur aplikasi.
