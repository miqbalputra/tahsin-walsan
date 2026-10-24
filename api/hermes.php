<?php
require_once __DIR__ . '/../includes/security_helper.php';
require_once __DIR__ . '/../includes/tahsin_report_helper.php';
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
 *   Key di query string/POST body TIDAK lagi diterima (tercatat di access log).
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
 *   curl -H "X-API-Key: rahasia" "https://domain.com/api/hermes.php?action=stats"
 *   curl -H "X-API-Key: rahasia" "https://domain.com/api/hermes.php?action=search&q=ahmad"
 */

// ============================================================
// BOOTSTRAP
// ============================================================

// CORS headers — hanya origin yang didaftarkan di HERMES_ALLOWED_ORIGINS
// (dipisah koma). Pemanggil server-side (n8n/Hermes agent) tidak memerlukan
// CORS; tanpa env ini API tidak mengirim header CORS sama sekali.
$hermesAllowedOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('HERMES_ALLOWED_ORIGINS') ?: ''))));
$hermesRequestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (is_string($hermesRequestOrigin) && $hermesRequestOrigin !== '' && in_array($hermesRequestOrigin, $hermesAllowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $hermesRequestOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

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
$configuredHermesKey = trim(getenv('HERMES_API_KEY') ?: getenv('N8N_API_KEY') ?: '');
if ($configuredHermesKey === '') {
    jsonError(503, 'API belum dikonfigurasi. Set HERMES_API_KEY di environment.');
}
define('HERMES_API_KEY', $configuredHermesKey);

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
 * Validasi API key — dilakukan SEBELUM koneksi database.
 * Key hanya diterima via header X-API-Key; parameter URL/POST tidak lagi
 * didukung agar secret tidak tercatat di access log / riwayat browser.
 */
function authenticate()
{
    $headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
    $key = $headers['x-api-key'] ?? '';

    if (!is_string($key) || $key === '' || !hash_equals(HERMES_API_KEY, $key)) {
        jsonError(401, 'Unauthorized. Provide the API key via the X-API-Key request header.');
    }
    if (!apiRateLimitAllow('hermes', 120, 60)) {
        jsonError(429, 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.');
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
        PDO::ATTR_TIMEOUT => max(1, (int) (getenv('DB_CONNECT_TIMEOUT') ?: 5)),
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
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
        case 'keluarga':
            if (!familySchemaReady($pdo)) jsonError(503, 'Migrasi keluarga belum diterapkan.');
            [$page, $limit, $offset] = getPagination();
            $familyData = familyFetchStudents($pdo, $limit, $offset, substr(trim((string) ($_GET['search'] ?? '')), 0, 100));
            jsonOk($familyData['students'], ['page' => $page, 'limit' => $limit, 'total' => $familyData['total']]);
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
    error_log('Hermes API request failed: ' . $e->getMessage());
    jsonError(500, 'Database error. Silakan coba lagi.');
} catch (Exception $e) {
    error_log('Hermes API server error: ' . $e->getMessage());
    jsonError(500, 'Server error. Silakan coba lagi.');
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
            'capaian', 'search', 'schema', 'logs', 'users', 'keluarga',
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
            LEFT JOIN halaqoh_members hm ON w.id = hm.wali_santri_id AND hm.archived_at IS NULL
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
        $stmtAnak = $pdo->prepare('SELECT id,nama_anak,kelas FROM santri_detail WHERE wali_santri_id=?');
        $stmtAnak->execute([$id]);
        $peserta['anak'] = $stmtAnak->fetchAll();
        if (familySchemaReady($pdo)) {
            $sharedChildren = $pdo->prepare('SELECT nama_anak,kelas,source_namespace,source_student_id FROM family_children WHERE wali_santri_id=?');
            $sharedChildren->execute([$id]);
            $peserta['anak_terpadu'] = $sharedChildren->fetchAll();
        }

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
        $conditions[] = "w.id IN (SELECT wali_santri_id FROM halaqoh_members WHERE halaqoh_id = :halaqoh_id AND archived_at IS NULL)";
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
                       (SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id WHERE hm.wali_santri_id = w.id AND hm.archived_at IS NULL LIMIT 1) as nama_halaqoh,
                       (SELECT u.nama_lengkap FROM halaqoh_members hm JOIN halaqoh h ON hm.halaqoh_id = h.id JOIN users u ON h.ustadz_id = u.id WHERE hm.wali_santri_id = w.id AND hm.archived_at IS NULL LIMIT 1) as nama_ustadz
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
                   (SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id = hm.wali_santri_id WHERE hm.halaqoh_id = h.id AND hm.archived_at IS NULL AND w.status_aktif = 1) as total_member
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
            WHERE hm.halaqoh_id = ? AND hm.archived_at IS NULL AND w.status_aktif = 1
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
                       (SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id = hm.wali_santri_id WHERE hm.halaqoh_id = h.id AND hm.archived_at IS NULL AND w.status_aktif = 1) as total_member
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
    $report = tahsinFetchReport($pdo, tahsinFilters(array_merge($_GET, ['start_date' => $start, 'end_date' => $end])));
    $summary = $report['summary']; $summary['total'] = $summary['total_presensi'];
    $groups = array_map(static function ($g) { $g['id'] = $g['halaqoh_id'] ?? 0; $g['total_input'] = $g['total_presensi']; return $g; }, $report['halaqoh']);
    $warnings = array_values(array_map(static function ($r) { unset($r['details'], $r['children'], $r['materi_record']); $r['total_alpha'] = $r['alpha']; return $r; }, array_filter($report['rows'], static fn($r) => $r['alpha'] >= 3)));
    jsonOk(['ringkasan' => $summary, 'per_halaqoh' => $groups, 'tren_harian' => $report['trend'], 'tren_pekanan' => $report['trend'],
        'peringatan_dini' => $warnings, 'periode' => ['start' => $start, 'end' => $end], 'basis_periode' => 'tanggal_sesi_ahad']);
}

/**
 * GET /api/hermes.php?action=progress
 * GET /api/hermes.php?action=progress&halaqoh_id=2&risk=rawan
 */
function handleProgress($pdo)
{
    [$page, $limit, $offset] = getPagination();
    [$start, $end] = getDateRange();
    $report = tahsinFetchReport($pdo, tahsinFilters(array_merge($_GET, ['start_date' => $start, 'end_date' => $end, 'aktif' => '1'])));
    $data = array_map(static function ($r) { unset($r['details'], $r['children'], $r['materi_record']); return $r; }, array_slice($report['rows'], $offset, $limit));
    jsonOk($data, paginationMeta($page, $limit, count($report['rows'])));
}

/**
 * GET /api/hermes.php?action=peringatan
 * GET /api/hermes.php?action=peringatan&halaqoh_id=2
 */
function handlePeringatan($pdo)
{
    $end = tahsinDate((string) ($_GET['end'] ?? '')) ?? date('Y-m-d');
    $data = tahsinLoadDataset($pdo, $end);
    $start = tahsinDate((string) ($_GET['start'] ?? '')) ?? (!empty($data['records']) ? tahsinSessionDate($data['records'][0]['tanggal']) : date('Y-m-01'));
    $report = tahsinAssembleReport($data, tahsinFilters(array_merge($_GET, ['start_date' => $start, 'end_date' => $end])));
    $warnings = array_values(array_map(static function ($r) {
        unset($r['details'], $r['children'], $r['materi_record']); $r['total_alpha'] = $r['alpha']; return $r;
    }, array_filter($report['rows'], static fn($r) => $r['alpha'] >= 3)));
    jsonOk(['total_peringatan' => count($warnings), 'data' => array_slice($warnings, 0, 50),
        'keterangan' => 'Alfa sah minimal 3 sesi; data kosong dan konflik tidak dianggap Alfa.']);
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
                   (SELECT COUNT(DISTINCT hm.wali_santri_id) FROM halaqoh h JOIN halaqoh_members hm ON h.id = hm.halaqoh_id WHERE h.ustadz_id = u.id AND hm.archived_at IS NULL) as total_member,
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
            SELECT h.*, (SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id = hm.wali_santri_id WHERE hm.halaqoh_id = h.id AND hm.archived_at IS NULL AND w.status_aktif = 1) as total_member
            FROM halaqoh h WHERE h.ustadz_id = ?
        ");
        $stmtH->execute([$id]);
        $ustadz['halaqoh'] = $stmtH->fetchAll();

        jsonOk($ustadz);
    }

    [$page, $limit, $offset] = getPagination();

    $baseSql = "SELECT u.id, u.username, u.nama_lengkap, u.no_hp, u.role, u.created_at,
                       (SELECT COUNT(*) FROM halaqoh WHERE ustadz_id = u.id) as total_halaqoh,
                       (SELECT COUNT(DISTINCT hm.wali_santri_id) FROM halaqoh h JOIN halaqoh_members hm ON h.id = hm.halaqoh_id WHERE h.ustadz_id = u.id AND hm.archived_at IS NULL) as total_member
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
    $now = tahsinNow();
    $end = tahsinDate((string) ($_GET['end'] ?? '')) ?? $now->format('Y-m-d');
    $dataset = tahsinLoadDataset($pdo, $end, $now);
    $start = $dataset['records'] ? tahsinSessionDate($dataset['records'][0]['tanggal']) : $now->format('Y-m-01');
    $report = tahsinAssembleReport($dataset, tahsinFilters(array_merge($_GET, ['start_date' => $start, 'end_date' => $end, 'aktif' => '1']), $now));
    $rows = array_values(array_filter($report['rows'], static fn($r) => !empty($r['current_halaqoh_id'])));
    $data = array_map(static function ($r) {
        $source = $r['materi_record'] ?? $r['baseline_record'];
        return ['wali_id' => $r['id'], 'nama_bapak' => $r['nama_bapak'], 'no_hp' => $r['no_hp'] ?? null,
            'halaqoh_id' => $r['halaqoh_id'], 'nama_halaqoh' => $r['nama_halaqoh'], 'nama_ustadz' => $r['nama_ustadz'],
            'children_names' => implode(', ', array_column($r['children'], 'nama_anak')),
            'classes' => implode(', ', array_unique(array_filter(array_column($r['children'], 'kelas')))),
            'jenis_materi' => $source['jenis_materi'] ?? null, 'jilid' => $source['jilid'] ?? null,
            'nama_surat' => $source['nama_surat'] ?? null, 'halaman' => $source['halaman'] ?? null,
            'last_date' => $source['tanggal'] ?? null, 'hasil_talaqqi' => $source['hasil_talaqqi'] ?? null,
            'is_initial' => $r['baseline_record'] !== null, 'quality' => $r['quality'], 'quality_label' => $r['quality_label']];
    }, array_slice($rows, $offset, $limit));
    jsonOk($data, paginationMeta($page, $limit, count($rows)));
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
        LEFT JOIN halaqoh_members hm ON w.id = hm.wali_santri_id AND hm.archived_at IS NULL
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
    // Struktur database membantu penyerang menyusun query; hanya aktif
    // secara eksplisit untuk kebutuhan integrasi tertentu.
    if (getenv('HERMES_EXPOSE_SCHEMA') !== '1') {
        jsonError(403, 'Aksi schema dinonaktifkan. Set HERMES_EXPOSE_SCHEMA=1 untuk mengaktifkannya.');
    }
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

    $baseSql = "SELECT id, user_id, username, action, description, created_at FROM activity_logs {$where} ORDER BY created_at DESC";
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

    // no_hp tidak ikut dibocorkan melalui API agent.
    $baseSql = "SELECT id, username, nama_lengkap, role, created_at FROM users {$where} ORDER BY nama_lengkap";
    $total = getTotalCount($pdo, $baseSql, $params);

    $sql = $baseSql . " LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonOk($data, paginationMeta($page, $limit, $total));
}
