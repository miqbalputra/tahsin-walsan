# Otomasi Backup Database Harian

Backup database kini dapat dijalankan otomatis lewat CLI memakai mesin backup
yang sama dengan fitur **Backup & Restore** aplikasi (ZIP berisi CSV per
tabel + `schema.sql` + manifest checksum, snapshot konsisten REPEATABLE READ).

- Skrip: `scripts/backup-database.php` (CLI-only; diblokir dari web oleh
  `.htaccess` dan `docker/apache.conf`).
- Folder tujuan: `BACKUP_STORAGE_PATH` — **wajib di luar webroot** karena
  arsip memuat hash password (dibutuhkan untuk pemulihan penuh).
- Nama arsip: `backup_auto_<tanggal>_<acak>.zip` (mode 0600).
- Retensi: 14 arsip terbaru dipertahankan berdasarkan jumlah; arsip terbaru
  tetap ada walau cron lama tidak berjalan.
- Status eksekusi terakhir: `backup_auto_last.json` di folder yang sama
  (`ok`, `file`, `size_bytes`, `rows`, `duration_ms`, `finished_at`).
- Kegagalan: exit code 1 + pesan di STDERR; backup lama tidak disentuh.

## Coolify (deployment Docker)

1. Set environment `BACKUP_STORAGE_PATH` ke volume persisten di luar webroot,
   mis. `/var/backups/tahsin` yang di-mount ke container.
2. Buka resource aplikasi → **Scheduled Tasks** → tambah task:
   - Name: `database-backup-daily`
   - Cron: `0 2 * * *` (02:00 setiap hari; sesuaikan jam dengan zona waktu server)
   - Command:
     ```
     php /var/www/html/scripts/backup-database.php
     ```
3. Jalankan sekali manual lewat tombol *Execute* dan periksa
   `backup_auto_last.json` di folder penyimpanan.

## cPanel (shared hosting)

1. Buat folder di luar `public_html`, mis. `/home/USER/tahsin-backups`, dan
   set `BACKUP_STORAGE_PATH=/home/USER/tahsin-backups` (via MultiPHP INI
   environment atau variabel di `config/database.php` environment Anda).
2. **Cron Jobs** → tambah:
   ```
   0 2 * * * APP_LOAD_ENV_FILE=1 /usr/local/bin/php /home/USER/public_html/tahsin/scripts/backup-database.php >> /home/USER/tahsin-backups/backup-cron.log 2>&1
   ```
   (sesuaikan path PHP dan aplikasi dengan server Anda; output cron masuk ke
   log sehingga kegagalan terlihat).
   `APP_LOAD_ENV_FILE=1` diperlukan jika kredensial hanya disimpan di `.env`;
   letakkan file tersebut di root aplikasi dan pastikan tidak dapat diunduh.
3. Verifikasi: setelah eksekusi pertama, cek folder — ada `backup_auto_*.zip`
   dan `backup_auto_last.json` berisi `"ok": true`.

## Pemulihan

Gunakan menu **Backup & Restore** di aplikasi (login admin): upload ZIP →
pratinjau → konfirmasi `RESTORE`. Restore selalu merge-only (baris dengan ID
yang sudah ada dilewati) dan otomatis membuat safety backup sebelum
pemulihan. Panduan lengkap: `docs/BACKUP_RECOVERY.md`.

## Checklist operasional mingguan

- [ ] `backup_auto_last.json` berisi `"ok": true` dan `finished_at` segar.
- [ ] Salinan arsip terbaru diunduh ke lokasi lain (server bukan satu-satunya
      tempat penyimpanan — backup di mesin yang sama bukan backup sejati).
- [ ] Uji restore ke database staging minimal sebulan sekali.
