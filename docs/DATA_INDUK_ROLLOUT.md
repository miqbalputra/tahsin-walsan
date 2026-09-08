# Rollout Data Induk untuk Presensi Tahsin Bapak

1. Backup kedua database dan jalankan baseline/audit tanpa perubahan:

   ```sh
   php scripts/database-snapshot.php
   php scripts/production-baseline.php
   php scripts/audit-presensi-duplicates.php
   php migrate_data_induk.php
   ```

2. Setelah duplikasi presensi sudah nol, terapkan migrasi sekali:

   ```sh
   php migrate_data_induk.php --apply
   ```

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
