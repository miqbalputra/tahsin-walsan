# Impor keluarga Anak–Bapak–Ibu

Paket `tahsin-family-v1` membawa wali, anak, serta relasinya menggunakan ID sumber yang stabil. Seorang anak memiliki satu identitas dan dapat terhubung ke Bapak serta Ibu. Masing-masing wali tetap mempunyai `wali_santri.id`, penugasan, presensi, dan capaian Tahsin sendiri.

## Paket yang disiapkan

Master lokal diekspor 15 Oktober 2026: 418 wali (209 Bapak, 209 Ibu), 266 anak (227 aktif, 36 lulus, 3 keluar), dan 529 relasi. Dua anak belum mempunyai relasi orang tua lengkap; lihat workbook pemeriksaan. Nama atau relasi yang tidak ada di sumber tidak dibuat berdasarkan perkiraan. NIK, akun login, QR, dan kata sandi tidak dimasukkan.

- `keluarga-tahsin.json`: paket yang diunggah ke menu **Keluarga Anak–Bapak–Ibu**.
- `wali.csv`, `anak.csv`, `relasi.csv`: salinan tabel untuk pemeriksaan / integrasi sistem lain. Bukan format import-peserta.php lama.
- Workbook: keluarga per anak, wali, relasi, kekurangan sumber, dan kandidat pemetaan wali lama.
- `validation.json`: hasil pemeriksaan dan checksum sumber.
- `peserta-aktif.csv`: daftar operasional 183 Bapak dan 184 Ibu yang sedikitnya satu anaknya masih memiliki kelas. `anak-aktif.csv` berisi 227 anak berkelas. Master JSON tetap lengkap, termasuk arsip sebagai referensi; impor master tidak membuat penugasan kelompok untuk mereka.
- `kakak-beradik.csv` dan tab **Kakak Beradik**: 50 keluarga, yaitu 44 keluarga dengan dua anak dan 6 keluarga dengan tiga anak. Ada 14 keluarga dengan anak lulus sekaligus anak aktif; orang tuanya tetap di daftar aktif. Relasi saudara menggunakan ID wali/anak, bukan nama yang mirip. Anak keluar dibedakan dari anak lulus.

Dalam snapshot ini semua anak yang masih memiliki kelas berstatus `active`, dan semua wali yang terhubung ke anak berkelas berstatus aktif di master. Tidak memiliki kelas tidak otomatis berarti lulus: gunakan status sekolah yang tercantum. Arsip lulusan diterapkan jika seluruh anak sudah lulus; adanya adik berkelas mempertahankan wali sebagai peserta aktif. Nomor akun aktif tetap merupakan status akun, bukan penentu tunggal kelulusan anak.

File pribadi berada di folder keluaran lokal yang diabaikan Git. Jangan unggah direktori keluaran itu ke webroot atau Git.

## Aktivasi dan impor

1. Backup database tujuan dengan fitur Backup & Restore. Semua tabel fisik keluarga ikut ditemukan oleh mekanisme backup yang ada.
2. Deploy kode, lalu jalankan `php migrate_family.php` untuk membaca rencana. Jalankan `php migrate_family.php --apply` untuk menambah lima tabel dan view. Migrasi tidak mengubah data lama dan dapat diulang. Akun database memerlukan izin CREATE TABLE dan CREATE VIEW.
3. Login sebagai admin / PJ Tahfidz, buka **Keluarga Anak–Bapak–Ibu**, unggah paket JSON dan periksa pratinjau.
4. Tinjau kandidat wali yang belum pasti. Nama saja tidak cukup. Tiga kecocokan—nama, HP, dan anak—memakai ID wali lama secara otomatis. Kandidat ganda, HP berubah, atau nama berbeda memerlukan pilihan ID lokal / buat orang baru. Semua konflik harus selesai sebelum impor dijalankan.
5. Klik **Impor Keluarga**. Server memeriksa ulang kondisi database dalam transaksi dan mengunci impor bersamaan. Pengulangan paket yang sama tidak menggandakan wali, anak, atau relasi.
6. Penempatan Ibu ke kelompok dilakukan terpisah sesuai data pengajar/jadwal. Impor identitas tidak membuat penugasan atau catatan presensi/capaian.

Pratinjau lokal membandingkan master dengan backup **30 Agustus 2026**, bukan keadaan produksi terkini: 150 wali cocok kuat, 32 perlu ditinjau, dan 236 baru. Angka ini bukan persetujuan untuk memakai ID backup di produksi. Pratinjau pada database tujuan harus dijalankan lagi.

Alternatif CLI (dry-run adalah default):

```sh
php scripts/import-family.php --file=/lokasi/keluarga-tahsin.json
php scripts/import-family.php --file=/lokasi/keluarga-tahsin.json --mapping=/lokasi/pilihan.json --apply
```

Contoh `pilihan.json`: `{"parent-id-sumber":"id-wali-lokal","parent-id-lain":"new"}`. Pilihan mengikat identitas orang; periksa sebelum apply. Output pratinjau CLI mengandung data pribadi, simpan secara terbatas.

## Integritas dan sumber identitas

- Tabel `wali_santri`, `santri_detail`, `presensi`, dan `halaqoh_members` lama tidak ditulis ulang oleh impor keluarga. Wali baru ditambahkan sesuai status sumber dan anak aktif; status/kategori wali lama tetap dipertahankan.
- Identitas sekolah disimpan di `family_students`; relasi banyak orang tua di `family_relations`. `family_legacy_children` hanya menghubungkan nama anak yang cocok unik di dalam wali yang sudah dikenal. Anak lokal yang tidak cocok tetap terbaca sebagai data lama.
- Halaman peserta, daftar kelompok, guru, capaian, rapor, dan laporan memakai view anak bersama setelah migrasi. Tanpa migrasi, pembacaan lama tetap berjalan. Agregasi presensi tidak melakukan join anak langsung yang memperbanyak jumlah kehadiran.
- Identitas wali yang terhubung master tidak dapat ditimpa lewat form peserta atau digabung melalui fitur duplikat. Kelas/identitas sekolah diperbarui dengan ekspor master baru. Fitur naik kelas lokal menolak anak yang sudah dikelola master agar perubahan tidak menjadi tersembunyi oleh view.
- Snapshot lebih lama ditolak. Isi berbeda pada tanggal snapshot yang sama juga ditolak. Gunakan ekspor lengkap bertanggal lebih baru untuk pembaruan. Relasi yang hilang dari snapshot baru ditandai tidak aktif; rekaman lama dipertahankan.
- ID numerik ekspor sekolah berada di namespace `wali-santri-export`. ID itu tidak disalin ke kolom UUID `data_induk_*`. Sinkronisasi Data Induk lama tidak boleh menimpa identitas yang dikelola master keluarga; konflik perlu dipetakan sebelum kedua sumber dapat dipakai bersama. Belum ada sinkronisasi otomatis lintas aplikasi yang dijadwalkan.

## Pusat laporan mendatang

Endpoint GET `api/hermes.php?action=keluarga&page=1&limit=50&search=...` memakai autentikasi `X-API-Key` yang sama dengan API yang ada. Respons berisi satu anak per baris, ID sumber bernamespace, status sekolah, snapshot, dan daftar wali beserta `wali_santri_id`. Pusat laporan dapat menghubungkan presensi/capaian Tahsin melalui ID wali lokal serta menghubungkan data anak melalui `(source_namespace, source_student_id)`. Jangan menggunakan nama sebagai kunci integrasi. Endpoint keluarga tidak mengirim NIK, QR, password atau kredensial login.

Detail API peserta mempertahankan properti `anak` beserta ID anak lokal yang lama. Properti tambahan `anak_terpadu` menyediakan anak dari view keluarga. Integrasi pusat yang baru sebaiknya memakai endpoint `keluarga` untuk menghitung anak unik.

## Verifikasi

`php -d extension=php_pdo_mysql.dll tests/family-integration.php` memakai MariaDB localhost port 13317 dengan database `tahsin_test_family_*`, bukan konfigurasi produksi. Menguji pencocokan ambigu, dua orang tua, saudara kandung, pengulangan, transaksi ditolak, snapshot kedaluwarsa, pencarian/filter, anak lokal yang belum terpetakan, dan keutuhan catatan Bapak. Jalankan juga regresi Tahsin serta autosave yang sudah ada.
