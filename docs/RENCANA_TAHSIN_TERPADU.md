# Rencana produk Tahsin terpadu: Bapak dan Ibu

Tanggal: 21 Oktober 2026 (Asia/Jakarta).
Status: rancangan produk; alur autosave sudah diimplementasikan lokal. Lihat AUTOSAVE_ROLLOUT.md untuk aktivasi dan batas dukungan. Keputusan terbaru pengguna: seluruh peserta dalam daftar, draft otomatis di HP dan server, termasuk isian parsial.

## 1. Keputusan yang sudah disepakati

- Mengembangkan satu aplikasi Tahsin untuk program Bapak dan Ibu dengan stack aplikasi yang sekarang: PHP, MySQL/MariaDB, Tailwind CSS, dan Alpine.js.
- Menyiapkan integrasi ke Data Induk dan pusat laporan lintas aplikasi, termasuk SPP.
- Mengutamakan penggunaan melalui HP, navigasi sederhana, pendataan cepat, dan keandalan penyimpanan.
- Untuk peserta yang hadir, presensi dan capaian harus lengkap serta tersimpan bersamaan. Guru tidak diminta melengkapi capaian pada alur terpisah setelah presensi resmi tercatat.

Keputusan produk lain di bawah adalah usulan yang perlu diuji lewat prototipe dan penggunaan guru. Target waktu adalah sasaran pengujian, belum hasil pengukuran aplikasi sekarang.

## 2. Situasi penggunaan yang menjadi acuan

Guru Ibu bisa membuka aplikasi sambil mengurus anak, berhenti sebentar saat mengajar, berpindah aplikasi, dan memakai koneksi yang berubah-ubah. Alur utama harus tetap mudah dilanjutkan setelah interupsi.

Pekerjaan utama guru dalam satu pertemuan:

1. Membuka sesi dan memastikan kelompok serta tanggalnya benar.
2. Menemukan peserta yang sedang mendapat giliran.
3. Memilih status; untuk Hadir, melengkapi capaian dan hasil bacaan.
4. Memantau status autosave dan berpindah ke peserta berikutnya.
5. Memeriksa ringkasan, kemudian menyelesaikan sesi.

Pengelolaan peserta, akun, perpindahan kelompok, jadwal, dan laporan lintas kelompok menjadi pekerjaan admin/PJ. Guru hanya menerima akses yang sesuai penugasan, termasuk penugasan guru pengganti jika diperlukan.

## 3. Temuan pada aplikasi saat ini

| Area | Dasar pemeriksaan | Implikasi rencana |
| --- | --- | --- |
| Halaman awal guru | `ustadz/dashboard.php`, `ustadz/input-presensi.php` | Jalur menuju input dapat dipersingkat dengan halaman Hari Ini dan sesi yang relevan. |
| Formulir peserta | `ustadz/form-presensi.php` | Sudah ada simpan otomatis, tetapi setiap peserta mempunyai banyak kontrol dan formulir dapat memanjang. Perlu tampilan daftar ringkas serta satu editor peserta aktif. |
| Kelengkapan Hadir | `includes/attendance_helper.php::attendanceNormalizePayload` | Backend sudah memvalidasi materi dan hasil. Aturan kelengkapan ini dipertahankan dalam alur baru. |
| Simpan data | `includes/attendance_helper.php::attendanceSaveEntries` | Sudah ada transaksi, penguncian kelompok, dan validasi kepemilikan. Fondasi ini digunakan kembali serta dilengkapi penanganan pengiriman ulang dan revisi data. |
| Simpan saat koneksi gagal | `ustadz/form-presensi.php`, `sw.js` | Ada pesan gagal, tetapi belum ditemukan antrean input persisten untuk pemulihan setelah halaman ditutup. PWA saat ini menyimpan aset statis; itu belum menyediakan pemulihan input offline. |
| Kalender | `includes/tahsin_report_helper.php`, `includes/attendance_helper.php` | Pengelompokan per Ahad, libur Ahad pertama, dan batas 07.30 masih terikat aturan Bapak. Ibu memerlukan konfigurasi sesi sendiri. |
| Identitas keluarga | `includes/data_induk_helper.php` | Sinkronisasi lokal masih memilih relasi ayah. Relasi ibu dan peserta perlu dimodelkan secara eksplisit. |
| Finalisasi | `includes/attendance_helper.php::attendanceNormalizeSubmission` | Formulir lama dapat menjadikan pilihan kosong sebagai Alfa saat finalisasi. Rancangan baru mengusulkan peninjauan peserta yang belum dicatat secara eksplisit. |

Pemeriksaan di atas adalah pembacaan kode, bukan audit produksi atau pengukuran performa. Tidak ada kesimpulan bahwa aplikasi sekarang sudah bebas konflik atau siap offline.

## 4. Bentuk pengalaman guru

### Navigasi utama

Tiga tujuan tetap pada HP: **Hari Ini**, **Riwayat**, dan **Akun**.

- Hari Ini menampilkan sesi terjadwal, kelompok, tanggal, jumlah peserta, dan kemajuan pencatatan.
- Riwayat berisi sesi terdahulu, pencarian peserta, dan koreksi sesuai kewenangan.
- Akun berisi identitas guru, bantuan singkat, pemasangan aplikasi bila tersedia, dan keluar.
- Tindakan input berada di dalam sesi; tidak membutuhkan menu admin atau halaman grafik.
- Guru yang hanya ditugaskan pada program Ibu tidak perlu memilih program setiap masuk. Pilihan program muncul ketika guru memang punya penugasan di beberapa program.

### Masuk ke sesi

- Setelah login, jika hanya ada satu sesi aktif yang relevan, tampilkan langsung sesi tersebut dengan tanggal dan kelompok yang jelas.
- Jika ada beberapa kelompok/sesi, tampilkan pilihan ringkas dengan status Belum Mulai, Dalam Pengisian, atau Selesai.
- Saat belum ada jadwal hari ini, jelaskan keadaan dan tampilkan sesi relevan; jangan diam-diam menggunakan tanggal lama.
- Link dari pengingat boleh menuju sesi tertentu, tetap melalui pemeriksaan login dan penugasan server.
- Tanggal lampau diberi penanda jelas. Perubahan tanggal tidak menghapus draft sesi lain.

### Daftar peserta

- Tampilkan nama peserta, identitas anak sebagai pembeda jika relevan, status input, dan ringkasan capaian.
- Sediakan pencarian nama serta pilihan Semua / Belum Lengkap.
- Gunakan urutan stabil; nama tidak meloncat ketika guru sedang membaca atau menekan tombol.
- Tampilkan kemajuan, misalnya 8 dari 12 lengkap, 2 menunggu koneksi, 2 belum dicatat. Catatan yang menunggu server belum dihitung sebagai lengkap di server.
- Ketuk peserta membuka editor di halaman yang sama. Tombol Kembali mempertahankan posisi daftar.
- Sediakan perpindahan ke peserta berikutnya tanpa kembali ke daftar; guru tetap bisa memilih peserta sesuai urutan talaqqi yang sebenarnya.

### Editor peserta

1. Nama peserta, kelompok, dan tanggal terlihat jelas.
2. Pilihan berlabel lengkap: Hadir, Sakit, Izin, Alfa.
3. Saat Hadir dipilih, tampilkan isian sesuai jenis materi saja.
4. Jenis materi, jilid/surat, dan posisi bacaan sebelumnya ditampilkan sebagai referensi. Hasil pertemuan baru tetap harus dikonfirmasi guru.
5. Materi sebelumnya boleh membantu mengisi jenis materi dan jilid/surat. Rentang bacaan hari ini dan hasil Lulus/Ulang memerlukan pilihan yang jelas; tidak boleh otomatis dianggap tercapai.
6. Catatan tambahan disembunyikan di balik Tambah Catatan. Alasan Sakit/Izin tetap mengikuti kebijakan program dan bisa dibantu pilihan ringkas yang benar-benar dipilih guru.
7. Tidak ada tombol Simpan. Semua perubahan disimpan otomatis; tombol **Berikutnya** dan **Lihat Ringkasan** hanya mengatur navigasi.
8. Data yang belum lengkap menampilkan petunjuk di dekat isian terkait, mempertahankan seluruh pilihan, dan belum dikirim sebagai catatan resmi.

Target isian Hadir yang umum: jenis materi/jilid sudah terbantu referensi, guru memilih rentang halaman atau ayat serta hasil. Tugasnya dapat selesai melalui pilihan singkat tanpa mengetik ulang identitas.

### Ringkasan akhir

- Tampilkan jumlah Hadir/Sakit/Izin/Alfa, peserta belum lengkap, antrean pengiriman, dan konflik.
- Guru dapat langsung membuka peserta yang bermasalah.
- Tombol **Selesaikan Sesi** baru berhasil setelah server memastikan semua peserta wajib sudah tercatat lengkap dan tidak ada konflik yang menghalangi.
- Pilihan kosong tetap Belum Dicatat. Usulan alur baru: peserta tersisa diputuskan statusnya secara eksplisit, dengan tanggal dan nama terlihat, sebelum sesi selesai.
- Perubahan kebijakan finalisasi Bapak tidak diterapkan diam-diam. Perilaku lama dipertahankan pada jalur lama sampai migrasi alur Bapak ditinjau dan diuji.

### Pedoman antarmuka

- Tombol utama dan kontrol status menargetkan area sentuh setidaknya 44 x 44 CSS px; tempatkan aksi penting mudah dijangkau ibu jari.
- Nama dan isian utama terbaca pada lebar 360 px, tanpa gulir horizontal; teks tetap dapat diperbesar.
- Gunakan label teks beserta warna, sehingga arti status tidak bergantung pada warna saja.
- Hindari modal bertingkat dan konfirmasi berulang untuk tindakan rutin. Koreksi tetap mudah ditemukan; penghapusan/finalisasi massal menampilkan dampaknya.
- Keyboard angka muncul untuk halaman/ayat; pemilihan surat memiliki pencarian agar tidak menggulir daftar panjang.
- Tombol bawah tidak menutup isian, pesan validasi, atau kontrol saat keyboard HP terbuka.
- Petunjuk simpan memakai bahasa pengguna: Draft di HP, Menunggu koneksi, Tersimpan, atau Perlu ditinjau.

## 5. Penyimpanan yang dapat dipahami dan dipulihkan

Presensi resmi dan capaian Hadir adalah satu paket atomik di server. Draft lokal dan server boleh belum lengkap, tetapi tidak masuk laporan resmi atau persentase kehadiran.

| Keadaan | Makna bagi guru | Masuk laporan resmi? |
| --- | --- | --- |
| Belum Dicatat | Belum ada pilihan yang cukup | Tidak |
| Draft di HP | Pilihan belum lengkap tersimpan pada browser/perangkat ini | Tidak |
| Draft server | Pilihan belum lengkap diterima server dan dapat dipulihkan oleh akun yang sama | Tidak |
| Menunggu koneksi | Paket lengkap sudah masuk antrean lokal, belum diakui server | Tidak |
| Tersimpan | Server mengakui paket lengkap beserta revisinya | Ya |
| Perlu ditinjau | Ditolak karena revisi/penugasan/aturan sesi berubah | Hanya versi resmi server yang sudah sah |

Alur simpan yang diusulkan:

1. Perubahan isian disimpan sebagai draft lokal yang terpisah menurut akun, program, sesi, dan peserta. Kegagalan penyimpanan lokal harus terlihat.
2. Setiap perubahan langsung memperbarui draft lokal. Pengiriman server dijadwalkan setelah jeda 600 ms, maksimal 2 detik saat mengetik terus-menerus. Draft parsial disimpan terpisah; paket lengkap dipromosikan otomatis ke catatan resmi.
3. Setelah penulisan antrean lokal benar-benar berhasil, guru boleh lanjut ke peserta lain. Status menunggu tetap terlihat hingga ada pengakuan server.
4. Server memvalidasi identitas, akses, sesi, keanggotaan historis, kelengkapan, versi data, dan identitas permintaan sebelum melakukan transaksi.
5. Pengakuan server memperbarui catatan resmi lokal. Antrean baru dibersihkan setelah pengakuan yang cocok diterima.
6. Pengiriman diulang saat aplikasi terbuka/kembali aktif dan koneksi kembali. Sediakan Coba Lagi. Jangan bergantung pada aplikasi yang terus berjalan di latar belakang.

Ketahanan yang wajib dirancang:

- Ketukan ganda dan pengiriman ulang memakai identitas operasi yang sama untuk paket yang sama, sehingga tidak membuat catatan ganda. Paket berubah menggunakan operasi baru.
- Perubahan per peserta dikirim berurutan. Revisi server diperiksa agar respons terlambat atau perangkat lain tidak menimpa perubahan terbaru secara diam-diam.
- Jika koneksi putus setelah server menyimpan, pengiriman ulang mengembalikan hasil operasi semula.
- Jika guru menekan Kembali, berpindah aplikasi, atau memuat ulang, draft/antrean dapat dipulihkan pada akun dan browser yang sama selama penyimpanan browser masih tersedia.
- Jika login kedaluwarsa, draft dipertahankan dan dikirim setelah login kembali oleh akun yang sesuai. Akun lain tidak boleh membaca atau mengirimkannya.
- Penolakan validasi, perubahan roster, atau konflik tidak diulang tanpa batas; catatan ditandai Perlu ditinjau dan draft tetap tersedia.
- Finalisasi membaca keadaan terbaru di server dalam transaksi dan mengunci sesi terhadap perubahan yang tidak sah. Koreksi setelah sesi selesai mengikuti kewenangan dan jejak perubahan.
- Pembaruan versi aplikasi tidak boleh memaksa reload ketika masih ada editor/antrean aktif; format draft memiliki versi dan jalur pemulihan.
- Logout dengan draft/antrean menjelaskan keadaan dan menyediakan pilihan yang jelas; data tidak dikirim sebagai akun berikutnya. Penghapusan data perangkat dilakukan secara eksplisit.

**Batas dukungan awal:** koneksi boleh terputus setelah sesi dimuat. Membuka sesi baru pertama kali tetap memerlukan internet. Pemulihan setelah tutup browser memerlukan penyimpanan browser yang masih utuh; draft lokal tidak otomatis berpindah perangkat dan bukan cadangan server. Pembukaan ulang seluruh sesi tanpa koneksi dapat menjadi pengembangan lanjutan dengan shell statis terautentikasi dan aturan penyimpanan roster yang jelas. Jangan menyimpan halaman PHP pribadi secara sembarang dalam cache.

## 6. Model bersama Bapak dan Ibu

- Program memiliki jadwal, libur, waktu selesai, tenggat, dan aturan koreksi sendiri. Jadwal Ibu tidak diasumsikan sama dengan Ahad Bapak.
- Sesi adalah pertemuan eksplisit yang memiliki ID, program, kelompok, tanggal/waktu, penanggung jawab, dan status. Pembatasan satu catatan per pekan pada model lama tidak dijadikan asumsi semua program.
- Peserta merujuk identitas orang. Keikutsertaan peserta dalam program/kelompok terpisah dari identitasnya.
- Ayah dan ibu memiliki ID wali masing-masing. Hubungan wali-anak mendukung lebih dari satu anak dan tidak memakai nama/nomor HP sebagai kunci utama.
- Keanggotaan dan penugasan guru memiliki periode berlaku. Roster sesi/historinya tidak ditulis ulang mengikuti keadaan kelompok hari ini.
- Catatan per peserta per sesi memiliki aturan unik di database setelah audit dan migrasi duplikasi lama. Penempatan lintas kelompok mengikuti kebijakan program yang eksplisit.
- Untuk Hadir, status dan capaian divalidasi serta disimpan bersama. Untuk Sakit/Izin/Alfa, capaian tidak diwajibkan.
- Laporan membedakan Belum Dicatat, Draft, Capaian Tidak Lengkap pada data lama, konflik, dan ketidakhadiran yang sudah dikonfirmasi.
- Tampilan guru menggunakan istilah Guru/Pengajar yang sesuai; pengembangan program tidak bergantung pada label Bapak atau role teknis bernama ustadz.

## 7. Performa dan kestabilan

Usulan teknis mengikuti stack yang sudah ada. Penggantian bahasa/framework tidak menjadi prasyarat perbaikan alur.

- Halaman guru memuat data untuk sesi/kelompoknya saja; grafik dan rekap lintas program dimuat pada halaman laporan sesuai kebutuhan.
- Gunakan editor peserta yang dipakai ulang, bukan seluruh kontrol detail setiap peserta terbuka bersamaan.
- Daftar serta referensi materi dimuat secara efisien; hindari permintaan jaringan terpisah untuk setiap baris saat membuka daftar.
- Gunakan indeks sesuai kueri program, sesi, keanggotaan, dan peserta setelah melihat rencana kueri serta data representatif.
- Permintaan memiliki batas waktu dan kegagalan yang jelas. Pengulangan dibatasi dengan jeda bertahap; satu kesalahan peserta tidak membuat halaman lain berhenti.
- Pusat laporan disinkronkan melalui pekerjaan terpisah setelah transaksi utama tersimpan. Gangguan pusat laporan tidak menunda pencatatan guru.
- Jalankan pencatatan galat dan pengukuran durasi simpan tanpa membocorkan kredensial atau isi pribadi yang tidak diperlukan; hubungkan kegagalan dengan ID permintaan untuk penelusuran.
- Uji jalur tanpa JavaScript untuk pengiriman daring atau sediakan jalur cadangan yang memakai validasi domain yang sama. Fitur antrean lokal tetap membutuhkan browser yang mendukungnya dan harus menjelaskan bila tidak tersedia.

## 8. Sasaran penerimaan yang diusulkan

Semua sasaran perlu dibandingkan dengan baseline aplikasi sekarang. Pengujian HP nyata lebih penting daripada hasil laptop pengembang saja.

| Aspek | Target awal | Cara menilai |
| --- | --- | --- |
| Masuk ke sesi | Maksimal satu tindakan navigasi dari Hari Ini; sesi tunggal langsung terbuka | Guru sudah login, penugasan dan tanggal benar |
| Input Hadir | Median <= 15 detik per peserta pada skenario capaian umum | Waktu pendataan saja, tidak termasuk membaca/mengajar; catat juga p90 dan kesalahan |
| Input ketidakhadiran | Median <= 5 detik untuk status dan alasan ringkas bila diperlukan | Skenario Izin/Sakit/Alfa |
| Kegunaan | Minimal 4 dari 5 guru pilot menuntaskan skenario umum tanpa bantuan setelah pengenalan singkat | Amati pilihan salah, langkah mundur, dan pertanyaan |
| Tampilan awal | LCP <= 2,5 detik pada p75 | HP dan koneksi representatif; bedakan kunjungan pertama/berikutnya |
| Respons interaksi | INP <= 200 ms pada p75 | Pemilihan status, membuka editor, dan perpindahan peserta |
| Konfirmasi server | p95 <= 2 detik saat koneksi normal yang didefinisikan dalam pengujian | Ukur dari pengiriman sampai pengakuan, terpisah dari simpan lokal |
| Integritas | Tidak ada duplikasi atau catatan Hadir tanpa capaian akibat alur baru dalam seluruh uji wajib | Verifikasi database, UI, dan laporan |
| Pemulihan | Seluruh draft/antrean pada skenario interupsi yang didukung kembali sesuai isinya | Uji reload, koneksi, dan login |
| Rilis pilot | Tidak ada galat kritis terbuka yang membuat input resmi hilang, salah peserta/sesi, atau bocor lintas akun/program | Tinjauan hasil pengujian dan pemantauan dua sesi pilot |

Ambang LCP/INP merujuk Core Web Vitals. Angka waktu pendataan dan konfirmasi simpan adalah sasaran produk yang diusulkan. Tidak ada janji aplikasi tanpa bug/crash; pencegahan, deteksi, dan pemulihan harus dapat diuji.

## 9. Skenario pengujian wajib

| Skenario | Hasil yang harus terjadi |
| --- | --- |
| Hadir dipilih, capaian belum lengkap | Tetap draft; server menolak catatan resmi yang tidak lengkap |
| Status diganti Hadir menjadi Izin | Payload akhir konsisten; capaian Hadir tidak tersisa sebagai hasil pertemuan Izin |
| Ketuk Simpan dua kali / permintaan dikirim ulang | Satu operasi resmi, tanpa duplikasi |
| Koneksi mati sebelum/dalam/sesudah simpan | Isi terpulihkan; status sesuai pengakuan server; pengiriman ulang aman |
| Muat ulang, tutup tab, atau berpindah aplikasi | Draft/antrean pulih sesuai batas dukungan penyimpanan lokal |
| Penyimpanan HP penuh atau ditolak | Tidak menampilkan klaim Draft di HP bila penulisan gagal; tampilkan pilihan mencoba lagi/menyimpan daring |
| Login kedaluwarsa; masuk kembali | Draft tetap terkait akun semula dan hanya dikirim setelah autentikasi sah |
| Beralih ke akun lain pada HP yang sama | Tidak ada tampilan atau pengiriman draft akun sebelumnya |
| Dua tab/perangkat mengubah peserta yang sama | Konflik revisi terlihat; perubahan baru tidak ditimpa diam-diam |
| Tanggapan jaringan datang tidak berurutan | Tampilan dan catatan server mengikuti revisi yang sah |
| Roster/guru/tanggal/libur berubah setelah form dibuka | Server memeriksa ulang; pesan jelas dan draft dipertahankan |
| Finalisasi saat ada draft/antrean/konflik | Sesi tidak ditandai selesai sebelum server memastikan syarat terpenuhi |
| Jadwal Ibu pada hari selain Ahad / dua sesi satu pekan | Catatan terhubung sesi yang benar dan tidak tertolak karena aturan Bapak |
| Akun guru Ibu mencoba kelompok Bapak tanpa penugasan | Akses ditolak di server, termasuk lewat URL/API langsung |
| Migrasi dan laporan Bapak lama | Jumlah peserta, status, capaian, historis, dan persentase cocok dengan baseline yang disetujui |
| Pusat laporan gagal dihubungi | Simpan Tahsin tetap berjalan; integrasi dapat mengejar perubahan kemudian |
| Versi aplikasi diperbarui dengan antrean lama | Draft dan antrean tetap dapat dipulihkan/dikirim melalui migrasi yang teruji |

Perangkat awal: minimal HP Android kelas rendah/menengah milik pengguna, Chrome Android, dan Safari iPhone bila digunakan guru. Uji lebar 360 px, pembesaran teks, keyboard terbuka, serta jaringan dengan latensi/koneksi putus yang dikendalikan.

## 10. Tahapan implementasi dan syarat melanjutkan

### Tahap A — Validasi alur dan baseline

- Ukur alur Bapak saat ini: langkah menuju input, waktu pendataan, dan kegagalan simpan yang ditemukan.
- Buat prototipe Hari Ini, daftar peserta, editor Hadir, dan ringkasan.
- Uji kepada 3–5 guru Ibu yang mewakili kebiasaan penggunaan berbeda.
- Catat aturan jadwal Ibu, bentuk capaian sebenarnya, penugasan guru pengganti, dan batas koreksi sebelum mengunci detail model.
- Hasil: alur yang dipahami guru dan daftar hambatan berurutan berdasarkan dampaknya.

### Tahap B — Fondasi data dan penyimpanan

- Perluas identitas wali, keikutsertaan program, sesi, dan penugasan dengan migrasi bertahap.
- Pertahankan data asli serta pemetaan histori Bapak; perubahan skema tambahan harus bisa dipakai bersama jalur lama selama transisi.
- Siapkan identitas operasi, revisi catatan, transaksi paket lengkap, serta pemulihan draft/antrean.
- Audit duplikasi dan uji integrasi database sebelum menambah constraint.
- Hasil: uji integritas, penugasan, dan regresi histori lulus.

### Tahap C — Alur guru dan pilot Ibu

- Implementasikan tiga tujuan navigasi, daftar seluruh peserta dengan satu detail terbuka, referensi capaian terakhir, autosave, dan ringkasan.
- Aktifkan untuk 1–2 kelompok Ibu melalui pengaturan fitur; jalur Bapak lama tetap tersedia selama transisi.
- Gunakan minimal dua pertemuan untuk mengamati interupsi, koreksi, kebiasaan pendataan, dan target waktu.
- Hasil: hambatan guru utama tertangani dan tidak ada masalah integritas kritis terbuka.

### Tahap D — Perluasan dan integrasi laporan

- Perluas kelompok Ibu; gunakan alur yang sudah teruji untuk Bapak dengan pengaturan programnya sendiri.
- Sinkronkan catatan resmi, revisi, pembatalan, dan status sesi ke pusat laporan.
- Tampilkan waktu sinkronisasi terakhir dan kesalahan integrasi pada halaman admin.
- Rekonsiliasi jumlah catatan sumber dan pusat; siapkan pengejaran ulang yang aman.
- Hasil: laporan sumber dan pusat konsisten untuk periode uji, termasuk koreksi historis.

Setiap tahap mempunyai jalur pemulihan: cadangan yang diuji pemulihannya, pengaturan untuk kembali ke UI stabil, dan kompatibilitas data selama transisi. Kembali ke kode lama tidak boleh menghapus catatan Ibu yang baru atau mengharuskan pemulihan database yang menimpa pencatatan terbaru.

## 11. Batas tahap awal

Prioritas: alur guru, kelengkapan presensi-capaian, identitas program/keluarga, jadwal yang benar, dan ketahanan simpan.

Fitur seperti dashboard grafik guru yang kompleks, input suara, rekomendasi capaian otomatis, pergantian stack, serta pembukaan sesi baru sepenuhnya offline dievaluasi setelah pilot. Pusat laporan dapat dibangun kemudian, sedangkan ID dan kontrak perubahan data disiapkan sejak fondasi.

## 12. Rujukan

- Kode proyek yang diperiksa tercantum pada bagian 3.
- [W3C: Target Size (Enhanced), 44 x 44 CSS px](https://www.w3.org/WAI/WCAG22/Understanding/target-size-enhanced.html). Dipakai sebagai sasaran area sentuh produk, bukan klaim kepatuhan seluruh WCAG.
- [web.dev: Core Web Vitals thresholds](https://web.dev/articles/defining-core-web-vitals-thresholds). Acuan sasaran LCP dan INP serta pengukuran p75.
- [MDN: Storage quotas and eviction criteria](https://developer.mozilla.org/en-US/docs/Web/API/Storage_API/Storage_quotas_and_eviction_criteria). Penyimpanan browser memiliki kuota dan dapat dihapus; batas tersebut harus tercermin dalam klaim pemulihan draft.
