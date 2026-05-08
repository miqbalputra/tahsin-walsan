<?php
/**
 * TEMPLATE RAPOR TAHSIN - CUSTOMIZABLE
 * Bapak bisa mengedit HTML & CSS di sini sesuka hati.
 * Variabel yang tersedia:
 * $peserta, $santri, $stats, $riwayat, $last_progress, $start_date, $end_date
 */
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Rapor Tahsin -
        <?php echo htmlspecialchars($peserta['nama_bapak']); ?>
    </title>
    <style>
        /* TULIS CSS CUSTOM BAPAK DI SINI */
        body {
            font-family: 'Arial', sans-serif;
            color: #333;
            line-height: 1.6;
        }

        .paper {
            width: 210mm;
            margin: auto;
            padding: 20mm;
            border: 1px solid #eee;
        }

        .header-logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .rapor-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #f2f2f2;
        }

        .summary-box {
            border: 2px solid #000;
            padding: 15px;
            margin-bottom: 20px;
        }

        .footer-grid {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
        }

        .sig-box {
            text-align: center;
            width: 200px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                padding: 0;
            }

            .paper {
                border: none;
            }
        }
    </style>
</head>

<body>
    <div class="no-print"
        style="text-align: center; padding: 10px; background: #fffde7; border-bottom: 1px solid #ffe57f;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; font-weight: bold;">[ KLIK UNTUK
            CETAK RAPOR ]</button>
        <p style="font-size: 12px; color: #795548;">Tips: Bapak bisa mengedit file <b>templates/rapor_template.php</b>
            untuk mengubah tampilan ini.</p>
    </div>

    <div class="paper">
        <!-- HEADER -->
        <div class="header-logo">
            <h1 style="margin:0; color: #1b5e20;">GRIYA QUR'AN</h1>
            <p style="margin:0; font-size: 14px;">Rumah Belajar Al-Qur'an & Tahfidz</p>
        </div>

        <div class="rapor-title">LAPORAN PERKEMBANGAN TAHSIN</div>

        <!-- INFO PESERTA -->
        <table style="border: none !important;">
            <tr style="border: none !important;">
                <td style="border: none !important; width: 120px;">Nama Wali</td>
                <td style="border: none !important;">: <b>
                        <?php echo htmlspecialchars($peserta['nama_bapak']); ?>
                    </b></td>
                <td style="border: none !important; width: 120px;">Halaqoh</td>
                <td style="border: none !important;">:
                    <?php echo htmlspecialchars($peserta['nama_halaqoh'] ?? '-'); ?>
                </td>
            </tr>
            <tr style="border: none !important;">
                <td style="border: none !important;">Nama Anak/Kelas</td>
                <td style="border: none !important;">:
                    <?php
                    $children = [];
                    foreach ($santri as $s)
                        $children[] = $s['nama_anak'] . " (" . $s['kelas'] . ")";
                    echo htmlspecialchars(implode(', ', $children));
                    ?>
                </td>
                <td style="border: none !important;">Ustadz</td>
                <td style="border: none !important;">:
                    <?php echo htmlspecialchars($peserta['nama_ustadz'] ?? '-'); ?>
                </td>
            </tr>
            <tr style="border: none !important;">
                <td style="border: none !important;">Periode</td>
                <td style="border: none !important;">:
                    <?php echo date('d M Y', strtotime($start_date)); ?> -
                    <?php echo date('d M Y', strtotime($end_date)); ?>
                </td>
            </tr>
        </table>

        <!-- RINGKASAN KEHADIRAN -->
        <div class="summary-box">
            <div style="font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #000;">Ringkasan Presensi:
            </div>
            <div style="display: flex; justify-content: space-around;">
                <span>Hadir: <b>
                        <?php echo $stats['H']; ?>
                    </b></span>
                <span>Sakit: <b>
                        <?php echo $stats['S']; ?>
                    </b></span>
                <span>Izin: <b>
                        <?php echo $stats['I']; ?>
                    </b></span>
                <span>Alpha: <b>
                        <?php echo $stats['A']; ?>
                    </b></span>
            </div>
        </div>

        <!-- CAPAIAN MATERI -->
        <div style="margin-bottom: 15px; font-weight: bold;">Capaian Materi Terakhir:</div>
        <div style="padding: 10px; background: #f9f9f9; border: 1px dashed #ccc; margin-bottom: 20px;">
            <?php if ($last_progress): ?>
                Materi: <b>
                    <?php echo $last_progress['jenis_materi']; ?>
                </b> |
                Detail: <b>
                    <?php echo $last_progress['jenis_materi'] === 'Iqro' ? 'Jilid ' . $last_progress['jilid'] : htmlspecialchars($last_progress['nama_surat']); ?>
                </b> |
                Halaman/Ayat: <b>
                    <?php echo $last_progress['halaman']; ?>
                </b>
            <?php else: ?>
                <i>Belum ada data materi.</i>
            <?php endif; ?>
        </div>

        <!-- RINCIAN HARIAN -->
        <table>
            <thead>
                <tr>
                    <th style="width: 100px;">Tanggal</th>
                    <th>Kegiatan / Materi</th>
                    <th style="width: 60px;">Status</th>
                    <th style="width: 60px;">Hasil</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($riwayat as $r): ?>
                    <tr>
                        <td>
                            <?php echo date('d/m/Y', strtotime($r['tanggal'])); ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'H'): ?>
                                <?php echo $r['jenis_materi']; ?>:
                                <?php echo $r['jenis_materi'] === 'Iqro' ? 'Jilid ' . $r['jilid'] : htmlspecialchars($r['nama_surat']); ?>
                                (Hal/Ayat:
                                <?php echo $r['halaman']; ?>)
                            <?php else: ?>
                                <?php echo $r['alasan'] ?: '-'; ?>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php echo $r['status']; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php echo $r['hasil_talaqqi'] ?: '-'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- FOOTER / TANDA TANGAN -->
        <div class="footer-grid">
            <div class="sig-box">
                <p>Mengetahui,</p>
                <p><b>Ustadz Pembimbing</b></p>
                <div style="height: 60px;"></div>
                <p>( .................................... )</p>
            </div>
            <div class="sig-box">
                <p>Dicetak pada:
                    <?php echo date('d/m/Y'); ?>
                </p>
                <p><b>Admin / PJ Tahfidz</b></p>
                <div style="height: 60px;"></div>
                <p><b>(
                        <?php echo htmlspecialchars($_SESSION['nama_lengkap']); ?> )
                    </b></p>
            </div>
        </div>

    </div>
</body>

</html>