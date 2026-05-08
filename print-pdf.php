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
$sql = "SELECT p.*, w.nama_bapak, h.nama_halaqoh, u.nama_lengkap as nama_ustadz
        FROM presensi p 
        JOIN wali_santri w ON p.wali_santri_id = w.id 
        JOIN halaqoh h ON p.halaqoh_id = h.id 
        JOIN users u ON h.ustadz_id = u.id
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

// Get filter details for header
$filter_halaqoh = "Semua Halaqoh";
if (!empty($halaqoh_id)) {
    $stmtH = $pdo->prepare("SELECT nama_halaqoh FROM halaqoh WHERE id = ?");
    $stmtH->execute([$halaqoh_id]);
    $filter_halaqoh = $stmtH->fetchColumn();
}

$filter_peserta = "Semua Peserta";
if (!empty($wali_santri_id)) {
    $stmtW = $pdo->prepare("SELECT nama_bapak FROM wali_santri WHERE id = ?");
    $stmtW->execute([$wali_santri_id]);
    $filter_peserta = $stmtW->fetchColumn();
}

$filter_kelas = $kelas_filter ?: "Semua Kelas";
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Presensi Tahsin -
        <?php echo $filter_halaqoh; ?>
    </title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            color: #334155;
            margin: 0;
            padding: 40px;
            font-size: 12px;
            line-height: 1.5;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #1e293b;
            text-transform: uppercase;
        }

        .header p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .info-grid {
            display: grid;
            grid-template-cols: 1fr 1fr;
            margin-bottom: 20px;
        }

        .info-item {
            margin-bottom: 5px;
        }

        .info-label {
            font-weight: 600;
            width: 100px;
            display: inline-block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.05em;
            border: 1px solid #e2e8f0;
            padding: 12px 10px;
        }

        td {
            border: 1px solid #e2e8f0;
            padding: 10px;
            vertical-align: top;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 10px;
        }

        .status-h {
            background: #ecfdf5;
            color: #059669;
        }

        .status-s {
            background: #eff6ff;
            color: #2563eb;
        }

        .status-i {
            background: #fffbeb;
            color: #d97706;
        }

        .status-a {
            background: #fef2f2;
            color: #dc2626;
        }

        .footer {
            margin-top: 50px;
            text-align: right;
        }

        .signature-box {
            display: inline-block;
            text-align: center;
            width: 200px;
        }

        .signature-space {
            height: 80px;
        }

        @media print {
            body {
                padding: 0;
            }

            .no-print {
                display: none;
            }

            button {
                display: none;
            }
        }

        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
    </style>
</head>

<body>
    <button class="print-btn no-print" onclick="window.print()">Cetak / Simpan ke PDF</button>

    <div class="header">
        <h1>Laporan Presensi Tahsin Bapak</h1>
        <p>GRIYA QUR'AN - Griya Quran Tahsin Apps</p>
    </div>

    <div class="info-grid">
        <div>
            <div class="info-item"><span class="info-label">Halaqoh</span>:
                <?php echo htmlspecialchars($filter_halaqoh); ?>
            </div>
            <div class="info-item"><span class="info-label">Kelas Anak</span>:
                <?php echo htmlspecialchars($filter_kelas); ?>
            </div>
            <div class="info-item"><span class="info-label">Peserta</span>:
                <?php echo htmlspecialchars($filter_peserta); ?>
            </div>
            <div class="info-item"><span class="info-label">Periode</span>:
                <?php echo date('d M Y', strtotime($start_date)); ?> s/d
                <?php echo date('d M Y', strtotime($end_date)); ?>
            </div>
        </div>
        <div style="text-align: right;">
            <div class="info-item"><span class="info-label">Dicetak Pada</span>:
                <?php echo date('d/m/Y H:i'); ?>
            </div>
            <div class="info-item"><span class="info-label">Total Data</span>:
                <?php echo count($results); ?> Record
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 80px;">Tanggal</th>
                <th>Nama Wali Santri</th>
                <th style="width: 100px;">Halaqoh</th>
                <th style="width: 50px; text-align: center;">Status</th>
                <th>Pencapaian Materi</th>
                <th style="width: 60px; text-align: center;">Hasil</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $row): ?>
                <tr>
                    <td>
                        <?php echo date('d/m/Y', strtotime($row['tanggal'])); ?>
                    </td>
                    <td><strong>
                            <?php echo htmlspecialchars($row['nama_bapak']); ?>
                        </strong></td>
                    <td>
                        <?php echo htmlspecialchars($row['nama_halaqoh']); ?>
                    </td>
                    <td style="text-align: center;">
                        <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                            <?php
                            echo match ($row['status']) {
                                'H' => 'HADIR',
                                'S' => 'SAKIT',
                                'I' => 'IZIN',
                                'A' => 'ALPHA',
                            };
                            ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($row['status'] === 'H'): ?>
                            <span style="color: #2563eb; font-weight: 600;">
                                <?php echo $row['jenis_materi']; ?>
                            </span>:
                            <?php echo ($row['jenis_materi'] === 'Iqro') ? "Jilid " . $row['jilid'] : htmlspecialchars($row['nama_surat']); ?>
                            (<?php echo $row['jenis_materi'] === 'Al Quran' ? 'Ayat' : 'Hal'; ?>         <?php echo $row['halaman']; ?>)
                        <?php elseif ($row['alasan']): ?>
                            <span style="color: #94a3b8; font-style: italic;">Keterangan:
                                <?php echo htmlspecialchars($row['alasan']); ?>
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td
                        style="text-align: center; font-weight: 700; color: <?php echo $row['hasil_talaqqi'] === 'Lulus' ? '#10b981' : '#f87171'; ?>">
                        <?php echo $row['hasil_talaqqi'] ? strtoupper($row['hasil_talaqqi']) : '-'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        <div class="signature-box">
            <p>Dicetak oleh:</p>
            <div class="signature-space"></div>
            <p><strong>
                    <?php echo $_SESSION['nama_lengkap']; ?>
                </strong></p>
            <p style="margin-top: -10px; color: #64748b;">(
                <?php echo ucfirst($_SESSION['role']); ?>)
            </p>
        </div>
    </div>

    <script>
        // Auto open print dialog
        window.onload = function () {
            // Uncomment below if you want it to trigger immediately
            // window.print();
        }
    </script>
</body>

</html>