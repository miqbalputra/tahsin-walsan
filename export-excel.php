<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';
require_once 'includes/report_helper.php';
require_once __DIR__ . '/includes/csv_helper.php';

checkLogin();
$allowed_roles = ['admin', 'pj_tahfidz', 'kepsek', 'ustadz'];
checkRole($allowed_roles);

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$filters = getReportFilters();
$start_date = $filters['start_date'];
$end_date = $filters['end_date'];
$results = fetchPresensiReport($pdo, $filters, $role, $user_id);

/**
 * Sel data aman untuk .xls berbasis HTML: HTML di-escape dan teks yang diawali
 * whitespace tersembunyi lalu karakter formula diberi awalan apostrof.
 */
function excelSafeCell($value): string
{
    $value = appSpreadsheetSafeText($value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
                    <?php echo excelSafeCell($row['nama_halaqoh']); ?>
                </td>
                <td>
                    <?php echo excelSafeCell($row['nama_bapak']); ?>
                </td>
                <td>
                    <?php echo excelSafeCell($row['status']); ?>
                </td>
                <td>
                    <?php echo excelSafeCell($row['jenis_materi'] ?: '-'); ?>
                </td>
                <td>
                    <?php
                    if ($row['jenis_materi'] === 'Iqro')
                        echo excelSafeCell("Jilid " . $row['jilid']);
                    elseif ($row['jenis_materi'] === 'Al Quran')
                        echo excelSafeCell($row['nama_surat']);
                    else
                        echo "-";
                    ?>
                </td>
                <td>
                    <?php
                    if ($row['jenis_materi'] === 'Al Quran' && !empty($row['halaman'])) {
                        echo excelSafeCell("Ayat " . $row['halaman']);
                    } elseif ($row['jenis_materi'] === 'Iqro' && !empty($row['halaman'])) {
                        echo excelSafeCell("Hal " . $row['halaman']);
                    } else {
                        echo "-";
                    }
                    ?>
                </td>
                <td>
                    <?php echo excelSafeCell($row['hasil_talaqqi'] ?: '-'); ?>
                </td>
                <td>
                    <?php echo excelSafeCell($row['alasan'] ?: '-'); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
