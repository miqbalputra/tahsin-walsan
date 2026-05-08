<?php
$pageTitle = 'Log Aktivitas';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
require_once 'config/database.php';
require_once 'includes/auth_helper.php';

checkRole(['admin']);

$logs = $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
?>

<div class="mb-10">
    <h2 class="text-3xl font-black text-slate-800">Log Aktivitas</h2>
    <p class="text-slate-500">Rekaman jejak tindakan administratif dalam sistem.</p>
</div>

<div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden mb-12">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                    <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waktu</th>
                    <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">User</th>
                    <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tindakan</th>
                    <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Keterangan
                    </th>
                    <th class="px-8 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">IP Address
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="px-8 py-12 text-center text-slate-400 italic">Belum ada rekaman aktivitas.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($logs as $l): ?>
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="px-8 py-4 text-xs text-slate-400 font-bold">
                            <?php echo date('d/m H:i', strtotime($l['created_at'])); ?>
                        </td>
                        <td class="px-8 py-4">
                            <span class="px-3 py-1 bg-slate-100 text-slate-700 rounded-lg text-[10px] font-black uppercase">
                                <?php echo htmlspecialchars($l['username']); ?>
                            </span>
                        </td>
                        <td class="px-8 py-4 text-xs font-black">
                            <span class="<?php echo match ($l['action']) {
                                'LOGIN' => 'text-blue-600',
                                'DELETE_PESERTA' => 'text-red-600',
                                'SAVE_PESERTA' => 'text-emerald-600',
                                'GENERATE_PIN' => 'text-amber-600',
                                default => 'text-slate-600'
                            }; ?>">
                                <?php echo $l['action']; ?>
                            </span>
                        </td>
                        <td class="px-8 py-4 text-sm text-slate-500">
                            <?php echo htmlspecialchars($l['description']); ?>
                        </td>
                        <td class="px-8 py-4 text-[10px] text-slate-300 font-mono">
                            <?php echo $l['ip_address']; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-blue-50 border border-blue-100 p-8 rounded-[2rem] flex items-center gap-6">
    <div class="bg-blue-600 p-4 rounded-2xl text-white shadow-lg shadow-blue-200">
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
    </div>
    <div>
        <h4 class="font-black text-blue-900 text-lg mb-1">Keamanan Data</h4>
        <p class="text-blue-700 text-sm leading-relaxed">Log aktivitas ini hanya dapat diakses oleh Administrator Utama.
            Data ini sangat penting untuk pelacakan jika terjadi kesalahan input atau penyalahgunaan akun.</p>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>