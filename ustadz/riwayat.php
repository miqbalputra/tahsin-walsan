<?php
$pageTitle = 'Riwayat Presensi';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../config/database.php';
require_once '../includes/auth_helper.php';

checkRole(['ustadz']);

$ustadz_id = $_SESSION['user_id'];

$message = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $presensi_id = $_POST['id'] ?? null;
    if ($presensi_id) {
        // Double check ownership before delete
        $stmt = $pdo->prepare("DELETE p FROM presensi p 
                              JOIN halaqoh h ON p.halaqoh_id = h.id 
                              WHERE p.id = ? AND h.ustadz_id = ?");
        $stmt->execute([$presensi_id, $ustadz_id]);
        $message = "Data presensi berhasil dihapus!";
    }
}

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$halaqoh_id = $_GET['halaqoh_id'] ?? '';

// Build Query
$sql = "SELECT p.*, w.nama_bapak, w.no_hp, h.nama_halaqoh 
        FROM presensi p 
        JOIN wali_santri w ON p.wali_santri_id = w.id 
        JOIN halaqoh h ON p.halaqoh_id = h.id 
        WHERE h.ustadz_id = :u_id AND p.tanggal BETWEEN :start AND :end";

$params = [':u_id' => $ustadz_id, ':start' => $start_date, ':end' => $end_date];

if (!empty($halaqoh_id)) {
    $sql .= " AND p.halaqoh_id = :h_id";
    $params[':h_id'] = $halaqoh_id;
}

$sql .= " ORDER BY p.tanggal DESC, h.nama_halaqoh, w.nama_bapak";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Fetch Halaqoh for Filter (Only owned by this ustadz)
$stmtH = $pdo->prepare("SELECT id, nama_halaqoh FROM halaqoh WHERE ustadz_id = ? ORDER BY nama_halaqoh");
$stmtH->execute([$ustadz_id]);
$halaqohs = $stmtH->fetchAll();
?>

<div class="mb-8">
    <h2 class="text-2xl font-bold text-slate-800">Riwayat Presensi</h2>
    <p class="text-slate-500">Lihat kembali data presensi yang telah Anda input.</p>
</div>

<!-- Filters -->
<div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Dari Tanggal</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>"
                class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Sampai Tanggal</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>"
                class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Halaqoh</label>
            <select name="halaqoh_id"
                class="w-full px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition text-sm">
                <option value="">Semua Halaqoh</option>
                <?php foreach ($halaqohs as $h): ?>
                    <option value="<?php echo $h['id']; ?>" <?php echo $halaqoh_id == $h['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($h['nama_halaqoh']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit"
                class="w-full bg-blue-600 text-white font-bold py-2.5 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-100">
                Filter Data
            </button>
        </div>
    </form>
</div>

<?php if ($message): ?>
    <div class="bg-emerald-50 text-emerald-600 p-4 rounded-xl mb-6 flex items-center border border-emerald-100 italic">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Data Table -->
<div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead
                class="bg-slate-50 border-b border-slate-100 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                <tr>
                    <th class="px-6 py-4">Tanggal</th>
                    <th class="px-6 py-4">Nama</th>
                    <th class="px-6 py-4 text-center">Status</th>
                    <th class="px-6 py-4">Pencapaian Materi</th>
                    <th class="px-6 py-4 text-center">Hasil</th>
                    <th class="px-6 py-4 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 text-sm">
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-slate-400 italic align-middle">Data riwayat tidak
                            ditemukan.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($results as $row): ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-6 py-4 text-nowrap text-slate-500 font-medium align-middle">
                            <?php echo date('d/m/Y', strtotime($row['tanggal'])); ?>
                        </td>
                        <td class="px-6 py-4 align-middle">
                            <div class="font-bold text-slate-700">
                                <?php echo htmlspecialchars($row['nama_bapak']); ?>
                            </div>
                            <div class="text-[10px] text-slate-400">
                                <?php echo htmlspecialchars($row['nama_halaqoh']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center align-middle">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-[10px] font-bold
                                <?php
                                echo match ($row['status']) {
                                    'H' => 'bg-emerald-100 text-emerald-700',
                                    'S' => 'bg-blue-100 text-blue-700',
                                    'I' => 'bg-amber-100 text-amber-700',
                                    'A' => 'bg-red-100 text-red-700',
                                };
                                ?>">
                                <?php echo $row['status']; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-slate-600 align-middle">
                            <?php if ($row['status'] === 'H'): ?>
                                <span class="font-bold text-blue-600">
                                    <?php echo $row['jenis_materi']; ?>
                                </span>:
                                <span class="text-slate-700">
                                    <?php echo ($row['jenis_materi'] === 'Iqro') ? "Jilid " . $row['jilid'] : htmlspecialchars($row['nama_surat']); ?>
                                </span>
                                <span
                                    class="text-[10px] bg-slate-100 text-slate-500 font-bold px-1.5 py-0.5 rounded-md ml-1 italic">
                                    <?php echo ($row['jenis_materi'] === 'Al Quran' ? 'Ayat ' : 'Hal ') . $row['halaman']; ?>
                                </span>
                            <?php elseif ($row['alasan']): ?>
                                <span
                                    class="text-xs italic text-slate-400 font-medium">(<?php echo htmlspecialchars($row['alasan']); ?>)</span>
                            <?php else: ?>
                                <span class="text-slate-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center align-middle">
                            <?php if ($row['hasil_talaqqi']): ?>
                                <span
                                    class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider
                                    <?php echo $row['hasil_talaqqi'] === 'Lulus' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-red-50 text-red-600 border border-red-200'; ?>">
                                    <?php echo $row['hasil_talaqqi']; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right align-middle">
                            <div class="flex justify-end items-center gap-2">
                                <?php if ($row['status'] === 'H' && !empty($row['no_hp'])):
                                    $wa_msg = "Alhamdulillah Pak " . $row['nama_bapak'] . ", hari ini telah hadir Tahsin. \n\n*Detail Pencapaian*: \n- Materi: " . $row['jenis_materi'] . " " . ($row['jenis_materi'] === 'Iqro' ? 'Jilid ' . $row['jilid'] : $row['nama_surat']) . " \n- " . ($row['jenis_materi'] === 'Al Quran' ? 'Ayat' : 'Halaman') . ": " . $row['halaman'] . " \n- Hasil: *" . ($row['hasil_talaqqi'] ?? '-') . "* \n\nSemangat terus Pak, baarokallaahu fiikum.";
                                    $wa_phone = preg_replace('/^0/', '62', $row['no_hp']);
                                    $wa_url = "https://wa.me/" . $wa_phone . "?text=" . urlencode($wa_msg);
                                    ?>
                                    <a href="<?php echo $wa_url; ?>" target="_blank"
                                        class="text-emerald-600 hover:bg-emerald-600 hover:text-white font-bold text-[10px] uppercase transition bg-emerald-50 px-3 py-1.5 rounded-xl border border-emerald-100 flex items-center gap-1.5 shadow-sm">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                                            <path
                                                d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.246 2.248 3.484 5.232 3.484 8.412-.003 6.557-5.338 11.892-11.893 11.892-1.997-.001-3.951-.5-5.688-1.448l-6.309 1.656zm6.224-3.82c1.516.903 3.129 1.417 4.829 1.419 5.231 0 9.487-4.258 9.49-9.487.001-2.537-.987-4.922-2.783-6.72s-4.183-2.784-6.72-2.784c-5.23 0-9.486 4.258-9.489 9.488-.002 1.669.444 3.294 1.294 4.704l-1.019 3.719 3.842-1.007c-.015.009-.3.018-.444.088zm10.375-7.398c-.287-.144-1.7-.84-1.967-.936-.267-.096-.462-.144-.657.144-.195.288-.754.936-.925 1.128-.17.192-.34.216-.627.072-.287-.144-1.21-.447-2.306-1.423-.852-.759-1.428-1.7-1.595-1.987-.167-.288-.018-.443.126-.586.129-.129.287-.336.431-.504.143-.168.191-.288.287-.48.096-.192.048-.36-.024-.504-.072-.144-.657-1.585-.899-2.16-.236-.56-.476-.484-.657-.492-.169-.007-.363-.008-.557-.008-.195 0-.512.072-.78.36-.268.288-1.025 1.008-1.025 2.459 0 1.451 1.057 2.855 1.204 3.048.147.192 2.081 3.178 5.04 4.461.703.305 1.252.487 1.68.623.707.225 1.35.193 1.859.117.568-.084 1.7-.696 1.942-1.368.243-.672.243-1.248.17-1.368-.073-.12-.267-.216-.554-.36z" />
                                        </svg>
                                        Kirim WA
                                    </a>
                                <?php endif; ?>
                                <a href="form-presensi.php?id=<?php echo $row['halaqoh_id']; ?>&tanggal=<?php echo $row['tanggal']; ?>"
                                    class="text-blue-600 hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase transition bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-100">
                                    Edit
                                </a>
                                <form method="POST" onsubmit="return confirm('Hapus data presensi ini?')"
                                    class="inline-flex">
                                    <?php csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit"
                                        class="text-red-600 hover:bg-red-600 hover:text-white font-bold text-[10px] uppercase transition bg-red-50 px-3 py-1.5 rounded-xl border border-red-100">
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>