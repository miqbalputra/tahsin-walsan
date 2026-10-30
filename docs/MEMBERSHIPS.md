# Pengelolaan anggota halaqoh per program

Halaman **Halaqoh → Kelola Anggota** menggunakan aturan program dan sumber
sekolah yang sama dengan penempatan baru melalui formulir peserta. Kode
controller, katalog, validasi, transaksi dan tampilan dipisahkan.

## Daftar dan konfirmasi

- Daftar anggota menampilkan satu baris per peserta, termasuk relasi aktif
  lama milik peserta yang sudah diarsipkan. Relasi ganda tidak dihapus diam-diam.
- Pencarian nama/ID peserta atau nama anak berlaku untuk daftar anggota dan
  calon anggota. Keduanya mempunyai halaman sendiri, 50 peserta per halaman.
  Anak tanpa kelas tetap ditampilkan; teks tidak dipotong oleh GROUP_CONCAT.
- Pilihan calon anggota dibatasi pada peserta Reguler/Askar aktif yang
  mempunyai anak sekolah aktif dan belum memiliki halaqoh aktif **pada
  program yang dipilih**. Penempatan program lain tidak menyembunyikan calon.
- Pilih peserta lalu **Tinjau penempatan**. Halaman konfirmasi menunjukkan
  nama, ID, program, pengampu, penempatan aktif dan sumber sekolah. Tombol
  simpan mengikat peserta/program yang ditampilkan, bukan ID tersembunyi
  yang dapat diganti pada POST.
- Peserta yang sudah memiliki kelompok lain dalam program yang sama
  dipindahkan melalui **Tinjau peserta / pindahkan**. Pemindahan eksplisit
  memakai formulir peserta, mengarsipkan relasi program tersebut dan
  mempertahankan program lainnya.

Program berada pada halaqoh, bukan pada nama peserta. Penugasan selalu memakai
`wali_santri.id` yang dipilih; aplikasi tidak menebak gender, menyalin presensi,
membuat identitas Ibu dari identitas Bapak, atau menggabungkan orang karena
namanya mirip. Master keluarga tetap mempunyai identitas masing-masing wali.

## Penonaktifan dan aktivasi kembali

**Nonaktifkan keanggotaan** hanya mengarsipkan relasi aktif pada halaqoh yang
dikonfirmasi. ID/nama peserta, anak, presensi, capaian awal, draft serta
penempatan program lain tidak diubah. Jika data lama mempunyai beberapa
relasi aktif ke halaqoh yang sama, semuanya dijadikan arsip dan ID lamanya
tetap dipertahankan.

Menambahkan peserta yang sudah aktif pada kelompok yang sama tidak
menggandakan relasi atau menutup/membuka ulang interval roster. Penambahan
kembali memakai salah satu ID relasi lama yang tersedia, termasuk ID nol
pada data legacy. Relasi arsip lain tetap tersimpan. Pengiriman ulang
mengembalikan receipt yang sama tanpa mengubah waktu arsip/roster.

Peserta yang tidak lagi memenuhi syarat tidak ditambahkan sebagai anggota
baru. Keanggotaan lama tetap dapat ditinjau atau dinonaktifkan secara
eksplisit. Perubahan kelayakan tidak otomatis menghapus biodata/riwayat.

## Sumber sekolah dan data lama

Untuk peserta dari master keluarga, kelayakan berasal dari relasi current
dan status sekolah di `family_students`. Data `santri_detail` lama tidak
menggantikan sumber tersebut ketika view keluarga belum tersedia. Untuk
peserta lokal yang belum dipetakan ke master, kelas legacy tetap menjadi
sumber; kelas kosong atau Lulus tidak memenuhi syarat penempatan baru.

Pembacaan katalog menggunakan sumber yang sama. Pada penyimpanan, parent,
keanggotaan, serta baris wali/relasi/anak sumber dikunci dan diperiksa ulang.
Formulir peserta juga memakai pemeriksaan ini untuk penempatan baru;
pilihan **Pertahankan penempatan saat ini** tidak menebak perubahan roster.

Identitas program dibaca dari kolom `halaqoh.program_code`, terpisah dari
kesiapan autosave. Program Ibu tidak diperlakukan sebagai Bapak hanya karena
trigger autosave belum tersedia. Program yang sudah mempunyai referensi
tetap tidak dapat diganti melalui pengelolaan halaqoh.

## Transaksi dan jurnal

- Hak admin/PJ diperiksa kembali dari database di dalam transaksi.
- Token konfirmasi terikat akun/sesi selama 30 menit, maksimum delapan tab,
  dan memerlukan CSRF. Snapshot mengikat peserta, relasi, histori roster,
  sumber sekolah, serta halaqoh/pengampu/program.
- Perubahan bersamaan, termasuk arsip lalu aktivasi kembali dengan nilai
  relasi yang tampak sama, menolak konfirmasi basi sebagai HTTP 409.
- Layanan memakai mutex peserta yang sama dengan formulir peserta dan
  penggabungan. Dua jalur tidak bisa menempatkan orang yang sama ke dua
  kelompok baru dalam satu program secara bersamaan.
- `participant_changes` menyimpan aksi `membership_add`/`membership_remove`,
  sebelum/sesudah dan receipt atomik. Riwayat anggota tersedia melalui
  tautan **Lihat riwayat sebelum/sesudah** dan unduhan JSON privat.
- Riwayat membedakan anak lokal dan sumber sekolah keluarga. Anak dari master
  ditampilkan dari snapshot pada waktu tindakan, sehingga view saat ini tidak
  mengubah atau menghilangkan tampilan sumber historis tersebut.
- Snapshot `membership_source` menyimpan seluruh kolom sumber dengan base64,
  termasuk NULL, biner dan kolom tambahan. Versi yang terbaca manusia
  menampilkan nilai biner sebagai hex; sidecar mempertahankan bytes aslinya.
- Kegagalan jurnal membatalkan relasi dan perubahan interval roster bersama.
  SQL mode, transaksi dan lock dipulihkan. Informasi SQL tidak ditampilkan.

## Persiapan dan pemeriksaan

Tidak ada tabel baru khusus halaman anggota. Struktur arsip/roster dan jurnal
peserta yang dipakai rilis harus tersedia. Periksa cadangan dan pemulihan
terpisah, lalu siapkan staging sesuai panduan:

```powershell
php migrate_tahsin_reporting.php
php migrate_participant_management.php
```

Jalankan opsi `--apply` hanya pada database tujuan yang sudah diperiksa dan
dicadangkan. Untuk program Ibu, kolom program disiapkan oleh migrasi autosave;
lihat [AUTOSAVE_ROLLOUT.md](AUTOSAVE_ROLLOUT.md). Master keluarga tetap mengikuti
[FAMILY_IMPORT.md](FAMILY_IMPORT.md). GET biasa tidak menjalankan DDL.

Tanpa jurnal/histori yang siap, daftar lama tetap dapat dibaca dan mutasi
ditolak dengan penjelasan. Endpoint POST lama tanpa token konfirmasi juga
ditolak. Backup penuh tetap mencakup jurnal peserta; fixture pemulihan
melakukan penambahan anggota nyata sebelum membandingkan seluruh tabel.

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/membership-integration.php
node tests/membership-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-management-integration.php
php -d extension=pdo_mysql tests/backup-integration.php
```

Semua suite memakai database sintetis baru di loopback `127.0.0.1:13317`.
Pengujian tidak menggunakan konfigurasi, identitas, atau database produksi.
Penerimaan seluruh fitur, runtime produksi dan pemulihan cadangan produksi
nyata tetap diperlukan untuk audit menyeluruh.
