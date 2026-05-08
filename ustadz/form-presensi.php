<?php
$pageTitle = 'Form Input Presensi';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../config/database.php';
require_once '../includes/auth_helper.php';

checkRole(['ustadz']);

$halaqoh_id = $_GET['id'] ?? null;
$ustadz_id = $_SESSION['user_id'];

// Verify halaqoh ownership
$stmt = $pdo->prepare("SELECT * FROM halaqoh WHERE id = ? AND ustadz_id = ?");
$stmt->execute([$halaqoh_id, $ustadz_id]);
$halaqoh = $stmt->fetch();

if (!$halaqoh) {
    header("Location: input-presensi.php");
    exit();
}

$message = '';
$error = '';

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');

// Fetch existing attendance for this date and halaqoh to pre-fill
$existing_attendance = [];
$stmtExist = $pdo->prepare("SELECT * FROM presensi WHERE halaqoh_id = ? AND tanggal = ?");
$stmtExist->execute([$halaqoh_id, $tanggal]);
while ($row = $stmtExist->fetch()) {
    $existing_attendance[$row['wali_santri_id']] = $row;
}

// Handle Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $presensi_data = $_POST['presensi'] ?? [];

    $pdo->beginTransaction();
    try {
        // Prepare statement for check
        $stmtCheck = $pdo->prepare("SELECT id FROM presensi WHERE halaqoh_id = ? AND wali_santri_id = ? AND tanggal = ?");

        $stmtInsert = $pdo->prepare("INSERT INTO presensi 
            (halaqoh_id, wali_santri_id, tanggal, status, alasan, jenis_materi, jilid, nama_surat, halaman, hasil_talaqqi) 
            VALUES (:h_id, :w_id, :tgl, :sts, :als, :jm, :jld, :srt, :hal, :hsl)");

        $stmtUpdate = $pdo->prepare("UPDATE presensi SET 
            status = :sts, alasan = :als, jenis_materi = :jm, jilid = :jld, nama_surat = :srt, halaman = :hal, hasil_talaqqi = :hsl 
            WHERE id = :id");

        foreach ($presensi_data as $wali_id => $data) {
            $status = $data['status'] ?? 'A'; // Default to Alpha if not selected
            $alasan = ($status === 'S' || $status === 'I') ? ($data['alasan'] ?? '') : null;

            $jenis_materi = null;
            $jilid = null;
            $nama_surat = null;
            $halaman = null;
            $hasil_talaqqi = null;

            if ($status === 'H') {
                $jenis_materi = $data['jenis_materi'] ?? null;
                $jilid = ($jenis_materi === 'Iqro') ? ($data['jilid'] ?? null) : null;
                $nama_surat = ($jenis_materi === 'Al Quran') ? ($data['nama_surat'] ?? null) : null;
                $halaman = $data['halaman'] ?? null;
                $hasil_talaqqi = $data['hasil_talaqqi'] ?? null;

                // Validasi
                if (empty($halaman) || empty($hasil_talaqqi)) {
                    throw new Exception("Data pencapaian materi (Halaman/Ayat & Hasil) wajib diisi untuk yang Hadir.");
                }
                if ($jenis_materi === 'Iqro' && empty($jilid)) {
                    throw new Exception("Jilid Iqro wajib dipilih.");
                }
                if ($jenis_materi === 'Al Quran' && empty($nama_surat)) {
                    throw new Exception("Nama Surat wajib diisi.");
                }
            } elseif ($status === 'S' || $status === 'I') {
                if (empty($alasan)) {
                    throw new Exception("Alasan/Keterangan wajib diisi jika status Sakit atau Izin.");
                }
            }

            // Check if record exists
            $stmtCheck->execute([$halaqoh_id, $wali_id, $tanggal]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                $stmtUpdate->execute([
                    ':sts' => $status,
                    ':als' => $alasan,
                    ':jm' => $jenis_materi,
                    ':jld' => $jilid,
                    ':srt' => $nama_surat,
                    ':hal' => $halaman,
                    ':hsl' => $hasil_talaqqi,
                    ':id' => $existing['id']
                ]);
            } else {
                $stmtInsert->execute([
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
            }
        }

        $pdo->commit();
        $message = "Presensi berhasil disimpan!";

        // Refresh existing data after save
        $stmtExist->execute([$halaqoh_id, $tanggal]);
        $existing_attendance = [];
        while ($row = $stmtExist->fetch()) {
            $existing_attendance[$row['wali_santri_id']] = $row;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal menyimpan presensi: " . $e->getMessage();
    }
}

// Fetch members of this halaqoh (Only Reguler & Active)
$stmt = $pdo->prepare("SELECT w.id, w.nama_bapak, w.no_hp,
                              GROUP_CONCAT(CONCAT(sd.nama_anak, ' [', sd.kelas, ']') SEPARATOR '<br>') as info_anak
                      FROM wali_santri w 
                      JOIN halaqoh_members hm ON w.id = hm.wali_santri_id 
                      LEFT JOIN santri_detail sd ON w.id = sd.wali_santri_id
                      WHERE hm.halaqoh_id = ? 
                      AND (w.kategori = 'reguler' OR w.kategori = 'askar') 
                      AND w.status_aktif = 1 
                      GROUP BY w.id
                      ORDER BY w.nama_bapak");
$stmt->execute([$halaqoh_id]);
$members = $stmt->fetchAll();

// Check if today is a holiday
$stmtHoliday = $pdo->prepare("SELECT keterangan FROM holidays WHERE tanggal = ?");
$stmtHoliday->execute([$tanggal]);
$holiday = $stmtHoliday->fetch();
?>

<div x-data="{ 
    confirmModal: {
        show: false,
        title: '',
        message: '',
        callback: null
    },
    openConfirm(title, message, callback) {
        this.confirmModal.title = title;
        this.confirmModal.message = message;
        this.confirmModal.callback = callback;
        this.confirmModal.show = true;
    }
}">
    <!-- Custom Interactive Modal -->
    <template x-teleport="body">
        <div x-show="confirmModal.show" 
             class="fixed inset-0 z-[9999] flex items-center justify-center p-4 sm:p-6"
             x-cloak>
            <!-- Backdrop with Blur -->
            <div x-show="confirmModal.show" 
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="confirmModal.show = false"
                 class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>

            <!-- Modal Content -->
            <div x-show="confirmModal.show"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95 translate-y-4"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                 x-transition:leave-end="opacity-0 scale-95 translate-y-4"
                 class="relative bg-white w-full max-w-sm rounded-[32px] shadow-2xl overflow-hidden border border-slate-100">
                
                <div class="p-8 text-center">
                    <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6 ring-8 ring-red-50/50">
                        <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </div>

                    <h3 class="text-xl font-black text-slate-800 mb-2 uppercase tracking-tight" x-text="confirmModal.title"></h3>
                    <p class="text-slate-500 text-sm font-medium leading-relaxed" x-text="confirmModal.message"></p>
                </div>

                <div class="flex p-4 gap-3 bg-slate-50/50">
                    <button @click="confirmModal.show = false"
                            class="flex-1 px-6 py-4 rounded-2xl bg-white border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition-all uppercase tracking-widest">
                        Batal
                    </button>
                    <button @click="confirmModal.callback(); confirmModal.show = false"
                            class="flex-1 px-6 py-4 rounded-2xl bg-red-500 text-white font-bold text-sm hover:bg-red-600 shadow-lg shadow-red-500/30 transition-all uppercase tracking-widest">
                        Ya, Hapus
                    </button>
                </div>
            </div>
        </div>
    </template>

    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="input-presensi.php"
                class="bg-white p-2 rounded-xl border border-slate-100 text-slate-400 hover:text-blue-600 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </a>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">
                    <?php echo htmlspecialchars($halaqoh['nama_halaqoh']); ?>
                </h2>
                <p class="text-slate-500 text-sm">Input kehadiran wali santri</p>
            </div>
        </div>
    </div>

<?php if ($message): ?>
    <div class="bg-emerald-50 text-emerald-600 p-6 rounded-2xl mb-6 shadow-sm border border-emerald-100 flex items-center">
        <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
        <span class="font-bold">
            <?php echo $message; ?>
        </span>
        <a href="dashboard.php" class="ml-auto bg-emerald-600 text-white px-4 py-2 rounded-xl text-sm font-bold">Kembali ke
            Beranda</a>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="bg-red-50 text-red-600 p-6 rounded-2xl mb-6 border border-red-100 font-medium">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<?php if ($holiday): ?>
    <div class="bg-amber-100 border-2 border-amber-200 p-8 rounded-[2.5rem] mb-8 text-center relative overflow-hidden shadow-xl shadow-amber-50">
        <div class="relative z-10">
            <div class="w-16 h-16 bg-white rounded-full flex items-center justify-center mx-auto mb-4 shadow-sm">
                <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-2xl font-black text-amber-800 uppercase tracking-tight">HARI LIBUR KAJIAN</h3>
            <p class="text-amber-700 font-bold mt-1"><?php echo htmlspecialchars($holiday['keterangan'] ?: 'Tidak ada keterangan'); ?></p>
            <p class="text-amber-600 text-xs mt-4 max-w-sm mx-auto leading-relaxed italic">Presensi pada hari libur tidak disarankan untuk diinput sebagai 'Alpha' agar tidak merusak statistik kehadiran santri.</p>
        </div>
        <svg class="absolute -right-10 -bottom-10 w-48 h-48 text-amber-200/50" fill="currentColor" viewBox="0 0 24 24"><path d="M19 19H5V8h14v11zM7 10v7h10v-7H7zm15-3h-3V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2H7V5a2 2 0 0 0-2-2H1a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h20a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zM9 5h6v2H9V5z"/></svg>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <?php csrfField(); ?>
    <!-- Tanggal Picker -->
    <div
        class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm mb-6 flex flex-col md:flex-row md:items-center gap-4">
        <label class="font-bold text-slate-700">Tanggal Pertemuan:</label>
        <input type="date" name="tanggal" value="<?php echo $tanggal; ?>"
            onchange="window.location.href='form-presensi.php?id=<?php echo $halaqoh_id; ?>&tanggal=' + this.value"
            class="px-4 py-2 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition font-semibold text-slate-700">
        <p class="text-xs text-slate-400 italic md:ml-4">*Pilih tanggal untuk melihat/mengedit data yang sudah ada.</p>
    </div>

    <!-- Members List -->
    <div class="space-y-4 pb-32">
        <?php foreach ($members as $index => $m):
            $prev = $existing_attendance[$m['id']] ?? null;
            // Format phone for WA link
            $wa_phone = preg_replace('/[^0-9]/', '', $m['no_hp']);
            if (strpos($wa_phone, '0') === 0) {
                $wa_phone = '62' . substr($wa_phone, 1);
            }
            ?>
            <div x-data="{ 
                status: '<?php echo $prev['status'] ?? ''; ?>', 
                materi: '<?php echo $prev['jenis_materi'] ?? 'Al Quran'; ?>',
                isSaving: false,
                isSaved: <?php echo isset($prev['id']) ? 'true' : 'false'; ?>,
                isValid() {
                    if (!this.status) return false;
                    if (this.status === 'H') {
                        const card = this.$el;
                        const halaman = card.querySelector('[name*=\'halaman\']')?.value.trim();
                        const hasil = card.querySelector('[name*=\'hasil_talaqqi\']:checked');
                        
                        let detailOk = true;
                        if (this.materi === 'Iqro') {
                            detailOk = !!card.querySelector('[name*=\'jilid\']')?.value;
                        } else if (this.materi === 'Al Quran') {
                            detailOk = !!card.querySelector('[name*=\'nama_surat\']')?.value.trim();
                        }
                        
                        return !!halaman && !!hasil && detailOk;
                    }
                    if (this.status === 'S' || this.status === 'I') {
                        const alasan = this.$el.querySelector('[name*=\'alasan\']')?.value.trim();
                        return !!alasan;
                    }
                    return true;
                },
                resetSelection() {
                    openConfirm('Batalkan Presensi?', 'Data kehadiran dan pencapaian materi untuk peserta ini akan dihapus secara permanen.', () => {
                        this.isSaving = true;
                        this.isSaved = false;
                        
                        const card = this.$el;
                        const formData = new FormData();
                        formData.append('csrf_token', card.closest('form').querySelector('[name=\'csrf_token\']').value);
                        formData.append('halaqoh_id', '<?php echo $halaqoh_id; ?>');
                        formData.append('wali_santri_id', '<?php echo $m['id']; ?>');
                        formData.append('tanggal', '<?php echo $tanggal; ?>');
                        formData.append('status', 'RESET');

                        fetch('api-save-single.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(res => res.json())
                        .then(data => {
                            this.isSaving = false;
                            if(data.status === 'success') {
                                this.status = '';
                                this.isSaved = true;
                                setTimeout(() => { this.isSaved = false; }, 3000);
                            }
                        })
                        .catch(() => {
                            this.isSaving = false;
                        });
                    });
                },
                saveDraft() {
                    if (!this.status) return; // Don't save if status is cleared
                    if (!this.isValid()) {
                        this.isSaved = false;
                        return;
                    }
                    
                    this.isSaving = true;
                    this.isSaved = false;
                    
                    const card = this.$el;
                    const formData = new FormData();
                    formData.append('csrf_token', card.closest('form').querySelector('[name=\'csrf_token\']').value);
                    formData.append('halaqoh_id', '<?php echo $halaqoh_id; ?>');
                    formData.append('wali_santri_id', '<?php echo $m['id']; ?>');
                    formData.append('tanggal', '<?php echo $tanggal; ?>');
                    formData.append('status', this.status);
                    
                    const alasan = card.querySelector('[name*=\'alasan\']')?.value;
                    if(alasan) formData.append('alasan', alasan);
                    
                    formData.append('jenis_materi', this.materi);
                    
                    const jilid = card.querySelector('[name*=\'jilid\']')?.value;
                    if(jilid) formData.append('jilid', jilid);
                    
                    const surat = card.querySelector('[name*=\'nama_surat\']')?.value;
                    if(surat) formData.append('nama_surat', surat);
                    
                    const halaman = card.querySelector('[name*=\'halaman\']')?.value;
                    if(halaman) formData.append('halaman', halaman);
                    
                    const hasil = card.querySelector('[name*=\'hasil_talaqqi\']:checked')?.value;
                    if(hasil) formData.append('hasil_talaqqi', hasil);

                    fetch('api-save-single.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        this.isSaving = false;
                        if(data.status === 'success') {
                            this.isSaved = true;
                            // Reset saved indicator after 3s
                            setTimeout(() => { this.isSaved = false; }, 3000);
                        }
                    })
                    .catch(() => {
                        this.isSaving = false;
                    });
                }
            }" @change.debounce.500ms="saveDraft()"
                class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden transition-all duration-300 relative"
                :class="status === 'H' ? 'ring-2 ring-blue-500 ring-offset-2' : (status === 'A' ? 'bg-red-50/30' : '')">

                <!-- Saving Indicator -->
                <div class="absolute top-4 right-4 flex items-center gap-1.5 pointer-events-none">
                    <div x-show="isSaving" class="flex items-center text-[10px] font-bold text-blue-500 animate-pulse">
                        <svg class="w-3 h-3 mr-1 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Menyimpan...
                    </div>
                    <div x-show="isSaved" x-transition
                        class="flex items-center text-[10px] font-bold text-emerald-500 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">
                        <svg class="w-3 h-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                        </svg>
                        Tersimpan
                    </div>
                </div>

                <!-- Main Member Info & Status -->
                <div class="p-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div
                                class="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 font-bold text-lg">
                                <?php echo substr($m['nama_bapak'], 0, 1); ?>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h4 class="font-bold text-slate-800">
                                        <?php echo htmlspecialchars($m['nama_bapak']); ?>
                                    </h4>
                                    <?php if ($wa_phone): ?>
                                        <a href="https://wa.me/<?php echo $wa_phone; ?>" target="_blank"
                                            class="p-1 px-1.5 bg-emerald-100 text-emerald-600 rounded-lg hover:bg-emerald-600 hover:text-white transition-all flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24">
                                                <path
                                                    d="M20.2 3.8C18.1 1.7 15.2 0.5 12.1 0.5c-6.1 0-11.1 5-11.1 11.1 0 2 0.5 3.9 1.5 5.6L1 23l5.8-1.5c1.6 0.9 3.5 1.3 5.3 1.3 6.1 0 11.1-5 11.1-11.1 0-3-1.2-5.8-3.3-8ZM12.1 20.8c-1.7 0-3.3-0.4-4.7-1.3l-0.3-0.2L3.6 20.5l1.2-3.4-0.2-0.3c-0.9-1.5-1.4-3.2-1.4-5 0-5.1 4.2-9.3 9.3-9.3 2.5 0 4.8 1 6.6 2.7 1.7 1.7 2.7 4.1 2.7 6.6 0 5.1-4.2 9.3-9.3 9.3Zm5.1-7c-0.3-0.1-1.7-0.9-1.9-1-0.3-0.1-0.5-0.1-0.7 0.1-0.2 0.3-0.8 1-1 1.2-0.2 0.2-0.3 0.2-0.6 0.1-0.3-0.1-1.2-0.5-2.4-1.5-0.9-0.8-1.5-1.8-1.6-2.1-0.2-0.3 0-0.5 0.1-0.6 0.1-0.1 0.3-0.3 0.4-0.5 0.1-0.2 0.2-0.3 0.3-0.5 0.1-0.2 0-0.3-0.1-0.5-0.1-0.1-0.7-1.7-1-2.4-0.3-0.6-0.6-0.5-0.7-0.5h-0.7c-0.2 0-0.6 0.1-0.9 0.4-0.3 0.3-1.1 1.1-1.1 2.7s1.2 3.1 1.3 3.3c0.1 0.2 2.3 3.5 5.5 4.9 0.8 0.3 1.4 0.5 1.9 0.7 0.8 0.2 1.5 0.2 2.1 0.1 0.6-0.1 1.9-0.8 2.2-1.5 0.3-0.7 0.3-1.3 0.2-1.5-0.1-0.2-0.3-0.3-0.6-0.4Z" />
                                            </svg>
                                            <span class="text-[9px] font-black uppercase tracking-widest hidden sm:inline">WhatsApp</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($m['info_anak']): ?>
                                    <p class="text-[10px] text-blue-500 font-semibold leading-tight mt-0.5">
                                        Anak: <?php echo $m['info_anak']; ?>
                                    </p>
                                <?php endif; ?>
                                <p class="text-[9px] text-slate-400 mt-1">ID Peserta: #
                                    <?php echo $m['id']; ?>
                                </p>
                            </div>
                        </div>

                        <!-- Status Selection (Mobile Friendly Buttons) -->
                        <div class="flex flex-wrap gap-2">
                            <label class="cursor-pointer">
                                <input type="radio" name="presensi[<?php echo $m['id']; ?>][status]" value="H"
                                    x-model="status" class="hidden">
                                <div :class="status === 'H' ? 'bg-blue-600 text-white scale-105' : 'bg-slate-50 text-slate-500'"
                                    class="px-5 py-2 rounded-xl font-bold text-sm transition-all border border-transparent shadow-sm">
                                    Hadir</div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="presensi[<?php echo $m['id']; ?>][status]" value="S"
                                    x-model="status" class="hidden">
                                <div :class="status === 'S' ? 'bg-emerald-500 text-white scale-105' : 'bg-slate-50 text-slate-500'"
                                    class="px-5 py-2 rounded-xl font-bold text-sm transition-all border border-transparent shadow-sm">
                                    Sakit</div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="presensi[<?php echo $m['id']; ?>][status]" value="I"
                                    x-model="status" class="hidden">
                                <div :class="status === 'I' ? 'bg-amber-500 text-white scale-105' : 'bg-slate-50 text-slate-500'"
                                    class="px-5 py-2 rounded-xl font-bold text-sm transition-all border border-transparent shadow-sm">
                                    Izin</div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="presensi[<?php echo $m['id']; ?>][status]" value="A"
                                    x-model="status" class="hidden">
                                <div :class="status === 'A' ? 'bg-red-500 text-white scale-105' : 'bg-slate-50 text-slate-500'"
                                    class="px-5 py-2 rounded-xl font-bold text-sm transition-all border border-transparent shadow-sm">
                                    Alpha</div>
                            </label>

                            <!-- Button Batal / Reset -->
                            <button type="button" x-show="status" @click="resetSelection()" 
                                class="px-3 py-2 rounded-xl text-slate-400 hover:text-red-500 hover:bg-red-50 transition-all border border-dashed border-slate-200 text-xs font-bold uppercase flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                                Batal
                            </button>
                        </div>
                    </div>

                    <!-- Additional Context Fields -->
                    <div x-show="status === 'S' || status === 'I'" x-cloak x-transition
                        class="mt-4 pt-4 border-t border-slate-50">
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-2">Alasan / Keterangan: <span class="text-red-500">*</span></label>
                        <input type="text" name="presensi[<?php echo $m['id']; ?>][alasan]"
                            :required="status === 'S' || status === 'I'"
                            value="<?php echo htmlspecialchars($prev['alasan'] ?? ''); ?>"
                            class="w-full px-4 py-2 rounded-xl border border-slate-200 bg-slate-50 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Tulis alasan...">
                    </div>

                    <!-- Achievement Form (Only for Hadir) -->
                    <div x-show="status === 'H'" x-cloak x-transition
                        class="mt-6 pt-6 border-t border-slate-100 bg-blue-50/20 -mx-6 -mb-6 p-6">
                        <h5 class="text-sm font-bold text-blue-600 mb-4 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            Pencapaian Materi
                        </h5>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Jenis
                                    Materi <span class="text-red-500">*</span></label>
                                <select name="presensi[<?php echo $m['id']; ?>][jenis_materi]" x-model="materi"
                                    :required="status === 'H'"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                                    <option value="Iqro" <?php echo ($prev['jenis_materi'] ?? '') === 'Iqro' ? 'selected' : ''; ?>>Iqro</option>
                                    <option value="Al Quran" <?php echo ($prev['jenis_materi'] ?? '') === 'Al Quran' ? 'selected' : ''; ?>>Al Quran</option>
                                </select>
                            </div>

                            <template x-if="materi === 'Iqro'">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Jilid <span class="text-red-500">*</span></label>
                                    <select name="presensi[<?php echo $m['id']; ?>][jilid]"
                                        :required="status === 'H' && materi === 'Iqro'"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                                        <option value="">Pilih Jilid</option>
                                        <?php for ($j = 1; $j <= 6; $j++): ?>
                                            <option value="<?php echo $j; ?>" <?php echo ($prev['jilid'] ?? '') == $j ? 'selected' : ''; ?>>Jilid
                                                <?php echo $j; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </template>

                            <template x-if="materi === 'Al Quran'">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Nama
                                        Surat <span class="text-red-500">*</span></label>
                                    <input type="text" name="presensi[<?php echo $m['id']; ?>][nama_surat]"
                                        :required="status === 'H' && materi === 'Al Quran'"
                                        placeholder="Contoh: Al-Baqarah"
                                        value="<?php echo htmlspecialchars($prev['nama_surat'] ?? ''); ?>"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                            </template>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">
                                    <span x-text="materi === 'Al Quran' ? 'Ayat Berapa - Berapa' : 'Halaman'"></span> <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="presensi[<?php echo $m['id']; ?>][halaman]"
                                    :required="status === 'H'"
                                    :placeholder="materi === 'Al Quran' ? 'Pilih Ayat: 1-10' : 'Halaman'"
                                    value="<?php echo htmlspecialchars($prev['halaman'] ?? ''); ?>"
                                    class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Hasil
                                    Talaqqi <span class="text-red-500">*</span></label>
                                <div class="flex gap-2 h-[38px]">
                                    <label class="flex-1 cursor-pointer">
                                        <input type="radio" name="presensi[<?php echo $m['id']; ?>][hasil_talaqqi]"
                                            value="Lulus" class="hidden md:block peer" 
                                            :required="status === 'H'"
                                            <?php echo ($prev['hasil_talaqqi'] ?? '') === 'Lulus' ? 'checked' : ''; ?>>
                                        <div
                                            class="h-full flex items-center justify-center rounded-lg border border-slate-200 text-xs font-bold text-slate-500 peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500 transition-all">
                                            LULUS</div>
                                    </label>
                                    <label class="flex-1 cursor-pointer">
                                        <input type="radio" name="presensi[<?php echo $m['id']; ?>][hasil_talaqqi]"
                                            value="Ulang" class="hidden md:block peer" 
                                            :required="status === 'H'"
                                            <?php echo ($prev['hasil_talaqqi'] ?? '') === 'Ulang' ? 'checked' : ''; ?>>
                                        <div
                                            class="h-full flex items-center justify-center rounded-lg border border-slate-200 text-xs font-bold text-slate-500 peer-checked:bg-red-500 peer-checked:text-white peer-checked:border-red-500 transition-all">
                                            ULANG</div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Floating Save Button -->
    <div
        class="fixed bottom-0 left-0 right-0 p-4 lg:left-64 bg-white/80 backdrop-blur-md border-t border-slate-100 z-40">
        <div class="max-w-4xl mx-auto flex flex-col md:flex-row items-center gap-4">
            <div class="hidden md:flex items-center text-xs text-slate-400 font-medium italic">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Sistem menyimpan otomatis setiap kali Anda mengubah data.
            </div>
            <button type="submit"
                class="w-full md:flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-2xl shadow-xl shadow-blue-200 transition-all active:scale-[0.98] flex justify-center items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4">
                    </path>
                </svg>
                FINALISASI & SIMPAN SEMUA
            </button>
        </div>
    </div>
</form>
</div>

<?php require_once '../includes/footer.php'; ?>