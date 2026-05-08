# Panduan Deployment VPS Coolify

Panduan ini untuk deploy aplikasi **Presensi Tahsin Bapak** ke VPS melalui Coolify.

Aplikasi ini adalah PHP native dengan database MySQL/MariaDB. Database produksi ada di file lokal `db_tahsinbpk.sql` dan sebaiknya di-import lewat Coolify atau phpMyAdmin/Adminer, bukan disimpan di image aplikasi.

## 1. Siapkan Database di Coolify

1. Buat resource baru: **Database > MySQL** atau **MariaDB**.
2. Catat credential database:
   - Host/service name
   - Port
   - Database
   - Username
   - Password
3. Import file `db_tahsinbpk.sql` ke database tersebut.

Contoh import dari terminal VPS/container database:

```bash
mysql -h DB_HOST -P 3306 -u DB_USERNAME -p DB_DATABASE < db_tahsinbpk.sql
```

Jika memakai fitur import di Coolify/Adminer/phpMyAdmin, pilih database yang sudah dibuat lalu upload `db_tahsinbpk.sql`.

## 2. Deploy Aplikasi

Di Coolify:

1. Buat resource **Application**.
2. Pilih repository:

```text
https://github.com/miqbalputra/tahsin-walsan.git
```

3. Build pack: **Dockerfile**.
4. Port aplikasi: `80`.
5. Health check path: `/login.php`.

Dockerfile sudah menyiapkan:

- PHP 8.2 + Apache
- Extension `pdo_mysql`, `mysqli`, `intl`, dan `zip`
- Apache module `headers` dan `rewrite`
- `.htaccess` aktif melalui `AllowOverride All`

## 3. Environment Variables

Set variabel berikut di menu **Environment Variables** aplikasi Coolify:

```env
APP_TIMEZONE=Asia/Jakarta

DB_HOST=nama-service-database-coolify
DB_PORT=3306
DB_DATABASE=db_tahsinbpk
DB_USERNAME=user_database
DB_PASSWORD=password_database
DB_CHARSET=utf8mb4

SESSION_SAVE_PATH=/var/www/html/.sessions
N8N_API_KEY=ganti_dengan_secret_panjang
```

Catatan:

- `DB_HOST` biasanya memakai nama service database internal Coolify, bukan `localhost`.
- `N8N_API_KEY` dipakai oleh endpoint:
  - `/api/notifications-pending.php`
  - `/api/daily-reminder.php`
- Saat memanggil API dari n8n, kirim header:

```text
X-API-KEY: isi_N8N_API_KEY
```

atau query string:

```text
?key=isi_N8N_API_KEY
```

## 4. Volume Mount Agar Data Tidak Hilang

Database tidak disimpan di container aplikasi. Data database aman jika memakai resource MySQL/MariaDB Coolify karena database punya storage sendiri.

Untuk aplikasi ini, data lokal yang perlu dibuat persisten adalah **session login**. Aplikasi menyimpan session di folder `.sessions` agar login tidak sering hilang. Mount volume berikut di aplikasi Coolify:

```text
Source volume: presensi-tahsin-sessions
Mount path: /var/www/html/.sessions
```

Langkah di Coolify:

1. Buka aplikasi **Presensi Tahsin Bapak**.
2. Masuk ke tab **Storage** atau **Persistent Storage**.
3. Tambah storage baru.
4. Pilih tipe **Volume**.
5. Isi mount path:

```text
/var/www/html/.sessions
```

6. Deploy ulang aplikasi.

Permission folder akan disiapkan otomatis oleh `docker/entrypoint.sh`.

## 5. File yang Tidak Ikut Dipush / Tidak Ikut Image

File berikut sengaja diabaikan oleh `.gitignore` dan `.dockerignore` karena berpotensi berisi data produksi atau artefak besar:

- `db_tahsinbpk.sql`
- `import_database*.sql`
- `import_mustawa*.sql`
- `*.zip`
- `.sessions/`
- `temp_excel/node_modules/`
- `Mustawa_*.xlsx`

Simpan `db_tahsinbpk.sql` di lokal/VPS hanya untuk proses import database.

## 6. Setelah Deploy

1. Buka domain aplikasi.
2. Pastikan halaman login tampil.
3. Login memakai akun yang ada di database hasil import.
4. Cek menu dashboard, laporan, halaqoh, peserta, dan form ustadz.
5. Jika integrasi n8n dipakai, update `N8N_API_KEY` di workflow n8n.

## 7. Troubleshooting

Jika muncul error koneksi database:

- Pastikan `DB_HOST` adalah hostname service database Coolify.
- Pastikan database sudah di-import.
- Pastikan username dan password punya akses ke database.

Jika login sering keluar setelah redeploy:

- Pastikan volume `/var/www/html/.sessions` sudah terpasang.
- Pastikan `SESSION_SAVE_PATH=/var/www/html/.sessions`.

Jika API n8n mengembalikan `Unauthorized`:

- Pastikan header `X-API-KEY` sama dengan `N8N_API_KEY` di Coolify.

Jika file SQL/ZIP terlihat dari browser:

- Pastikan aplikasi dibuild dari Dockerfile terbaru.
- File dump sebaiknya tidak berada di container aplikasi.
