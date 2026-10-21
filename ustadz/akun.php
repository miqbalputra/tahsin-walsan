<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';
checkRole(['ustadz']);
$pageTitle = 'Akun';
$teacherNavProvided = true;
$stmt = $pdo->prepare('SELECT nama_lengkap,username FROM users WHERE id=?');
$stmt->execute([(int) $_SESSION['user_id']]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="../assets/css/tahsin-autosave.css?v=1">
<section class="tas-app"><header class="tas-heading"><p class="tas-eyebrow">Akun guru</p><h1><?= htmlspecialchars($teacher['nama_lengkap']) ?></h1><p><?= htmlspecialchars($teacher['username']) ?></p></header>
<article class="tas-person"><h2>Pendataan otomatis</h2><p>Pilih kehadiran pada daftar peserta. Untuk Hadir, lengkapi materi, rentang bacaan, dan hasil Lulus/Ulang.</p><p>Draft belum lengkap juga disimpan. Periksa status “Tersimpan otomatis” sebelum menyelesaikan sesi.</p><p>Jika koneksi terputus, draft yang berhasil disimpan di HP akan dikirim kembali ketika aplikasi aktif dan koneksi tersedia.</p></article>
<article class="tas-person" style="margin-top:16px"><h2>Bantuan dan laporan</h2><p>Hubungi admin untuk penempatan, perubahan jadwal, atau catatan yang memerlukan verifikasi.</p><a href="../rekap-lengkap.php" class="tas-button" style="display:inline-block;margin-top:12px">Lihat rekap kelompok</a></article>
<a href="../logout.php" class="tas-button" style="display:inline-block;margin-top:20px">Keluar dari akun</a>
<nav class="tas-nav" aria-label="Navigasi guru"><a href="hari-ini.php">Hari Ini</a><a href="riwayat.php">Riwayat</a><a href="akun.php" aria-current="page">Akun</a></nav></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
