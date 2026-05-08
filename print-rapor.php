<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkLogin();
$allowed_roles = ['admin', 'pj_tahfidz', 'kepsek', 'ustadz'];
checkRole($allowed_roles);

$wali_santri_id = $_GET['wali_santri_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

if (empty($wali_santri_id)) {
    die("Pilih peserta terlebih dahulu untuk mencetak rapor.");
}

// 1. Fetch Participant & Children details
$stmtW = $pdo->prepare("SELECT w.*, h.nama_halaqoh, u.nama_lengkap as nama_ustadz 
                        FROM wali_santri w 
                        LEFT JOIN halaqoh_members hm ON w.id = hm.wali_santri_id
                        LEFT JOIN halaqoh h ON hm.halaqoh_id = h.id
                        LEFT JOIN users u ON h.ustadz_id = u.id
                        WHERE w.id = ?");
$stmtW->execute([$wali_santri_id]);
$peserta = $stmtW->fetch();

if (!$peserta) {
    die("Peserta tidak ditemukan.");
}

// Fetch children
$stmtS = $pdo->prepare("SELECT nama_anak, kelas FROM santri_detail WHERE wali_santri_id = ?");
$stmtS->execute([$wali_santri_id]);
$santri = $stmtS->fetchAll();

// 2. Fetch Attendance Summary for the period
$stmtStats = $pdo->prepare("SELECT status, COUNT(*) as total FROM presensi 
                            WHERE wali_santri_id = ? AND tanggal BETWEEN ? AND ? 
                            GROUP BY status");
$stmtStats->execute([$wali_santri_id, $start_date, $end_date]);
$statsData = $stmtStats->fetchAll(PDO::FETCH_KEY_PAIR);
$stats = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
foreach ($statsData as $s => $t) {
    $stats[$s] = $t;
}

// 3. Fetch Attendance History
$stmtHistory = $pdo->prepare("SELECT * FROM presensi 
                              WHERE wali_santri_id = ? AND tanggal BETWEEN ? AND ? 
                              ORDER BY tanggal ASC");
$stmtHistory->execute([$wali_santri_id, $start_date, $end_date]);
$riwayat = $stmtHistory->fetchAll();

// 4. Fetch Latest Progress
$stmtLast = $pdo->prepare("SELECT * FROM presensi WHERE wali_santri_id = ? AND status = 'H' ORDER BY tanggal DESC LIMIT 1");
$stmtLast->execute([$wali_santri_id]);
$last_progress = $stmtLast->fetch();

/**
 * LOAD THE TEMPLATE FROM DATABASE
 */
$stmtTpl = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('rapor_template', 'pj_tahfidz_name', 'pj_tahfidz_title')");
$stmtTpl->execute();
$settings = $stmtTpl->fetchAll(PDO::FETCH_KEY_PAIR);

$db_template = $settings['rapor_template'] ?? '';
$pj_tahfidz_name = $settings['pj_tahfidz_name'] ?? 'Admin Sekolah';
$pj_tahfidz_title = $settings['pj_tahfidz_title'] ?? 'PJ Tahfidz';

if ($db_template) {
    // Render from database content
    // We use eval to process the PHP inside the template string safely in this context
    eval ("?> " . $db_template);
} else {
    // Fallback if DB entry is missing
    include 'templates/rapor_template.php';
}
