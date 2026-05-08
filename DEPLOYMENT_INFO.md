# Panduan Deployment - Presensi Tahsin Bapak
Domain: `tahsin.griyaquran.web.id`

Ikuti langkah-langkah berikut untuk memasang aplikasi ini di cPanel Hosting:

## 1. Persiapan Database
1.  Login ke cPanel Anda.
2.  Buka menu **MySQL Database Wizard**.
3.  Buat database baru (misal: `griyaqur_presensi_tahsin`).
4.  Buat user database baru (misal: `griyaqur_user_tahsin`) dan catat passwordnya.
5.  Berikan **All Privileges** kepada user tersebut untuk database yang baru dibuat.

## 2. Import Struktur Database
1.  Buka menu **phpMyAdmin** di cPanel.
2.  Pilih database yang baru Anda buat di kolom sebelah kiri.
3.  Klik tab **Import** di bagian atas.
4.  Klik **Choose File** dan pilih file `database.sql` dari folder project ini.
5.  Gulir ke bawah dan klik **Go** atau **Import**.

## 3. Upload File ke hosting
1.  Compress seluruh file di folder ini (kecuali folder `.git`, `.vscode`, atau file `.env` jika ada) menjadi format `.zip`.
2.  Di cPanel, buka **File Manager**.
3.  Masuk ke direktori domain Anda (biasanya `public_html/tahsin` atau sesuai setting subdomain).
4.  Klik **Upload** dan pilih file `.zip` tadi.
5.  Setelah selesai, klik kanan pada file `.zip` tersebut dan pilih **Extract**.

## 4. Konfigurasi Database di Hosting
1.  Di File Manager, cari file `config/database.php`.
2.  Klik kanan dan pilih **Edit**.
3.  Sesuaikan nilai berikut dengan data database yang Anda buat di langkah 1:
    ```php
    $host = 'localhost';
    $db   = 'griyaqur_presensi_tahsin'; // Nama database Anda
    $user = 'griyaqur_user_tahsin';    // Username database Anda
    $pass = 'PASSWORD_ANDA';           // Password database Anda
    ```
4.  Klik **Save Changes**.

## 5. Verifikasi
1.  Buka browser dan akses `https://tahsin.griyaquran.web.id`.
2.  Login menggunakan akun default:
    - **Username**: `admin`
    - **Password**: `admin123`
3.  Pastikan untuk segera mengubah password admin setelah login pertama kali di menu Data User.

---
**Catatan Tambahan:**
- Pastikan versi PHP di hosting minimal **PHP 7.4** (disarankan **8.1** atau lebih tinggi).
- Jika ada kendala "Koneksi database gagal", periksa kembali username, password, dan nama database di `config/database.php`.
