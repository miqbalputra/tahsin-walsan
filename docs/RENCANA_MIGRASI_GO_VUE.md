# Rencana migrasi Tahsin ke Go + Vue

Tanggal rencana: 21 Oktober 2026. Status: rencana pelaksanaan, bukan aktivasi produksi. Dokumen ini menjadi acuan tahapan dan syarat pengalihan; [GO_VUE_MIGRATION.md](GO_VUE_MIGRATION.md) memuat cara menjalankan pratinjau yang sudah tersedia secara lokal.

Implementasi perlindungan data dan form Vue bertahap dijelaskan pada [runbook pelaksanaan](MIGRATION_SAFETY_RUNBOOK.md). Gerbang produksi dalam rencana ini tetap berlaku meskipun pengujian lokal sudah lulus.

## 1. Keputusan utama

- Migrasi bertahap ke **Go + Vue 3 + TypeScript**, tetap memakai **database MySQL/MariaDB yang sama** pada tahap awal. Versi mesin, lokasi database, domain aplikasi, dan stack tidak diganti sekaligus.
- PHP tetap berjalan sampai penggantinya terbukti sesuai. Tampilan Vue untuk guru terlebih dahulu memakai API PHP yang ada; pengalihan backend tulis dilakukan pada rilis terpisah.
- Data Bapak tidak dibuat ulang, tidak diganti menjadi data Ibu, dan tidak dikosongkan. ID, keanggotaan, arsip, histori, presensi, capaian, dan hasil verifikasi dipertahankan.
- Impor master Anak–Bapak–Ibu adalah perubahan terpisah. Paket Excel/JSON merupakan sumber impor, **bukan backup database produksi**. Tidak ada impor massal bersamaan dengan pengalihan stack.
- Satu jalur menjadi penulis resmi untuk lingkup data yang sama. Tidak ada penulisan ganda otomatis ke PHP dan Go, atau dua database operasional yang harus disamakan belakangan.
- Setiap tahap berhenti bila bukti keutuhan data atau pemulihan belum tersedia. Jadwal mengikuti hasil pemeriksaan, bukan tanggal peluncuran yang dipaksakan.

Target keutuhan adalah **tidak kehilangan perubahan yang sudah diakui server akibat migrasi atau rollback** (target RPO 0 untuk proses ini). Ini harus dibuktikan lewat pengujian dan rekonsiliasi, bukan dijanjikan hanya karena ada file backup. Draft yang baru tersimpan di HP belum dapat dipulihkan dari server; penghapusan penyimpanan browser atau kerusakan perangkat tetap menjadi batas perlindungannya.

## 2. Keadaan saat ini dan pekerjaan yang belum selesai

| Komponen | Keadaan yang diketahui | Syarat sebelum digunakan lebih jauh |
|---|---|---|
| Aplikasi PHP | Jalur utama untuk login, pengisian, impor, dan laporan | Bekukan versi stabil yang dapat dijalankan kembali |
| Go + Vue tahap pertama | Pratinjau keluarga hanya baca, lokal, mati secara default; belum dipush pada saat rencana dibuat | Paket deploy, build container, dan uji di lingkungan server |
| Go untuk guru | Kontrak baca sesi tersedia pada branch migrasi, gateway guru terpisah dan mati secara default; lihat [hasil uji](GO_SESSION_READ.md) | Uji image/MySQL target sebelum aktivasi; penulisan Go masih belum tersedia |
| Autosave | Ada IndexedDB, draft server, revisi, tanda terima operasi, dan transaksi catatan resmi | Kontrak lama harus tetap dapat diproses frontend/backend baru |
| Backup ZIP aplikasi | Mengekspor tabel fisik dan skema tabel; restore hanya memasukkan ID yang belum ada | Tidak dipakai sebagai satu-satunya pemulihan penuh |
| Backup/pemulihan produksi | Belum diperiksa atau diuji pada home server dalam pekerjaan ini | Uji restore lengkap serta pemulihan transaksi setelah backup |
| Kapasitas home server | Informasi pengguna: 4 CPU, RAM 6 GB, SSD 60 GB, Intel i5 generasi 12 | Ukur sisa sumber daya, aplikasi lain, ukuran DB, dan beban nyata |

Temuan backup: `backup-restore.php` menggunakan restore **merge-only**, sehingga nilai salah pada baris yang sudah ada tidak dikembalikan ke nilai backup. Ekspornya juga tidak menyertakan definisi view, trigger, routine, atau event. `scripts/database-snapshot.php` yang tersedia di workspace pun mengekspor tabel, bukan seluruh objek database; skrip tersebut belum menjadi perangkat pemulihan produksi yang terverifikasi. `scripts/production-baseline.php` menghitung baris dan indeks, belum membuktikan kesamaan isi setiap baris. Skrip `verify-tahsin-snapshot.php` berguna untuk regresi enam tabel lama, bukan bukti restore seluruh aplikasi.

## 3. Data yang wajib dipertahankan

Inventaris diambil dari database target yang benar-benar digunakan. Daftar berikut adalah minimum; tabel tambahan yang ditemukan tetap masuk backup dan pemeriksaan.

| Area | Yang dipertahankan dan diperiksa |
|---|---|
| Akun dan akses | `users`, identitas akun, hash password, role, penugasan, konfigurasi autentikasi; sesi tidak terputus tanpa alur pemulihan |
| Peserta dan kelompok | `wali_santri`, `santri_detail`, `halaqoh`, `halaqoh_members`, program Bapak/Ibu, ID lama, status aktif/arsip dan alasannya |
| Catatan pembelajaran | Seluruh `presensi`, materi, rentang bacaan, hasil, tanggal, pencatat, koreksi, serta referensi catatan lama |
| Histori dan laporan | `tahsin_tracking`, `tahsin_roster_history`, `tahsin_verifications`, `tahsin_capaian_awal`, libur dan aturan perhitungan laporan |
| Autosave server | `tahsin_autosave_drafts`, `tahsin_autosave_operations`, `tahsin_autosave_revisions`, `tahsin_autosave_sessions`; payload, revisi, hash permintaan, respons operasi, dan penanda finalisasi |
| Anak–Bapak–Ibu | `family_guardians`, `family_students`, `family_relations`, `family_legacy_children`, `family_import_state`, serta view `family_children`; ID sumber, pemetaan wali lama, saudara dan relasi aktif |
| Integrasi | Pemetaan Data Induk jika terpasang, namespace ID, status sinkronisasi/konflik, kontrak API Hermes/n8n dan integrasi lain yang ditemukan |
| Objek dan berkas | Definisi tabel, foreign key, indeks, nilai auto-increment, trigger, view, routine/event bila ada; berkas persisten, konfigurasi, kunci aplikasi dan artefak rilis yang diperlukan untuk pemulihan |
| Perangkat guru | IndexedDB `tahsin-autosave-v1`, identitas akun/program/sesi/peserta/tab, draft, nomor urut lokal, dan operasi yang responsnya belum diketahui |

Nomor telepon, NIS, dan ID sumber yang berupa teks tetap teks, termasuk nol di depan. ID numerik ekspor wali tidak dianggap sama dengan UUID Data Induk. Relasi yang belum jelas ditandai untuk ditinjau, tidak ditebak selama migrasi.

Aturan keluarga tetap: orang tua dengan sedikitnya satu anak berkelas aktif masih aktif; kakak yang sudah lulus tetap terlihat dalam riwayat keluarga. Lulus, keluar, dan arsip manual dibedakan. Data keluarga sumber tidak boleh mengubah histori Bapak atau menambah kehadiran secara otomatis.

## 4. Tahapan dan syarat lulus

| Tahap | Pekerjaan | Bukti sebelum melanjutkan |
|---|---|---|
| 0. Inventaris dan versi stabil | Catat versi aplikasi/DB, skema, seluruh jalur tulis, jadwal, integrasi, volume persisten, serta domain dan penyimpanan browser | Daftar komponen lengkap, versi PHP stabil tersimpan, perangkat/kelompok pilot terdaftar |
| 1. Backup dan latihan pemulihan | Buat backup penuh yang konsisten, simpan salinan di luar home server, restore ke DB kosong terisolasi, lalu uji pemulihan perubahan setelah backup | Isi data dan objek DB cocok; waktu pemulihan terukur; transaksi uji setelah backup dapat dipulihkan |
| 2. Go hanya membaca | Jalankan pratinjau keluarga, lanjutkan API baca sesi/riwayat; bandingkan PHP dan Go pada snapshot yang sama | Tidak ada selisih tanpa penjelasan, akses guru/admin sesuai, kegagalan Go tidak menghambat PHP |
| 3. Vue memakai backend PHP | Bangun Hari Ini, Riwayat, Akun dengan kontrak autosave lama; mulai pilot tampilan | Draft lokal lama pulih, antrean diproses sekali, input/capaian dan laporan sama; fallback tampilan lulus uji |
| 4. Go menulis di staging | Implementasikan transaksi, validasi, revisi, idempotensi, finalisasi, dan penguncian pemilik jalur tulis; uji rollback ke PHP | Semua skenario integritas dan pemulihan lulus; PHP dapat membaca/mengoreksi data hasil Go |
| 5. Pilot backend Go | Alihkan 1–2 kelompok, 3–5 guru; backend kelompok lain tetap PHP | Minimal dua pertemuan, tanpa kehilangan/duplikasi data; semua antrean dan selisih terselesaikan |
| 6. Perluasan | Uji beban, lalu perluas per kelompok sekitar 25%, 50%, dan 100% sesuai hasil | Pemeriksaan keutuhan lulus pada setiap gelombang dan sumber daya mencukupi |
| 7. Pensiunkan jalur lama dan integrasi pusat | Tutup jalur tulis PHP setelah seluruh antrean lama aman; pusat laporan ditambahkan sebagai konsumen data resmi | Pemulihan tetap tersedia; masa pengamatan selesai; tidak ada klien lama yang belum terhitung |

Tahap 2 dapat berjalan tanpa mengalihkan seorang guru pun. Tahap 3 memisahkan risiko perubahan tampilan dari perubahan aturan penyimpanan. Setiap perubahan skema lebih dulu diuji pada salinan terisolasi, dengan pola menambah sebelum mengganti; tidak ada `DROP`, `TRUNCATE`, penggantian ID, atau penyusutan kolom dalam rilis pengalihan.

## 5. Backup yang dapat dipulihkan

1. **Identifikasi database produksi.** Cocokkan konfigurasi layanan, versi mesin, nama DB, engine tabel, charset/collation, timezone dan jalur volume. Jangan mengasumsikan MySQL produksi sama dengan MariaDB uji lokal. Backup data, konfigurasi dan berkas persisten ke penyimpanan terlindungi di luar web root dan Git.
2. **Buat snapshot konsisten.** Gunakan perangkat backup resmi yang cocok dengan mesin target, mencakup seluruh tabel dan objek DB. Verifikasi engine; konsistensi snapshot InnoDB tidak otomatis melindungi tabel nontransaksional atau perubahan DDL bersamaan. Jadwalkan penguncian/jeda yang diperlukan setelah mengukur dampaknya. Hentikan perubahan skema selama backup.
3. **Catat manifest.** Waktu, versi aplikasi dan DB, identitas backup, hash SHA-256 berkas, ukuran, daftar objek, hitungan baris, dan posisi log pemulihan yang terkait. Simpan kredensial pemulihan terpisah dari manifest dan repository.
4. **Simpan salinan independen.** Minimum satu salinan di luar home server, dengan akses terbatas dan enkripsi. Pastikan kunci pembukanya tersedia saat server utama gagal. Menaruh backup di SSD yang sama saja belum cukup.
5. **Restore ke DB baru yang kosong.** Gunakan lingkungan terisolasi dengan akses/integrasi keluar dimatikan. Periksa urutan pemulihan data dan trigger agar pemuatan data tidak menggandakan histori atau revisi. Validasi definisi, jumlah, dan perilaku trigger/view setelah restore.
6. **Bandingkan isi, bukan hanya jumlah.** Hitung hash deterministik per primary key dan agregat per tabel, termasuk primary key gabungan. Bedakan NULL, string kosong, tipe/encoding dan presisi tanggal; buat daftar pengecualian yang jelas untuk nilai teknis yang memang berubah. Bandingkan dengan manifest dari snapshot yang sama, bukan DB hidup yang masih menerima input.
7. **Uji perubahan setelah backup.** Siapkan serta uji rantai backup + log transaksi native mesin target untuk pemulihan ke titik waktu, atau mekanisme jurnal perubahan lengkap yang setara dan sudah dibuktikan. Cakup perubahan dari semua penulis, bukan hanya autosave. Tanda terima operasi autosave dan log aktivitas aplikasi saja bukan jurnal pemulihan penuh. Simulasikan transaksi diakui, respons hilang, lalu crash dan pulihkan tanpa duplikasi.
8. **Ukur waktu dan ruang.** Catat waktu backup, restore, verifikasi, dan replay. Ukur ruang untuk database aktif, salinan pemulihan, backup, log, serta artefak lama. Tetapkan anggaran jeda layanan berdasarkan pengukuran, bukan perkiraan kapasitas SSD 60 GB.

Rantai pemulihan harus mencakup transaksi sampai penulisan dihentikan saat insiden, termasuk posisi dan retensi log. Konfigurasi durabilitas commit mesin DB serta penyimpanan volume harus diperiksa. Backup atau log yang hanya ada pada disk utama tidak memberikan target kehilangan nol bila disk tersebut rusak; jaminan terhadap kegagalan fisik memerlukan mekanisme salinan/durabilitas yang sesuai dan diuji tersendiri.

**Gerbang wajib:** backend Go belum boleh menerima penulisan produksi sebelum restore penuh dan pemulihan perubahan setelah backup berhasil dibuktikan. Bila belum siap, tetap lanjutkan pekerjaan baca dan staging dengan PHP sebagai penulis produksi.

## 6. Kontrak penyimpanan dan satu penulis

### Kesamaan perilaku PHP dan Go

- Hadir + capaian menjadi resmi dalam satu transaksi setelah lengkap; snapshot belum lengkap disimpan sebagai draft. Mengosongkan sementara kolom pada catatan resmi tidak menghapus versi resmi sebelumnya.
- Kosong tetap Belum Dicatat, tidak menjadi Alfa. Sakit/Izin mengikuti kelengkapan alasan. Finalisasi memeriksa ulang keadaan server dan tidak menimpa perubahan baru.
- Hak akses, penugasan, keanggotaan, jadwal/libur, histori roster, CSRF, dan program tetap diperiksa server. Kalender Ahad yang sekarang dipakai tidak diganti tanpa aturan jadwal Ibu yang terkonfirmasi.
- Pertahankan `operation_id`, hash permintaan, hasil operasi, `draft_revision`, `official_revision`, dan `context_revision`. Retry operasi PHP yang sudah commit harus dikenali oleh Go, dan sebaliknya. Serialisasi dan hash diuji dengan fixture kontrak yang sama, termasuk teks Indonesia, NULL, angka dan tanggal.
- Penyimpanan catatan, revisi dan tanda terima operasi harus atomik. Jika proses mati setelah commit tetapi sebelum respons, pengiriman ulang mengembalikan hasil operasi sebelumnya. Respons lama tidak boleh menandai perubahan lokal yang lebih baru sebagai sudah terkirim.
- Koreksi, hapus, merge, pindah kelompok, perubahan roster, dan administrasi harus memperbarui revisi/konteks atau memblokir draft lama dengan penjelasan. Trigger lama dipertahankan dan diuji supaya revisi tidak hilang atau naik ganda karena implementasi baru.

### Pembatasan penulis di server

Pengalihan tidak cukup dengan menyembunyikan tombol atau memilih route di frontend. Tambahkan pemilik penulisan dan nomor generasi pengalihan yang diperiksa **di dalam transaksi sebelum mutasi**, dengan penguncian yang membuat transaksi lama selesai/ditolak sebelum pemilik berubah. PHP dan Go harus memakai pembatas yang sama sebelum pilot tulis dimulai. Akun DB aplikasi tidak boleh mengubah kepemilikan itu secara bebas.

Lingkup kepemilikan mengikuti identitas catatan resmi yang bisa bertabrakan: minimal peserta/wali dan sesi kanonis, bukan hanya nama program atau kelompok. Revisi saat ini memakai pasangan wali–tanggal sesi. Jika peserta muncul di dua kelompok atau dipindah, kedua jalur tidak boleh mendapat pemilik berbeda untuk catatan yang sama. Finalisasi satu sesi harus memakai pemilik yang konsisten untuk semua catatannya.

Inventaris wajib mencakup form lama/`legacy=1`, API autosave, `capaian.php`, `laporan.php`, `libur.php`, penghapusan kelompok, merge/pengarsipan peserta, impor/restore, API integrasi, skrip CLI, dan job terjadwal yang benar-benar berjalan. Jalur yang belum memakai pembatas dinonaktifkan untuk lingkup pilot; mutasi massal yang menyentuhnya ditunda atau ditangani satu transaksi melalui penulis yang berwenang. Trigger efek samping tetap bagian transaksi penulis tersebut. Akses SQL manual menjadi prosedur pemeliharaan, bukan jalur pengisian alternatif.

Pada tahap awal, login/sesi tetap ditangani PHP. Akses Go memakai gateway server yang memvalidasi sesi/role setiap permintaan; perluasan untuk guru dan CSRF harus diuji sebelum dibuka. Akun SELECT-only pratinjau dipertahankan terpisah. Go yang menulis memperoleh akun terpisah dengan izin minimum, tanpa DDL atau hak pengaturan pengguna; kredensial tidak dikirim ke browser. Port Go tetap internal.

## 7. Menjaga draft di HP dan tab lama

- Pertahankan origin aplikasi, nama database IndexedDB `tahsin-autosave-v1`, namespace akun/program/sesi, identitas peserta, payload, antrean dan operasi yang sedang dikirim. Vue tidak boleh memulai penyimpanan kosong yang mengabaikan data PHP.
- Buat adaptor kompatibel yang dibuktikan dengan membuka draft dari frontend lama di frontend baru dan sebaliknya. Perubahan versi IndexedDB harus aditif dan diuji dengan dua tab; jangan menghapus store atau mengosongkan antrean pada deploy, logout, konflik, atau kegagalan jaringan.
- Input tetap langsung diperbarui dan ditulis lokal; debounce hanya pengiriman jaringan: 600 ms, maksimum tunggu 2 detik. Label tersimpan di HP baru muncul setelah transaksi lokal berhasil. Catatan resmi baru ditandai tersimpan setelah server mengakuinya.
- Sebelum mengalihkan kelompok, guru membuka aplikasi dengan akun yang benar dan memeriksa antrean, draft, konflik dan operasi yang responsnya belum diketahui. Kirim ulang operasi terakhir dengan ID aslinya sampai statusnya pasti. Salinannya tidak dianggap aman hanya karena aplikasi sudah ditutup.
- Server tidak dapat membuktikan bahwa HP yang sedang offline tidak memiliki antrean. Perangkat guru yang belum dapat diperiksa menunda pengalihan kelompok terkait; jangan menganggap antrean kosong dari angka draft server saja.
- Tab PHP lama yang mengirim setelah pengalihan harus menerima respons kompatibel yang mempertahankan antrean dan mengarahkan pengiriman ke penulis baru. Jeda pemeliharaan bersifat dapat dicoba ulang, bukan dianggap sebagai data tidak valid atau konflik yang menghapus antrean. Mekanisme ini harus diuji pada klien lama sebelum pilot.
- Service worker/aset lama tetap tersedia selama masa kompatibilitas. Hindari pemaksaan reload editor aktif, penghapusan cache/IndexedDB, atau pergantian domain. Draft tersimpan server dipulihkan setelah login; membuka ulang aplikasi ketika offline penuh belum menjadi janji dukungan baru.

## 8. Urutan pengalihan produksi per kelompok

Ini adalah prosedur tahap 5, setelah seluruh gerbang tahap 0–4 lulus.

1. Pilih waktu di luar pengisian. Siapkan versi aplikasi lama, versi baru, artefak aset, konfigurasi, operator pengalihan dan pengamat verifikasi. Pastikan tidak ada impor, restore, merge, atau job administrasi yang bertabrakan.
2. Selesaikan pemeriksaan perangkat/antrean pilot. Selesaikan konflik secara eksplisit; jangan memilih versi terbaru berdasarkan jam HP saja.
3. Aktifkan jeda tulis terbatas untuk lingkup pilot di server. Tunggu transaksi lama selesai dan pastikan tidak ada penulis di luar pembatas. Klien menampung input lokal dan memperlihatkan status menunggu.
4. Buat titik backup/pemulihan dan baseline pada batas yang sama; catat posisi log, pemilik penulisan, revisi dan operasi terakhir. Bila jeda melewati anggaran hasil latihan, batalkan pengalihan dan buka kembali penulis lama setelah dipastikan aman.
5. Ubah pemilik secara atomik ke Go, naikkan generasi pengalihan, lalu buka akses tulis pilot. Pemeriksaan server menolak penulis lama tanpa membuang draftnya. Kelompok lain tetap PHP.
6. Jalankan satu operasi yang memang diperlukan guru sebagai uji awal dan verifikasi catatan resmi/draft, revisi, tanda terima, histori serta laporan. Jangan memasukkan peserta atau presensi fiktif ke produksi untuk mengetes.
7. Amati selama dua pertemuan: antrean, kesalahan, konflik, pemakaian sumber daya, dan kesamaan laporan. Bekukan perluasan bila ada selisih yang belum dijelaskan. Catat perubahan sah setelah baseline, sehingga input baru tidak dianggap sebagai kerusakan migrasi.

Masa kompatibilitas PHP dan Go menggunakan skema yang dapat dibaca kedua versi. Format baru yang tidak dipahami versi stabil belum boleh ditulis selama versi tersebut menjadi cadangan pemulihan.

## 9. Prosedur kembali ke versi stabil

| Kondisi | Tindakan | Perlindungan data |
|---|---|---|
| Pratinjau baca Go bermasalah | Matikan `TAHSIN_NEXT_ENABLED` dan hentikan layanan pratinjau bila perlu | Tidak ada penulisan Go atau rollback database |
| Tampilan Vue bermasalah, backend masih PHP | Kembali ke tampilan PHP melalui pengaturan rilis yang sudah diuji | Penyimpanan IndexedDB kompatibel, antrean dan operasi tetap ada |
| Go sudah menulis, data tetap benar | Jeda tulis, tunggu transaksi selesai, ambil titik pemulihan dan rekonsiliasi; kembalikan pemilik ke PHP secara atomik, lanjutkan retry | PHP memakai **database terkini**, termasuk data sah yang sudah ditulis Go |
| Ada indikasi data rusak | Hentikan penulisan lingkup terdampak, simpan DB dan log saat kejadian, tentukan rentang dampak | Restore ke **DB pemulihan baru**, replay perubahan sah setelah backup, verifikasi sebelum pengalihan koneksi terkoordinasi |

**Jangan menimpa database hidup dengan backup lama.** Tindakan itu dapat membuang input yang sah setelah waktu backup. Pemulihan kerusakan harus mempertahankan operasi valid, mengecualikan perubahan penyebab kerusakan berdasarkan bukti, dan memeriksa kesesuaian tanda terima/revisi. Jangan mengembalikan tanda terima sukses jika catatan yang diwakilinya tidak ikut pulih.

Jika hanya sebagian data terdampak tetapi pemulihan memakai seluruh database, seluruh penulis database harus dijeda pada batas akhir replay dan pengalihan koneksi. Jangan mengganti koneksi hanya sebagian layanan sehingga PHP dan Go menulis ke dua database berbeda. Sesudahnya kirim ulang antrean lokal dengan ID operasi asli dan cocokkan hasilnya.

Rencana ini tidak mengasumsikan pemulihan ke titik waktu sudah tersedia. Tanpa rantai pemulihan yang teruji, tahap produksi yang mengalihkan penulisan Go belum boleh dimulai.

## 10. Pengujian dan ukuran kelulusan

| Area | Skenario wajib | Hasil yang disyaratkan |
|---|---|---|
| Keutuhan data lama | Bandingkan ID, isi/hash baris, foreign key, objek DB dan laporan periode tetap | Tidak ada kehilangan/perubahan tanpa perubahan bisnis yang tercatat; seluruh selisih dijelaskan |
| Laporan Bapak | Hadir/Sakit/Izin/Alfa, total, konflik, kelengkapan historis, roster pindahan dan rapor | Sama dengan versi PHP untuk snapshot/filter yang sama; draft parsial tidak masuk laporan |
| Keluarga | Aktif berkelas, arsip, lulus vs keluar, saudara aktif + lulus, relasi belum lengkap | Identitas dan status tetap; tidak menggabungkan wali hanya karena nama mirip |
| Input dan pemulihan lokal | Ketik/pindah kolom/tutup detail, reload setelah simpan lokal, HP lambat, IndexedDB gagal, ganti akun | Draft tidak tertukar atau hilang diam-diam; kegagalan simpan terlihat |
| Jaringan dan server | Putus sebelum/saat/sesudah commit, respons hilang/terlambat, timeout, restart proses | Operasi dapat diulang tanpa duplikasi; perubahan lokal terbaru tetap ada |
| Konflik dan akses | Dua tab/perangkat, sesi kedaluwarsa, perubahan penugasan, roster/jadwal, CSRF dan akses lintas kelompok | Tidak menimpa diam-diam; konflik/akses tampil benar dan draft dapat ditinjau |
| Peralihan | PHP → Go → PHP, tab lama masih terbuka, guru offline, retry operasi lintas backend | Satu penulis per lingkup; perubahan yang sudah diakui server tetap ada |
| Administrasi | Koreksi/hapus/merge/pindah/arsip/libur dan integrasi yang menyentuh pilot | Pembatas penulis, histori, revisi dan pembatalan draft berlaku di semua jalur |
| Pemulihan penuh | Restore backup, replay transaksi baru, gangguan saat replay, verifikasi objek dan data | Pulih ke titik yang ditetapkan, tanpa menggandakan trigger, operasi atau catatan |
| Beban | Pola realistis 25, 50, lalu 100 guru di lingkungan setara/server target pada waktu terencana | Tentukan kapasitas dari hasil; tidak ada operasi hilang atau korup dan sumber daya memiliki ruang sisa |

Angka guru adalah **target uji**, bukan kapasitas yang sudah terbukti. Ukur latensi API autosave dari permintaan terkirim sampai respons diterima, di luar debounce; sasaran awal p95 di bawah 2 detik pada jaringan uji stabil, lalu sesuaikan berdasarkan baseline dan pilot. Target integritas tetap nol operasi diakui yang hilang dan nol duplikasi akibat retry. Tingkat kesalahan, antrean tertua, konflik, koneksi DB, CPU, RAM, ruang disk dan log juga dicatat; pengujian tidak boleh mengganggu pengisian produksi.

Uji regresi yang sudah ada menjadi dasar, bukan pengganti skenario di atas: `tests/run-tahsin-tests.php`, `tests/autosave-integration.php`, `tests/autosave-browser.cjs`, pengujian Go, dan pratinjau browser Go–Vue. Hasil lokal sebelumnya tidak membuktikan konfigurasi produksi, build container, durabilitas disk atau kapasitas home server.

## 11. Bukti rilis dan penghentian jalur lama

Simpan catatan untuk setiap gelombang: versi/commit dan hash artefak, versi skema, kelompok/perangkat yang sudah diperiksa, pemilik/generasi penulisan, waktu jeda, identitas backup dan posisi log, baseline/hash, hasil restore/replay, hasil laporan, hasil uji, serta nama operator dan pemeriksa. Bukti tidak berisi password atau data pribadi di repository publik. Bila salah satu bukti keutuhan/pemulihan tidak tersedia, status gelombang adalah belum siap.

Retensi awal yang direncanakan: simpan versi PHP yang kompatibel, aset lama, backup sebelum pengalihan, dan catatan operasi minimal **30 hari setelah perluasan penuh**, melewati sedikitnya dua pertemuan pengamatan, serta sampai seluruh perangkat/antrean lama sudah terhitung. Sesuaikan kapasitas penyimpanan dan retensi log agar titik pemulihan yang dijanjikan tetap dapat dicapai. Jangan memangkas tanda terima operasi yang masih mungkin dikirim ulang klien.

Setelah itu, penutupan jalur lama dilakukan sebagai rilis tersendiri. Penghapusan tabel/kolom/histori tidak menjadi bagian otomatis migrasi stack; harus memiliki kebutuhan, cadangan dan verifikasi tersendiri. Tidak ada keharusan menghapus data Bapak untuk menyelesaikan migrasi.

Untuk pusat laporan mendatang, Tahsin tetap menjadi sumber data resminya. Pusat memakai API baca atau aliran perubahan dengan ID peristiwa dan posisi sinkronisasi yang dapat diulang; jika memakai outbox, pencatatannya satu transaksi dengan perubahan resmi. Konsumen mengatasi duplikasi dan dapat membangun ulang laporan. Draft tidak dihitung sebagai capaian resmi. Relasi Anak–Bapak–Ibu memakai ID sumber dan crosswalk yang jelas; integrasi SPP dilanjutkan sebagai proyek terpisah tanpa mengubah identitas Tahsin.

## 12. Urutan pekerjaan terdekat

1. Selesaikan inventaris target, seluruh jalur tulis, dan perangkat pilot; tetapkan versi PHP stabil yang benar-benar terpasang.
2. Siapkan backup penuh, manifest/hash baris, dan latihan restore/replay pada lingkungan terisolasi. Ini adalah pekerjaan perlindungan data yang didahulukan.
3. Lengkapi paket deploy pratinjau Go–Vue, termasuk build aset Vue di image PHP, build container Go dan pemantauan layanan internal. Fitur tetap mati sampai pemeriksaan staging selesai.
4. Bangun kontrak baca sesi dan adaptor Vue untuk autosave PHP; buktikan kompatibilitas draft lama sebelum mengalihkan tampilan.
5. Baru lanjutkan Go sebagai penulis di staging, pembatas seluruh jalur tulis, latihan rollback, dan pilot produksi sesuai gerbang di atas.

Rujukan aturan yang tetap berlaku: [autosave](AUTOSAVE_ROLLOUT.md), [impor dan relasi keluarga](FAMILY_IMPORT.md), serta [implementasi pratinjau Go–Vue](GO_VUE_MIGRATION.md).
