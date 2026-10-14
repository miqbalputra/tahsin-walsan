<?php
function tahsinH($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function tahsinQuery(array $filters, array $extra = []): string { return http_build_query(array_merge($filters, $extra)); }
function tahsinStatusLabel($status): string { return ['H' => 'Hadir', 'S' => 'Sakit', 'I' => 'Izin', 'A' => 'Alfa'][$status] ?? 'Belum Diinput'; }
function tahsinCellLabel(array $cell): string
{
    if (!$cell['in_schedule']) return 'Di luar jadwal; tidak dihitung';
    return ['conflict' => 'Perlu Verifikasi', 'invalid_date' => 'Tanggal Pelaksanaan Perlu Diperbaiki', 'missing' => 'Belum Diinput', 'baseline' => 'Capaian Awal; bukan kehadiran', 'capaian' => 'Capaian Belum Lengkap', 'presensi_incomplete' => 'Keterangan Presensi Belum Lengkap'][$cell['state']] ?? tahsinStatusLabel($cell['record']['status'] ?? null);
}
function tahsinRenderCards(array $s): void
{
    $cards = [['Wali dalam rekap', $s['peserta'], 'blue'], ['Hadir tercatat', $s['hadir'], 'green'], ['Alfa tercatat', $s['alpha'], 'red'],
        ['Kehadiran', tahsinPercent($s['hadir_percent']), 'blue'], ['Kelengkapan input', tahsinPercent($s['coverage_percent']), 'purple'], ['Perlu verifikasi', $s['conflicts'], 'amber']];
    echo '<div class="tahsin-cards">';
    foreach ($cards as [$label, $value, $color]) echo '<div class="tahsin-card ' . $color . '"><span>' . tahsinH($label) . '</span><strong>' . tahsinH($value) . '</strong></div>';
    echo '</div><p class="tahsin-note">Kehadiran = H ÷ (H + S + I + A). Data kosong bukan Alfa. ';
    echo $s['provisional'] ? '<strong>Data sementara: masih ada kekurangan atau histori belum terverifikasi.</strong>' : 'Kelengkapan dihitung dari kewajiban peserta-sesi yang diketahui.';
    echo '</p>';
}
function tahsinRenderGroupTable(array $groups, bool $guru = false): void
{
    echo '<section class="tahsin-panel"><h3>Rekap per ' . ($guru ? 'Guru' : 'Halaqoh') . '</h3><div class="tahsin-scroll"><table class="tahsin-table"><thead><tr><th>' . ($guru ? 'Guru' : 'Halaqoh / Guru') . '</th><th>Wali</th><th>H</th><th>S</th><th>I</th><th>A</th><th>Kehadiran</th><th>Input lengkap</th><th>Capaian L/U</th></tr></thead><tbody>';
    foreach ($groups as $g) {
        echo '<tr><td><strong>' . tahsinH($guru ? ($g['nama_ustadz'] ?? 'Belum ditentukan') : ($g['nama_halaqoh'] ?? 'Halaqoh lama')) . '</strong>';
        if (!$guru) echo '<small>' . tahsinH($g['nama_ustadz'] ?? '—') . '</small>';
        echo '</td><td>' . $g['total_anggota'] . '</td>';
        foreach (['hadir', 'sakit', 'izin', 'alpha'] as $key) echo '<td>' . $g[$key] . '</td>';
        echo '<td>' . tahsinPercent($g['hadir_percent']) . ($g['provisional'] ? '<small>Sementara</small>' : '') . '</td><td>' . tahsinPercent($g['coverage_percent']) . '</td><td>' . $g['lulus'] . ' / ' . $g['ulang'] . '</td></tr>';
    }
    if (!$groups) echo '<tr><td colspan="9">Tidak ada sesi tercatat dalam filter ini.</td></tr>';
    echo '</tbody></table></div></section>';
}
function tahsinRenderWaliTable(array $rows, array $filters, bool $excel = false): void
{
    echo '<div class="tahsin-scroll"><table class="tahsin-table tahsin-wali"><thead><tr><th>Wali / Anak</th><th>Halaqoh / Guru</th><th>Kepesertaan saat ini</th><th>H</th><th>S</th><th>I</th><th>A</th><th>Kehadiran</th><th>Kelengkapan</th><th>Kualitas data</th><th>Terakhir hadir / Capaian</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $text = static function ($value) use ($excel) {
            $value = (string) $value;
            if ($excel && preg_match('/^[=+@\-\t\r]/', $value)) $value = "'" . $value;
            return tahsinH($value);
        };
        echo '<tr><td><strong>';
        if (!$excel) echo '<a href="rekap-lengkap.php?' . tahsinH(tahsinQuery($filters, ['wali_santri_id' => $r['id'], 'detail' => '1'])) . '#detail-wali">';
        echo $text($r['nama_bapak']);
        if (!$excel) echo '</a>';
        echo '</strong><small>' . $text($r['anak'] ?: 'Data anak belum tersedia') . '</small></td><td>' . $text($r['nama_halaqoh']) . '<small>' . $text($r['nama_ustadz']) . '</small></td><td>' . $text(str_replace('_', ' ', ucfirst($r['kategori']))) . '<small>' . ((int) $r['status_aktif'] ? 'Aktif' : 'Arsip') . (!empty($r['lanjut_tahsin']) ? ' · Lanjut Tahsin' : '') . '</small>';
        if ((int) $r['status_aktif'] && $r['kategori'] !== 'tahsin_luar' && empty($r['current_halaqoh_id'])) echo '<small>Belum punya halaqoh aktif</small>';
        if ($r['kategori'] === 'tahsin_luar') echo '<small>' . $text(trim(($r['tempat_tahsin'] ?? '') . ' · ' . ($r['ustadz_luar'] ?? ''), ' ·')) . '</small>';
        echo '</td>';
        foreach (['hadir', 'sakit', 'izin', 'alpha'] as $key) echo '<td class="tahsin-count">' . $r[$key] . '</td>';
        echo '<td><strong>' . tahsinPercent($r['hadir_percent']) . '</strong>' . ($r['provisional'] ? '<small>Sementara</small>' : '') . '</td><td>' . tahsinPercent($r['coverage_percent']) . '<small>' . ($r['unverified'] ? 'Histori belum terverifikasi' : ($r['expected'] ? $r['complete_input'] . ' / ' . $r['expected'] . ' peserta-sesi' : 'Tidak ada kewajiban yang diketahui')) . '</small></td>';
        echo '<td><span class="tahsin-badge ' . tahsinH($r['quality']) . '">' . $text($r['quality_label']) . '</span><small>' . $text($r['risk_label']) . '</small></td><td>' . $text($r['terakhir_hadir'] ? date('d/m/Y', strtotime($r['terakhir_hadir'])) : '—') . '<small>' . $text($r['materi_terakhir']) . '</small></td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="11">Tidak ada wali yang sesuai filter.</td></tr>';
    echo '</tbody></table></div>';
}
function tahsinRenderDetails(array $rows, bool $canVerify, bool $print = false): void
{
    echo '<section class="tahsin-panel" id="detail-wali"><h3>Detail per Pekan</h3><p class="tahsin-note">H = Hadir, S = Sakit, I = Izin, A = Alfa. Catatan di hari pengganti mengikuti sesi Ahad sebelumnya.</p>';
    foreach ($rows as $row) {
        if ($print && !$row['details']) continue;
        echo '<details class="tahsin-detail"' . ($print || count($rows) === 1 ? ' open' : '') . '><summary>' . tahsinH($row['nama_bapak']) . ' <span>' . tahsinH($row['quality_label']) . '</span></summary>';
        if (!$row['details']) echo '<p>Belum ada catatan atau kewajiban sesi yang diketahui untuk periode ini.</p>';
        foreach ($row['details'] as $cell) {
            echo '<div class="tahsin-session"><div class="tahsin-session-heading"><strong>Sesi Ahad ' . date('d/m/Y', strtotime($cell['session_date'])) . '</strong><span class="tahsin-badge ' . tahsinH($cell['state']) . '">' . tahsinH(tahsinCellLabel($cell)) . '</span></div>';
            echo '<p class="tahsin-note">' . tahsinH(($cell['owner']['nama_halaqoh'] ?? 'Tanpa halaqoh') . ' · ' . ($cell['owner']['nama_ustadz'] ?? 'Guru belum ditentukan')) . '<br>' . tahsinH($cell['roster_source']) . '</p>';
            if ($cell['missing_fields'] && !$cell['complete']) echo '<p class="tahsin-note">Perlu dilengkapi: ' . tahsinH(implode(', ', $cell['missing_fields'])) . '</p>';
            echo '<div class="tahsin-scroll"><table class="tahsin-table"><thead><tr><th>Catatan</th><th>Tanggal pelaksanaan</th><th>Status</th><th>Alasan / Capaian</th><th>Hasil</th></tr></thead><tbody>';
            foreach ($cell['records'] as $record) echo '<tr><td>#' . (int) $record['id'] . '</td><td>' . date('d/m/Y', strtotime($record['tanggal'])) . (!empty($record['on_holiday']) ? '<small>Libur; tidak dihitung</small>' : '') . '</td><td>' . tahsinStatusLabel($record['status']) . '</td><td>' . tahsinH($record['status'] === 'H' ? tahsinMaterial($record) : ($record['alasan'] ?? '—')) . '</td><td>' . tahsinH($record['hasil_talaqqi'] ?? '—') . '</td></tr>';
            if (!$cell['records']) echo '<tr><td colspan="5">Guru belum menyimpan presensi dan capaian. Status wali belum diketahui.</td></tr>';
            echo '</tbody></table></div>';
            if ($canVerify && $cell['state'] === 'conflict' && $cell['records']) {
                echo '<form method="post" class="tahsin-verify no-print">' . csrfFieldReturn() . '<input type="hidden" name="action" value="verify"><input type="hidden" name="wali_id" value="' . $row['id'] . '"><input type="hidden" name="session" value="' . $cell['session_date'] . '"><input type="hidden" name="fingerprint" value="' . $cell['fingerprint'] . '"><label>Catatan yang sah <select name="selected_id" required><option value="">Pilih setelah memeriksa sumber</option>';
                foreach ($cell['records'] as $record) echo '<option value="' . (int) $record['id'] . '">#' . (int) $record['id'] . ' · ' . tahsinH($record['tanggal']) . ' · ' . tahsinStatusLabel($record['status']) . ' · ' . tahsinH(tahsinMaterial($record)) . '</option>';
                echo '<option value="0">Semua hanya capaian awal; bukan kehadiran</option></select></label><label>Alasan verifikasi <input name="note" required maxlength="2000" placeholder="Sumber konfirmasi guru / koreksi data"></label><button class="tahsin-button" type="submit">Simpan Verifikasi</button><p class="tahsin-note">Catatan asli tetap tersimpan. Jika sumber berubah, verifikasi harus diulang.</p></form>';
            }
            echo '</div>';
        }
        echo '</details>';
    }
    echo '</section>';
}
function csrfFieldReturn(): string
{
    return '<input type="hidden" name="csrf_token" value="' . tahsinH(generateCsrfToken()) . '">';
}
