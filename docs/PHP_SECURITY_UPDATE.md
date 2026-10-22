# Pembaruan keamanan PHP — 22 Oktober 2026

Dockerfile menggunakan image resmi `php:8.2.35-apache`. Rilis ini memperbaiki
CVE-2026-109618 dan CVE-2026-109619. Perubahan runtime ini tidak memerlukan
migrasi maupun impor ulang database.

Sumber resmi: [advisori PHP 21 Oktober 2026](https://www.php.net/archive/2026.php).

Versi minimum dengan perbaikan tersebut pada setiap cabang:

| Cabang | Versi patch |
| --- | --- |
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | 8.4.27 |
| 8.5 | 8.5.12 |

PHP 8.2 masih dipertahankan untuk kompatibilitas aplikasi. Dukungan keamanan
cabang ini berakhir pada 31 Desember 2026; perpindahan ke cabang berikutnya
perlu direncanakan dan diuji tersendiri.

## Pemeriksaan sebelum pembaruan

1. Di terminal container aplikasi Coolify, jalankan `php -v`. Versi CLI
   Windows berbeda dari runtime container yang melayani situs.
2. Pastikan database MySQL/MariaDB tetap menggunakan resource dan volume
   yang sama. Periksa juga volume `/var/www/html/.sessions`.
3. Buat backup baru melalui menu Backups pada resource database. Pastikan
   statusnya berhasil dan file tersimpan di luar container aplikasi.
4. Simpan jumlah baris seluruh tabel sebagai pembanding. Gunakan akun dan
   database produksi yang sama pada pemeriksaan setelah deployment.
5. Catat deployment dan image sebelumnya untuk pemulihan aplikasi.

## Build dan deployment

Gunakan Dockerfile yang sudah dipatch. Untuk build manual:

```bash
docker build --pull -t tahsin-walsan:php-8.2.35 .
```

Di Coolify, deploy commit yang memuat Dockerfile ini. Database dan volume
persistent tetap menggunakan resource existing. Entrypoint proyek hanya
menyiapkan direktori sesi lalu menjalankan Apache.

Jangan mengimpor ulang `database.sql`, menjalankan skrip migrasi, menghapus
resource database, atau menghapus volume untuk pembaruan runtime ini.

## Pemeriksaan setelah deployment

Di terminal container aktif:

```bash
php -v
curl -fsS http://127.0.0.1/health.php
```

Pastikan PHP 8.2.35 atau patch keamanan lebih baru dalam cabang 8.2, container
sehat, halaman login dapat dibuka, dan jumlah baris tabel tidak berkurang.
Perubahan jumlah akibat input pengguna tetap perlu diperhitungkan.

Jika aplikasi bermasalah, pulihkan deployment aplikasi sebelumnya dengan
resource database yang sama. Simpan backup baru; pemulihan database hanya
dilakukan jika data memang rusak, setelah memperhitungkan input terbaru.

## PHP Windows

Gunakan PHP 8.4.27 atau patch lebih baru pada cabang 8.4. Setelah mengubah PATH,
buka ulang terminal atau Codex karena proses yang sudah terbuka dapat tetap
menunjuk ke instalasi lama. Periksa menggunakan `php -v` dan `php --ini`.
Pertahankan `php.ini` dan ekstensi yang digunakan aplikasi. Paket Windows
resmi tersedia melalui [halaman unduhan PHP](https://www.php.net/downloads.php?os=windows).
