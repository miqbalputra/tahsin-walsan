<?php
/**
 * Naik Kelas & Arsip Lulusan
 *
 * Tab 1 — Bulk Naik Kelas: pratinjau + naikkan santri_detail.kelas satu mustawa.
 *          Mustawa N (Ikhwan/Akhwat) -> Mustawa N+1 (Ikhwan/Akhwat); Mustawa 6 -> "Lulus".
 * Tab 2 — Arsip Lulusan: kandidat wali yang SEMUA anaknya "Lulus" -> status_aktif=0,
 *          toggle "Lanjut Tahsin" untuk wali yang tetap mau ikut tahsin,
 *          daftar wali terarsip + Aktifkan Kembali.
 *
 * Safeguard kakak-beradik dijalankan server-side: wali yang masih punya anak
 * aktif (kelas != 'Lulus') TIDAK dapat diarsipkan meski ID-nya dipaksa dari client.
 */
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin', 'pj_tahfidz']);

$pageTitle = 'Naik Kelas & Arsip Lulusan';

/**
 * Hitung kelas baru dari kelas lama.
 * Mengembalikan [bool $ok, string $next].
 * - "Mustawa N Ikhwan|Akhwat" -> "Mustawa (N+1) Ikhwan|Akhwat" (N<6) atau "Lulus" (N>=6)
 * - "Mustawa N"               -> "Mustawa (N+1)"            (N<6) atau "Lulus" (N>=6)
 * - lainnya (NULL, '', "Lulus", typo) -> [false, '']  (di-skip)
 */
function nextKelas(string $kelas): array
{
    $k = trim($kelas ?? '');
    if ($k === '') {
        return [false, ''];
    }
    if (preg_match('/^Mustawa\s+(\d+)\s+(Ikhwan|Akhwat)$/i', $k, $m)) {
        $n = (int) $m[1];
        $suffix = ucfirst(strtolower($m[2]));
        return $n >= 6 ? [true, 'Lulus'] : [true, 'Mustawa ' . ($n + 1) . ' ' . $suffix];
    }
    if (preg_match('/^Mustawa\s+(\d+)$/i', $k, $m)) {
        $n = (int) $m[1];
        return $n >= 6 ? [true, 'Lulus'] : [true, 'Mustawa ' . ($n + 1)];
    }
    return [false, ''];
}

// ---------------------------------------------------------------------------
// Export backup CSV (GET ?export=backup) — snapshot santri_detail sebelum eksekusi
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'backup') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup_santri_detail_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['santri_detail_id', 'wali_santri_id', 'nama_bapak', 'nama_anak', 'kelas']);
    $rows = $pdo->query("
        SELECT sd.id, sd.wali_santri_id, w.nama_bapak, sd.nama_anak, sd.kelas
        FROM santri_detail sd
        JOIN wali_santri w ON w.id = sd.wali_santri_id
        ORDER BY sd.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['wali_santri_id'], $r['nama_bapak'], $r['nama_anak'], $r['kelas']]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// POST handler — semua aksi redirect setelah selesai (mencegah resubmit saat refresh)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_naik') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['santri_ids'] ?? []), fn($x) => $x > 0)));
        if (!$ids) {
            redirectTo('naik-kelas.php?tab=naik&err=' . rawurlencode('Tidak ada santri yang dipilih.'));
        }
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, kelas FROM santri_detail WHERE id IN ($placeholders) FOR UPDATE");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $upd = $pdo->prepare("UPDATE santri_detail SET kelas = ? WHERE id = ?");
            $snap = [];
            $done = 0;
            $skip = 0;
            foreach ($rows as $r) {
                [$ok, $next] = nextKelas((string) $r['kelas']);
                if (!$ok) {
                    $skip++;
                    continue;
                }
                $upd->execute([$next, $r['id']]);
                $snap[] = $r['id'] . ':' . $r['kelas'] . '->' . $next;
                $done++;
            }
            addLog($pdo, 'BULK_NAIK_KELAS', json_encode($snap));
            $pdo->commit();

            $msg = "$done anak berhasil dinaikkan kelas.";
            if ($skip > 0) {
                $msg .= " $skip di-skip (kelas sudah Lulus / format tidak dikenal).";
            }
            redirectTo('naik-kelas.php?tab=naik&msg=' . rawurlencode($msg));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirectTo('naik-kelas.php?tab=naik&err=' . rawurlencode('Gagal: ' . $e->getMessage()));
        }
    }

    // Luluskan manual (per-pilih) — tandai santri terpilih sbg 'Lulus' langsung,
    // tanpa lewat nextKelas. Berguna bila kenaikan kelas sudah jalan sebagian
    // sehingga label "Mustawa 6" berisi campuran mantan M5 & M6 asli, dan admin
    // perlu memilih manual siapa yang benar-benar lulus.
    if ($action === 'bulk_lulus') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['santri_ids'] ?? []), fn($x) => $x > 0)));
        if (!$ids) {
            redirectTo('naik-kelas.php?tab=naik&err=' . rawurlencode('Tidak ada santri yang dipilih untuk diluluskan.'));
        }
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE santri_detail SET kelas = 'Lulus' WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $done = $stmt->rowCount();
            addLog($pdo, 'BULK_LULUS_MANUAL', "Luluskan manual santri: " . implode(',', $ids));
            $pdo->commit();
            redirectTo('naik-kelas.php?tab=naik&msg=' . rawurlencode("$done santri ditandai 'Lulus' (manual)."));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirectTo('naik-kelas.php?tab=naik&err=' . rawurlencode('Gagal: ' . $e->getMessage()));
        }
    }

    if ($action === 'bulk_archive') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['wali_ids'] ?? []), fn($x) => $x > 0)));
        if (!$ids) {
            redirectTo('naik-kelas.php?tab=arsip&err=' . rawurlencode('Tidak ada wali yang dipilih.'));
        }
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // Safeguard server-side: hanya wali aktif, bukan lanjut_tahsin,
            // punya minimal 1 anak, DAN semua anaknya berkelas 'Lulus'.
            $stmt = $pdo->prepare("
                SELECT w.id FROM wali_santri w
                WHERE w.id IN ($placeholders)
                  AND w.status_aktif = 1
                  AND w.lanjut_tahsin = 0
                  AND EXISTS (SELECT 1 FROM santri_detail sd WHERE sd.wali_santri_id = w.id)
                  AND NOT EXISTS (
                      SELECT 1 FROM santri_detail sd
                      WHERE sd.wali_santri_id = w.id AND sd.kelas <> 'Lulus'
                  )
            ");
            $stmt->execute($ids);
            $ok_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $archived = 0;
            if ($ok_ids) {
                $updPh = implode(',', array_fill(0, count($ok_ids), '?'));
                $upd = $pdo->prepare("UPDATE wali_santri SET status_aktif = 0 WHERE id IN ($updPh)");
                $upd->execute($ok_ids);
                $archived = $upd->rowCount();
            }
            addLog($pdo, 'BULK_ARCHIVE_WALI', 'Arsipkan wali: ' . implode(',', $ok_ids));
            $pdo->commit();

            $rejected = count($ids) - count($ok_ids);
            $msg = "$archived wali berhasil diarsipkan.";
            if ($rejected > 0) {
                $msg .= " $rejected ditolak (masih punya anak aktif / ditandai lanjut tahsin).";
            }
            redirectTo('naik-kelas.php?tab=arsip&msg=' . rawurlencode($msg));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirectTo('naik-kelas.php?tab=arsip&err=' . rawurlencode('Gagal: ' . $e->getMessage()));
        }
    }

    if ($action === 'unarchive') {
        $wid = (int) ($_POST['wali_id'] ?? 0);
        if ($wid > 0) {
            $pdo->prepare("UPDATE wali_santri SET status_aktif = 1 WHERE id = ?")->execute([$wid]);
            addLog($pdo, 'UNARCHIVE_WALI', "Aktifkan kembali wali: $wid");
            redirectTo('naik-kelas.php?tab=arsip&msg=' . rawurlencode('Wali berhasil diaktifkan kembali.'));
        }
        redirectTo('naik-kelas.php?tab=arsip&err=' . rawurlencode('ID wali tidak valid.'));
    }

    if ($action === 'set_lanjut_tahsin') {
        $wid = (int) ($_POST['wali_id'] ?? 0);
        $val = ((int) ($_POST['value'] ?? 0)) ? 1 : 0;
        if ($wid > 0) {
            $pdo->prepare("UPDATE wali_santri SET lanjut_tahsin = ? WHERE id = ?")->execute([$val, $wid]);
            addLog($pdo, 'TOGGLE_LANJUT_TAHSIN', "Wali $wid lanjut_tahsin=$val");
            $label = $val ? 'Wali ditandai lanjut tahsin (tidak akan diarsipkan).' : 'Tanda lanjut tahsin dibatalkan.';
            redirectTo('naik-kelas.php?tab=arsip&msg=' . rawurlencode($label));
        }
        redirectTo('naik-kelas.php?tab=arsip&err=' . rawurlencode('ID wali tidak valid.'));
    }

    redirectTo('naik-kelas.php');
}

// ---------------------------------------------------------------------------
// Data untuk渲染 (GET)
// ---------------------------------------------------------------------------
$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';
$active_tab = ($_GET['tab'] ?? '') === 'arsip' ? 'arsip' : 'naik';

// Filter Tab 1
$kelas_filter = $_GET['kelas'] ?? '';
$halaqoh_filter = $_GET['halaqoh'] ?? '';

// Daftar kelas (exclude 'Lulus') + halaqoh untuk dropdown
$daftar_kelas = $pdo->query("SELECT DISTINCT kelas FROM santri_detail WHERE kelas IS NOT NULL AND kelas <> '' AND kelas <> 'Lulus' ORDER BY kelas")->fetchAll(PDO::FETCH_COLUMN);
$daftar_halaqoh = $pdo->query("SELECT h.id, h.nama_halaqoh, u.nama_lengkap as nama_ustadz FROM halaqoh h JOIN users u ON h.ustadz_id = u.id ORDER BY h.nama_halaqoh")->fetchAll();

// Tab 1 — pratinjau naik kelas
$naikSql = "
    SELECT sd.id, sd.wali_santri_id, w.nama_bapak, sd.nama_anak, sd.kelas, h.nama_halaqoh
    FROM santri_detail sd
    JOIN wali_santri w ON w.id = sd.wali_santri_id AND w.status_aktif = 1
    LEFT JOIN halaqoh_members hm ON hm.wali_santri_id = w.id
    LEFT JOIN halaqoh h ON h.id = hm.halaqoh_id
    WHERE sd.kelas LIKE 'Mustawa %' AND sd.kelas <> 'Lulus'
";
$naikParams = [];
if ($kelas_filter !== '') {
    $naikSql .= " AND sd.kelas = :kelas";
    $naikParams[':kelas'] = $kelas_filter;
}
if ($halaqoh_filter !== '') {
    $naikSql .= " AND w.id IN (SELECT wali_santri_id FROM halaqoh_members WHERE halaqoh_id = :halaqoh)";
    $naikParams[':halaqoh'] = $halaqoh_filter;
}
$naikSql .= " ORDER BY sd.kelas, w.nama_bapak, sd.nama_anak";
$naikStmt = $pdo->prepare($naikSql);
$naikStmt->execute($naikParams);
$naikRows = $naikStmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung kelas baru per baris + daftar id yang valid (bisa dicentang)
$naikPreview = [];
$naikValidIds = [];
foreach ($naikRows as $r) {
    [$ok, $next] = nextKelas((string) $r['kelas']);
    $r['next_ok'] = $ok;
    $r['next_kelas'] = $next;
    $naikPreview[] = $r;
    if ($ok) {
        $naikValidIds[] = (int) $r['id'];
    }
}

// Tab 2 — kandidat arsip (semua anak 'Lulus', aktif, bukan lanjut_tahsin)
$kandidatArsip = $pdo->query("
    SELECT w.id, w.nama_bapak, w.kategori, w.no_hp,
        COUNT(sd.id) AS total_anak,
        SUM(sd.kelas = 'Lulus') AS lulus_count,
        GROUP_CONCAT(CONCAT(sd.nama_anak, ' (', sd.kelas, ')') SEPARATOR ', ') AS daftar_anak,
        (SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON h.id = hm.halaqoh_id WHERE hm.wali_santri_id = w.id LIMIT 1) AS nama_halaqoh
    FROM wali_santri w
    LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
    WHERE w.status_aktif = 1 AND w.lanjut_tahsin = 0
    GROUP BY w.id
    HAVING total_anak > 0 AND lulus_count = total_anak
    ORDER BY w.nama_bapak
")->fetchAll(PDO::FETCH_ASSOC);

// Tab 2 — wali ditandai lanjut tahsin (aktif)
$lanjutList = $pdo->query("
    SELECT w.id, w.nama_bapak, w.kategori,
        GROUP_CONCAT(CONCAT(sd.nama_anak, ' (', sd.kelas, ')') SEPARATOR ', ') AS daftar_anak
    FROM wali_santri w
    LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
    WHERE w.status_aktif = 1 AND w.lanjut_tahsin = 1
    GROUP BY w.id
    ORDER BY w.nama_bapak
")->fetchAll(PDO::FETCH_ASSOC);

// Tab 2 — wali terarsip (status_aktif = 0)
$terarsip = $pdo->query("
    SELECT w.id, w.nama_bapak, w.kategori,
        GROUP_CONCAT(CONCAT(sd.nama_anak, ' (', sd.kelas, ')') SEPARATOR ', ') AS daftar_anak,
        (SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON h.id = hm.halaqoh_id WHERE hm.wali_santri_id = w.id LIMIT 1) AS nama_halaqoh
    FROM wali_santri w
    LEFT JOIN santri_detail sd ON sd.wali_santri_id = w.id
    WHERE w.status_aktif = 0
    GROUP BY w.id
    ORDER BY w.nama_bapak
")->fetchAll(PDO::FETCH_ASSOC);

// JSON untuk Alpine
$naikValidIdsJson = json_encode($naikValidIds);
$kandidatIdsJson = json_encode(array_map(fn($k) => (int) $k['id'], $kandidatArsip));

// Sekarang boleh output HTML
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div x-data="{
    tab: '<?php echo $active_tab; ?>',
    selectedNaik: [],
    selectedArsip: [],
    showConfirmNaik: false,
    showConfirmArsip: false,
    showConfirmLulus: false,
    toggleAllNaik(ev) {
        this.selectedNaik = ev.target.checked ? [...JSON.parse(document.getElementById('naik-valid-ids').textContent)] : [];
    },
    toggleAllArsip(ev) {
        this.selectedArsip = ev.target.checked ? [...JSON.parse(document.getElementById('kandidat-ids').textContent)] : [];
    }
}">
    <script id="naik-valid-ids" type="application/json"><?php echo $naikValidIdsJson; ?></script>
    <script id="kandidat-ids" type="application/json"><?php echo $kandidatIdsJson; ?></script>

    <div class="mb-6">
        <a href="peserta.php" class="inline-flex items-center text-slate-400 hover:text-blue-600 transition mb-3">
            <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Kembali ke Data Peserta
        </a>
        <h2 class="text-2xl font-bold text-slate-800">Naik Kelas & Arsip Lulusan</h2>
        <p class="text-slate-500 text-sm mt-1">Tahun ajaran baru: naikkan semua santri satu mustawa, dan kelola wali
            yang anaknya telah lulus Mustawa 6.</p>
    </div>

    <?php if ($message): ?>
        <div class="bg-emerald-50 text-emerald-700 p-4 rounded-xl mb-6 flex items-center border border-emerald-100 font-semibold">
            <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 border border-red-100 font-semibold">
            ⚠️ <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Tab switcher -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm mb-6 p-1.5 inline-flex gap-1">
        <button @click="tab='naik'" :class="tab==='naik' ? 'bg-blue-600 text-white shadow' : 'text-slate-500 hover:bg-slate-100'"
            class="px-5 py-2 rounded-xl font-semibold text-sm transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
            </svg>
            Bulk Naik Kelas
        </button>
        <button @click="tab='arsip'" :class="tab==='arsip' ? 'bg-blue-600 text-white shadow' : 'text-slate-500 hover:bg-slate-100'"
            class="px-5 py-2 rounded-xl font-semibold text-sm transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
            </svg>
            Arsip Lulusan
        </button>
    </div>

    <!-- =========================== TAB 1: BULK NAIK KELAS =========================== -->
    <div x-show="tab==='naik'" x-cloak>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="tab" value="naik">
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Filter Kelas</label>
                    <select name="kelas" class="bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">Semua Kelas</option>
                        <?php foreach ($daftar_kelas as $k): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $kelas_filter === $k ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($k); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Filter Halaqoh</label>
                    <select name="halaqoh" class="bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">Semua Halaqoh</option>
                        <?php foreach ($daftar_halaqoh as $hq): ?>
                            <option value="<?php echo $hq['id']; ?>" <?php echo $halaqoh_filter == $hq['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hq['nama_halaqoh']); ?> (<?php echo htmlspecialchars($hq['nama_ustadz']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-xl font-semibold text-sm hover:bg-blue-700 transition">Terapkan</button>
                <a href="naik-kelas.php?tab=naik" class="text-slate-500 hover:text-slate-700 px-4 py-2 rounded-xl font-semibold text-sm hover:bg-slate-100 transition">Reset</a>
                <a href="naik-kelas.php?export=backup" class="ml-auto bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl font-semibold text-sm hover:bg-emerald-200 transition flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Export Backup CSV
                </a>
            </form>
        </div>

        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-4 mb-6 text-sm text-amber-800">
            <strong>⚠️ Sebelum eksekusi:</strong> disarankan <a href="naik-kelas.php?export=backup" class="underline font-semibold">export backup CSV</a> terlebih dahulu.
            Mustawa 6 akan menjadi <strong>"Lulus"</strong>. Nilai kelas yang formatnya tidak dikenal akan di-skip (tidak ikut dinaikkan).
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <div>
                    <h3 class="font-bold text-slate-800">Pratinjau Kenaikan Kelas</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        <?php echo count($naikPreview); ?> santri ditampilkan,
                        <span class="text-blue-600 font-semibold"><?php echo count($naikValidIds); ?></span> dapat dinaikkan.
                    </p>
                </div>
                <button @click="showConfirmNaik = true" :disabled="selectedNaik.length === 0"
                    :class="selectedNaik.length === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-700'"
                    class="bg-blue-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm transition shadow-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
                    </svg>
                    Naikkan Kelas (<span x-text="selectedNaik.length"></span> terpilih)
                </button>
                <button @click="showConfirmLulus = true" :disabled="selectedNaik.length === 0"
                    :class="selectedNaik.length === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-emerald-700'"
                    class="bg-emerald-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm transition shadow-sm flex items-center gap-2"
                    title="Tandai santri terpilih sebagai Lulus (manual), tanpa menaikkan kelas. Pakai ini untuk memilih siapa yang benar-benar lulus.">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Luluskan Terpilih (<span x-text="selectedNaik.length"></span>)
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                        <tr>
                            <th class="px-4 py-3 w-10">
                                <input type="checkbox" @change="toggleAllNaik($event)"
                                    :checked="selectedNaik.length === <?php echo count($naikValidIds); ?> && <?php echo count($naikValidIds); ?> > 0"
                                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            </th>
                            <th class="px-4 py-3">Nama Wali (Bapak)</th>
                            <th class="px-4 py-3">Nama Anak</th>
                            <th class="px-4 py-3">Halaqoh</th>
                            <th class="px-4 py-3">Kelas Saat Ini</th>
                            <th class="px-4 py-3">→ Kelas Baru</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($naikPreview)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400 italic">Tidak ada santri yang perlu dinaikkan kelas (semua sudah "Lulus" atau tidak ada data Mustawa).</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($naikPreview as $r): ?>
                            <tr class="<?php echo $r['next_ok'] ? 'hover:bg-slate-50' : 'bg-slate-50/60'; ?> transition">
                                <td class="px-4 py-3 align-middle">
                                    <?php if ($r['next_ok']): ?>
                                        <input type="checkbox" value="<?php echo $r['id']; ?>" x-model="selectedNaik"
                                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                    <?php else: ?>
                                        <span class="text-slate-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 font-semibold text-slate-800 align-middle"><?php echo htmlspecialchars($r['nama_bapak']); ?></td>
                                <td class="px-4 py-3 text-sm text-slate-600 align-middle"><?php echo htmlspecialchars($r['nama_anak']); ?></td>
                                <td class="px-4 py-3 text-sm text-slate-500 align-middle"><?php echo htmlspecialchars($r['nama_halaqoh'] ?: '-'); ?></td>
                                <td class="px-4 py-3 text-sm text-slate-700 align-middle font-medium"><?php echo htmlspecialchars($r['kelas']); ?></td>
                                <td class="px-4 py-3 text-sm align-middle">
                                    <?php if ($r['next_ok']): ?>
                                        <span class="px-2.5 py-1 rounded-lg bg-blue-100 text-blue-700 font-bold text-xs"><?php echo htmlspecialchars($r['next_kelas']); ?></span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-lg bg-slate-200 text-slate-500 font-semibold text-xs" title="Format kelas tidak dikenali — perbaiki via menu Data Peserta">Tidak dikenali</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal konfirmasi naik kelas -->
        <div x-show="showConfirmNaik" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
            <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showConfirmNaik = false">
                <h3 class="text-xl font-bold text-slate-800 mb-3">Konfirmasi Kenaikan Kelas</h3>
                <p class="text-sm text-slate-600 mb-2">Anda akan menaikkan kelas <strong x-text="selectedNaik.length"></strong> santri terpilih.</p>
                <ul class="text-xs text-slate-500 space-y-1 mb-5 bg-amber-50 border border-amber-100 rounded-xl p-4">
                    <li>• Mustawa 1→2, 2→3, …, 5→6</li>
                    <li>• Mustawa 6 → <strong>"Lulus"</strong></li>
                    <li>• Tindakan ini sulit dibalik. Pastikan backup CSV sudah diunduh.</li>
                </ul>
                <form method="POST" action="" class="flex gap-3">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_naik">
                    <template x-for="id in selectedNaik" :key="id">
                        <input type="hidden" name="santri_ids[]" :value="id">
                    </template>
                    <button type="button" @click="showConfirmNaik = false"
                        class="flex-1 px-4 py-3 rounded-2xl border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 transition">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-3 rounded-2xl bg-blue-600 text-white font-bold hover:bg-blue-700 transition shadow-lg shadow-blue-200">Ya, Naikkan</button>
                </form>
            </div>
        </div>

        <!-- Modal konfirmasi luluskan manual -->
        <div x-show="showConfirmLulus" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
            <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showConfirmLulus = false">
                <h3 class="text-xl font-bold text-slate-800 mb-3">Konfirmasi Luluskan Manual</h3>
                <p class="text-sm text-slate-600 mb-2">Anda akan menandai <strong x-text="selectedNaik.length"></strong> santri terpilih sebagai <strong>"Lulus"</strong>.</p>
                <ul class="text-xs text-slate-500 space-y-1 mb-5 bg-emerald-50 border border-emerald-100 rounded-xl p-4">
                    <li>• Kelas santri terpilih diubah jadi <strong>"Lulus"</strong> langsung (tidak dinaikkan).</li>
                    <li>• Pakai ini untuk memilih manual siapa yang benar-benar lulus (mis. dari campuran Mustawa 6).</li>
                    <li>• Yang tidak dicentang tetap di kelasnya saat ini.</li>
                </ul>
                <form method="POST" action="" class="flex gap-3">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_lulus">
                    <template x-for="id in selectedNaik" :key="id">
                        <input type="hidden" name="santri_ids[]" :value="id">
                    </template>
                    <button type="button" @click="showConfirmLulus = false"
                        class="flex-1 px-4 py-3 rounded-2xl border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 transition">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-3 rounded-2xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 transition shadow-lg shadow-emerald-200">Ya, Luluskan</button>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================== TAB 2: ARSIP LULUSAN =========================== -->
    <div x-show="tab==='arsip'" x-cloak>
        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 mb-6 text-sm text-blue-800">
            Wali yang <strong>masih punya anak aktif</strong> (ada anak yang belum "Lulus") <strong>tidak muncul</strong> di daftar kandidat arsip —
            termasuk kasus kakak beradik (kakak lulus, adik masih sekolah). Wali yang diarsip tetap tersimpan di database beserta riwayat presensinya,
            hanya tidak tampil di form presensi ustadz. Bisa diaktifkan kembali kapan saja.
        </div>

        <!-- A. Kandidat arsip -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden mb-6">
            <div class="flex items-center justify-between p-5 border-b border-slate-100">
                <div>
                    <h3 class="font-bold text-slate-800">Kandidat Arsip (Semua Anak Lulus)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Centang wali yang anaknya sudah lulus DAN berhenti tahsin. Untuk yang masih mau tahsin, klik "Lanjut Tahsin".</p>
                </div>
                <button @click="showConfirmArsip = true" :disabled="selectedArsip.length === 0"
                    :class="selectedArsip.length === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-red-600'"
                    class="bg-red-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm transition shadow-sm flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
                    </svg>
                    Arsipkan (<span x-text="selectedArsip.length"></span> terpilih)
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                        <tr>
                            <th class="px-4 py-3 w-10">
                                <input type="checkbox" @change="toggleAllArsip($event)"
                                    :checked="selectedArsip.length === <?php echo count($kandidatArsip); ?> && <?php echo count($kandidatArsip); ?> > 0"
                                    class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            </th>
                            <th class="px-4 py-3">Nama Wali (Bapak)</th>
                            <th class="px-4 py-3">Kategori</th>
                            <th class="px-4 py-3">Anak (semua Lulus)</th>
                            <th class="px-4 py-3">Halaqoh</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($kandidatArsip)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400 italic">Tidak ada kandidat arsip. Semua wali aktif masih punya anak yang belum lulus, atau sudah ditandai lanjut tahsin.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($kandidatArsip as $k): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 align-middle">
                                    <input type="checkbox" value="<?php echo $k['id']; ?>" x-model="selectedArsip"
                                        class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                                </td>
                                <td class="px-4 py-3 font-semibold text-slate-800 align-middle"><?php echo htmlspecialchars($k['nama_bapak']); ?></td>
                                <td class="px-4 py-3 align-middle">
                                    <span class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $k['kategori'] === 'reguler' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'; ?>">
                                        <?php echo htmlspecialchars(str_replace('_', ' ', $k['kategori'])); ?>
                                    </span>
                                    <?php if ($k['kategori'] === 'tahsin_luar'): ?>
                                        <div class="mt-1 text-[9px] text-amber-600 italic">Mungkin mau lanjut</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600 align-middle max-w-xs"><?php echo htmlspecialchars($k['daftar_anak'] ?: '-'); ?></td>
                                <td class="px-4 py-3 text-sm text-slate-500 align-middle"><?php echo htmlspecialchars($k['nama_halaqoh'] ?: '-'); ?></td>
                                <td class="px-4 py-3 text-right align-middle">
                                    <form method="POST" action="" class="inline-flex">
                                        <?php csrfField(); ?>
                                        <input type="hidden" name="action" value="set_lanjut_tahsin">
                                        <input type="hidden" name="wali_id" value="<?php echo $k['id']; ?>">
                                        <input type="hidden" name="value" value="1">
                                        <button type="submit" class="text-emerald-700 hover:bg-emerald-600 hover:text-white font-bold text-[10px] uppercase transition bg-emerald-50 px-3 py-1.5 rounded-xl border border-emerald-100"
                                            title="Bapak tetap mau ikut tahsin — kecualikan dari arsip">
                                            Lanjut Tahsin
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- B. Wali ditandai lanjut tahsin -->
        <?php if (!empty($lanjutList)): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-emerald-100 overflow-hidden mb-6">
                <div class="p-5 border-b border-emerald-100 bg-emerald-50/40">
                    <h3 class="font-bold text-emerald-800 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Ditandai Lanjut Tahsin
                    </h3>
                    <p class="text-xs text-emerald-700 mt-0.5">Wali ini TIDAK akan diarsipkan saat bulk arsip dijalankan.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Nama Wali (Bapak)</th>
                                <th class="px-4 py-3">Anak</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($lanjutList as $l): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-3 font-semibold text-slate-800"><?php echo htmlspecialchars($l['nama_bapak']); ?></td>
                                    <td class="px-4 py-3 text-sm text-slate-600 max-w-xs"><?php echo htmlspecialchars($l['daftar_anak'] ?: '-'); ?></td>
                                    <td class="px-4 py-3 text-right">
                                        <form method="POST" action="" class="inline-flex">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="set_lanjut_tahsin">
                                            <input type="hidden" name="wali_id" value="<?php echo $l['id']; ?>">
                                            <input type="hidden" name="value" value="0">
                                            <button type="submit" class="text-slate-600 hover:bg-slate-200 font-bold text-[10px] uppercase transition bg-slate-100 px-3 py-1.5 rounded-xl border border-slate-200">
                                                Batalkan Tanda
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- C. Wali terarsip -->
        <?php if (!empty($terarsip)): ?>
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-5 border-b border-slate-100">
                    <h3 class="font-bold text-slate-800 flex items-center gap-2">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
                        </svg>
                        Wali Terarsip (<?php echo count($terarsip); ?>)
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">Tidak tampil di form presensi. Riwayat presensi tetap utuh.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase text-xs font-bold tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Nama Wali (Bapak)</th>
                                <th class="px-4 py-3">Kategori</th>
                                <th class="px-4 py-3">Anak</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($terarsip as $t): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-3 font-semibold text-slate-700 align-middle"><?php echo htmlspecialchars($t['nama_bapak']); ?></td>
                                    <td class="px-4 py-3 align-middle">
                                        <span class="px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-500">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $t['kategori'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-500 align-middle max-w-xs"><?php echo htmlspecialchars($t['daftar_anak'] ?: '-'); ?></td>
                                    <td class="px-4 py-3 text-right align-middle">
                                        <form method="POST" action="" onsubmit="return confirm('Aktifkan kembali wali ini? Ia akan muncul kembali di form presensi ustadz.')">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="action" value="unarchive">
                                            <input type="hidden" name="wali_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="text-blue-600 hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase transition bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-100">
                                                Aktifkan Kembali
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal konfirmasi arsip -->
        <div x-show="showConfirmArsip" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
            <div class="bg-white rounded-3xl w-full max-w-md p-8 shadow-2xl" @click.away="showConfirmArsip = false">
                <h3 class="text-xl font-bold text-slate-800 mb-3">Konfirmasi Arsip</h3>
                <p class="text-sm text-slate-600 mb-4">Anda akan mengarsipkan <strong x-text="selectedArsip.length"></strong> wali terpilih.</p>
                <p class="text-xs text-slate-500 mb-5 bg-amber-50 border border-amber-100 rounded-xl p-4">
                    Wali arsip tidak tampil di form presensi ustadz dan progress, namun data + riwayat presensi tetap utuh.
                    Wali yang masih punya anak aktif akan otomatis ditolak sistem. Bisa diaktifkan kembali kapan saja.
                </p>
                <form method="POST" action="" class="flex gap-3">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_archive">
                    <template x-for="id in selectedArsip" :key="id">
                        <input type="hidden" name="wali_ids[]" :value="id">
                    </template>
                    <button type="button" @click="showConfirmArsip = false"
                        class="flex-1 px-4 py-3 rounded-2xl border border-slate-200 font-bold text-slate-600 hover:bg-slate-50 transition">Batal</button>
                    <button type="submit"
                        class="flex-1 px-4 py-3 rounded-2xl bg-red-600 text-white font-bold hover:bg-red-700 transition shadow-lg shadow-red-200">Ya, Arsipkan</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>