<?php
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkLogin();
checkRole(['admin', 'pj_tahfidz']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    if (isset($_FILES['file_csv'])) {
        $file = $_FILES['file_csv']['tmp_name'];
        $filename = $_FILES['file_csv']['name'];

        // Basic Extension Validation
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'csv') {
            header("Location: peserta.php?err=Hanya file CSV yang diizinkan!");
            exit();
        }

        $handle = fopen($file, "r");

        // Skip header
        fgetcsv($handle, 1000, ",");

        $success_count = 0;
        $error_count = 0;

        $pdo->beginTransaction();
        try {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (empty($data[0]))
                    continue; // Skip empty nama_bapak

                $nama_bapak = trim($data[0]);
                $no_hp = trim($data[1] ?? '');
                $alamat = trim($data[2] ?? '');
                $kategori = strtolower(trim($data[3] ?? 'reguler'));
                $nama_anak = trim($data[4] ?? '');
                $kelas = trim($data[5] ?? '');
                $tempat_tahsin = trim($data[6] ?? '');
                $ustadz_luar = trim($data[7] ?? '');

                // 1. Find or Create Wali
                $stmt = $pdo->prepare("SELECT id FROM wali_santri WHERE nama_bapak = ? AND no_hp = ?");
                $stmt->execute([$nama_bapak, $no_hp]);
                $wali = $stmt->fetch();

                if ($wali) {
                    $wali_id = $wali['id'];
                    // Update external info if it's Tahsin Luar
                    if ($kategori === 'tahsin_luar') {
                        $pdo->prepare("UPDATE wali_santri SET tempat_tahsin = ?, ustadz_luar = ? WHERE id = ?")
                            ->execute([$tempat_tahsin, $ustadz_luar, $wali_id]);
                    }
                } else {
                    $stmtInsert = $pdo->prepare("INSERT INTO wali_santri (nama_bapak, no_hp, alamat, kategori, tempat_tahsin, ustadz_luar) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtInsert->execute([$nama_bapak, $no_hp, $alamat, $kategori, $tempat_tahsin, $ustadz_luar]);
                    $wali_id = $pdo->lastInsertId();
                }

                // 2. Insert Anak
                if (!empty($nama_anak)) {
                    $stmtAnak = $pdo->prepare("INSERT INTO santri_detail (wali_santri_id, nama_anak, kelas) VALUES (?, ?, ?)");
                    $stmtAnak->execute([$wali_id, $nama_anak, $kelas]);
                }

                $success_count++;
            }
            $pdo->commit();
            header("Location: peserta.php?msg=Import berhasil! $success_count data diproses.");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: peserta.php?err=Gagal import: " . urlencode($e->getMessage()));
            exit();
        }
    }
} else {
    header("Location: peserta.php");
    exit();
}
