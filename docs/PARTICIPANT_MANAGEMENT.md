# Formulir dan riwayat peserta

## Persiapan rilis

Jalankan pada staging setelah cadangan tersedia, lalu pada produksi saat rilis:

```powershell
php migrate_participant_management.php
php migrate_participant_management.php --apply
```

Dry-run tidak membuka database. Apply menambahkan tabel InnoDB
`participant_changes` beserta indeks dan referensinya. Tabel peserta, anak,
presensi, draft dan keanggotaan tidak direset. Migrasi menolak transaksi aktif
agar DDL tidak melakukan commit atas pekerjaan lain. Skema alumni/roster yang
dipakai versi aplikasi ini tetap diperlukan, termasuk `archived_at` dan
`archive_reason`. Tanpa jurnal, formulir menjelaskan persiapan yang diperlukan
dan menolak penyimpanan.

## Alur pengguna

- **Data Peserta → Tambah Peserta / Edit** membuka halaman formulir dengan
  label dan kontrol HTML biasa. Penyimpanan dan penambahan baris anak tidak
  membutuhkan JavaScript. Filter daftar menggunakan elemen `details` native.
  Daftar dipaginasi 50 peserta per halaman, dengan filter tetap tersimpan pada
  tautan halaman. Anak tanpa kelas tetap ditampilkan; daftar anak panjang
  dapat dibuka seluruhnya tanpa pemotongan `GROUP_CONCAT`.
- Kesalahan validasi menampilkan kembali isian. Tombol **Batal** kembali ke
  daftar tanpa menyimpan. Tambah baris anak hanya mengubah formulir.
- Maksimal 100 baris anak, termasuk baris kosong baru. Wali tanpa anak boleh
  disimpan; baris baru yang kosong dilewati. Nomor HP bersifat opsional.
- Edit nama/kelas memperbarui ID anak yang sama. Anak lama yang hilang dari
  POST ditolak sebagai formulir tidak lengkap, bukan dianggap ingin dihapus.
- Untuk menghapus data anak yang keliru, centang anak tersebut dan konfirmasi
  penghapusan. Anak dengan referensi foreign key, termasuk `CASCADE` dan
  constraint gabungan, tidak dapat dihapus. Snapshot sebelumnya tetap dicatat
  dalam jurnal untuk penghapusan yang diizinkan. Anak alumni cukup diberi
  kelas **Lulus**.
- Nama/kontak/alamat/anak dari master keluarga atau Data Induk tetap dilindungi
  walaupun view keluarga belum siap atau integrasi dimatikan. Mode Data Induk
  aktif juga mengunci biodata lokal dan pembuatan baru saat konfigurasi pusat
  belum lengkap. Kategori, informasi Tahsin Luar dan halaqoh adalah pengaturan
  lokal yang dapat diedit. Tidak ada panggilan API pusat dari formulir.

## Keanggotaan dan arsip

Penempatan program Bapak dan Ibu dipilih terpisah. Default mempertahankan
keanggotaan. Memilih halaqoh yang sama tidak menutup/membuka ulang histori;
memindahkan satu program tidak menutup program lain. Keanggotaan lama
diarsipkan, bukan dihapus. Halaqoh divalidasi ulang terhadap programnya.

**Arsipkan** membuka halaman konfirmasi dan hanya mengubah status aktif serta
menutup keanggotaan aktif. Anak, presensi dan draft tetap tersimpan. Identitas
pusat diarsipkan dari sumbernya. Wali dengan seluruh anak Lulus mengikuti
aturan alumni otomatis. Catatan Lanjut Tahsin lama dipertahankan.

Wali arsip tidak aktif kembali hanya karena disimpan. Aktivasi eksplisit
memerlukan anak dengan kelas sekolah aktif. Membership lama tidak aktif
otomatis; pengguna dapat memilih penempatan baru pada formulir yang sama.

## Transaksi dan perubahan bersamaan

`includes/participant_helper.php` memisahkan validasi dan mutasi dari halaman.
Formulir server menyimpan ID target, fingerprint dan token acak selama satu jam,
terikat akun/sesi; maksimal sepuluh formulir tersimpan. CSRF diperiksa terpisah.

Service memeriksa peran akun terkini, mengambil advisory lock yang sama dengan
impor CSV, lalu mengunci baris peserta, anak dan membership. Engine tabel harus
InnoDB dan SQL mode ketat dipakai selama operasi. Perubahan domain, trigger
histori dan jurnal berada dalam satu transaksi. Kegagalan jurnal membatalkan
semuanya. ID operasi dan hash permintaan mencegah simpan ulang membuat
peserta/anak ganda. SQL mode dan lock dipulihkan setelah selesai/gagal.

Perubahan peserta/anak/membership/mapping atau mode sumber sejak formulir
dibuka menolak simpan. Isian tetap terlihat; pengguna membuka formulir terbaru
dan membandingkan perubahan sebelum mengirim ulang. Fingerprint tidak diganti
otomatis pada error. Nilai NULL dan spasi existing yang tidak diedit dipertahankan.

## Riwayat dan pemulihan

**Riwayat perubahan** menampilkan halaman sebelum/sesudah dan keanggotaan,
dipaginasi 20 operasi per halaman, serta unduhan JSON privat. Hanya admin/PJ
yang dapat membuka jurnal. Ia mencakup formulir sejak migrasi dipasang;
impor CSV memiliki jurnal tersendiri. Perubahan lama tidak direkonstruksi.

Jurnal menyimpan pelaku, waktu, operasi, hasil, serta snapshot sebelum/sesudah.
Gabungan snapshot dibatasi 8 MB; bila terlampaui transaksi dibatalkan.
Unduhan mempertahankan kolom snapshot JSON asli agar data rusak sekalipun
dapat diperiksa pengelola. Jurnal berisi data pribadi dan ikut backup database.
Jurnal bukan tombol undo otomatis. Pemulihan manual harus memeriksa relasi,
ID, konflik dan perubahan sesudahnya pada database terpisah terlebih dahulu.

## Pemeriksaan

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-management-integration.php
node tests/security-http-tests.cjs
php -d extension=pdo_mysql -d memory_limit=256M tests/backup-integration.php
```

Suite memakai database sintetis baru pada loopback `127.0.0.1:13317`.
Artefak service berada pada
`.sessions/audit-20261023/participant-management-*/results.json`.
Pemeriksaan HTTP tidak membuktikan layout, interaksi browser, atau Apache.
