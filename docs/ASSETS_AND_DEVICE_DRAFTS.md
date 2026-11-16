# Versi aset dan salinan draft perangkat

## Aset publik

`appAssetUrl()` pada `includes/asset_helper.php` menghitung SHA-256 isi file,
lalu menambahkan 16 digit awalnya ke URL. Halaman admin/guru/login, laporan,
rapor dan halaman error menggunakan helper yang sama. Jalur root maupun
instalasi subdirektori didukung. File privat, traversal, query buatan, aset
hilang dan symlink ditolak. Hash runtime berubah pada request berikutnya
setelah isi file berubah, sekalipun pengembang belum menjalankan build.

```powershell
npm run build
```

Build menjalankan CSS, vendor, lalu `build:public`. Tahap terakhir memberi
versi ikon dalam manifest dan memperbarui blok aset publik pada `sw.js`.
Hasil build harus ikut commit/rilis; tidak ada Node.js yang diperlukan pada
container PHP produksi. Daftar precache hanya mengambil CSS/JS publik tingkat
pertama, manifest dan ikon, bukan direktori prototipe atau data pengguna.

Worker v8 menggunakan hash kumpulan aset sebagai nama cache. URL versi berbeda
tidak memakai respons cache versi lain. Varian query sembarang dan tautan
lama tanpa versi dapat dimuat daring tetapi tidak ditambahkan sebagai aset
release saat ini. Respons HTML, redirect, opaque/error dan MIME yang keliru
tidak dicache. Seluruh respons install divalidasi sebelum penulisan cache.
Cache penuh/tidak tersedia tidak menghalangi aset dimuat melalui jaringan.

Cache halaman akun, navigasi pribadi, API dan POST tetap tidak dibuat.
Pembersihan cache terbatas pada instalasi aplikasi yang sama. Worker baru
tidak mengambil alih tab editor yang masih terbuka; draft IndexedDB tidak
disentuh oleh pembaruan cache. Registrasi meminta pemeriksaan worker melalui
jaringan, dan konfigurasi Apache/cPanel memberi `sw.js` header tanpa cache.
Pengujian router PHP lokal belum membuktikan header Apache yang sebenarnya.

## Draft perangkat

Setiap instance editor memakai ID klien baru. Tab yang diduplikasi tidak
berbagi kunci editor hanya karena browser menyalin `sessionStorage`.
Pemulihan tetap mencari draft tertunda pada scope akun/program/halaqoh/sesi
yang sesuai.

Sebelum pengguna memilih versi konflik, salinan isian perangkat, permintaan
yang belum mendapat kepastian dan versi server yang dibandingkan disimpan
pada kunci arsip baru. Penggantian versi tidak dilanjutkan jika arsip gagal
disimpan. Pengetikan yang terjadi saat arsip dibuat juga tidak ditimpa oleh
pilihan lama.

Setelah editor pemulihan mendapat balasan server untuk versi akhirnya,
sumber lama tidak lagi dihapus. Transaksi menambah salinan mandiri dan
mengarsipkan sumber pada kunci lama, hanya jika nomor urutnya masih sama.
Arsip tidak dikirim otomatis. Versi yang lebih baru dari sumber tidak ditandai
selesai oleh acknowledgement lama. Label arsip menyatakan pemulihan ke
editor lain; label tersebut tidak membuktikan payload sumber lama menjadi
catatan resmi terkini.

Peringatan keluar dipasang hanya ketika ada perubahan yang belum berhasil
disimpan ke perangkat. Ketika draft lokal sudah committed, halaman dapat
ditinggalkan sambil menunggu server. Peringatan browser tidak dijadikan
mekanisme penyimpanan: event keluar tidak selalu terjadi pada perangkat
seluler. Penulisan tetap dilakukan ketika isian berubah.

Jika roster, jadwal atau program berubah, pengiriman/finalisasi dihentikan
sampai halaman dibuka ulang. Isian yang tidak lagi memiliki baris roster
tetap disimpan dan dapat diunduh. Pada pembukaan sesi, draft tertunda dengan
ID yang tidak tampil atau program lama dalam halaqoh/sesi tersebut ditampilkan
sebagai draft di luar roster.

## Unduhan

Editor memiliki tombol **Unduh cadangan isian perangkat**. Halaman guru lain
memiliki tombol cadangan untuk seluruh draft akun pada perangkat tersebut,
termasuk arsip dan sesi lain. Tombol tetap tersedia ketika autosave sedang
dinonaktifkan atau skemanya belum siap. Pengunduhan tidak mengirim data
perangkat ke server dan tidak menghapus antrean. File berisi data pribadi.

Format JSON menyimpan versi format, nomor akun, waktu dan record asli.
Token CSRF permintaan dihilangkan dari salinan ekspor; data asli/receipt
operasi tidak ditulis ulang. Unduhan belum memiliki alur impor file melalui
antarmuka. Pemulihan lintas ID/roster masih memerlukan peninjauan yang harus
diselesaikan sebelum rilis audit keseluruhan.

## Pemeriksaan dan pekerjaan yang tersisa

```powershell
php tests/asset-tests.php
node tests/service-worker-tests.cjs
node tests/autosave-storage-tests.cjs
php -d extension=pdo_mysql tests/autosave-integration.php
node tests/security-http-tests.cjs
```

Tes storage/protocol memakai model transaksi deterministik, bukan mesin
IndexedDB browser. Tes worker juga memakai VM JavaScript dengan response/cache
tiruan. Keduanya tidak membuktikan dialog, durability browser, layout ponsel,
atau perilaku service worker terpasang pada perangkat nyata.

Pekerjaan yang masih diperlukan untuk autosave/recovery:

- Halaman pemulihan yang dapat membaca semua versi perangkat tanpa bergantung
  pada akses editor lama, meninjau versi server dan mengimpor salinan secara
  eksplisit tanpa pengiriman otomatis.
- Pemeriksaan alias ID dan receipt lama melalui API dengan otorisasi terhadap
  konteks peserta/halaqoh saat ini. ID/nama yang sama tidak boleh menjadi
  alasan pemetaan otomatis.
- Namespace instalasi dan identitas akun yang tahan pemakaian ulang nomor
  akun; scope format lama hanya berisi nomor akun/program/halaqoh/sesi dan
  memerlukan migrasi yang mempertahankan data serta peninjauan legacy.
- Pemeriksaan browser terhadap offline/reload, banyak tab, quota/penolakan
  penyimpanan, login ulang, perubahan roster, pemilihan konflik, ekspor dan
  instalasi/pembaruan PWA. Pemeriksaan tersebut belum selesai pada audit ini.

Dasar API: [IndexedDB transaction complete](https://developer.mozilla.org/en-US/docs/Web/API/IDBTransaction/complete_event),
[service worker register](https://developer.mozilla.org/en-US/docs/Web/API/ServiceWorkerContainer/register),
dan [beforeunload](https://developer.mozilla.org/en-US/docs/Web/API/Window/beforeunload_event).
