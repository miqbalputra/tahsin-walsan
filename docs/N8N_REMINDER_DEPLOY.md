Pengingat presensi Tahsin memeriksa seluruh roster sesi Ahad pada pekan berjalan. Ketika histori belum tersedia, pemeriksaan pekan berjalan menggunakan roster aktif yang sama dengan formulir guru dan menyebutkan sumbernya. Roster saat ini tidak dipakai untuk menebak kewajiban pekan lama. Ahad pekan pertama dan tanggal libur dilewati.

Deploy kode aplikasi lebih dahulu, kemudian impor workflow n8n. Redeploy tidak menjalankan migrasi atau mengubah presensi lama. Finalisasi penuh oleh guru setelah Ahad 07.30 WIB menyimpan peserta yang belum dipilih sebagai Alfa; autosave kosong dan guru yang belum melakukan penyimpanan tetap berstatus belum diinput. Hadir wajib memiliki capaian lengkap. Pilihan kosong pada formulir lama tidak menimpa Hadir yang sudah tersimpan melalui autosave.

File aplikasi yang berubah: `includes/attendance_helper.php`, `includes/tahsin_report_helper.php`, `includes/tahsin_reminder_helper.php`, `includes/tahsin_reminder_api.php`, `includes/tahsin_pending_widget.php`, `pantau-input.php`, dan `ustadz/form-presensi.php`. Seluruh file ini perlu dideploy bersama. Perbaikan operasional pekan berjalan tidak memerlukan migrasi database; verifikasi histori tetap mengikuti prosedur migrasi laporan.

Cara mengaktifkan pengingat:

1. Setelah deployment, uji GET `/api/daily-reminder.php` menggunakan header `X-API-Key` yang sesuai `N8N_API_KEY`. Respons baru memuat `inspection_complete`, `summary`, `roster_basis`, jumlah peserta yang diperiksa, dan daftar kekurangan. Daftar kosong hanya dapat disimpulkan lengkap jika roster benar-benar telah diperiksa.
2. Impor `n8n_workflow_laporan_admin_tahsin.template.json`. Isi header API, Chat ID admin pada kedua node Telegram, serta pilih credential Telegram dan GoWA. Community node GoWA yang digunakan workflow harus tersedia pada instance n8n. Ekspor lokal yang sudah dikonfigurasi dapat digunakan sebagai alternatif; file tersebut tidak disertakan dalam Git.
3. Jalankan node pengambilan API dan penyusunan laporan untuk preview. Eksekusi seluruh workflow mengirim laporan Telegram lalu pengingat WhatsApp guru, satu per satu dengan jeda enam detik. Konflik penempatan saja dilaporkan kepada admin dan tidak memicu permintaan mengisi ulang kepada guru.
4. Setelah hasil sesuai, publish/aktifkan jadwal Senin–Sabtu 10.00 WIB (`Asia/Jakarta`) dan gantikan workflow lama agar tidak mengirim pengingat ganda. Template disimpan dalam keadaan tidak aktif.

Nomor WhatsApp yang tidak valid dicantumkan pada laporan admin dan dilewati dari antrean pengiriman. Error node WhatsApp tidak menghentikan antrean, dan dilaporkan ke Telegram setelah antrean selesai. Laporan panjang dibagi ke beberapa pesan. Error API, API versi lama tanpa bukti cakupan roster, serta respons `skip` tidak dianggap sebagai presensi lengkap.

Pengujian tanpa layanan eksternal:

```text
php tests/tahsin-reminder-tests.php
node tests/n8n-reminder-workflow.cjs
```

Pengujian integrasi memerlukan instance MySQL/MariaDB lokal terisolasi pada `127.0.0.1:13317` dan database fixture baru dengan awalan `tahsin_test_`. Kredensial fixture tercantum pada script pengujian; script tidak memakai konfigurasi database aplikasi:

```text
php -d extension=pdo_mysql tests/tahsin-reminder-mysql-integration.php tahsin_test_weekly_reminder_new
```

`tests/tahsin-reminder-http-checks.cjs` memeriksa login, halaman pemantauan, autosave, finalisasi, dan API melalui server PHP lokal. Gunakan fixture integrasi di atas dan router localhost dengan `DB_DATABASE` menunjuk database fixture tersebut, `N8N_API_KEY=tahsin-test-api`, serta `HERMES_API_KEY=tahsin-test-api`. Tes HTTP menggunakan sesi `2026-10-18` dan perlu dijalankan sebelum sesi itu diisi pada fixture. Jangan menggunakan endpoint produksi sebagai target pengujian ini.
