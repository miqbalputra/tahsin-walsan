# Rollout Data Induk untuk Presensi Tahsin Bapak

1. Atur BACKUP_STORAGE_PATH ke folder privat di luar webroot. Jika kredensial
   hanya tersedia dalam .env pada cPanel, aktifkan APP_LOAD_ENV_FILE=1 untuk
   perintah CLI. Buat backup lengkap, simpan salinannya di luar server, lalu
   jalankan pemeriksaan duplikasi read-only:

   ```sh
   BACKUP_STORAGE_PATH=/secure/backup/path APP_LOAD_ENV_FILE=1 php scripts/backup-database.php
   APP_LOAD_ENV_FILE=1 php scripts/audit-presensi-duplicates.php
   APP_LOAD_ENV_FILE=1 php migrate_data_induk.php
   ```

   Periksa backup_auto_last.json dan pastikan nilai ok adalah true. Uji
   pulihkan ZIP ke staging sebelum migrasi. Snapshot SQL tambahan dapat dibuat
   memakai alat native MySQL/MariaDB dengan file opsi klien berizin privat;
   jangan berikan password melalui argumen command line.

   Sinkronisasi arsip memerlukan kolom histori keanggotaan dari migrasi
   reporting. Periksa dry-run `APP_LOAD_ENV_FILE=1 php migrate_tahsin_reporting.php`, lalu setelah
   backup dapat dipulihkan pada staging, terapkan migrasi itu lebih dahulu
   dengan `APP_LOAD_ENV_FILE=1 APPLY_MIGRATIONS=1 php migrate_tahsin_reporting.php`. Migrasi ini
   additive; jangan melewati validasi backup/staging.

2. Pastikan pemeriksaan duplikasi lulus, lalu terapkan migrasi:

   ```sh
   APP_LOAD_ENV_FILE=1 php migrate_data_induk.php --apply
   ```

   Migrasi memeriksa semua prasyarat, definisi kolom/indeks yang sudah ada,
   duplikasi presensi, dan UUID yang akan diberi indeks unik sebelum DDL
   pertama. Tiap kolom dan indeks ditambahkan sendiri-sendiri supaya migrasi
   yang sempat terhenti dapat dilanjutkan dengan perintah yang sama. Migrasi
   tidak menghapus baris atau menulis ulang nilai lama; tabel status sync
   memakai InnoDB. Jika definisi lama berbenturan atau ada data duplikat,
   migrasi berhenti sebelum perubahan skema dan menjelaskan pemeriksaan yang
   perlu dilakukan.

3. Di VPS/Coolify masukkan secret `DATA_INDUK_ENABLED`, `DATA_INDUK_BASE_URL`,
   `DATA_INDUK_API_KEY`, dan `DATA_INDUK_UNIT_ID`. Nilai tersebut tidak boleh
   masuk Git maupun frontend. API key dibatasi pada unit Kajian/Tahsin Bapak.

4. Setelah endpoint pusat live, sinkronkan referensi, roster, dan ustadz. Buka
   **Status Data Induk** untuk meninjau serta menyetujui/abaikan konflik. Hanya
   setelah roster benar, aktifkan `DATA_INDUK_ENABLED=true` di produksi.

   ```sh
   php scripts/data-induk-sync.php references
   php scripts/data-induk-sync.php roster
   php scripts/data-induk-sync.php teachers
   ```

5. Tambahkan Scheduled Task Coolify atau cron VPS berikut (jalankan di
   container aplikasi, bukan browser):

   ```cron
   */15 * * * * php /var/www/html/scripts/data-induk-sync.php changes >> /proc/1/fd/2 2>&1
   ```

   Bila pusat tidak tersedia, job gagal dengan aman dan form presensi tetap
   memakai snapshot lokal. Admin akan melihat waktu sync lama dan error terakhir
   pada halaman status.

6. Sesudah deploy image baru, gunakan health check `/health.php`. Untuk deploy
   file langsung pada Apache lama, restart PHP/Apache; konfigurasi OPCache juga
   memeriksa perubahan file setiap dua detik.

## Arsip dan pelestarian data

- Arsip wali mengubah `status_aktif` dan menutup keanggotaan aktif dengan
  `archived_at` serta alasan. Arsip anak mengubah `status_roster`; keanggotaan
  hanya ditutup jika tidak ada anak lain yang masih sekolah atau kelasnya
  belum diketahui.
- Sinkronisasi menolak berjalan sebelum kolom arsip tersedia. Perubahan status
  dan penutupan roster berlangsung dalam transaksi pada tabel InnoDB.
- Baris wali, anak, `halaqoh_members`, presensi, draft, serta histori roster
  tidak dihapus. Interval histori ditutup oleh trigger; catatan presensi lama
  tetap dapat dibaca.
- Snapshot Data Induk yang aktif tidak otomatis mengaktifkan kembali wali
  yang sudah diarsipkan secara lokal. Penempatan yang sudah diarsipkan perlu
  ditinjau dan diaktifkan secara eksplisit oleh admin.
