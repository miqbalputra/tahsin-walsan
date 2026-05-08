<?php
/**
 * API Endpoint: Daily Attendance Reminder (Senin-Sabtu)
 * 
 * LOGIKA:
 * - Tahsin dilaksanakan setiap MINGGU/AHAD (kecuali Minggu pertama = libur)
 * - Reminder dikirim Senin-Sabtu ke Ustadz yang BELUM LENGKAP isi presensi Minggu terakhir
 * - Terus diingatkan setiap hari sampai presensi lengkap
 */

require_once '../config/database.php';

// PENTING: Set timezone ke WIB agar perhitungan tanggal benar
date_default_timezone_set('Asia/Jakarta');

// Simple API Key security. Set N8N_API_KEY in Coolify for production.
$apiKey = trim(getenv('N8N_API_KEY') ?: "tahsin_secure_key_123");
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$providedKey = trim($headers['x-api-key'] ?? ($_GET['key'] ?? ''));

if ($providedKey !== $apiKey) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$today = date('Y-m-d');
$dayOfWeek = date('w'); // 0=Minggu, 1=Senin, ..., 6=Sabtu

// Hanya jalankan Senin-Sabtu (1-6)
if ($dayOfWeek == 0) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'skip', 'reason' => 'Hari Minggu, presensi baru berlangsung hari ini']);
    exit;
}

// ====================
// Cari TANGGAL MINGGU TERAKHIR (tanggal tahsin terakhir)
// ====================
// Hitung mundur berapa hari ke Minggu terakhir
// dayOfWeek: 1=Senin→mundur 1, 2=Selasa→2, ..., 6=Sabtu→6
$daysSinceSunday = intval($dayOfWeek); // Senin=1, Selasa=2, ..., Sabtu=6
$lastSunday = date('Y-m-d', strtotime("-{$daysSinceSunday} days"));

// Cek apakah Minggu terakhir itu adalah Minggu PERTAMA di bulan itu (libur)
$sundayDay = intval(date('j', strtotime($lastSunday)));
if ($sundayDay <= 7) {
    // Minggu pertama bulan itu = LIBUR, cari Minggu sebelumnya lagi (-7 hari)
    $lastSunday = date('Y-m-d', strtotime($lastSunday . ' -7 days'));

    $sundayDay2 = intval(date('j', strtotime($lastSunday)));
    if ($sundayDay2 <= 7) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'skip', 'reason' => 'Tidak ada sesi tahsin aktif yang perlu direminder']);
        exit;
    }
}

// ====================
// CEK LIBUR KAJIAN (DATABASE)
// ====================
$stmtHoliday = $pdo->prepare("SELECT keterangan FROM holidays WHERE tanggal = ?");
$stmtHoliday->execute([$lastSunday]);
$holiday = $stmtHoliday->fetch();

if ($holiday) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'skip', 'reason' => 'Sesi Tahsin ' . $lastSunday . ' LIBUR: ' . ($holiday['keterangan'] ?: 'Libur Kajian')]);
    exit;
}

try {
    // =====================
    // QUERY: Cari Ustadz yang presensi belum lengkap
    // =====================
    // Cek presensi dalam rentang minggu ini (dari Ahad terakhir s/d hari ini)
    // Ini mengakomodasi peserta yang tahsin di luar hari Ahad (Senin-Sabtu)
    $sql = "SELECT 
                h.id as halaqoh_id,
                h.nama_halaqoh,
                u.nama_lengkap as ustadz_name,
                u.no_hp as ustadz_phone,
                (SELECT COUNT(*) FROM halaqoh_members hm WHERE hm.halaqoh_id = h.id) as total_anggota,
                (SELECT COUNT(DISTINCT p.wali_santri_id) FROM presensi p WHERE p.halaqoh_id = h.id AND p.tanggal BETWEEN :tgl_start AND :tgl_end) as sudah_diisi
            FROM halaqoh h
            JOIN users u ON h.ustadz_id = u.id
            WHERE 
                EXISTS (SELECT 1 FROM halaqoh_members hm WHERE hm.halaqoh_id = h.id)
                AND (SELECT COUNT(DISTINCT p.wali_santri_id) FROM presensi p WHERE p.halaqoh_id = h.id AND p.tanggal BETWEEN :tgl_start2 AND :tgl_end2) 
                    < (SELECT COUNT(*) FROM halaqoh_members hm WHERE hm.halaqoh_id = h.id)
            ORDER BY u.nama_lengkap";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tgl_start' => $lastSunday, ':tgl_end' => $today, ':tgl_start2' => $lastSunday, ':tgl_end2' => $today]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format data per Ustadz
    foreach ($pending as &$row) {
        // Format nomor HP → 62xxx
        $phone = preg_replace('/[^0-9]/', '', $row['ustadz_phone'] ?? '');
        if (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1);
        }
        $row['ustadz_phone'] = $phone;
        $row['tanggal_tahsin'] = $lastSunday;
        $row['belum_diisi'] = intval($row['total_anggota']) - intval($row['sudah_diisi']);

        // Tentukan jenis reminder
        if (intval($row['sudah_diisi']) == 0) {
            $row['status_isi'] = 'belum_sama_sekali';
            $row['keterangan'] = 'Belum diisi sama sekali';
        } else {
            $row['status_isi'] = 'belum_lengkap';
            $row['keterangan'] = "Sudah {$row['sudah_diisi']} dari {$row['total_anggota']} peserta (kurang {$row['belum_diisi']})";
        }
    }

    // Format tanggal Minggu dalam bahasa Indonesia
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $tgl = date('j', strtotime($lastSunday));
    $bln = intval(date('n', strtotime($lastSunday)));
    $thn = date('Y', strtotime($lastSunday));
    $tanggalFormatted = "Ahad, $tgl {$namaBulan[$bln]} $thn";

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'date_today' => $today,
        'day' => ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$dayOfWeek],
        'tanggal_tahsin' => $lastSunday,
        'tanggal_tahsin_formatted' => $tanggalFormatted,
        'server_timezone' => date_default_timezone_get(),
        'server_time' => date('Y-m-d H:i:s'),
        'count' => count($pending),
        'data' => $pending
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
