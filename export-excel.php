<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkLogin();
$allowed_roles = ['admin', 'pj_tahfidz', 'kepsek', 'ustadz'];
checkRole($allowed_roles);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$halaqoh_id = $_GET['halaqoh_id'] ?? '';
$wali_santri_id = $_GET['wali_santri_id'] ?? '';
$kelas_filter = $_GET['kelas'] ?? '';

// Build Query
$sql = "SELECT p.*, w.nama_bapak, h.nama_halaqoh 
        FROM presensi p 
        JOIN wali_santri w ON p.wali_santri_id = w.id 
        JOIN halaqoh h ON p.halaqoh_id = h.id 
        WHERE p.tanggal BETWEEN :start AND :end";

$params = [':start' => $start_date, ':end' => $end_date];

// Security: If Ustadz, only show their own halaqohs
if ($role === 'ustadz') {
    $sql .= " AND h.ustadz_id = :u_id";
    $params[':u_id'] = $user_id;
}

if (!empty($halaqoh_id)) {
    $sql .= " AND p.halaqoh_id = :h_id";
    $params[':h_id'] = $halaqoh_id;
}

if (!empty($wali_santri_id)) {
    $sql .= " AND p.wali_santri_id = :w_id";
    $params[':w_id'] = $wali_santri_id;
}

if (!empty($kelas_filter)) {
    $sql .= " AND w.id IN (SELECT wali_santri_id FROM santri_detail WHERE kelas = :kls)";
    $params[':kls'] = $kelas_filter;
}

$sql .= " ORDER BY p.tanggal DESC, h.nama_halaqoh, w.nama_bapak";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Excel Header
$filename = "Rekap_Presensi_Tahsin_" . $start_date . "_to_" . $end_date . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");

?>
<table border="1">
    <thead>
        <tr style="background-color: #f2f2f2; font-weight: bold;">
            <th>No</th>
            <th>Tanggal</th>
            <th>Halaqoh</th>
            <th>Nama Wali Santri</th>
            <th>Status</th>
            <th>Materi</th>
            <th>Keterangan/Surat/Jilid</th>
            <th>Halaman/Ayat</th>
            <th>Hasil Talaqqi</th>
            <th>Alasan (Jika S/I)</th>
        </tr>
    </thead>
    <tbody>
        <?php $no = 1;
        foreach ($results as $row): ?>
            <tr>
                <td>
                    <?php echo $no++; ?>
                </td>
                <td>
                    <?php echo date('d/m/Y', strtotime($row['tanggal'])); ?>
                </td>
                <td>
                    <?php echo $row['nama_halaqoh']; ?>
                </td>
                <td>
                    <?php echo $row['nama_bapak']; ?>
                </td>
                <td>
                    <?php echo $row['status']; ?>
                </td>
                <td>
                    <?php echo $row['jenis_materi'] ?: '-'; ?>
                </td>
                <td>
                    <?php
                    if ($row['jenis_materi'] === 'Iqro')
                        echo "Jilid " . $row['jilid'];
                    elseif ($row['jenis_materi'] === 'Al Quran')
                        echo $row['nama_surat'];
                    else
                        echo "-";
                    ?>
                </td>
                <td>
                    <?php
                    if ($row['jenis_materi'] === 'Al Quran' && !empty($row['halaman'])) {
                        echo "Ayat " . $row['halaman'];
                    } elseif ($row['jenis_materi'] === 'Iqro' && !empty($row['halaman'])) {
                        echo "Hal " . $row['halaman'];
                    } else {
                        echo "-";
                    }
                    ?>
                </td>
                <td>
                    <?php echo $row['hasil_talaqqi'] ?: '-'; ?>
                </td>
                <td>
                    <?php echo $row['alasan'] ?: '-'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>