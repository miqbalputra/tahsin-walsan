# Impor peserta CSV

## Menyiapkan fitur

Jalankan dari CLI setelah cadangan dan pemeriksaan staging:

```powershell
php migrate_participant_import.php
php migrate_participant_import.php --apply
```

Dry-run tidak membuka koneksi database. Apply hanya menambah tabel
`participant_import_batches`; tidak mereset peserta, anak, atau presensi.
Struktur alumni/keanggotaan versi aplikasi saat ini tetap diperlukan,
termasuk kolom `archived_at` dan `archive_reason` pada `halaqoh_members`.
Migrasi menolak transaksi aktif agar DDL tidak meng-commit perubahan lain.

## Alur pengguna

1. Buka **Data Peserta → Impor CSV** dan unduh template jika diperlukan.
2. Unggah CSV UTF-8. Koma dan titik koma, BOM UTF-8, serta penanda Excel
   `sep=,`/`sep=;` didukung. Kutip, newline di dalam sel dan backslash tidak
   dipotong oleh batas 1000 byte yang dipakai implementasi sebelumnya.
3. Periksa pengelompokan wali dan anak. Untuk nama/telepon yang cocok
   tunggal, peserta existing dipilih. Nama yang ambigu atau nomor kosong
   memerlukan pilihan. Nomor Indonesia `0...` dan `62...` dibandingkan
   secara normalisasi; nilai telepon yang tersimpan tidak diubah otomatis.
4. Pilih perilaku kelas dan biodata. Default mempertahankan data existing.
5. Centang konfirmasi dan simpan. Hasil menunjukkan peserta/anak baru,
   baris yang dilewati, pembaruan kelas/biodata, dan pengarsipan alumni.

Pratinjau berlaku 30 menit dan terikat ke sesi serta akun pembuatnya.
Token klien tidak memuat baris CSV; data bersumber dari pratinjau server.
Jika data terkait berubah, penyimpanan ditolak dan pilihan harus ditinjau
ulang. Membatalkan pratinjau tidak mengubah data domain.

## Format dan batas

Kolom wajib: `nama_bapak,no_hp,alamat,kategori,nama_anak,kelas`.
Kolom opsional: `tempat_tahsin,ustadz_luar`. Urutan kolom boleh berbeda;
header ganda, kolom asing dan jumlah sel yang tidak sesuai ditolak.
Satu baris berisi satu anak. Wali tanpa anak dapat memakai sel anak/kelas
kosong. Nama wali wajib diisi. Kategori kosong berarti `reguler`; nilai
lain harus `reguler`, `askar`, atau `tahsin_luar`.

Maksimal 5 MB, 5.000 baris data dan 500 kelompok keluarga per pratinjau.
Jika `max_input_vars` PHP lebih kecil, batas keluarga mengikuti kapasitas
formulir supaya pilihan tidak terpotong. Record CSV dibatasi 64 KB; batas
kolom mengikuti aplikasi, termasuk nama 100, kelas/telepon 20, alamat
5.000, tempat tahsin 255, dan nama pengajar luar 100 karakter.

Jurnal data sumber + sebelum/sesudah maksimal 12 MB per batch. Jika
riwayat data existing sangat besar, seluruh transaksi dibatalkan dengan
petunjuk untuk memecah CSV menjadi lebih sedikit keluarga. Baris tanpa
perubahan memakai penanda ringkas; data yang benar-benar berubah tetap
memiliki snapshot sebelumnya. Batas CSV backup 32 MB per record menampung
payload jurnal beserta penggandaan kutip CSV.

## Identitas, kelas dan pengulangan

- Pemilihan peserta tidak didasarkan pada nama saja. Nama yang sama tidak
  membuktikan orang yang sama; preview menampilkan telepon, anak dan ID.
- Dua kelompok CSV dapat secara eksplisit diarahkan ke peserta existing
  yang sama. Eksekusi menggabungkan barisnya, menghitung peserta sekali,
  dan menolak biodata/kelas yang saling bertentangan.
- Anak dengan nama dan kelas yang sudah cocok dilewati. Mengunggah ulang
  CSV tidak menambah anak identik pada peserta yang sama.
- Untuk anak existing dengan nama sama dan kelas berbeda, pilih tetap
  memakai kelas lama, memperbarui kelas pada ID anak yang sama, atau
  menambahkan sebagai anak berbeda. Pembaruan ambigu tidak dipilih otomatis.
- Anak baru bernama sama tetapi kelas berbeda tetap menjadi dua baris anak.
  Pembaruan kelas dari dua nilai CSV yang bertentangan ditolak, termasuk
  ketika berasal dari dua kelompok yang dipetakan ke peserta yang sama.
- Kolom opsional yang tidak disertakan tetap dipertahankan. Jika biodata
  dipilih untuk diganti, sel kosong pada kolom yang disertakan memang
  mengosongkan nilai tersebut; nilai sebelumnya tetap dicatat dalam jurnal.
- Impor ulang yang tidak mengubah anak tidak mengaktifkan wali arsip.
  Anak aktif baru, atau perubahan dari Lulus/kelas kosong ke kelas aktif,
  dapat mengaktifkan wali kembali. Membership lama tidak diaktifkan otomatis.
  Wali yang seluruh anaknya Lulus mengikuti aturan arsip alumni aplikasi.

## Sumber identitas pusat

Ketika `DATA_INDUK_ENABLED` aktif, impor biodata CSV diblokir sekalipun
konfigurasi atau skema Data Induk belum lengkap. Gunakan sinkronisasi sumber.
Mapping master keluarga dan UUID wali/anak tetap dilindungi ketika mode
integrasi dimatikan atau view keluarga belum selesai dipasang.

Kelompok yang dikelola sumber pusat dapat dilewati, sehingga keluarga lokal
lain dalam CSV tetap dapat diimpor. Mengganti pilihan lewat POST atau membuat
duplikat lokal dengan nama/telepon master yang sama tidak melewati perlindungan.
Tidak ada permintaan HTTP ke Data Induk selama pratinjau atau impor CSV.

## Transaksi, jurnal dan pemeliharaan

`includes/participant_import_helper.php` menangani parsing/plan, validasi,
pilihan identitas, transaksi, dan jurnal. CSV streaming serta pemeriksaan
engine/SQL mode dipakai bersama fitur backup melalui `csv_helper.php` dan
`database_helper.php`. Controller menangani sesi, otorisasi, HTTP dan tampilan.

Service memeriksa ulang peran admin/PJ, memakai advisory lock per database,
mengunci baris terkait, dan memvalidasi fingerprint preview. Engine tabel
harus InnoDB dan SQL mode ketat mencegah pemotongan nilai diam-diam.
Operasi yang diulang dengan ID batch serta pilihan sama mengembalikan
receipt existing; ID yang sama dengan pilihan lain ditolak.

Data sumber setelah validasi/normalisasi, perubahan sebelum/sesudah,
pembuat dan ringkasan disimpan dalam transaksi yang sama. Kegagalan
penyimpanan jurnal membatalkan perubahan domain. Tidak ada DELETE peserta,
anak atau presensi dalam service impor. Jurnal keluarga yang dilewati atau
tidak berubah menyebut keputusan tersebut tanpa menyalin seluruh biodata.

Riwayat dipaginasi dan menyediakan tampilan sebelum/sesudah serta unduhan
JSON untuk arsip. Halaman rincian menampilkan snapshot pada saat impor,
bukan data hidup yang mungkin sudah berubah. Akses memerlukan admin/PJ;
guru dan pengunjung tidak dapat mengunduh jurnal. Unduhan berisi data pribadi.
Jurnal ikut dalam backup database; ia bukan tombol undo otomatis.

## Pemeriksaan lokal

```powershell
php -d extension=pdo_mysql -d memory_limit=256M tests/participant-import-integration.php
php -d extension=pdo_mysql -d memory_limit=256M tests/backup-integration.php
node tests/security-http-tests.cjs
```

Suite memakai database sintetis baru pada `127.0.0.1:13317` dan tidak membaca
konfigurasi koneksi produksi. Bukti hasil impor disimpan pada
`.sessions/audit-20261023/participant-import-*/results.json`.
Pemeriksaan HTTP tidak membuktikan interaksi browser atau layout visual.
