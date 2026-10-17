# Rekap Lengkap dan Pemantauan Input Tahsin

Menu **Rekap Lengkap** menyediakan rekap seluruh wali, status kepesertaan, H/S/I/A, persentase kehadiran, kelengkapan input, detail pekanan, Mode Rapat, Excel, dan cetak/PDF. **Ringkasan Pertemuan** menyediakan jumlah peserta serta statistik H/S/I/A dan kelengkapan per sesi, total periode, serta rekap per halaqoh/guru. Excel, PNG, dan PDF statistik hanya berisi agregat; nama wali dan anak tidak disertakan. Data kosong tetap Belum Diinput dan Alfa hanya berasal dari status tersimpan. Menu **Pemantauan Input** menampilkan setiap sesi Ahad dalam rentang secara berurutan. Guru hanya masuk hitungan kekurangan saat roster sesi terverifikasi; sesi sebelum `tracked_from` tetap terlihat sebagai belum terverifikasi, dan guru lama tidak ditebak dari penempatan sekarang. Catatan lama yang tersimpan dan menunjukkan kekurangan tetap dapat ditinjau tanpa atribusi guru yang tidak terbukti. WhatsApp dibuka sebagai pesan siap kirim oleh admin/PJ hanya untuk kekurangan roster terverifikasi; fitur ini tidak menjadwalkan atau mengirim pesan otomatis.

## Penerapan

1. Ambil baseline dan snapshot database memakai prosedur operasional yang sudah ada. Simpan backup di luar web root, lalu validasi restore pada staging.
2. Jalankan `php migrate_tahsin_reporting.php` untuk dry-run. Perintah ini tidak mengubah database.
3. Dalam jendela pemeliharaan, jalankan `APPLY_MIGRATIONS=1 php migrate_tahsin_reporting.php`. Di PowerShell, set `$env:APPLY_MIGRATIONS='1'` lalu jalankan perintah PHP. Gunakan akun database dengan izin CREATE/ALTER dan TRIGGER. Semua tabel sumber harus InnoDB.
4. Pastikan empat tabel tambahan, tujuh trigger, dan satu baris `tahsin_tracking` tersedia. Ulangi migrasi bila terputus; boundary histori dan baseline yang sudah dibuat tidak direset. Jangan menghapus trigger ketika memindahkan atau merestore database. Pengecekan aplikasi memerlukan visibilitas trigger pada akun database aplikasi.
5. Build aset dengan `npm run build`, deploy kode dan aset, kemudian periksa rekap/pemantauan menggunakan akun admin, kepala sekolah, dan guru. Service worker v5 membuang cache v4 agar browser mengambil CSS/JavaScript laporan terbaru.
6. Bandingkan baseline sebelum/sesudah. Migrasi tidak mengubah atau menghapus wali, anak, halaqoh, dan presensi lama. Tabel baru ikut mekanisme backup seluruh tabel. Trigger harus diterapkan kembali setelah restore ke server baru.

Histori roster direkam otomatis dalam transaksi sumber lewat trigger, termasuk impor, sinkronisasi, mutasi, arsip, reaktivasi, dan penghapusan grup. Penggabungan peserta juga memindahkan histori/capaian awal/verifikasi ke identitas yang dipertahankan. Perubahan identitas atau sumber membatalkan fingerprint verifikasi lama.

Sebelum migrasi, rekap masih dapat dibaca; kelengkapan historis berstatus belum terverifikasi dan penyimpanan capaian awal/verifikasi baru tidak tersedia. Setelah migrasi, periode sebelum `tracked_from` tetap belum terverifikasi. Baseline keanggotaan dimulai pada waktu penerapan, bukan tanggal bergabung yang ditebak.

## Aturan laporan

- Menu **Persentase & Keaktifan** (`persentase-keaktifan.php`) menampilkan persentase Hadir, Izin, Alfa, dan Sakit untuk sekolah, setiap wali, halaqoh, dan guru. Keaktifan mengikuti persentase Hadir, tanpa skor atau kategori keaktifan buatan. Semua persentase memakai H+S+I+A terkonfirmasi sebagai pembagi. Tanpa presensi, hasil `—`; kekurangan input dan konflik tetap terpisah. Mode Rapat menampilkan 12 wali per halaman, sedangkan Excel dan cetak/PDF mencakup seluruh wali hasil filter. Nama anak, nomor telepon, dan alamat tidak dimuat pada tabel presentasi/ekspor. Pembaruan ini tidak memerlukan migrasi tambahan dan tidak menulis data presensi.

- Sesi menggunakan tanggal Ahad; Senin–Sabtu memenuhi sesi Ahad sebelumnya. Ahad pertama dan Ahad yang tercatat libur dikecualikan. Catatan pada tanggal pengganti yang libur tidak dihitung sebagai kehadiran/Alfa. Tanggal pelaksanaan yang salah perlu diperbaiki. Pertemuan mendatang tidak dihitung.
- Filter tanggal mengikuti **tanggal sesi**, termasuk ketika pelaksanaan pengganti melewati pergantian bulan. Tanggal pelaksanaan asli tetap tampil. Materi terakhir hanya memakai catatan hingga akhir sesi dalam periode, bukan capaian masa depan.
- Kehadiran = H/(H+S+I+A), sementara kelengkapan = peserta-sesi lengkap/kewajiban peserta-sesi yang diketahui. Jumlah wali berbeda dari jumlah peserta-sesi. Rasio ringkasan menggunakan pembilang/penyebut total.
- Data kosong bukan Alfa; tanpa kehadiran terkonfirmasi, persentase tampil `—`. Rasio diberi penanda sementara jika ada kekurangan, konflik, atau histori belum tersedia.
- Input Hadir membutuhkan jenis materi, jilid/surat, halaman/ayat, dan hasil talaqqi. Alasan diperlukan untuk Sakit/Izin. Autosave yang gagal menjelaskan bahwa data belum tersimpan.
- Catatan identik dihitung sekali. Catatan berbeda atau dugaan inisialisasi capaian lama berdasarkan bukti log ditahan untuk verifikasi. Admin/PJ memilih catatan sah dan mengisi alasan, atau menandai seluruh catatan sesi sebagai capaian awal. Sumber asli tetap ada. Mengubah sumber mengharuskan verifikasi ulang.
- Capaian awal baru tersimpan pada tabel terpisah; tidak menambah Hadir atau Lulus. Kepala sekolah membaca data; guru hanya mengakses tanggung jawabnya, termasuk sesi historis ketika guru/halaqoh berubah.
- Pengingat hanya menghitung kewajiban guru dari roster sesi terverifikasi; dashboard dan endpoint pengingat tidak menyatakan roster aktif hari ini sebagai roster historis. Sesi sebelum `tracked_from` tetap terlihat sebagai belum terverifikasi. Catatan lama dengan capaian/keterangan kurang, konflik, atau dugaan capaian awal ditampilkan untuk tinjauan berdasarkan sumber tersimpan, tanpa menebak peserta yang kosong atau mengatribusikan guru yang tidak tercatat. Batas input selalu Senin 10.00 WIB, termasuk sesi pengganti. Catatan lama yang roster/gurunya belum diketahui diberi penanda perlu konfirmasi, bukan tuduhan terlambat pada guru yang belum terverifikasi.

## Pemeriksaan

`php tests/run-tahsin-tests.php` menguji aturan tanpa database atau Composer. `tests/tahsin-mysql-integration.php` memakai database baru bernama `tahsin_test_*` pada instance lokal terisolasi `127.0.0.1:13317`; tidak membaca konfigurasi database aplikasi. Fixture hanya berisi data sintetis. Gunakan driver PDO MySQL (`php -d extension=pdo_mysql ...` bila diperlukan pada PHP Windows).

`scripts/verify-tahsin-snapshot.php` merestore direktori backup CSV lokal ke database staging baru pada instance yang sama, membandingkan hash seluruh kolom sumber sebelum/sesudah migrasi, dan memastikan rekap historis tidak berubah. Jangan menggunakannya terhadap server produksi; koneksinya sengaja dibatasi pada instance pengujian lokal.

`tests/tahsin-browser-checks.cjs` menggunakan Playwright dan browser Chromium pada router staging localhost dengan fixture sintetis. Pemeriksaan mencakup role, CSRF, input tanpa status, Hadir tanpa capaian, API, pagination, ekspor detail, konsistensi statistik pertemuan pada layar/PNG/PDF/Excel tanpa nama wali/anak, ponsel, dan Mode Rapat. Screenshot/PDF hasil pemeriksaan disimpan pada `.sessions/tahsin-ui/` yang diabaikan Git. Dependensi pengujian browser bukan dependensi aplikasi produksi.

Field lama endpoint n8n dipertahankan. Tambahan: `ustadz_id`, `complete_input`, `missing_presensi`, `missing_capaian`, `missing_keterangan`, `conflicts`, `pending_participants`, `coverage_percent`, `deadline_at`, `roster_source`, `data_verified`, dan `timeliness`. Setiap item mempunyai tanggal sesi sendiri. Dashboard manual menyertakan backlog; endpoint n8n tetap memakai sesi terakhir secara default. `all_pending=1` mengikutsertakan backlog bila memang diinginkan. Template n8n harian menggunakan tanggal item; tidak ada workflow yang diaktifkan oleh perubahan ini.

Rollback kode dapat dilakukan dengan mempertahankan tabel/trigger baru. Jangan menghapus histori atau mengembalikan inisialisasi capaian yang membuat Hadir palsu. Hindari pencampuran versi lama/baru saat migrasi dan saat penyimpanan presensi berjalan.
