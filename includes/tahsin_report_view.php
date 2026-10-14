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

/** Aggregate-only tables for a general meeting. Never accepts or renders wali/child rows. */
function tahsinMeetingText($value, bool $excel = false): string
{
    $value = (string) $value;
    if ($excel && preg_match('/^[=+@\-\t\r]/', $value)) $value = "'" . $value;
    return tahsinH($value);
}

function tahsinMeetingValue($value): string
{
    return $value === null ? 'Belum Terverifikasi' : number_format((int) $value, 0, ',', '.');
}

function tahsinMeetingAggregateTable(array $groups, bool $guru = false, bool $excel = false): void
{
    echo '<section class="tahsin-panel tahsin-meeting-aggregate"><h3>Rekap periode per ' . ($guru ? 'guru' : 'halaqoh') . '</h3><div class="tahsin-scroll"><table class="tahsin-table"><thead><tr><th>' . ($guru ? 'Guru' : 'Halaqoh') . '</th>';
    if (!$guru) echo '<th>Guru</th>';
    echo '<th>Wali tersentuh dalam periode</th><th>Peserta-sesi roster diketahui</th><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alfa tercatat</th><th>Belum diinput</th><th>Capaian belum lengkap</th><th>Keterangan belum lengkap</th><th>Perlu verifikasi</th><th>Kehadiran</th><th>Kelengkapan input</th></tr></thead><tbody>';
    foreach ($groups as $g) {
        $name = $guru ? ($g['nama_ustadz'] ?? 'Belum ditentukan') : ($g['nama_halaqoh'] ?? 'Halaqoh lama');
        echo '<tr><td><strong>' . tahsinMeetingText($name, $excel) . '</strong></td>';
        if (!$guru) echo '<td>' . tahsinMeetingText($g['nama_ustadz'] ?? '—', $excel) . '</td>';
        echo '<td>' . (int) ($g['total_anggota'] ?? 0) . '</td><td>' . (int) ($g['expected'] ?? 0) . ($g['unverified'] ? ' <small>+ sebagian histori belum terverifikasi</small>' : '') . '</td>';
        foreach (['hadir', 'sakit', 'izin', 'alpha', 'missing_presensi', 'missing_capaian', 'missing_keterangan', 'conflicts'] as $key) echo '<td>' . (int) ($g[$key] ?? 0) . '</td>';
        echo '<td>' . tahsinPercent($g['hadir_percent'] ?? null) . '</td><td>' . tahsinPercent($g['coverage_percent'] ?? null) . '</td>';
        echo '</tr>';
    }
    if (!$groups) echo '<tr><td colspan="' . ($guru ? 13 : 14) . '">Tidak ada kelompok pada filter periode ini.</td></tr>';
    echo '</tbody></table></div></section>';
}

function tahsinRenderMeetingSummary(array $report, bool $excel = false): void
{
    $s = $report['summary'];
    echo '<div class="tahsin-meeting-summary" id="ringkasan-pertemuan">';
    echo '<section class="tahsin-panel"><h3>Ringkasan Keseluruhan Periode</h3><p class="tahsin-note">' . (int) $s['peserta_periode'] . ' peserta unik diketahui dari roster/rekaman dalam sesi periode · ' . (int) $s['peserta_sesi'] . ' peserta-sesi berdasarkan roster yang diketahui. Roster sesi lama yang belum terverifikasi tidak ditebak dari anggota saat ini.</p>';
    if ($s['provisional']) echo '<p class="tahsin-alert">Data masih sementara karena ada input belum lengkap, verifikasi tertunda, atau histori roster belum terverifikasi.</p>';
    echo '<div class="tahsin-meeting-total"><table class="tahsin-table"><thead><tr><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alfa tercatat</th><th>Belum diinput</th><th>Capaian belum lengkap</th><th>Keterangan belum lengkap</th><th>Perlu verifikasi</th><th>Kehadiran</th><th>Kelengkapan input</th></tr></thead><tbody><tr>';
    foreach (['hadir', 'sakit', 'izin', 'alpha', 'missing_presensi', 'missing_capaian', 'missing_keterangan', 'conflicts'] as $key) echo '<td>' . (int) ($s[$key] ?? 0) . '</td>';
    echo '<td>' . tahsinPercent($s['hadir_percent'] ?? null) . '</td><td>' . tahsinPercent($s['coverage_percent'] ?? null) . '</td>';
    echo '</tr></tbody></table></div><p class="tahsin-note">Belum Diinput dihitung hanya untuk roster yang terverifikasi. Kehadiran = H ÷ (H + S + I + A tersimpan); kelengkapan input = peserta-sesi lengkap ÷ kewajiban peserta-sesi yang diketahui.</p></section>';
    echo '<section class="tahsin-panel"><h3>Statistik per Pertemuan</h3><div class="tahsin-scroll"><table class="tahsin-table tahsin-weekly-stats"><thead><tr><th>Sesi Ahad</th><th>Jumlah peserta dalam roster</th><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alfa tercatat</th><th>Belum diinput</th><th>Capaian belum lengkap</th><th>Keterangan belum lengkap</th><th>Perlu verifikasi</th><th>Kehadiran</th><th>Kelengkapan input</th></tr></thead><tbody>';
    foreach ($report['trend'] as $row) {
        $roster = !empty($row['roster_verified']) ? tahsinMeetingValue($row['peserta_roster']) : 'Belum Terverifikasi';
        echo '<tr><td>' . tahsinMeetingText(date('d/m/Y', strtotime($row['tanggal'])), $excel) . '</td><td>' . $roster . '</td>';
        foreach (['hadir', 'sakit', 'izin', 'alpha'] as $key) echo '<td>' . (int) ($row[$key] ?? 0) . '</td>';
        foreach (['missing_presensi', 'missing_capaian', 'missing_keterangan', 'conflicts'] as $key) {
            $value = $key === 'missing_presensi' && empty($row['roster_verified']) ? '—' : (int) ($row[$key] ?? 0);
            echo '<td>' . tahsinH($value) . '</td>';
        }
        echo '<td>' . tahsinPercent($row['hadir_percent'] ?? null) . '</td><td>' . tahsinPercent($row['coverage_percent'] ?? null) . '</td>';
        echo '</tr>';
    }
    if (!$report['trend']) echo '<tr><td colspan="12">Tidak ada sesi Ahad aktif pada periode yang dipilih.</td></tr>';
    echo '</tbody></table></div><p class="tahsin-note">Wali dengan beberapa anak tetap dihitung satu kali pada setiap sesi. Sesi sebelum histori roster tersedia tetap menampilkan presensi tercatat, sedangkan jumlah roster dan Belum Diinput tidak ditebak.</p></section>';
    tahsinMeetingAggregateTable($report['halaqoh'], false, $excel);
    tahsinMeetingAggregateTable($report['guru'], true, $excel);
    echo '</div>';
}

function tahsinMeetingPayload(array $report): array
{
    $groupRows = static function (array $groups, bool $guru): array {
        return array_map(static fn($g) => [
            'label' => $guru ? ($g['nama_ustadz'] ?? 'Belum ditentukan') : ($g['nama_halaqoh'] ?? 'Halaqoh lama'),
            'wali' => (int) ($g['total_anggota'] ?? 0),
            'peserta_sesi' => (int) ($g['expected'] ?? 0), 'roster_unverified' => (bool) ($g['unverified'] ?? false),
            'hadir' => (int) ($g['hadir'] ?? 0), 'sakit' => (int) ($g['sakit'] ?? 0), 'izin' => (int) ($g['izin'] ?? 0),
            'alpha' => (int) ($g['alpha'] ?? 0), 'belum' => (int) ($g['missing_presensi'] ?? 0),
            'capaian' => (int) ($g['missing_capaian'] ?? 0), 'keterangan' => (int) ($g['missing_keterangan'] ?? 0), 'verifikasi' => (int) ($g['conflicts'] ?? 0),
            'hadir_percent' => $g['hadir_percent'] ?? null, 'coverage_percent' => $g['coverage_percent'] ?? null,
        ], $groups);
    };
    return [
        'summary' => array_intersect_key($report['summary'], array_flip(['peserta_periode', 'peserta_sesi', 'hadir', 'sakit', 'izin', 'alpha', 'missing_presensi', 'missing_capaian', 'missing_keterangan', 'conflicts', 'hadir_percent', 'coverage_percent'])),
        'trend' => array_map(static fn($r) => array_intersect_key($r, array_flip(['tanggal', 'roster_verified', 'peserta_roster', 'hadir', 'sakit', 'izin', 'alpha', 'missing_presensi', 'missing_capaian', 'missing_keterangan', 'conflicts', 'hadir_percent', 'coverage_percent'])), $report['trend']),
        'halaqoh' => $groupRows($report['halaqoh'], false), 'guru' => $groupRows($report['guru'], true),
    ];
}

function csrfFieldReturn(): string
{
    return '<input type="hidden" name="csrf_token" value="' . tahsinH(generateCsrfToken()) . '">';
}
