# Panduan Deployment cPanel - Presensi Tahsin

Informasi ini disiapkan untuk membantu Anda melakukan proses upload ke cPanel tanpa membawa data dummy.

## 1. Persiapan Database (phpMyAdmin)
Gunakan file `database_production.sql` yang baru saja saya buat. File ini berisi struktur tabel lengkap (termasuk fitur terbaru: Tahsin Luar, Log Aktivitas, Setting Rapor, dan Daftar Capaian) namun **tanpa data dummy**.

**Langkah-langkah:**
1. Masuk ke **cPanel** > **MySQL® Databases**.
2. Buat database baru (misal: `tahsin_db`).
3. Buat user database baru dan hubungkan ke database tersebut (pastikan beri hak akses ALL PRIVILEGES).
4. Masuk ke **phpMyAdmin**, pilih database tersebut, lalu klik menu **Import**.
5. Pilih file `database_production.sql` dan jalankan.

**Akun Admin Awal:**
*   Username: `admin`
*   Password: `admin123` (Segera ganti setelah login di menu Data User).

## 2. Konfigurasi Koneksi
Sebelum diupload, edit file `config/database.php` dan sesuaikan dengan data database di hosting Anda:
```php
$host = 'localhost';
$db   = 'nama_database_anda';
$user = 'username_database_anda';
$pass = 'password_database_anda';
```

## 3. Daftar File yang PERLU di-Upload
Anda bisa mengompres semua file dalam direktori ini kecuali file-file berikut yang bersifat internal/development:
*   `database.sql` (file skema lama)
*   `migrate.php` atau file berawalan `migrate_...` (tidak diperlukan untuk install baru)
*   `.gemini` (jika ada)
*   `Presensi Tahsin Bapak.zip` (lama)

**Struktur folder utama yang harus ada:**
*   `/config`
*   `/includes`
*   `/templates`
*   `/ustadz`
*   Semua file `.php` utama di root.
*   `.htaccess` (Penting untuk keamanan folder)
*   `manifest.json`, `sw.js`, `icon-512.png` (Untuk fitur web app/PWA)

## 4. Tips Tambahan
*   Pastikan versi PHP di cPanel minimal **8.1** atau **8.2**.
*   Pastikan ekstensi **PDO** dan **PDO_MySQL** aktif.
*   Jika menggunakan pengumuman/broadcast, pastikan folder root tersebut memiliki izin akses yang tepat (biasanya 755 untuk folder dan 644 untuk file).

## 5. Fitur Otomatisasi (n8n API)
Terdapat API di `/api/notifications-pending.php` untuk integrasi n8n (pengingat WhatsApp). 
*   **Security Key**: Harap buka file tersebut dan ganti `$apiKey = "tahsin_secure_key_123"` dengan kunci rahasia Anda sendiri demi keamanan.

Semoga sukses proses upload-nya! Jika ada error koneksi setelah upload, cek kembali penulisan username/password database di `config/database.php`.
