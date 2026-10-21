# Migrasi bertahap Go dan Vue

Rencana tahapan, perlindungan data, syarat pengalihan, dan pemulihan ada di [Rencana migrasi Go + Vue](RENCANA_MIGRASI_GO_VUE.md). Implementasi pilot Vue memakai autosave PHP, hasil backup/restore produksi terisolasi, dan build image home server dicatat di [runbook pelaksanaan](MIGRATION_SAFETY_RUNBOOK.md). Dokumen ini menjelaskan layanan pratinjau baca; cara mematikannya di bawah tidak berlaku sebagai prosedur rollback setelah Go mulai menulis data.

Kode berikutnya menyediakan [kontrak baca sesi guru Go](GO_SESSION_READ.md), terpisah dari pratinjau keluarga admin/PJ dan mati secara default. Editor masih membaca serta menyimpan melalui PHP.

Writer Go untuk database sintetis, pengunci penulisan dan bukti kompatibilitas PHP → Go → PHP tersedia dalam [panduan autosave staging](GO_AUTOSAVE_STAGING.md). Flag writer terpisah; tahap pratinjau baca dalam dokumen ini tetap memakai PHP sebagai penulis.

Tahap pertama menambahkan pratinjau **baca data keluarga** untuk admin/PJ Tahfidz. PHP tetap melayani aplikasi utama, login, semua penulisan presensi/capaian, draft, finalisasi, impor, serta laporan. Tidak ada migrasi skema atau impor data produksi untuk tahap ini.

## Yang sudah tersedia

- Backend Go 1.26, HTTP standar, driver MySQL `go-sql-driver/mysql` terkunci di go.mod/go.sum.
- Frontend Vue 3 + TypeScript, build Vite terpisah dari build/aset PHP lama.
- `go-vue.php` dan `api/go-vue-families.php` mati jika `TAHSIN_NEXT_ENABLED` bukan `1`.
- Login/role diperiksa PHP pada setiap pembacaan. PHP menandatangani metode, path, query, identitas, role, dan waktu permintaan ke Go. Kunci server tidak dikirim ke browser. Go menolak tanda tangan tidak valid/kedaluwarsa dan role guru. Tidak ada endpoint tulis.
- Go memakai konfigurasi `TAHSIN_NEXT_DB_*`, tanpa fallback ke kredensial `DB_*` PHP. Go menolak akun dengan hak tulis/DDL, hak grant, atau role database. Pembacaan dilakukan dalam transaksi READ ONLY dengan batas waktu dan pool maksimal empat koneksi pada tahap pilot.
- MySQL belum memiliki master keluarga: Go membaca identitas `santri_detail`/`wali_santri` lama. Identitas anak diberi `legacy_student_id`, peran ditampilkan sebagai wali terdata; Ibu tidak ditebak dari nama. Setelah master keluarga berisi data, Go memakai ID sumber dan ID wali yang sama dengan PHP.
- Filter awal menampilkan anak aktif yang memiliki kelas; pilihan Semua anak tetap memperlihatkan saudara yang lulus/keluar. Satu anak tetap satu baris dengan daftar wali terpisah.
- Mode `demo` memakai identitas fiktif dan ditandai jelas di UI. Kegagalan MySQL tidak dialihkan diam-diam ke demo.

Data pratinjau tidak disimpan di localStorage, IndexedDB, atau service worker. Permintaan browser menggunakan endpoint PHP yang `no-store`; data ditampilkan hanya di memori selama halaman terbuka. Antrean IndexedDB autosave PHP tidak disentuh. Browser tidak pernah menerima kredensial database atau kunci penghubung Go.

## Urutan tahap berikutnya

1. **Pratinjau keluarga (tahap ini):** jalankan terpisah, periksa kesamaan ID/relasi dan kegagalan akses pada staging.
2. **Baca sesi guru:** port aturan penugasan, jadwal, libur, histori roster, dan laporan. Bandingkan hasil dengan PHP pada salinan database yang sama.
3. **Vue memakai API PHP:** ganti tampilan secara terpisah dari backend tulis. Buktikan pemulihan draft lokal lama, operasi yang sedang dikirim, dan pengembalian tampilan ke PHP sebelum pilot guru.
4. **Penulisan Go di staging:** pindahkan aturan draft/resmi, revisi, tanda terima operasi, koreksi, dan finalisasi. Pertahankan format antrean browser dan operasi lama. Uji satu pemilik penulisan pada seluruh jalur server, backup lengkap, pemulihan transaksi setelah backup, serta pengembalian ke PHP dengan database terkini.
5. **Pilot backend Go:** aktifkan per kelompok setelah pemeriksaan perangkat, antrean, dan pemulihan lulus. Uji HP guru, koneksi putus, dua perangkat, login berakhir, dan perubahan roster. Jalankan 1–2 kelompok selama minimal dua pertemuan.
6. **Perluasan:** uji beban 25/50/100 guru dengan pola input yang realistis, cocokkan laporan serta histori Bapak, kemudian perluas berdasarkan hasil. Angka ini target uji, bukan kapasitas yang sudah dibuktikan. Ikuti seluruh gerbang pada rencana migrasi sebelum mengalihkan penulisan produksi.

## Menjalankan pratinjau lokal/staging

Gunakan terminal/runtime pengembangan terpisah; aplikasi Go tidak membaca file `.env` otomatis.

1. Build frontend dari root proyek:

   ```sh
   npm --prefix next/frontend ci --ignore-scripts
   npm --prefix next/frontend run build
   ```

   Hasil ada di `assets/go-vue/`, terpisah dari aset lama. Manifest menentukan nama aset dengan hash; build tidak menghapus aset pilot sebelumnya. Folder hasil build diabaikan Git. Sebelum mengaktifkan di server, build/paketkan hasil ini ke image PHP. Dockerfile PHP yang sekarang tetap dapat berjalan tanpa build baru karena fitur mati secara default; alur Git deploy perlu menambahkan langkah build ini sebelum pilot.

2. Buat kunci acak minimal 32 karakter, misalnya `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Simpan kunci yang sama hanya di environment PHP dan Go.
3. Atur environment kedua layanan: `TAHSIN_NEXT_ENABLED=1`, `TAHSIN_NEXT_SHARED_SECRET=<kunci-acak>`.
4. Environment Go: `TAHSIN_NEXT_DATA_SOURCE=demo`, `TAHSIN_NEXT_LISTEN=127.0.0.1:18090`. Jalankan `go run ./cmd/server` dari `next/backend`.
5. Environment PHP: `TAHSIN_NEXT_API_URL=http://127.0.0.1:18090`. Login admin/PJ lalu buka `go-vue.php` atau menu Pratinjau Keluarga. Akun guru tetap memakai aplikasi utama.

Untuk mode MySQL, gunakan akun khusus SELECT-only dan ubah `TAHSIN_NEXT_DATA_SOURCE=mysql`. Berikan SELECT hanya pada tabel yang dibutuhkan: `santri_detail`, `wali_santri`, serta `family_students`, `family_guardians`, `family_relations` jika sudah tersedia. Jangan menggunakan akun aplikasi PHP/root. Go memeriksa seluruh hak efektif yang dilaporkan SHOW GRANTS, termasuk hak bawaan PUBLIC di MariaDB; periksa konfigurasi tersebut jika akun ditolak. Tidak ada izin yang diubah oleh startup Go.

Isi `TAHSIN_NEXT_DB_HOST`, `TAHSIN_NEXT_DB_PORT`, `TAHSIN_NEXT_DB_DATABASE`, `TAHSIN_NEXT_DB_USERNAME`, `TAHSIN_NEXT_DB_PASSWORD` secara eksplisit. Gunakan TLS terverifikasi (`TAHSIN_NEXT_DB_TLS=true`) untuk koneksi yang memerlukannya. `skip-verify`, role, dan mode TLS tanpa verifikasi tidak diterima.

Pada container, jalankan Go sebagai layanan **internal** di jaringan yang sama dengan PHP; jangan publikasikan port Go ke internet. `next/backend/Dockerfile` menyediakan build terpisah. Alamat PHP menjadi, misalnya, `http://tahsin-next:8080`. TLS publik dan cookie login tetap ditangani aplikasi utama. File sumber `next/` ditolak Apache dan tidak dimasukkan ke image PHP; hanya aset hasil build yang diperlukan. Build image dan smoke test privat pada home server telah lulus pada 22 Oktober 2026; rincian versi/batas bukti ada di runbook.

## Pengembalian ke PHP

Set `TAHSIN_NEXT_ENABLED=0` pada PHP: menu dan endpoint pratinjau langsung tidak tersedia setelah environment diterapkan. Go dapat dihentikan secara terpisah. Tidak ada perubahan tabel/ID yang perlu dibatalkan, dan aplikasi utama tetap memakai jalur lama. Jika Go mati saat pratinjau terbuka, UI menunjukkan kegagalan dan tautan ke aplikasi utama.

## Verifikasi

- `go test ./...` dan `go vet ./...` dari `next/backend`: autentikasi, manipulasi identitas/query, waktu tanda tangan, penolakan semua metode tulis, parameter, ID, NIS, kegagalan, dan pembacaan paralel. Tes paralel ini bukan uji beban server produksi.
- `npm --prefix next/frontend run build`: pemeriksaan tipe dan build produksi.
- `php tests/go-vue-helper.php`: query kanonis dan pengikatan tanda tangan PHP.
- `php tests/run-tahsin-tests.php`: baseline laporan lama.
- Integrasi database: buat fixture dengan `php tests/autosave-integration.php tahsin_test_autosave_<nama-unik>` pada MariaDB localhost port 13317. `php tests/go-vue-fixture.php <database-fixture>` menambahkan akun SELECT-only; `--family` menambahkan keluarga fiktif dan menyimpan hasil PHP untuk perbandingan di `.sessions/go-vue-expected.json`. Fixture tidak menerima nama/host database produksi.
- Atur `TAHSIN_NEXT_MYSQL_TEST=1`, `TAHSIN_NEXT_TEST_SCHEMA=legacy` atau `family`, dan `TAHSIN_NEXT_DB_*` sesuai fixture/akun baca untuk menjalankan `TestMySQLReadOnlyIntegration`. Tes memastikan UPDATE/CREATE ditolak oleh akun database dan akun root ditolak startup.
- Jalankan server PHP lokal dengan `tests/go-vue-router.php`, port 18083, `TAHSIN_TEST_DB_NAME` menunjuk fixture. Jalankan Go pada port 18090 memakai akun baca fixture. `tests/go-vue-browser.cjs` memakai Playwright dan Chrome lokal; memeriksa batas akses, filter, saudara lulus, kesamaan ID PHP–Go, logout, kegagalan Go, dan tampilan HP. Router hanya dapat berjalan di PHP CLI development server.

Dependensi frontend terkunci di package-lock.json; jangan mengganti versi tanpa mengulang pemeriksaan yang relevan. Tahap ini belum mengalihkan guru, mengaktifkan lingkungan produksi, atau membuktikan kapasitas guru di home server.

Hasil verifikasi lokal 21 Oktober 2026: pengujian Go dan vet lolos; integrasi akun baca pada skema lama dan keluarga lolos; 12 pemeriksaan gateway PHP, 29 pemeriksaan browser, 147 regresi laporan lama, serta 25 pemeriksaan database autosave lama lolos. Build Vue dengan pemeriksaan tipe dan kompilasi biner Go untuk Windows/Linux berhasil. Build image Docker kemudian lulus pada home server, sebagaimana dicatat di runbook; uji beban belum dilakukan.

Referensi: [Vue dan TypeScript](https://vuejs.org/guide/typescript/composition-api), [Vite backend integration](https://vite.dev/guide/backend-integration), [Go MySQL driver](https://github.com/go-sql-driver/mysql), [Transaksi database Go](https://pkg.go.dev/database/sql#TxOptions).
