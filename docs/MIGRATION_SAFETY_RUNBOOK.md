# Pelaksanaan perlindungan data dan pilot tampilan

Mengikuti [rencana migrasi](RENCANA_MIGRASI_GO_VUE.md). Perangkat ini tidak mengalihkan koneksi produksi, mengimpor keluarga, atau mengaktifkan penulisan Go.

Implementasi lanjutan [autosave Go dan pengunci staging](GO_AUTOSAVE_STAGING.md) sudah diuji pada database sintetis. Gerbang produksi dan bukti pemulihan dalam runbook ini tetap berlaku; writer staging tersebut belum diaktifkan pada database operasional.

## Backup dan pemulihan

`scripts/migration-safety.php` menyediakan inventaris, backup, validasi checksum, pengambilan delta binlog, restore terisolasi, serta replay. Konfigurasi koneksi memakai `TAHSIN_SAFETY_DB_HOST`, `PORT`, `DATABASE`, `USERNAME`, `PASSWORD` dengan prefix lengkap pada setiap variabel; tidak mengambil kredensial `DB_*` aplikasi secara otomatis. Isi path client native melalui `TAHSIN_SAFETY_DUMP_BINARY`, `TAHSIN_SAFETY_CLIENT_BINARY`, dan `TAHSIN_SAFETY_BINLOG_BINARY`. Gunakan client yang cocok dengan mesin sumber.

Contoh operasi CLI, setelah environment diisi melalui mekanisme privat host:

```text
php scripts/migration-safety.php inventory --out=/private/tahsin/inventory-UNIK
php scripts/migration-safety.php backup --lock-writes --out=/private/tahsin/backup-UNIK
php scripts/migration-safety.php verify --backup=/private/tahsin/backup-UNIK
php scripts/migration-safety.php capture-delta --lock-writes --backup=/private/tahsin/backup-UNIK --out=/private/tahsin/delta-UNIK
php scripts/migration-safety.php restore-drill --backup=/private/tahsin/backup-UNIK --out=/private/tahsin/restore-UNIK
php scripts/migration-safety.php replay-drill --backup=/private/tahsin/backup-UNIK --delta=/private/tahsin/delta-UNIK --out=/private/tahsin/replay-UNIK
```

Direktori keluaran harus baru, parent sudah ada, dan berada di luar workspace/web root. Tidak ada penimpaan backup. Tabel tanpa primary key menghentikan proses agar rekonsiliasinya dirancang dahulu. Paket menyertakan hash berkas, hash setiap baris menurut urutan primary key, struktur tabel, objek DB, dan posisi binlog jika tersedia. Nilai NULL, string kosong, data biner, nomor dengan nol awal, serta pecahan waktu dibedakan.

Backup memakai tiga tahap: skema/routine, data, kemudian trigger/event. Saat restore, trigger baru dipasang sesudah seluruh data masuk agar histori/revisi tidak dihasilkan ulang. Perbandingan mencakup seluruh tabel yang ditemukan dan definisi objek, bukan hanya jumlah presensi.

`--lock-writes` berarti **global read lock pada seluruh instance database**, sehingga harus memakai jendela pemeliharaan yang terukur untuk aplikasi lain yang memakai instance itu juga. Inventaris saja tidak mengunci penulisan. Pengambilan delta hanya menerima rantai binlog ROW lengkap tanpa perubahan skema selama jendelanya. Backup/delta belum memindahkan log secara berkala ke penyimpanan di luar server.

Pemulihan PHP CLI memakai konfigurasi `TAHSIN_RECOVERY_DB_HOST`, `PORT`, `USERNAME`, `PASSWORD`, serta `TAHSIN_RECOVERY_GUARD_FILE`. Target wajib instance loopback pada port nondefault, datadir berbeda, versi mesin cocok, scheduler event mati, dan tidak berisi database pengguna. Guard dibuat **hanya setelah instance baru terisolasi disiapkan**, di dalam datadir target:

```json
{"purpose":"tahsin-recovery-only","port":13318}
```

Database dipulihkan dengan nama sumber yang sama pada instance berbeda agar referensi schema pada view/routine tetap tepat. Replay menolak target yang sudah berubah dari backup awal, termasuk percobaan replay ulang. Kesalahan menyisakan bukti privat dan database pemulihan untuk pemeriksaan; tidak ada penghapusan database sumber.

## Profil Docker MySQL 8.4

`scripts/migration-safety-docker.py` menjalankan client native dari container DB yang ada dan perhitungan hash dengan PHP PDO dari image aplikasi yang ada. Ini menghindari pemasangan PHP pada host atau perubahan image aplikasi produksi. Jalankan pada host Linux Docker dengan Python 3.11 atau lebih baru.

```text
python3 scripts/migration-safety-docker.py backup --app-container=NAMA_APLIKASI --db-container=NAMA_DB --helper=includes/migration_safety_helper.php --lock-writes --out=/private/tahsin/backup-UNIK
python3 scripts/migration-safety-docker.py verify --backup=/private/tahsin/backup-UNIK
python3 scripts/migration-safety-docker.py restore-drill --backup=/private/tahsin/backup-UNIK --helper=includes/migration_safety_helper.php --out=/private/tahsin/restore-UNIK
```

Profil ini memeriksa bahwa aplikasi dan client native benar-benar mengakses server DB yang sama. Password yang sudah ada digunakan dalam konfigurasi client sementara berizin terbatas, tanpa dicetak atau dimasukkan ke argument command. Koneksi pengunci memiliki watchdog 30 detik; jika anggaran terlampaui atau posisi log berubah, paket tidak diberi manifest siap pakai. Transaksi tulis aktif menunda backup.

Restore Docker memakai **image DB yang sama persis menurut image ID**, direktori data baru, jaringan `none`, tanpa publikasi port, scheduler mati, dan batas RAM/CPU. Volume produksi tidak dipasang ke container pemulihan. Pemeriksaan hash berjalan lewat container PHP sementara yang berbagi jaringan loopback container pemulihan. Sesudah uji, container DB pemulihan dihentikan; data/bukti tetap tersedia untuk audit. Pembersihan terpisah hanya boleh menyentuh sumber daya uji yang telah dipastikan identitasnya.

Profil Docker belum menyediakan replay binlog atau otomatisasi salinan di luar server. Berhasil restore penuh **belum memenuhi gerbang penulisan Go** sebelum bukti pemulihan transaksi setelah backup dan salinan independen tersedia. User DB/grants, konfigurasi host, serta volume sesi tetap dicadangkan sebagai lingkup tersendiri.

Image MySQL server minimal tidak selalu memuat `mysqlbinlog`. `docker/Dockerfile.mysql-client` menyediakan client resmi dengan versi paket yang disamakan dengan sumber; repository lengkap dan pemeriksaan GPG tetap digunakan. Image client terpisah ini tidak mengganti image database. Set `TAHSIN_MYSQL_CLIENT_IMAGE` pada proses pengujian untuk menjalankan pembaca binlog melalui client tersebut, hanya berbagi jaringan container target dan konfigurasi client sementara yang hanya dapat dibaca.

`tests/migration-docker-integration.py` menguji objek, hash data dan replay melewati rotasi binlog pada instance fiktif tanpa volume produksi. `tests/verify-migration-clone.php` menguji penerapan skema reporting/autosave/keluarga dua kali pada salinan pemulihan; UUID/hostname target wajib cocok guard dan berbeda dari sumber. Seluruh kolom asli setiap tabel dibandingkan lewat hash, serta jumlah laporan historis diperiksa. Skrip ini tidak mengimpor data keluarga atau dijalankan pada database produksi.

## Pilot Vue dengan PHP

Build frontend menyediakan entry `src/main.ts` untuk pratinjau keluarga dan `src/teacher.ts` untuk form guru. PHP memvalidasi manifest beserta chunk importnya sebelum memilih tampilan baru. Build opsional `docker/Dockerfile.go-vue` menyertakan hasil Vue di image PHP; `Dockerfile` utama tetap tersedia untuk versi stabil. Build container harus diuji sebelum deploy.

```env
TAHSIN_VUE_TEACHER_ENABLED=0
TAHSIN_VUE_TEACHER_HALAQOH_IDS=
```

Aktivasi memerlukan flag tepat `1` dan daftar ID kelompok yang valid dan tidak kosong, misalnya `3,5` pada staging. Flag ini terpisah dari `TAHSIN_NEXT_ENABLED`. Hak akses guru dan roster diperiksa PHP yang sama seperti sebelumnya. Jika aset tidak lengkap, form PHP tetap dipakai.

Vue saat ini merender form sekali, lalu memakai controller autosave bersama dengan PHP. Controller tetap menjadi satu pemilik input/penyimpanan; tidak ada store IndexedDB atau penjadwal pengiriman kedua. Format draft, scope, client ID, operasi yang sedang dikirim dan tanda terima tetap sama. URL runtime versi baru menghindari cache runtime lama tanpa membersihkan data pengguna.

Tautan **Buka tampilan sebelumnya** memakai `renderer=php`, kembali ke autosave PHP, dan mempertahankan pilihan itu saat mengganti tanggal. Ini tidak mengaktifkan finalisasi manual lama yang dapat berbeda perlakuannya terhadap isian kosong. Menonaktifkan flag tampilan juga mengembalikan form PHP tanpa menghapus draft. Hari Ini, Riwayat, dan Akun masih menggunakan halaman PHP. Backend pilot tampilan ini tetap PHP kecuali writer staging yang terpisah diaktifkan; mengembalikan backend tulis memerlukan pengalihan kepemilikan, bukan sekadar mengganti renderer.

## Bukti lokal yang sudah ada

- 20 pemeriksaan integrasi backup/restore/replay pada dua instance MariaDB terpisah: seluruh tabel operasional/keluarga/autosave, objek DB, data biner, presisi waktu, tanda terima, serta transaksi setelah backup.
- 25 pemeriksaan integrasi database autosave lama dan 147 regresi laporan.
- 39 pemeriksaan browser autosave berjalan pada form Vue dengan API PHP.
- 15 pemeriksaan perpindahan Vue ↔ PHP: draft offline, ID operasi yang responsnya hilang, fallback, batas kelompok pilot, dan lebar HP.
- Build Vue/typecheck dan pengujian/vet Go lulus.

## Pemeriksaan home server, 22 Oktober 2026

Coolify sudah dapat diakses. Database yang berjalan adalah MySQL **8.4.11**. Commit aplikasi PHP yang benar-benar terpasang adalah `24b8ed39bde8b43b382af11d03c9a4ebc2f4b6e6`, lebih lama daripada Git `main`; keberhasilan push tidak berarti versi itu sudah terdeploy. Image PHP yang terpasang diberi tag pemulihan lokal dan tetap menjadi aplikasi aktif.

- Uji MySQL fiktif di host yang sama lulus **11 pemeriksaan**, termasuk restore seluruh objek dan replay transaksi setelah backup melewati rotasi binlog. Tidak memasang volume produksi pada container uji.
- Backup penuh database produksi berhasil, dengan waktu proses terukur **1,194 detik**. Restore ke instance MySQL dengan image ID yang sama persis berhasil membandingkan seluruh **11 tabel**, definisi objek dan hash seluruh baris terhadap snapshot beku.
- Skema reporting/autosave/keluarga diterapkan **hanya pada salinan pemulihan**, kemudian diulang. Seluruh nilai kolom lama tetap cocok; histori tidak digandakan; ringkasan laporan kandidat sebelum/sesudah migrasi sama. Pemeriksaan ini bukan impor keluarga atau perbaikan konflik historis.
- Image kandidat PHP+Vue dan Go pada kode `aa19d4c9027d6c075fc0c55f0fe940f0b04e75de` berhasil dibangun di home server. `tests/migration-image-smoke.py` lulus **5 pemeriksaan** pada jaringan klon privat: koneksi PHP/MySQL, kedua entrypoint Vue, larangan akses berkas privat, gateway PHP–Go bertanda tangan dalam mode demo, serta penolakan permintaan tanpa tanda tangan dan penulisan Go. Pengujian Go MySQL sebelumnya tetap terpisah; smoke test image ini memakai mode demo Go secara eksplisit.
- Container pemulihan dan runtime uji dihentikan sesudah pemeriksaan; data dan log uji disimpan privat di host. Tidak ada penggantian koneksi, migrasi skema, impor keluarga, atau deploy pada aplikasi produksi.

Bukti penuh berada di direktori root privat host dengan prefix `/root/tahsin-migration-20261022-`: `synthetic-mysql-02/result.json`, `production-backup-01/manifest.json`, `production-restore-01/result.json`, `production-restore-01/verified/verification.json`, `production-schema-check-01/clone-migration.json`, dan `image-smoke-01/result.json`. Jangan memasukkan SQL, hash per baris, environment atau password pemulihan ke repository.

Backup Coolify manual tambahan juga berstatus sukses. **Salinan di luar home server belum berhasil diambil**: tombol unduh tidak menghasilkan berkas melalui browser yang dipakai saat pemeriksaan, dan SSH port 22 timeout. Backup yang tersedia sekarang tetap berada pada host yang sama. Keberhasilan restore tidak menggantikan kebutuhan salinan independen.

Gerbang yang masih terbuka: salinan independen beserta validasinya; rantai log produksi dan pemulihan delta operasional; inventaris perangkat/antrean serta kelompok pilot; uji manusia dan beban; validasi kode baca/tulis Go pada MySQL/image target; pemulihan data hasil Go dan administrasi pilot. Kode staging dan latihan pengembalian lokal kini tersedia dalam panduan writer. Penulisan Go dan pilot **produksi** belum diaktifkan. Tidak ada klaim migrasi penuh atau kapasitas guru sudah terbukti.

Contoh smoke test setelah backup/restore terverifikasi tersedia, memakai image kandidat yang telah dibangun:

```text
python3 tests/migration-image-smoke.py --tool=scripts/migration-safety-docker.py --backup=/private/tahsin/backup-UNIK --restore=/private/tahsin/restore-UNIK --php-image=IMAGE_PHP_KANDIDAT --go-image=IMAGE_GO_KANDIDAT --out=/private/tahsin/image-smoke-UNIK
```

Referensi resmi: [mysqldump MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html), [posisi binlog MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/show-binary-log-status.html), [mysqlbinlog MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/mysqlbinlog.html), [mariadb-dump](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump), [Vue v-once](https://vuejs.org/guide/best-practices/performance#v-once), [Vite backend integration](https://vite.dev/guide/backend-integration).
