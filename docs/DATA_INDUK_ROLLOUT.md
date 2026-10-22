# Rollout Data Induk untuk Presensi Tahsin Bapak

1. Backup kedua database dan jalankan baseline/audit tanpa perubahan:

   ```sh
   php scripts/database-snapshot.php
   php scripts/production-baseline.php
   php scripts/audit-presensi-duplicates.php
   php migrate_data_induk.php
   ```

2. Terapkan migrasi aditif setelah hasil restore clone diverifikasi:

   ```sh
   php migrate_data_induk.php --apply
   ```

   Migrasi memeriksa setiap kolom dan indeks secara terpisah agar aman
   dilanjutkan setelah penerapan parsial. Migrasi ini tidak mengubah atau
   menghapus baris presensi, tidak mengimpor keluarga, dan tidak menambahkan
   indeks unik ke `presensi`. Jalankan `php scripts/audit-presensi-duplicates.php`
   untuk meninjau duplikasi historis; jangan menghapus atau menggabungkannya
   otomatis. Catatan yang nilainya berbeda akan tetap ditandai untuk verifikasi
   melalui rekap Tahsin.

3. Di VPS/Coolify masukkan secret `DATA_INDUK_ENABLED`, `DATA_INDUK_BASE_URL`,
   `DATA_INDUK_API_KEY`, dan `DATA_INDUK_UNIT_ID`. Nilai tersebut tidak boleh
   masuk Git maupun frontend. API key dibatasi pada unit Kajian/Tahsin Bapak.

4. Setelah endpoint pusat live, sinkronkan referensi, roster, dan ustadz. Buka
   **Status Data Induk** untuk meninjau serta menyetujui/abaikan konflik. Hanya
   setelah roster benar, aktifkan `DATA_INDUK_ENABLED=true` di produksi.

   ```sh
   # Saat ini melalui PHP yang masih aktif
   php scripts/data-induk-sync.php references
   php scripts/data-induk-sync.php roster
   php scripts/data-induk-sync.php teachers

   # Setelah cutover Go+Vue, binary yang sama tersedia di image produksi
   tahsin-data-induk-sync references
   tahsin-data-induk-sync roster
   tahsin-data-induk-sync teachers
   ```

5. Tambahkan Scheduled Task Coolify atau cron VPS berikut (jalankan di
   container aplikasi, bukan browser):

   ```cron
   */15 * * * * tahsin-data-induk-sync changes >> /proc/1/fd/2 2>&1
   ```

   Pada Scheduled Task Coolify, jalankan `tahsin-data-induk-sync changes` dengan environment yang sama seperti service Go. Tombol admin dan task ini berbagi MySQL advisory lock. Bila pusat tidak tersedia, job gagal dengan aman dan form presensi tetap
   memakai snapshot lokal. Admin akan melihat waktu sync lama dan error terakhir
   pada halaman status.

6. Sesudah deploy image baru, gunakan health check `/health.php`. Untuk deploy
   file langsung pada Apache lama, restart PHP/Apache; konfigurasi OPCache juga
   memeriksa perubahan file setiap dua detik.
