# Status kesiapan Go + Vue

Pemeriksaan kode terakhir: 22 Oktober 2026. Dokumen ini membedakan fitur yang telah diimplementasikan dari bukti yang masih dibutuhkan untuk mengganti PHP. Database produksi tidak diubah oleh pekerjaan ini.

## Status kode kandidat

| Area | Status di Go + Vue | Syarat parity/rilis |
|---|---|---|
| Login, sesi, role dan CSRF | Diimplementasikan | Uji seluruh akun/role pada clone produksi dan pastikan hash PHP diterima tanpa reset password |
| Peserta, kelompok, pengguna, libur, pengumuman, keluarga | API dan layar manajemen tersedia | Cocokkan seluruh aturan CRUD, arsip, relasi dan batas akses dengan PHP pada clone |
| Dashboard, log aktivitas, pengaturan laporan | API dan layar tersedia | Bandingkan hasil/filter dengan PHP; template PHP lama tetap inert dan tidak dieksekusi Go |
| Presensi guru dan autosave | API snapshot, draft, retry dan pemulihan perangkat tersedia; format antrean lama dipertahankan | Jalankan ulang suite browser/integrasi lengkap terhadap image kandidat dan clone skema yang sama |
| Capaian peserta | Daftar berfilter, baca-saja untuk guru, koreksi catatan Hadir dan simpan capaian awal untuk admin/PJ tersedia | Cocokkan hasil, audit log, serta pembaruan revisi autosave dengan PHP pada clone; uji tanpa membuat presensi baru untuk capaian awal |
| Rekap canonical Tahsin | API `/api/v1/reports/tahsin`, detail periode/roster, penanda konflik, verifikasi dan layar Vue ditambahkan pada branch ini | Bandingkan semua filter, agregat, histori Bapak dan Ibu dengan PHP pada snapshot yang sama; uji clone MySQL sebelum menyatakan parity |
| Rekap presensi mentah dan ekspor Excel lama | API/alias Go tersedia | Cocokkan file ekspor dan akses role pada clone |
| Hermes, notifikasi tertunda, pengingat harian | Handler Go untuk seluruh action Hermes, `/api/notifications-pending.php`, dan `/api/daily-reminder.php` tersedia; memakai `HERMES_API_KEY`/fallback `N8N_API_KEY` dan `N8N_API_KEY` yang lama | Cocokkan response pada clone dan jalankan workflow n8n sintetis; pesan nyata tidak dikirim oleh tes |
| Impor peserta/keluarga, Data Induk, sinkronisasi | Endpoint dan layar status admin, sync manual/CLI, konflik pemetaan, serta scope incremental/reference/roster/ustadz tersedia; impor Excel penuh belum dipindahkan | Cocokkan hasil sync dengan PHP pada clone; pastikan migrasi aditif Data Induk sudah diterapkan, retry/cursor/konflik dan rollback transaksi teruji; uji impor Excel terpisah |
| Backup/restore aplikasi | API Go dan layar Vue tersedia: ZIP v2, preview per tabel/checksum, safety backup sebelum restore, dan merge-only tanpa UPDATE/DELETE; pembacaan CSV streaming | Cocokkan arsip Go↔PHP pada clone MySQL 8.4; buktikan insert/conflict, rollback, backup sebelum restore, dan restore drill dari salinan yang dapat dipulihkan |
| Rapor/PDF | Pengaturan template lama dibaca/disimpan; renderer, perhitungan rapor penuh, dan ekspor PDF belum tersedia | Port template ke renderer aman dan bandingkan hasil file dengan PHP pada fixture serta clone |
| Pusat laporan lintas aplikasi | Belum dibangun | Jadikan Go API sumber data resmi yang terversi; konsumen pusat laporan dibuat terpisah tanpa penulisan balik |

## Pemeriksaan lokal

Perintah yang lulus pada checkout ini:

- `go test -count=1 ./...`
- `go vet ./...`
- `npm run build:app` di `next/frontend`
- `php tests/run-tahsin-tests.php` — 147 assertion regresi Tahsin
- `php tests/go-vue-helper.php` — 26 assertion gateway PHP–Go
- Uji Go backup — validasi path ZIP, duplicate entries, batas ukuran ekstrak, streaming CSV, dan autentikasi route backup/restore

`composer` tidak tersedia di komputer ini, jadi PHPUnit melalui `composer test` belum dijalankan. Uji tersebut bukan pengganti untuk perbandingan PHP–Go pada clone database yang sama.

## Keputusan keselamatan deploy

**Belum siap menggantikan PHP di produksi.** Flag `APP_PRODUCTION_READY` harus tetap `0`; jangan mengisi `1` untuk redeploy kandidat ini. Backup/restore Go belum diuji terhadap arsip dan clone MySQL produksi; impor/Data Induk serta rapor/PDF juga belum lengkap. Salinan backup di luar home server, pemulihan delta binlog produksi, perbandingan laporan clone, dan pilot guru tetap menjadi gerbang wajib dalam [runbook keselamatan](MIGRATION_SAFETY_RUNBOOK.md).

Go menggunakan schema MySQL lama secara langsung dan tidak menyalin atau mengimpor ulang data. Penambahan schema hanya boleh dijalankan sebagai migrasi aditif eksplisit setelah backup/restore clone berhasil. PHP tetap menjadi jalur rollback hingga seluruh gerbang rilis terverifikasi.
