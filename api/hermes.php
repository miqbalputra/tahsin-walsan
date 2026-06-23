<?php
/**
 * ============================================================
 * HERMES API — REST API untuk Hermes Agent
 * ============================================================
 * 
 * Memberikan akses data terstruktur ke seluruh isi database
 * Presensi Tahsin Bapak. Didesain untuk dikonsumsi oleh AI
 * agent (Hermes) maupun sistem eksternal lainnya.
 * 
 * AUTENTIKASI:
 *   Header: X-API-Key: <your-secret-key>
 *   Atau:   ?api_key=<your-secret-key>
 * 
 * FORMAT RESPON:
 *   Semua respon dalam JSON.
 *   Sukses: { "status": "ok", "data": ..., "meta": { ... } }
 *   Error:  { "status": "error", "message": "..." }
 * 
 * ENDPOINT:
 *   GET /api/hermes.php?action=<action>&<params>
 * 
 * DAFTAR ACTION:
 *   status       → Info API & ringkasan database
 *   peserta      → Daftar wali santri (filter: id, search, kategori, halaqoh_id, kelas, page, limit)
 *   halaqoh      → Daftar halaqoh (filter: id, ustadz_id, page, limit)
 *   presensi     → Data kehadiran (filter: id, wali_id, halaqoh_id, start, end, status, page, limit)
 *   stats        → Statistik dashboard (filter: halaqoh_id, start, end)
 *   progress     → Progress per wali santri (filter: halaqoh_id, search, risk, start, end, page, limit)
 *   peringatan   → Peringatan dini alpha >= 3 (filter: halaqoh_id)
 *   ustadz       → Daftar ustadz (filter: id, page, limit)
 *   libur        → Daftar hari libur (filter: start, end, page, limit)
 *   pengumuman   → Daftar pengumuman (filter: aktif_only, page, limit)
 *   capaian      → Capaian terakhir per peserta (filter: halaqoh_id, search, page, limit)
 *   search       → Pencarian global (param: q, page, limit)
 *   schema       → Informasi skema database
 *   logs         → Log aktivitas (filter: action, start, end, page, limit)
 *   users        → Daftar user (filter: role, page, limit)
 * 
 * PAGINATION:
 *   &page=1&limit=50  (default: page=1, limit=25, max: 100)
 * 
 * CONTOH:
 *   curl -H "X-API-Key: rahasia" "https://domain.com/api/hermes.php?action=peserta&halaqoh_id=2&page=1&limit=10"
 *   curl "https://domain.com/api/hermes.php?action=stats&api_key=rahasia"
 *   curl "https://domain.com/api/hermes.php?action=search&q=ahmad&api_key=rahasia"
 */

// ============================================================
// BOOTSTRAP
// ============================================================

// CORS headers — allow any origin for Hermes Agent
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    jsonError(405, 'Method not allowed. Use GET or POST.');
}

// ============================================================
// KONFIGURASI API
// ============================================================

// Baca API key dari environment variable
define('HERMES_API_KEY', trim(getenv('HERMES_API_KEY') ?: getenv('N8N_API_KEY') ?: 'hermes_default_key_2026'));

// Default pagination
define('DEFAULT_PAGE', 1);
define('DEFAULT_LIMIT', 25);
define('MAX_LIMIT', 100);

// ============================================================
// FUNGSI BANTU
// ============================================================

/**
 * Kirim respon JSON sukses
 */
function jsonOk($data, $meta = [])
{
    $response = ['status' => 'ok', 'data' => $data];
    if (!empty($meta)) {
        $response['meta'] = $meta;
    }
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Kirim respon JSON error
 */
function jsonError($httpCode, $message)
{
    http_response_code($httpCode);
    echo json_encode([
        'status' => 'error',
        'message' => $message,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validasi API key — dilakukan SEBELUM koneksi database
 */
function authenticate()
{
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $key = $headers['x-api-key'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';

    if ($key !== HERMES_API_KEY) {
        jsonError(401, 'Unauthorized. Provide valid API key via X-API-Key header or api_key parameter.');
    }
}

/**
 * Ambil parameter pagination dari request
 */
function getPagination()
{
    $page = max(1, (int) ($_GET['page'] ?? DEFAULT_PAGE));
    $limit = (int) ($_GET['limit'] ?? DEFAULT_LIMIT);
    $limit = min(max(1, $limit), MAX_LIMIT);
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

/**
 * Ambil parameter tanggal dari request
 */
function getDateRange()
{
    $start = $_GET['start'] ?? $_GET['start_date'] ?? date('Y-m-01');
    $end = $_GET['end'] ?? $_GET['end_date'] ?? date('Y-m-d');

    // Validasi format tanggal
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        jsonError(400, 'Format tanggal tidak valid. Gunakan YYYY-MM-DD.');
    }

    return [$start, $end];
}

/**
 * Hitung total data untuk pagination
 */
function getTotalCount($pdo, $sql, $params = [])
{
    $countSql = preg_replace('/SELECT.*?FROM\s/si', 'SELECT COUNT(*) as total FROM ', $sql, 1);
    // Hapus ORDER BY untuk count query
    $countSql = preg_replace('/\s+ORDER\s+BY\s+.*$/si', '', $countSql);
    // Hapus LIMIT/OFFSET
    $countSql = preg_replace('/\s+LIMIT\s+\d+(\s+OFFSET\s+\d+)?$/si', '', $countSql);

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Format meta pagination
 */
function paginationMeta($page, $limit, $total)
{
    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => ceil($total / $limit),
        'has_next' => ($page * $limit) < $total,
        'has_prev' => $page > 1,
    ];
}

/**
 * Escape untuk LIKE query
 */
function escapeLike($value)
{
    return str_replace(['%', '_'], ['\\%', '\\_'], $value);
}

// ============================================================
// AUTENTIKASI
// ============================================================

authenticate();

// ============================================================
// KONEKSI DATABASE (setelah auth, sebelum router)
// ============================================================

try {
    date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'presensi_tahsin';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\PDOException $e) {
    error_log('Hermes API DB Error: ' . $e->getMessage());
    jsonError(500, 'Gagal terhubung ke database. Periksa konfigurasi koneksi.');
}

// ============================================================
// ROUTER
// ============================================================

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'status':
            handleStatus($pdo);
            break;
        case 'peserta':
            handlePeserta($pdo);
            break;
        case 'halaqoh':
            handleHalaqoh($pdo);
            break;
        case 'presensi':
            handlePresensi($pdo);
            break;
        case 'stats':
            handleStats($pdo);
            break;
        case 'progress':
            handleProgress($pdo);
            break;
        case 'peringatan':
            handlePeringatan($pdo);
            break;
        case 'ustadz':
            handleUstadz($pdo);
            break;
        case 'libur':
            handleLibur($pdo);
            break;
        case 'pengumuman':
            handlePengumuman($pdo);
            break;
        case 'capaian':
            handleCapaian($pdo);
            break;
        case 'search':
            handleSearch($pdo);
            break;
        case 'schema':
            handleSchema($pdo);
            break;
        case 'logs':
            handleLogs($pdo);
            break;
        case 'users':
            handleUsers($pdo);
            break;
        default:
            jsonError(400, 'Action tidak dikenal. Gunakan: status, peserta, halaqoh, presensi, stats, progress, peringatan, ustadz, libur, pengumuman, capaian, search, schema, logs, users');
    }
} catch (PDOException $e) {
    jsonError(500, 'Database error: ' . $e->getMessage());
} catch (Exception $e) {
    jsonError(500, 'Server error: ' . $e->getMessage());
}

// ============================================================
// HANDLER FUNCTIONS
// ============================================================

/**
 * GET /api/hermes.php?action=status
 * Info API & ringkasan database
 */
function handleStatus($pdo)
{
    $countPeserta = $pdo->query("SELECT COUNT(*) FROM wali_santri")->fetchColumn();
    $countAktif = $pdo->query("SELECT COUNT(*) FROM wali_santri WHERE status_aktif = 1")->fetchColumn();
    $countHalaqoh = $pdo->query("SELECT COUNT(*) FROM halaqoh")->fetchColumn();
    $countUstadz = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ustadz'")->fetchColumn();
    $countPresensi = $pdo->query("SELECT COUNT(*) FROM presensi")->fetchColumn();
    $countUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $countSantri = $pdo->query("SELECT COUNT(*) FROM santri_detail")->fetchColumn();
    $countLibur = $pdo->query("SELECT COUNT(*) FROM holidays")->fetchColumn();

    // Presensi hari ini
    $hariIni = $pdo->prepare("SELECT COUNT(*) FROM presensi WHERE tanggal = ?");
    $hariIni->execute([date('Y-m-d')]);
    $presensiHariIni = $hariIni->fetchColumn();

    // Rentang tanggal presensi
    $rentang = $pdo->query("SELECT MIN(tanggal) as pertama, MAX(tanggal) as terakhir FROM presensi")->fetch();

    // Distribusi status
    $distribusi = $pdo->query("SELECT status, COUNT(*) as total FROM presensi GROUP BY status")->fetchAll();

    jsonOk([
        'api_version' => '1.0.0',
        'api_name' => 'Hermes API — Presensi Tahsin Bapak',
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'database' => [
            'total_peserta' => (int) $countPeserta,
            'peserta_aktif' => (int) $countAktif,
            'total_halaqoh' => (int) $countHalaqoh,
            'total_ustadz' => (int) $countUstadz,
            'total_users' => (int) $countUsers,
            'total_santri_anak' => (int) $countSantri,
            'total_presensi' => (int) $countPresensi,
            'presensi_hari_ini' => (int) $presensiHariIni,
            'total_hari_libur' => (int) $countLibur,
            'rentang_data' => $rentang ? [
                'dari' => $rentang['pertama'],
                'sampai' => $rentang['terakhir'],
            ] : null,
            'distribusi_status' => $distribusi,
        ],
        'endpoints' => [
            'status', 'peserta', 'halaqoh', 'presensi', 'stats',
            'progress', 'peringatan', 'ustadz', 'libur', 'pengumuman',
            'capaian', 'search', 'schema', 'logs', 'users',
        ],
    ]);
}

/**
 * GET /api/hermes.php?action=peserta
 * GET /api/hermes.php?action=peserta&id=5
 * GET /api/hermes.php?action=peserta&search=ahmad&kategori=reguler&halaqoh_id=2&kelas=3A
 */
function handlePeserta($pdo)
{
    $id = $_GET['id'] ?? null;

    // Single participant detail
    if ($id) {
        $stmt = $pdo->prepare("
            SELECT w.*, 
                   h.nama_halaqoh, 
                   u.nama_lengkap as nama_ustadz,
                   (SELECT COUNT(*) FROM presensi WHERE wali_santri_id = w.id) as total_presensi,
                   (SELECT COUNT(*) FROM presensi WHERE wali_santri_id = w.id AND status = 'H') as total_hadir,
                   (SELECT COUNT(*) FROM presensi WHERE wali_santri_id = w.id AND status = 'A') as total_alpha
            FROM wali_santri w
            LEFT JOIN halaqoh_members hm ON w.id = hm.wali_santri_id
            LEFT JOIN halaqoh h ON hm.halaqoh_id = h.id
            LEFT JOIN users u ON h.ustadz_id = u.id
            WHERE w.id = ?
        ");
        $stmt->execute([$id]);
        $peserta = $stmt->fetch();

        if (!$peserta) {
            jsonError(404, 'Peserta tidak ditemukan.');
        }

        // Ambil data anak-anak
        $stmtAnak = $pdo->prepare("SELECT id, nama_anak, kelas FROM santri_detail WHERE wali_santri_id = ?");
        $stmtAnak->execute([$id]);
        $peserta['anak'] = $stmtAnak->fetchAll();

        // Ambil riwayat presensi (5 terakhir)
        $stmtRiwayat = $pdo->prepare("
            SELECT p.id, p.tanggal, p.status, p.jenis_materi, p.jilid, p.nama_surat, p.halaman, p.hasil_talaqqi, p.alasan, h.nama_halaqoh
            FROM presensi p
            JOIN halaqoh h ON p.halaqoh_id = h.id
            WHERE p.wali_santri_id = ?
            ORDER BY p.tanggal DESC LIMIT 5
        ");
        $stmtRiwayat->execute([$id]);
        $peserta['riwayat_terbaru'] = $stmtRiwayat->fetchAll();

        jsonOk($peserta);
    }

    // List with filters
    [$page, $limit, $offset] = getPagination();
    $search = $_GET['search'] ?? '';
    $kategori = $_GET['kategori'] ?? '';
    $halaqoh_id = $_GET['halaqoh_id'] ?? '';
    $kelas = $_GET['kelas'] ?? '';
    $status_aktif = $_GET['status_aktif'] ?? '';

    $conditions = [];
    $params = [];

    if ($search) {
        $conditions[] = "(w.nama_bapak LIKE :search OR w.no_hp LIKE :search2)";
        $params[':search'] = '%' . escapeLike($search) . '%';
        $params[':search2'] = '%' . escapeLike($search) . '%';
    }

    if ($kategori) {
        $conditions[] = "w.kategori = :kategori";
        $params[':kategori'] = $kategori;
    }

    if ($halaqoh_id) {
        $conditions[] = "w.id IN (SELECT wali_santri_id FROM halaqoh_members WHERE halaqoh_id = :halaqoh_id)";
        $params[':halaqoh_id'] = $halaqoh_id;
    }

    if ($kelas) {
        $conditions[] = "w.id IN (SELECT wali_santri_id FROM santri_detail WHERE kelas = :kelas)";
        $params[':kelas'] = $kelas;
    }

    if ($status_aktif !== '') {
        $conditions[] = "w.status_aktif = :status_aktif";
        $params[':status_aktif'] = (int) $status_aktif;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "SELECT w.*, 
                       (SELECT GROUP_CONCAT(CONCAT(sd.nama_anak, IF(sd.kelas IS NULL OR sd.kelas = '', '', CONCAT(' (', sd.kelas, ')'))) SEPARATOR ', ') FROM santri_detail sd WHERE sd.wali_santri_id = w.id) as daftar_anak,
                       (SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id WHERE hm.wali_santri_id = w.id LIMIT 1) as nama_halaqoh,
                       (SELECT u.nama_lengkap FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id JOIN users u ON h.ustadz_id = u.id WHERE hm.wali_santri_id = w.id LIMIT 1) as nama_ustadz
                FROM wali_santri w
                {$where}
                ORDER BY w.nama_bapak";

    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=halaqoh
 * GET /api/hermes.php?action=halaqoh&id=3
 * GET /api/hermes.php?action=halaqoh&ustadz_id=2
 */
function handleHalaqoh($pdo)
{
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare("
            SELECT h.*, u.nama_lengkap as nama_ustadz, u.no_hp as no_hp_ustadz,
                   (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_member
            FROM halaqoh h
            JOIN users u ON h.ustadz_id = u.id
            WHERE h.id = ?
        ");
        $stmt->execute([$id]);
        $halaqoh = $stmt->fetch();

        if (!$halaqoh) {
            jsonError(404, 'Halaqoh tidak ditemukan.');
        }

        // Ambil anggota
        $stmtAnggota = $pdo->prepare("
            SELECT w.id, w.nama_bapak, w.no_hp, w.kategori, w.status_aktif,
                   (SELECT GROUP_CONCAT(CONCAT(sd.nama_anak, ' (', sd.kelas, ')') SEPARATOR ', ') FROM santri_detail sd WHERE sd.wali_santri_id = w.id) as anak
            FROM halaqoh_members hm
            JOIN wali_santri w ON hm.wali_santri_id = w.id
            WHERE hm.halaqoh_id = ?
            ORDER BY w.nama_bapak
        ");
        $stmtAnggota->execute([$id]);
        $halaqoh['anggota'] = $stmtAnggota->fetchAll();

        // Statistik presensi halaqoh ini
        $stmtStats = $pdo->prepare("
            SELECT status, COUNT(*) as total
            FROM presensi
            WHERE halaqoh_id = ?
            GROUP BY status
        ");
        $stmtStats->execute([$id]);
        $halaqoh['statistik_presensi'] = $stmtStats->fetchAll();

        jsonOk($halaqoh);
    }

    [$page, $limit, $offset] = getPagination();
    $ustadz_id = $_GET['ustadz_id'] ?? '';

    $conditions = [];
    $params = [];

    if ($ustadz_id) {
        $conditions[] = "h.ustadz_id = :ustadz_id";
        $params[':ustadz_id'] = $ustadz_id;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "SELECT h.*, u.nama_lengkap as nama_ustadz,
                       (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_member
                FROM halaqoh h
                JOIN users u ON h.ustadz_id = u.id
                {$where}
                ORDER BY h.nama_halaqoh";

    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=presensi
 * GET /api/hermes.php?action=presensi&wali_id=5
 * GET /api/hermes.php?action=presensi&halaqoh_id=2
 * GET /api/hermes.php?action=presensi&start=2026-01-01&end=2026-06-30&status=H
 */
function handlePresensi($pdo)
{
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare("
            SELECT p.*, w.nama_bapak, w.no_hp, h.nama_halaqoh, u.nama_lengkap as nama_ustadz
            FROM presensi p
            JOIN wali_santri w ON p.wali_santri_id = w.id
            JOIN halaqoh h ON p.halaqoh_id = h.id
            JOIN users u ON h.ustadz_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch();

        if (!$data) {
            jsonError(404, 'Data presensi tidak ditemukan.');
        }

        jsonOk($data);
    }

    [$page, $limit, $offset] = getPagination();
    [$start, $end] = getDateRange();

    $wali_id = $_GET['wali_id'] ?? $_GET['wali_santri_id'] ?? '';
    $halaqoh_id = $_GET['halaqoh_id'] ?? '';
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';

    $conditions = ["p.tanggal BETWEEN :start AND :end"];
    $params = [':start' => $start, ':end' => $end];

    if ($wali_id) {
        $conditions[] = "p.wali_santri_id = :wali_id";
        $params[':wali_id'] = $wali_id;
    }

    if ($halaqoh_id) {
        $conditions[] = "p.halaqoh_id = :halaqoh_id";
        $params[':halaqoh_id'] = $halaqoh_id;
    }

    if ($status && in_array($status, ['H', 'S', 'I', 'A'])) {
        $conditions[] = "p.status = :status";
        $params[':status'] = $status;
    }

    if ($search) {
        $conditions[] = "(w.nama_bapak LIKE :search OR h.nama_halaqoh LIKE :search2)";
        $params[':search'] = '%' . escapeLike($search) . '%';
        $params[':search2'] = '%' . escapeLike($search) . '%';
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $baseSql = "SELECT p.*, w.nama_bapak, w.no_hp, h.nama_halaqoh, u.nama_lengkap as nama_ustadz
                FROM presensi p
                JOIN wali_santri w ON p.wali_santri_id = w.id
                JOIN halaqoh h ON p.halaqoh_id = h.id
                JOIN users u ON h.ustadz_id = u.id
                {$where}
                ORDER BY p.tanggal DESC, h.nama_halaqoh, w.nama_bapak";

    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=stats
 * GET /api/hermes.php?action=stats&halaqoh_id=2
 * GET /api/hermes.php?action=stats&start=2026-01-01&end=2026-06-30
 */
function handleStats($pdo)
{
    [$start, $end] = getDateRange();
    $halaqoh_id = $_GET['halaqoh_id'] ?? '';

    $halaqohFilter = '';
    $params = [':start' => $start, ':end' => $end];
    if ($halaqoh_id) {
        $halaqohFilter = "AND p.halaqoh_id = :halaqoh_id";
        $params[':halaqoh_id'] = $halaqoh_id;
    }

    // Ringkasan presensi
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(status = 'H'), 0) as hadir,
            COALESCE(SUM(status = 'S'), 0) as sakit,
            COALESCE(SUM(status = 'I'), 0) as izin,
            COALESCE(SUM(status = 'A'), 0) as alpha,
            COALESCE(SUM(hasil_talaqqi = 'Lulus'), 0) as lulus,
            COALESCE(SUM(hasil_talaqqi = 'Ulang'), 0) as ulang
        FROM presensi p
        WHERE p.tanggal BETWEEN :start AND :end {$halaqohFilter}
    ");
    $stmt->execute($params);
    $ringkasan = $stmt->fetch();

    // Per halaqoh
    $stmtH = $pdo->prepare("
        SELECT h.id, h.nama_halaqoh, u.nama_lengkap as nama_ustadz,
               COUNT(p.id) as total_input,
               COALESCE(SUM(p.status = 'H'), 0) as hadir,
               COALESCE(SUM(p.status = 'A'), 0) as alpha,
               (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_anggota
        FROM halaqoh h
        JOIN users u ON h.ustadz_id = u.id
        LEFT JOIN presensi p ON p.halaqoh_id = h.id AND p.tanggal BETWEEN :start2 AND :end2
        GROUP BY h.id, h.nama_halaqoh, u.nama_lengkap
        ORDER BY h.nama_halaqoh
    ");
    $stmtH->execute([':start2' => $start, ':end2' => $end]);
    $perHalaqoh = $stmtH->fetchAll();

    // Tren harian (14 hari terakhir)
    $stmtTrend = $pdo->prepare("
        SELECT tanggal,
               COALESCE(SUM(status = 'H'), 0) as hadir,
               COALESCE(SUM(status = 'A'), 0) as alpha,
               COUNT(*) as total
        FROM presensi
        WHERE tanggal >= DATE_SUB(:end_date, INTERVAL 13 DAY) AND tanggal <= :end_date2
        GROUP BY tanggal
        ORDER BY tanggal ASC
    ");
    $stmtTrend->execute([':end_date' => $end, ':end_date2' => $end]);
    $tren = $stmtTrend->fetchAll();

    // Peringatan dini
    $stmtWarning = $pdo->prepare("
        SELECT w.id, w.nama_bapak, h.nama_halaqoh, COUNT(p.id) as total_alpha
        FROM wali_santri w
        JOIN presensi p ON w.id = p.wali_santri_id
        JOIN halaqoh h ON p.halaqoh_id = h.id
        WHERE p.status = 'A' AND p.tanggal BETWEEN :start3 AND :end3
        GROUP BY w.id, w.nama_bapak, h.nama_halaqoh
        HAVING total_alpha >= 3
        ORDER BY total_alpha DESC
        LIMIT 10
    ");
    $stmtWarning->execute([':start3' => $start, ':end3' => $end]);
    $peringatan = $stmtWarning->fetchAll();

    jsonOk([
        'ringkasan' => $ringkasan,
        'per_halaqoh' => $perHalaqoh,
        'tren_harian' => $tren,
        'peringatan_dini' => $peringatan,
        'periode' => ['start' => $start, 'end' => $end],
    ]);
}

/**
 * GET /api/hermes.php?action=progress
 * GET /api/hermes.php?action=progress&halaqoh_id=2&risk=rawan
 */
function handleProgress($pdo)
{
    [$page, $limit, $offset] = getPagination();
    [$start, $end] = getDateRange();
    $halaqoh_id = $_GET['halaqoh_id'] ?? '';
    $search = $_GET['search'] ?? '';
    $risk = $_GET['risk'] ?? '';

    $conditions = ["w.status_aktif = 1"];
    $params = [':start' => $start, ':end' => $end];

    if ($halaqoh_id) {
        $conditions[] = "h.id = :halaqoh_id";
        $params[':halaqoh_id'] = $halaqoh_id;
    }

    if ($search) {
        $conditions[] = "(w.nama_bapak LIKE :search OR h.nama_halaqoh LIKE :search2)";
        $params[':search'] = '%' . escapeLike($search) . '%';
        $params[':search2'] = '%' . escapeLike($search) . '%';
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $baseSql = "
        SELECT w.id, w.nama_bapak, w.no_hp, w.kategori,
               h.nama_halaqoh, u.nama_lengkap as nama_ustadz,
               (SELECT GROUP_CONCAT(CONCAT(sd.nama_anak, IF(sd.kelas = '', '', CONCAT(' (', sd.kelas, ')'))) SEPARATOR ', ') FROM santri_detail sd WHERE sd.wali_santri_id = w.id) as anak,
               COUNT(p.id) as total_presensi,
               COALESCE(SUM(p.status = 'H'), 0) as hadir,
               COALESCE(SUM(p.status = 'A'), 0) as alpha,
               COALESCE(SUM(p.hasil_talaqqi = 'Lulus'), 0) as lulus,
               COALESCE(SUM(p.hasil_talaqqi = 'Ulang'), 0) as ulang,
               MAX(p.tanggal) as terakhir_hadir,
               (SELECT CONCAT_WS(' ', lp.jenis_materi, CASE WHEN lp.jenis_materi = 'Iqro' THEN CONCAT('Jilid ', lp.jilid) WHEN lp.jenis_materi = 'Al Quran' THEN lp.nama_surat END, CASE WHEN lp.halaman IS NOT NULL THEN CONCAT('Hal/Ayat ', lp.halaman) END) FROM presensi lp WHERE lp.wali_santri_id = w.id AND lp.status = 'H' ORDER BY lp.tanggal DESC, lp.id DESC LIMIT 1) as materi_terakhir
        FROM wali_santri w
        JOIN halaqoh_members hm ON hm.wali_santri_id = w.id
        JOIN halaqoh h ON h.id = hm.halaqoh_id
        JOIN users u ON u.id = h.ustadz_id
        LEFT JOIN presensi p ON p.wali_santri_id = w.id AND p.halaqoh_id = h.id AND p.tanggal BETWEEN :start AND :end
        {$where}
        GROUP BY w.id, w.nama_bapak, w.no_hp, w.kategori, h.nama_halaqoh, u.nama_lengkap
        ORDER BY alpha DESC, terakhir_hadir ASC, w.nama_bapak ASC
    ";

    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Hitung risk level
    foreach ($data as &$row) {
        $totalPresensi = (int) $row['total_presensi'];
        $row['hadir_percent'] = $totalPresensi > 0 ? round(((int) $row['hadir'] / $totalPresensi) * 100) : 0;
        $last = $row['terakhir_hadir'] ? strtotime($row['terakhir_hadir']) : null;
        $daysSince = $last ? floor((time() - $last) / 86400) : null;

        if ((int) $row['alpha'] >= 3 || $daysSince === null || $daysSince >= 21) {
            $row['risk_level'] = 'rawan';
            $row['risk_label'] = 'Butuh Follow-up';
        } elseif ((int) $row['alpha'] >= 1 || $daysSince >= 14) {
            $row['risk_level'] = 'pantau';
            $row['risk_label'] = 'Pantau';
        } else {
            $row['risk_level'] = 'stabil';
            $row['risk_label'] = 'Stabil';
        }
    }
    unset($row);

    // Filter risk jika diminta
    if ($risk) {
        $data = array_values(array_filter($data, fn($r) => $r['risk_level'] === $risk));
        $total = count($data);
    }

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=peringatan
 * GET /api/hermes.php?action=peringatan&halaqoh_id=2
 */
function handlePeringatan($pdo)
{
    $halaqoh_id = $_GET['halaqoh_id'] ?? '';

    $conditions = ["p.status = 'A'"];
    $params = [];

    if ($halaqoh_id) {
        $conditions[] = "p.halaqoh_id = :halaqoh_id";
        $params[':halaqoh_id'] = $halaqoh_id;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT w.id, w.nama_bapak, w.no_hp, h.nama_halaqoh, u.nama_lengkap as nama_ustadz,
                   COUNT(p.id) as total_alpha,
                   MAX(p.tanggal) as alpha_terakhir
            FROM wali_santri w
            JOIN presensi p ON w.id = p.wali_santri_id
            JOIN halaqoh h ON p.halaqoh_id = h.id
            JOIN users u ON h.ustadz_id = u.id
            {$where}
            GROUP BY w.id, w.nama_bapak, w.no_hp, h.nama_halaqoh, u.nama_lengkap
            HAVING total_alpha >= 3
            ORDER BY total_alpha DESC
            LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk([
        'total_peringatan' => count($data),
        'data' => $data,
        'keterangan' => 'Peserta dengan alpha >= 3 kali. Butuh follow-up segera.',
    ]);
}

/**
 * GET /api/hermes.php?action=ustadz
 * GET /api/hermes.php?action=ustadz&id=2
 */
function handleUstadz($pdo)
{
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.nama_lengkap, u.no_hp, u.role, u.created_at,
                   (SELECT COUNT(*) FROM halaqoh WHERE ustadz_id = u.id) as total_halaqoh,
                   (SELECT COUNT(DISTINCT hm.wali_santri_id) FROM halaqoh h JOIN halaqoh_members hm ON h.id = hm.halaqoh_id WHERE h.ustadz_id = u.id) as total_member,
                   (SELECT COUNT(*) FROM presensi p JOIN halaqoh h ON p.halaqoh_id = h.id WHERE h.ustadz_id = u.id) as total_input_presensi
            FROM users u
            WHERE u.id = ? AND u.role = 'ustadz'
        ");
        $stmt->execute([$id]);
        $ustadz = $stmt->fetch();

        if (!$ustadz) {
            jsonError(404, 'Ustadz tidak ditemukan.');
        }

        // Halaqoh yang diampu
        $stmtH = $pdo->prepare("
            SELECT h.*, (SELECT COUNT(*) FROM halaqoh_members WHERE halaqoh_id = h.id) as total_member
            FROM halaqoh h WHERE h.ustadz_id = ?
        ");
        $stmtH->execute([$id]);
        $ustadz['halaqoh'] = $stmtH->fetchAll();

        jsonOk($ustadz);
    }

    [$page, $limit, $offset] = getPagination();

    $baseSql = "SELECT u.id, u.username, u.nama_lengkap, u.no_hp, u.role, u.created_at,
                       (SELECT COUNT(*) FROM halaqoh WHERE ustadz_id = u.id) as total_halaqoh,
                       (SELECT COUNT(DISTINCT hm.wali_santri_id) FROM halaqoh h JOIN halaqoh_members hm ON h.id = hm.halaqoh_id WHERE h.ustadz_id = u.id) as total_member
                FROM users u
                WHERE u.role = 'ustadz'
                ORDER BY u.nama_lengkap";

    $total = getTotalCount($pdo, $baseSql);
    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=libur
 * GET /api/hermes.php?action=libur&start=2026-01-01&end=2026-12-31
 */
function handleLibur($pdo)
{
    [$page, $limit, $offset] = getPagination();
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';

    $conditions = [];
    $params = [];

    if ($start && $end) {
        $conditions[] = "tanggal BETWEEN :start AND :end";
        $params[':start'] = $start;
        $params[':end'] = $end;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "SELECT * FROM holidays {$where} ORDER BY tanggal DESC";
    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=pengumuman
 * GET /api/hermes.php?action=pengumuman&aktif_only=1
 */
function handlePengumuman($pdo)
{
    [$page, $limit, $offset] = getPagination();
    $aktifOnly = $_GET['aktif_only'] ?? $_GET['aktif'] ?? '';

    $conditions = [];
    $params = [];

    if ($aktifOnly === '1' || $aktifOnly === 'true') {
        $conditions[] = "is_aktif = 1";
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "SELECT * FROM pengumuman {$where} ORDER BY created_at DESC";
    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=capaian
 * GET /api/hermes.php?action=capaian&halaqoh_id=2&search=ahmad
 */
function handleCapaian($pdo)
{
    [$page, $limit, $offset] = getPagination();
    $halaqoh_id = $_GET['halaqoh_id'] ?? '';
    $search = $_GET['search'] ?? '';

    $conditions = [];
    $params = [];

    if ($halaqoh_id) {
        $conditions[] = "h.id = :halaqoh_id";
        $params[':halaqoh_id'] = $halaqoh_id;
    }

    if ($search) {
        $conditions[] = "(w.nama_bapak LIKE :search OR h.nama_halaqoh LIKE :search2)";
        $params[':search'] = '%' . escapeLike($search) . '%';
        $params[':search2'] = '%' . escapeLike($search) . '%';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "
        SELECT w.id as wali_id, w.nama_bapak, w.no_hp,
               h.id as halaqoh_id, h.nama_halaqoh, u.nama_lengkap as nama_ustadz,
               GROUP_CONCAT(DISTINCT s.nama_anak SEPARATOR ', ') as children_names,
               GROUP_CONCAT(DISTINCT s.kelas SEPARATOR ', ') as classes,
               p.jenis_materi, p.jilid, p.nama_surat, p.halaman, p.tanggal as last_date, p.hasil_talaqqi
        FROM wali_santri w
        JOIN halaqoh_members hm ON w.id = hm.wali_santri_id
        JOIN halaqoh h ON hm.halaqoh_id = h.id
        JOIN users u ON h.ustadz_id = u.id
        LEFT JOIN santri_detail s ON w.id = s.wali_santri_id
        LEFT JOIN (
            SELECT p1.*
            FROM presensi p1
            INNER JOIN (
                SELECT wali_santri_id, MAX(tanggal) as max_date, MAX(id) as max_id
                FROM presensi WHERE status = 'H'
                GROUP BY wali_santri_id
            ) p2 ON p1.wali_santri_id = p2.wali_santri_id AND p1.tanggal = p2.max_date AND p1.id = p2.max_id
        ) p ON w.id = p.wali_santri_id
        {$where}
        GROUP BY w.id, h.id
        ORDER BY h.nama_halaqoh, w.nama_bapak
    ";

    $total = getTotalCount($pdo, $baseSql, $params);
    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=search&q=ahmad
 * Pencarian global di semua tabel
 */
function handleSearch($pdo)
{
    $q = $_GET['q'] ?? '';
    if (!$q) {
        jsonError(400, 'Parameter "q" (query pencarian) wajib diisi.');
    }

    [$page, $limit, $offset] = getPagination();
    $searchTerm = '%' . escapeLike($q) . '%';

    $results = [];

    // 1. Cari wali santri
    $stmt = $pdo->prepare("
        SELECT w.id, w.nama_bapak as nama, w.no_hp, w.kategori, 'wali_santri' as tipe,
               h.nama_halaqoh as kelompok
        FROM wali_santri w
        LEFT JOIN halaqoh_members hm ON w.id = hm.wali_santri_id
        LEFT JOIN halaqoh h ON hm.halaqoh_id = h.id
        WHERE w.nama_bapak LIKE ? OR w.no_hp LIKE ?
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    // 2. Cari santri (anak)
    $stmt = $pdo->prepare("
        SELECT sd.id, sd.nama_anak as nama, sd.kelas, w.nama_bapak as nama_wali, 'santri' as tipe
        FROM santri_detail sd
        JOIN wali_santri w ON sd.wali_santri_id = w.id
        WHERE sd.nama_anak LIKE ?
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    // 3. Cari halaqoh
    $stmt = $pdo->prepare("
        SELECT h.id, h.nama_halaqoh as nama, u.nama_lengkap as ustadz, 'halaqoh' as tipe
        FROM halaqoh h
        JOIN users u ON h.ustadz_id = u.id
        WHERE h.nama_halaqoh LIKE ?
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    // 4. Cari ustadz/user
    $stmt = $pdo->prepare("
        SELECT id, nama_lengkap as nama, username, role, 'user' as tipe
        FROM users
        WHERE nama_lengkap LIKE ? OR username LIKE ?
        LIMIT ?
    ");
    $stmt->execute([$searchTerm, $searchTerm, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    jsonOk([
        'query' => $q,
        'total' => count($results),
        'results' => $results,
    ]);
}

/**
 * GET /api/hermes.php?action=schema
 * Informasi skema database
 */
function handleSchema($pdo)
{
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $schema = [];

    foreach ($tables as $table) {
        $columns = $pdo->query("SHOW FULL COLUMNS FROM `{$table}`")->fetchAll();
        $createTable = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);

        $schema[$table] = [
            'columns' => array_map(function ($col) {
                return [
                    'name' => $col['Field'],
                    'type' => $col['Type'],
                    'null' => $col['Null'] === 'YES',
                    'key' => $col['Key'],
                    'default' => $col['Default'],
                    'extra' => $col['Extra'],
                    'comment' => $col['Comment'],
                ];
            }, $columns),
            'row_count' => (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn(),
        ];
    }

    jsonOk([
        'database' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
        'total_tables' => count($tables),
        'tables' => $schema,
    ]);
}

/**
 * GET /api/hermes.php?action=logs
 * GET /api/hermes.php?action=logs&action=LOGIN&start=2026-01-01&end=2026-06-30
 */
function handleLogs($pdo)
{
    [$page, $limit, $offset] = getPagination();
    $filterAction = $_GET['action'] ?? '';
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';

    $conditions = [];
    $params = [];

    if ($filterAction) {
        $conditions[] = "action = :action";
        $params[':action'] = $filterAction;
    }

    if ($start && $end) {
        $conditions[] = "created_at BETWEEN :start AND :end";
        $params[':start'] = $start . ' 00:00:00';
        $params[':end'] = $end . ' 23:59:59';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "SELECT * FROM activity_logs {$where} ORDER BY created_at DESC";
    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}

/**
 * GET /api/hermes.php?action=users
 * GET /api/hermes.php?action=users&role=ustadz
 */
function handleUsers($pdo)
{
    [$page, $limit, $offset] = getPagination();
    $role = $_GET['role'] ?? '';

    $conditions = [];
    $params = [];

    if ($role) {
        $conditions[] = "role = :role";
        $params[':role'] = $role;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $baseSql = "SELECT id, username, nama_lengkap, role, no_hp, created_at FROM users {$where} ORDER BY nama_lengkap";
    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}
