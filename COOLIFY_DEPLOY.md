# Panduan Deploy ke VPS Coolify

Panduan ini untuk memindahkan aplikasi **Presensi Tahsin Bapak** dari cPanel hosting ke VPS yang dikelola oleh Coolify.

Aplikasi ini memakai:

- PHP native + Apache, dibuild dari `Dockerfile`
- MySQL atau MariaDB
- File dump database existing: `db_tahsinbpk.sql`
- Session login di folder `.sessions`

Tujuan deployment:

- aplikasi bisa redeploy tanpa kehilangan data,
- database lama dari cPanel bisa di-import,
- session aplikasi punya volume mount sendiri.

## 1. Siapkan Repository

Pastikan repository yang dipakai Coolify berisi file berikut:

```text
Dockerfile
docker/apache.conf
docker/entrypoint.sh
.env.example
config/database.php
```

File `db_tahsinbpk.sql` tidak perlu ikut dipush ke Git dan tidak perlu masuk image Docker. File dump cukup disimpan di komputer lokal atau di VPS sementara untuk proses import.

Di repo ini `.dockerignore` sudah mengabaikan file `*.sql`, sehingga dump database tidak akan ikut masuk image aplikasi.

## 2. Buat Database di Coolify

Di dashboard Coolify:

1. Masuk ke project yang akan dipakai.
2. Klik **New Resource**.
3. Pilih **Database**.
4. Pilih **MySQL** atau **MariaDB**.
5. Buat database baru, misalnya:

```text
Database name : db_tahsinbpk
Username      : tahsinbpk
Password      : buat_password_yang_kuat
```

6. Deploy/start database tersebut.
7. Catat data koneksi database:

```text
Host internal
Port
Database name
Username
Password
```

Catatan penting:

- Untuk koneksi dari aplikasi ke database di dalam Coolify, gunakan **host internal/service name** database, bukan `localhost`.
- Data database aman saat aplikasi redeploy karena database berjalan sebagai resource terpisah dan memakai persistent storage milik database Coolify.
- Jangan menyimpan database di container aplikasi.

## 3. Import Database Existing

File yang di-import adalah:

```text
db_tahsinbpk.sql
```

Pilih salah satu cara berikut.

### Opsi A: Import Lewat UI Database Tool

Jika Coolify menyediakan akses database tool seperti phpMyAdmin/Adminer:

1. Buka database resource di Coolify.
2. Buka database tool.
3. Pilih database `db_tahsinbpk`.
4. Masuk menu **Import**.
5. Upload file `db_tahsinbpk.sql`.
6. Jalankan import.
7. Pastikan tabel dan data sudah muncul.

Ini cara paling aman dan mudah jika ukuran dump masih diterima oleh UI.

### Opsi B: Import Lewat Terminal VPS

Upload dulu `db_tahsinbpk.sql` ke VPS, misalnya ke folder:

```bash
/root/db_tahsinbpk.sql
```

Lalu jalankan dari VPS:

```bash
mysql -h HOST_DATABASE_INTERNAL -P 3306 -u USER_DATABASE -p NAMA_DATABASE < /root/db_tahsinbpk.sql
```

Contoh:

```bash
mysql -h mysql-presensi -P 3306 -u tahsinbpk -p db_tahsinbpk < /root/db_tahsinbpk.sql
```

Setelah menekan Enter, masukkan password database.

Jika command `mysql` belum tersedia di VPS, install client MySQL/MariaDB dulu:

```bash
apt update
apt install -y default-mysql-client
```

### Opsi C: Import dari Container Database

Jika ingin import dari container database langsung:

1. Cari nama container database di VPS:

```bash
docker ps
```

2. Copy file SQL ke container:

```bash
docker cp /root/db_tahsinbpk.sql NAMA_CONTAINER_DB:/tmp/db_tahsinbpk.sql
```

3. Jalankan import:

```bash
docker exec -it NAMA_CONTAINER_DB sh -c 'mysql -u USER_DATABASE -p NAMA_DATABASE < /tmp/db_tahsinbpk.sql'
```

Contoh:

```bash
docker exec -it presensi-db sh -c 'mysql -u tahsinbpk -p db_tahsinbpk < /tmp/db_tahsinbpk.sql'
```

## 4. Deploy Aplikasi di Coolify

Di Coolify:

1. Klik **New Resource**.
2. Pilih **Application**.
3. Pilih source dari Git repository.
4. Pilih branch yang ingin dideploy, misalnya `main`.
5. Build pack pilih **Dockerfile**.
6. Port aplikasi isi:

```text
80
```

7. Health check path isi:

```text
/login.php
```

8. Deploy aplikasi.

Dockerfile project ini sudah menyiapkan:

- PHP 8.2 Apache
- extension `pdo_mysql`, `mysqli`, `intl`, `zip`
- Apache module `headers` dan `rewrite`
- `.htaccess` aktif lewat `AllowOverride All`
- proteksi akses langsung ke file `.sql`, `.zip`, `.xlsx`, dan `.md`

## 5. Environment Variables Aplikasi

Di menu **Environment Variables** aplikasi Coolify, isi:

```env
APP_TIMEZONE=Asia/Jakarta

DB_HOST=host_internal_database_coolify
DB_PORT=3306
DB_DATABASE=db_tahsinbpk
DB_USERNAME=tahsinbpk
DB_PASSWORD=password_database
DB_CHARSET=utf8mb4

SESSION_SAVE_PATH=/var/www/html/.sessions
N8N_API_KEY=ganti_dengan_secret_panjang
```

Contoh jika host internal database dari Coolify adalah `mysql-presensi`:

```env
DB_HOST=mysql-presensi
DB_PORT=3306
DB_DATABASE=db_tahsinbpk
DB_USERNAME=tahsinbpk
DB_PASSWORD=password_database
```

Catatan:

- `DB_HOST` jangan diisi `localhost`, kecuali database berada di container yang sama. Untuk Coolify normalnya database ada di service terpisah.
- `N8N_API_KEY` dipakai endpoint:
  - `/api/notifications-pending.php`
  - `/api/daily-reminder.php`
- Jika workflow n8n memanggil API aplikasi, kirim API key lewat header:

```text
X-API-KEY: isi_N8N_API_KEY
```

atau query string:

```text
?key=isi_N8N_API_KEY
```

## 6. Volume Mount Agar Data Tidak Hilang

Ada dua jenis data yang perlu dipahami.

### Data Database

Data utama aplikasi berada di MySQL/MariaDB. Agar data tidak hilang:

- gunakan resource database bawaan Coolify,
- jangan jalankan database di container aplikasi,
- jangan menyimpan file database di folder project aplikasi,
- pastikan database resource memiliki persistent storage aktif.

Saat aplikasi redeploy, database tidak ikut dihapus karena resource database terpisah.

### Data Session Login

Aplikasi menyimpan session login di folder:

```text
/var/www/html/.sessions
```

Buat volume mount di aplikasi Coolify:

```text
Source volume : presensi-tahsin-sessions
Mount path    : /var/www/html/.sessions
```

Langkahnya:

1. Buka resource aplikasi di Coolify.
2. Masuk tab **Storage** atau **Persistent Storage**.
3. Tambah storage baru.
4. Pilih tipe **Volume**.
5. Isi mount path:

```text
/var/www/html/.sessions
```

6. Simpan.
7. Redeploy aplikasi.

Permission folder session akan disiapkan otomatis oleh:

```text
docker/entrypoint.sh
```

## 7. Urutan Deploy yang Disarankan

Urutan paling aman:

1. Buat database MySQL/MariaDB di Coolify.
2. Import `db_tahsinbpk.sql`.
3. Buat aplikasi dari repository.
4. Isi environment variables aplikasi.
5. Tambahkan volume mount `/var/www/html/.sessions`.
6. Deploy aplikasi.
7. Pasang domain dan SSL di Coolify.
8. Test login dan fitur utama.

## 8. Checklist Setelah Deploy

Cek halaman:

- `/login.php`
- dashboard admin
- menu peserta
- menu halaqoh
- laporan
- halaman ustadz
- export/print jika dipakai

Cek juga:

- login berhasil memakai akun dari database lama,
- data peserta lama muncul,
- data presensi lama muncul,
- timezone tanggal sesuai WIB,
- redeploy aplikasi tidak menghapus data,
- workflow n8n masih bisa memanggil API jika digunakan.

## 9. Backup Database

Sebelum import dan sebelum perubahan besar, buat backup database.

Contoh backup dari VPS:

```bash
mysqldump -h HOST_DATABASE_INTERNAL -P 3306 -u USER_DATABASE -p NAMA_DATABASE > backup-db_tahsinbpk.sql
```

Contoh:

```bash
mysqldump -h mysql-presensi -P 3306 -u tahsinbpk -p db_tahsinbpk > backup-db_tahsinbpk.sql
```

Simpan backup di luar container aplikasi.

## 10. Troubleshooting

Jika muncul pesan:

```text
Maaf, terjadi masalah koneksi ke database. Silakan coba beberapa saat lagi.
```

Cek:

- `DB_HOST` memakai host internal database Coolify.
- `DB_PORT` benar, biasanya `3306`.
- `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` sesuai.
- database sudah berjalan.
- `db_tahsinbpk.sql` sudah berhasil di-import.

Jika login sering keluar setelah redeploy:

- pastikan volume `/var/www/html/.sessions` sudah terpasang.
- pastikan env `SESSION_SAVE_PATH=/var/www/html/.sessions`.
- redeploy ulang setelah menambahkan storage.

Jika data hilang setelah redeploy:

- pastikan yang di-redeploy adalah aplikasi, bukan menghapus resource database.
- cek apakah aplikasi terhubung ke database yang benar.
- pastikan tidak membuat database baru kosong dengan nama/host berbeda.

Jika API n8n mengembalikan `Unauthorized`:

- pastikan `N8N_API_KEY` di Coolify sama dengan key di workflow n8n.
- gunakan header `X-API-KEY`.

Jika import SQL gagal karena ukuran file besar:

- gunakan import lewat terminal VPS, bukan UI.
- pastikan client `mysql` tersedia.
- jika dump berasal dari cPanel, pastikan encoding tetap UTF-8.

Jika file SQL/ZIP bisa diakses dari browser:

- pastikan aplikasi dibuild dari `Dockerfile` terbaru.
- jangan upload file SQL ke folder publik aplikasi.
- simpan dump SQL di luar container aplikasi setelah import selesai.
