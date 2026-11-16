# Capaian peserta dan perlindungan versi lama

Daftar capaian admin dan guru memakai penyelesaian sesi yang sama dengan
Rekap Lengkap. Catatan Hadir yang lengkap dan terkonfirmasi menjadi sumber
bacaan terakhir. Catatan konflik, tanggal tidak sah, atau keputusan basi
perlu diperiksa terlebih dahulu. Capaian awal tetap terpisah dari presensi.

## Akses

- Admin/PJ dapat meninjau semua peserta, termasuk filter arsip, dan membuka
  editor native pada catatan Hadir dengan ID serta tanggal yang jelas.
- Guru melihat peserta pada penempatan aktifnya. Sumber dari kelompok lain
  tidak ditampilkan meskipun peserta yang sama pernah/masih menjadi anggotanya.
- Capaian awal bersifat global per peserta. Guru hanya melihatnya jika
  penempatan aktif tunggal sesuai penugasannya; kepemilikan roster historis
  saja atau penempatan aktif yang ambigu tidak membuka baseline global.
- Pencarian dan filter pada katalog guru memakai proyeksi yang sudah dibatasi
  aksesnya. Nilai lama selalu di-escape sebelum ditampilkan sebagai HTML.

## Mengubah capaian

1. Buka **Daftar Capaian Peserta**. Pilih **Ubah catatan Hadir [tanggal]**
   atau **Capaian awal** untuk peserta yang dimaksud.
2. Periksa tanggal, ID dan materi. Formulir terikat akun/sesi selama 30 menit,
   maksimum delapan tab. Pengiriman memerlukan CSRF.
3. Simpan. Server memeriksa kembali peran dan fingerprint sumber, salinan
   sesi, keputusan verifikasi, serta kalender. Perubahan bersamaan ditolak;
   isian yang dikirim tetap ditampilkan untuk dibandingkan dengan versi baru.
4. Buka tautan **Lihat riwayat sebelum/sesudah** setelah penyimpanan. Pengiriman
   ulang formulir yang sama mengembalikan receipt yang sudah tercatat.

Editor Hadir hanya mengubah materi/jilid/surat/halaman/hasil yang benar-benar
berubah. Identitas, ID, tanggal, status, alasan, kolom tambahan dan draft
tidak ditulis ulang. Salinan identik pada halaqoh/sesi yang sama menerima
perubahan materi bersama. Sumber berbeda atau lintas halaqoh harus melalui
verifikasi Rekap Lengkap. Jika sumber terpilih yang sudah diverifikasi
diubah, keputusan lama tetap disimpan dan perlu ditinjau ulang.

Capaian awal tidak menambah Hadir atau hasil talaqqi. Simpan tanpa perubahan
tidak mengganti waktu baseline. Teks/spasi atau kolom tidak terpakai pada
materi lama yang tidak diubah tetap dipertahankan.

## Pencatatan dan pemulihan

`participant_changes` menyimpan snapshot peserta serta ekstensi `achievement`
sebelum/sesudah, termasuk seluruh baseline, sumber sesi dan keputusan asal.
Nilai kolom dikodekan base64 agar NULL, spasi, teks dan biner tidak hilang.
Admin/PJ dapat membandingkan nilai dan mengunduh JSON lengkap melalui
`peserta-riwayat.php`. Unduhan mengandung data pribadi.

Perubahan Hadir juga dicatat melalui `presensi_history`; pemulihan satu versi
mengikuti [ATTENDANCE_RECOVERY.md](ATTENDANCE_RECOVERY.md). Untuk baseline,
jurnal menyediakan bukti sebelum/sesudah dan salinan unduhan. Koreksi melalui
editor versi terbaru membuat jejak baru; belum ada tombol pemulihan baseline
otomatis. Backup penuh mencakup kedua jurnal.

Seluruh mutasi dan jurnal wajib berhasil dalam transaksi InnoDB yang sama.
Jika jurnal gagal, material, baseline, histori dan revisi dibatalkan bersama.
Editor menolak penulisan jika struktur pelaporan/jurnal peserta belum siap;
perubahan Hadir juga membutuhkan trigger histori yang sesuai struktur.
Tidak ada migrasi baru khusus editor capaian. Persiapan jurnal lama dan
prosedur [backup/pemulihan](BACKUP_RECOVERY.md) tetap berlaku.

## Pengujian lokal

```powershell
php tests/run-tahsin-tests.php
php -d extension=pdo_mysql -d memory_limit=256M tests/achievement-integration.php
node tests/achievement-http-tests.cjs
```

Suite database/HTTP membuat database sintetis baru hanya pada
`127.0.0.1:13317`. Tidak memakai konfigurasi database produksi dan tidak
mengirim pesan WhatsApp/pengingat. Cakupan meliputi pembatasan akses guru,
CSRF, array/XSS, snapshot biner, duplikat/konflik/verifikasi, stale form,
replay dan rollback saat jurnal gagal.
