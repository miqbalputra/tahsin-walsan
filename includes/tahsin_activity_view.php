<?php
/** Display-only helpers. All counts and ratios come from the shared report. */
function tahsinActivityPeriod(array $filters, array $query = [], ?DateTimeImmutable $now = null): array
{
    $now = $now ?? tahsinNow();
    $selectedEnd = tahsinDate((string) ($query['end_date'] ?? $query['end'] ?? '')) ?? $filters['end_date'];
    if ($selectedEnd < $filters['start_date']) $selectedEnd = $filters['end_date'];
    $through = min($selectedEnd, $now->format('Y-m-d'));
    $note = '';
    if ($filters['start_date'] > $through) $note = 'Periode ini belum dimulai. Belum ada sesi yang dapat dihitung; sesi mendatang tidak dianggap Alfa atau Belum Diinput.';
    elseif ($selectedEnd > $through) $note = 'Data dihitung sampai ' . date('d/m/Y', strtotime($through)) . '. Sesi setelah tanggal tersebut belum berlangsung dan tidak masuk perhitungan.';
    return ['filters' => array_replace($filters, ['end_date' => $selectedEnd]),
        'label' => date('d/m/Y', strtotime($filters['start_date'])) . ' – ' . date('d/m/Y', strtotime($selectedEnd)),
        'through' => $through, 'note' => $note];
}

function tahsinActivitySort(array $rows, string $sort): array
{
    usort($rows, static function ($a, $b) use ($sort) {
        if ($sort !== 'nama') {
            $key = $sort === 'alfa' ? 'alpha_percent' : 'hadir_percent';
            if (($a[$key] === null) !== ($b[$key] === null)) return $a[$key] === null ? 1 : -1;
            $comparison = $sort === 'hadir_asc' ? ($a[$key] <=> $b[$key]) : ($b[$key] <=> $a[$key]);
            if ($comparison) return $comparison;
        }
        return strnatcasecmp($a['nama_bapak'], $b['nama_bapak']) ?: ($a['id'] <=> $b['id']);
    });
    return $rows;
}

function tahsinActivityNote(array $totals): string
{
    if (!$totals['total_presensi']) return $totals['conflicts'] ? 'Menunggu verifikasi; persentase belum tersedia.' : 'Belum ada presensi terkonfirmasi; persentase belum tersedia.';
    return $totals['provisional'] ? 'Data sementara; persentase berdasarkan presensi terkonfirmasi.' : 'Data lengkap untuk kewajiban sesi yang diketahui.';
}

function tahsinActivityBar(array $totals): void
{
    if (!$totals['total_presensi']) {
        echo '<div class="tahsin-activity-empty">Belum ada data terkonfirmasi</div>';
        return;
    }
    $label = [];
    foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'alpha' => 'Alfa', 'sakit' => 'Sakit'] as $key => $name) $label[] = $name . ' ' . tahsinPercent($totals[$key . '_percent']);
    echo '<div class="tahsin-activity-bar" role="img" aria-label="' . tahsinH(implode(', ', $label)) . '">';
    foreach (['hadir', 'izin', 'alpha', 'sakit'] as $key) {
        // Use unrounded ratios so the stacked bar always fills exactly 100%.
        $width = 100 * $totals[$key] / $totals['total_presensi'];
        if ($width > 0) echo '<span class="' . $key . '" style="width:' . number_format($width, 6, '.', '') . '%"></span>';
    }
    echo '</div>';
}

function tahsinActivityStatusCells(array $totals): void
{
    foreach (['hadir', 'izin', 'alpha', 'sakit'] as $key) {
        echo '<td class="tahsin-activity-status ' . $key . '"><strong>' . tahsinPercent($totals[$key . '_percent']) . '</strong><small>' . (int) $totals[$key] . ' kali</small></td>';
    }
}

function tahsinActivityDataCell(array $totals): void
{
    echo '<td class="tahsin-activity-quality"><strong>' . ($totals['provisional'] ? 'Data sementara' : ($totals['total_presensi'] ? 'Lengkap' : 'Belum ada data')) . '</strong>';
    echo '<small>Input lengkap: ' . tahsinPercent($totals['coverage_percent']) . '</small>';
    if ($totals['missing_presensi']) echo '<small>Belum diinput: ' . (int) $totals['missing_presensi'] . '</small>';
    if ($totals['conflicts']) echo '<small>Perlu verifikasi: ' . (int) $totals['conflicts'] . '</small>';
    if ($totals['missing_capaian']) echo '<small>Capaian belum lengkap: ' . (int) $totals['missing_capaian'] . '</small>';
    if ($totals['missing_keterangan']) echo '<small>Keterangan belum lengkap: ' . (int) $totals['missing_keterangan'] . '</small>';
    if ($totals['unverified']) echo '<small>Histori roster belum terverifikasi; jumlah kosong belum dapat dipastikan.</small>';
    echo '</td>';
}

function tahsinRenderActivitySummary(array $report, bool $periodOnly = false): void
{
    $s = $report['summary'];
    $withData = count(array_filter($report['rows'], static fn($r) => $r['total_presensi'] > 0));
    echo '<section class="tahsin-panel tahsin-activity-overview" id="ringkasan-keaktifan"><h3>Ringkasan Total Periode</h3>';
    if ($periodOnly) {
        echo '<div class="tahsin-period-headline"><div><span>Total persentase kehadiran</span><strong>' . tahsinPercent($s['hadir_percent']) . '</strong><p>' . (int) $s['hadir'] . ' Hadir dari ' . (int) $s['total_presensi'] . ' presensi terkonfirmasi dalam seluruh periode.</p></div><div class="tahsin-period-basis"><strong>' . (int) $s['peserta'] . ' wali dalam filter</strong><p>' . count($report['sessions']) . ' sesi Ahad aktif yang sudah berlangsung</p><p>' . tahsinH(tahsinActivityNote($s)) . '</p></div></div>';
    }
    if (!$periodOnly) {
        echo '<div class="tahsin-cards tahsin-activity-cards">';
        foreach ([['Jumlah wali dalam filter', $s['peserta'], 'blue'], ['Presensi terkonfirmasi', $s['total_presensi'], 'purple'], ['Keaktifan / Hadir', tahsinPercent($s['hadir_percent']), 'green'], ['Izin', tahsinPercent($s['izin_percent']), 'amber'], ['Alfa tercatat', tahsinPercent($s['alpha_percent']), 'red'], ['Sakit', tahsinPercent($s['sakit_percent']), 'blue']] as [$label, $value, $color]) {
            echo '<div class="tahsin-card ' . $color . '"><span>' . tahsinH($label) . '</span><strong>' . tahsinH($value) . '</strong></div>';
        }
        echo '</div>';
    }
    echo '<p class="tahsin-note">' . $withData . ' dari ' . (int) $s['peserta'] . ' wali memiliki presensi terkonfirmasi · ' . count($report['sessions']) . ' sesi Ahad aktif dalam periode. Total presensi adalah jumlah catatan wali-sesi, bukan jumlah wali unik.</p>';
    tahsinActivityBar($s);
    if (!$periodOnly) {
        echo '<div class="tahsin-activity-legend">';
        foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'alpha' => 'Alfa', 'sakit' => 'Sakit'] as $key => $label) echo '<span><i class="' . $key . '"></i>' . $label . ': <strong>' . (int) $s[$key] . ' (' . tahsinPercent($s[$key . '_percent']) . ')</strong></span>';
        echo '</div>';
    }
    echo '<div class="tahsin-scroll"><table class="tahsin-table tahsin-period-totals"><thead><tr><th>Status presensi</th><th>Total selama periode</th><th>Persentase dari presensi terkonfirmasi</th></tr></thead><tbody>';
    foreach (['hadir' => 'Hadir', 'izin' => 'Izin', 'alpha' => 'Alfa tercatat', 'sakit' => 'Sakit'] as $key => $label) echo '<tr data-period-status="' . $key . '"><td>' . $label . '</td><td>' . (int) $s[$key] . '</td><td><strong>' . tahsinPercent($s[$key . '_percent']) . '</strong></td></tr>';
    echo '<tr class="tahsin-period-total"><td>Total presensi terkonfirmasi</td><td>' . (int) $s['total_presensi'] . '</td><td>' . ($s['total_presensi'] ? '100%' : '—') . '</td></tr></tbody></table></div><p class="tahsin-note">Jumlah merupakan akumulasi wali-sesi selama seluruh periode yang dipilih. Wali yang hadir pada tiga sesi menyumbang tiga Hadir. Persentase dibulatkan satu angka desimal.</p>';
    echo '<p class="tahsin-note"><strong>' . tahsinH(tahsinActivityNote($s)) . '</strong> Input lengkap: ' . tahsinPercent($s['coverage_percent']) . ' · Belum diinput yang dapat dipastikan: ' . (int) $s['missing_presensi'] . ' · Perlu verifikasi: ' . (int) $s['conflicts'] . '.</p>';
    if ($s['unverified']) echo '<p class="tahsin-note">Histori roster sebagian sesi belum tersedia. Jumlah kewajiban dan kekosongan pada sesi tersebut belum dapat dipastikan.</p>';
    echo '</section>';
}

function tahsinRenderActivityWali(array $rows, array $filters, bool $excel = false, int $offset = 0): void
{
    echo '<div class="tahsin-scroll"><table class="tahsin-table tahsin-activity-wali"><thead><tr><th>No.</th><th>Wali santri</th><th>Halaqoh / Guru</th><th>Keaktifan (Hadir)</th><th>Hadir</th><th>Izin</th><th>Alfa tercatat</th><th>Sakit</th><th>Presensi tercatat</th><th>Kualitas data</th></tr></thead><tbody>';
    foreach ($rows as $index => $r) {
        echo '<tr><td>' . ($offset + $index + 1) . '</td><td><strong>' . tahsinMeetingText($r['nama_bapak'], $excel) . '</strong>';
        if (!$excel) echo '<small class="no-print"><a href="rekap-lengkap.php?' . tahsinH(tahsinQuery($filters, ['wali_santri_id' => $r['id']])) . '#detail-wali">Rincian sesi</a></small>';
        echo '</td><td>' . tahsinMeetingText($r['nama_halaqoh'], $excel) . '<small>' . tahsinMeetingText($r['nama_ustadz'], $excel) . '</small></td><td class="tahsin-activity-score"><strong>' . tahsinPercent($r['hadir_percent']) . '</strong>';
        tahsinActivityBar($r);
        echo '</td>';
        tahsinActivityStatusCells($r);
        echo '<td class="tahsin-count">' . (int) $r['total_presensi'] . '</td>';
        tahsinActivityDataCell($r);
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="10">Tidak ada wali yang sesuai filter.</td></tr>';
    echo '</tbody></table></div>';
}

function tahsinRenderActivityGroups(array $groups, bool $guru = false, bool $excel = false): void
{
    echo '<section class="tahsin-panel tahsin-activity-groups"><h3>Persentase per ' . ($guru ? 'Guru' : 'Halaqoh') . '</h3><div class="tahsin-scroll"><table class="tahsin-table"><thead><tr><th>' . ($guru ? 'Guru' : 'Halaqoh / Guru') . '</th><th>Wali dalam periode</th><th>Presensi tercatat</th><th>Hadir</th><th>Izin</th><th>Alfa tercatat</th><th>Sakit</th><th>Kualitas data</th></tr></thead><tbody>';
    foreach ($groups as $g) {
        echo '<tr><td><strong>' . tahsinMeetingText($guru ? ($g['nama_ustadz'] ?? 'Belum ditentukan') : ($g['nama_halaqoh'] ?? 'Halaqoh lama'), $excel) . '</strong>';
        if (!$guru) echo '<small>' . tahsinMeetingText($g['nama_ustadz'] ?? '—', $excel) . '</small>';
        echo '</td><td>' . (int) $g['total_anggota'] . '</td><td>' . (int) $g['total_presensi'] . '</td>';
        tahsinActivityStatusCells($g);
        tahsinActivityDataCell($g);
        echo '</tr>';
    }
    if (!$groups) echo '<tr><td colspan="8">Tidak ada penempatan sesi yang diketahui pada periode ini.</td></tr>';
    echo '</tbody></table></div><p class="tahsin-note">Wali yang pindah kelompok dapat muncul pada lebih dari satu kelompok sesuai sesi tanggung jawabnya; total sekolah tetap menghitung satu wali per sesi.</p></section>';
}
