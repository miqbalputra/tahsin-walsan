<?php
require_once '../config/database.php';
require_once '../includes/auth_helper.php';
session_start();

// Proteksi Keamanan
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ustadz') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid security token (CSRF)']);
        exit();
    }

    $ustadz_id = $_SESSION['user_id'];
    $halaqoh_id = $_POST['halaqoh_id'] ?? null;
    $wali_id = $_POST['wali_santri_id'] ?? null;
    $tanggal = $_POST['tanggal'] ?? null;

    // Check if today is a holiday
    $stmtHoliday = $pdo->prepare("SELECT id FROM holidays WHERE tanggal = ?");
    $stmtHoliday->execute([$tanggal]);
    $isHoliday = $stmtHoliday->fetch();

    // Data Presensi
    $status = $_POST['status'] ?? 'A';
    
    // Prevent auto-alfa on holidays
    if ($isHoliday && $status === 'A' && !isset($_POST['status'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Hari libur, tidak menyimpan Alfa otomatis.']);
        exit();
    }
    $alasan = $_POST['alasan'] ?? null;
    $jenis_materi = $_POST['jenis_materi'] ?? null;
    $jilid = $_POST['jilid'] ?? null;
    $nama_surat = $_POST['nama_surat'] ?? null;
    $halaman = $_POST['halaman'] ?? null;
    $hasil_talaqqi = $_POST['hasil_talaqqi'] ?? null;

    // Validasi Server-side
    // Jika status adalah RESET, kita akan menghapus record tersebut
    if ($status === 'RESET') {
        // No further data validation needed for reset
    } elseif ($status === 'H') {
        if (empty($jenis_materi) || empty($halaman) || empty($hasil_talaqqi)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Lengkapi data pencapaian materi!']);
            exit();
        }
        if ($jenis_materi === 'Iqro' && empty($jilid)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Pilih Jilid Iqro!']);
            exit();
        }
        if ($jenis_materi === 'Al Quran' && empty($nama_surat)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Isi Nama Surat!']);
            exit();
        }
    } elseif ($status === 'S' || $status === 'I') {
        if (empty($alasan)) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Isi alasan/keterangan!']);
            exit();
        }
    }

    try {
        // Cek kepemilikan halaqoh
        $check = $pdo->prepare("SELECT id FROM halaqoh WHERE id = ? AND ustadz_id = ?");
        $check->execute([$halaqoh_id, $ustadz_id]);
        if (!$check->fetch()) {
            throw new Exception("Halaqoh tidak ditemukan atau bukan milik Anda.");
        }

        // Cek apakah data sudah ada
        $stmtCheck = $pdo->prepare("SELECT id FROM presensi WHERE halaqoh_id = ? AND wali_santri_id = ? AND tanggal = ?");
        $stmtCheck->execute([$halaqoh_id, $wali_id, $tanggal]);
        $existing = $stmtCheck->fetch();

        if ($status === 'RESET') {
            if ($existing) {
                // Hapus jika ada
                $stmtDelete = $pdo->prepare("DELETE FROM presensi WHERE id = ?");
                $stmtDelete->execute([$existing['id']]);
            }
            $resMessage = 'Presensi dibatalkan';
        } elseif ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE presensi SET 
                status = :sts, alasan = :als, jenis_materi = :jm, jilid = :jld, nama_surat = :srt, halaman = :hal, hasil_talaqqi = :hsl 
                WHERE id = :id");
            $stmt->execute([
                ':sts' => $status,
                ':als' => $alasan,
                ':jm' => $jenis_materi,
                ':jld' => $jilid,
                ':srt' => $nama_surat,
                ':hal' => $halaman,
                ':hsl' => $hasil_talaqqi,
                ':id' => $existing['id']
            ]);
            $resMessage = 'Draft tersimpan otomatis';
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO presensi 
                (halaqoh_id, wali_santri_id, tanggal, status, alasan, jenis_materi, jilid, nama_surat, halaman, hasil_talaqqi) 
                VALUES (:h_id, :w_id, :tgl, :sts, :als, :jm, :jld, :srt, :hal, :hsl)");
            $stmt->execute([
                ':h_id' => $halaqoh_id,
                ':w_id' => $wali_id,
                ':tgl' => $tanggal,
                ':sts' => $status,
                ':als' => $alasan,
                ':jm' => $jenis_materi,
                ':jld' => $jilid,
                ':srt' => $nama_surat,
                ':hal' => $halaman,
                ':hsl' => $hasil_talaqqi
            ]);
            $resMessage = 'Draft tersimpan otomatis';
        }

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => $resMessage]);
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
