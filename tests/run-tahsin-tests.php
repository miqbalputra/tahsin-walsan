<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Standalone regression suite: php tests/run-tahsin-tests.php (no external services needed). */
require_once __DIR__ . '/../includes/tahsin_report_helper.php';
require_once __DIR__ . '/../includes/attendance_helper.php';
date_default_timezone_set('Asia/Jakarta');
$checks = 0;
function expectTahsin($expected, $actual, string $message): void
{
    global $checks;
    $checks++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}
function tahsinFixture(): array
{
    $data = ['wali' => [], 'children' => [], 'halaqoh' => [['id' => 1, 'ustadz_id' => 1, 'nama_halaqoh' => 'Halaqoh Uji', 'nama_ustadz' => 'Guru Uji', 'ustadz_phone' => '081234567890']],
        'records' => [], 'roster' => [], 'history' => [], 'verifications' => [], 'baselines' => [], 'suspect_ids' => [], 'holidays' => [], 'tracked_from' => '2026-10-01 00:00:00', 'schema_ready' => true];
    for ($id = 1; $id <= 4; $id++) {
        $data['wali'][] = ['id' => $id, 'nama_bapak' => 'Wali ' . $id, 'kategori' => 'reguler', 'status_aktif' => 1, 'lanjut_tahsin' => 0];
        $member = ['membership_id' => $id, 'wali_santri_id' => $id, 'halaqoh_id' => 1, 'ustadz_id' => 1, 'nama_halaqoh' => 'Halaqoh Uji', 'nama_ustadz' => 'Guru Uji', 'ustadz_phone' => '081234567890', 'kategori' => 'reguler'];
        $data['roster'][] = $member;
        $data['history'][] = $member + ['valid_from' => '2026-10-01 00:00:00', 'valid_until' => null];
    }
    $data['children'] = [['wali_santri_id' => 1, 'nama_anak' => 'Anak Satu', 'kelas' => '1A'], ['wali_santri_id' => 1, 'nama_anak' => 'Anak Dua', 'kelas' => '2A']];
    return $data;
}
function fixtureRecord(int $id, int $wali, string $status, string $date = '2026-10-11'): array
{
    return ['id' => $id, 'wali_santri_id' => $wali, 'halaqoh_id' => 1, 'tanggal' => $date, 'status' => $status,
        'alasan' => in_array($status, ['I', 'S']) ? 'Keterangan uji' : null, 'jenis_materi' => $status === 'H' ? 'Iqro' : null,
        'jilid' => $status === 'H' ? 2 : null, 'nama_surat' => null, 'halaman' => $status === 'H' ? '1-2' : null,
        'hasil_talaqqi' => $status === 'H' ? 'Lulus' : null, 'created_at' => $date . ' 09:00:00'];
}
$now = new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('Asia/Jakarta'));
$filters = tahsinFilters(['start_date' => '2026-10-11', 'end_date' => '2026-10-12'], $now);
$data = tahsinFixture();
$data['records'] = [fixtureRecord(1, 1, 'H'), fixtureRecord(2, 2, 'A')];
$report = tahsinAssembleReport($data, $filters, 'admin', 0, $now);
expectTahsin(50.0, $report['summary']['hadir_percent'], 'Attendance uses confirmed statuses');
expectTahsin(50.0, $report['summary']['coverage_percent'], 'Completeness includes missing entries');
expectTahsin(1, $report['summary']['alpha'], 'Missing rows are never alpha');
expectTahsin(2, $report['summary']['missing_presensi'], 'Two rows are still pending');
expectTahsin(4, $report['summary']['peserta'], 'Multiple children do not multiply parents');
expectTahsin(1, $report['summary']['total_halaqoh'], 'Unassigned people do not create a fictitious group');
expectTahsin('2026-10-11', $report['rows'][0]['terakhir_hadir'], 'Last attendance requires H');
expectTahsin(null, $report['rows'][1]['terakhir_hadir'], 'Alpha is not last attendance');
expectTahsin('belum_data', $report['rows'][2]['risk_level'], 'No data does not trigger follow-up');
expectTahsin(true, $report['summary']['provisional'], 'Incomplete attendance is provisional');
$pending = tahsinPendingFromReport($report, $now);
expectTahsin(2, $pending[0]['belum_diisi'], 'Reminder counts outstanding participants');
expectTahsin('Terlambat', $pending[0]['timeliness'], 'Deadline starts at Monday 10 WIB');
expectTahsin('Menunggu Input', tahsinPendingFromReport($report, $now->modify('-1 second'))[0]['timeliness'], 'Deadline has no premature lateness');
expectTahsin('6281234567890', $pending[0]['ustadz_phone'], 'WhatsApp phone normalization');
$empty = tahsinFixture();
$r = tahsinAssembleReport($empty, $filters, 'admin', 0, $now);
expectTahsin(null, $r['summary']['hadir_percent'], 'No attendance is unknown, not zero percent');
expectTahsin(0, $r['summary']['alpha'], 'Entire empty session has zero alpha');
expectTahsin(4, $r['summary']['missing_presensi'], 'Entire roster requires input');
$dup = $data; $dup['records'][] = fixtureRecord(3, 1, 'H', '2026-10-17');
$later = $now->modify('+6 days');
$r = tahsinAssembleReport($dup, $filters, 'admin', 0, $later);
expectTahsin(1, $r['summary']['hadir'], 'Replacement Saturday and Sunday count once');
$dup['records'][2]['status'] = 'A';
$r = tahsinAssembleReport($dup, $filters, 'admin', 0, $later);
expectTahsin(1, $r['summary']['conflicts'], 'Different statuses require verification');
expectTahsin(0, $r['summary']['hadir'], 'Conflicts are excluded from official ratios');
expectTahsin(1, $r['summary']['alpha'], 'Conflicting alpha must not count either');
$source = [$dup['records'][0], $dup['records'][2]];
$dup['verifications'][] = ['wali_santri_id' => 1, 'session_date' => '2026-10-11', 'fingerprint' => tahsinFingerprint($source), 'selected_presensi_id' => 1];
$r = tahsinAssembleReport($dup, $filters, 'admin', 0, $later);
expectTahsin(1, $r['summary']['hadir'], 'Verification selects the confirmed row');
expectTahsin(0, $r['summary']['conflicts'], 'Valid verification clears the conflict');
$dup['records'][0]['halaman'] = '3-4';
$r = tahsinAssembleReport($dup, $filters, 'admin', 0, $later);
expectTahsin(1, $r['summary']['conflicts'], 'Changed source invalidates verification');
$incomplete = $data; $incomplete['records'][0]['hasil_talaqqi'] = null;
$r = tahsinAssembleReport($incomplete, $filters, 'admin', 0, $now);
expectTahsin(1, $r['summary']['missing_capaian'], 'Legacy H without achievement remains outstanding');
expectTahsin(25.0, $r['summary']['coverage_percent'], 'Only complete entries satisfy obligations');
$legacyIncomplete = $incomplete; $legacyIncomplete['tracked_from'] = null; $legacyIncomplete['history'] = [];
$r = tahsinAssembleReport($legacyIncomplete, $filters, 'admin', 0, $now);
expectTahsin(1, $r['summary']['missing_capaian'], 'Incomplete recorded H remains detectable without a historical roster');
expectTahsin(0, $r['summary']['missing_presensi'], 'Incomplete recorded H invents no absent historical participants');
expectTahsin(null, tahsinPendingFromReport($r, $now)[0]['coverage_percent'], 'Historical correction reminder does not claim class coverage');
$reason = $data; $reason['records'][0] = fixtureRecord(1, 1, 'S'); $reason['records'][0]['alasan'] = null;
$r = tahsinAssembleReport($reason, $filters, 'admin', 0, $now);
expectTahsin(1, $r['summary']['missing_keterangan'], 'Sakit without reason requests attendance detail');
expectTahsin(0, $r['summary']['missing_capaian'], 'Absent participants do not owe an achievement');
expectTahsin(1, $r['summary']['sakit'], 'Missing reason does not erase recorded status S');
$legacy = $data; $legacy['tracked_from'] = null; $legacy['history'] = [];
$r = tahsinAssembleReport($legacy, $filters, 'admin', 0, $now);
expectTahsin(null, $r['summary']['coverage_percent'], 'Historical roster completeness is not guessed');
expectTahsin(0, $r['summary']['missing_presensi'], 'Current roster creates no historical missing attendance');
$r = tahsinAssembleReport($legacy, $filters, 'admin', 0, $now, '2026-10-11');
expectTahsin(2, tahsinPendingFromReport($r, $now)[0]['belum_diisi'], 'Operational latest-session fallback uses labeled current roster');
$suspect = $data; $suspect['suspect_ids'][1] = true;
$r = tahsinAssembleReport($suspect, $filters, 'admin', 0, $now);
expectTahsin(1, $r['summary']['conflicts'], 'Initial-achievement suspicion cannot inflate attendance');
$suspect['verifications'][] = ['wali_santri_id' => 1, 'session_date' => '2026-10-11', 'fingerprint' => tahsinFingerprint([$data['records'][0]]), 'selected_presensi_id' => null];
$r = tahsinAssembleReport($suspect, $filters, 'admin', 0, $now);
expectTahsin(0, $r['summary']['hadir'], 'Confirmed initial achievement is not attendance');
$base = $empty; $base['baselines'][] = ['wali_santri_id' => 1, 'jenis_materi' => 'Iqro', 'jilid' => 2, 'halaman' => '1-2', 'updated_at' => '2026-10-10 12:00:00'];
$r = tahsinAssembleReport($base, $filters, 'admin', 0, $now);
expectTahsin(0, $r['summary']['hadir'], 'Separate baseline never creates H');
expectTahsin(true, str_starts_with($r['rows'][0]['materi_terakhir'], 'Capaian awal:'), 'Baseline is explicitly labeled');
$new = $empty; $new['history'][0]['valid_from'] = '2026-10-12 00:00:00';
$r = tahsinAssembleReport($new, $filters, 'admin', 0, $now);
expectTahsin(3, $r['summary']['expected'], 'New enrollment creates no prior obligation');
$archived = $empty; $archived['history'][0]['valid_until'] = '2026-10-10 10:00:00'; $archived['wali'][0]['status_aktif'] = 0;
$r = tahsinAssembleReport($archived, $filters, 'admin', 0, $now);
expectTahsin(3, $r['summary']['expected'], 'Archive ends obligations without removing parent');
$external = $empty; $external['wali'][] = ['id' => 5, 'nama_bapak' => 'Luar', 'kategori' => 'tahsin_luar', 'status_aktif' => 1];
$external['wali'][] = ['id' => 6, 'nama_bapak' => 'Belum Kelompok', 'kategori' => 'reguler', 'status_aktif' => 1];
$r = tahsinAssembleReport($external, $filters, 'admin', 0, $now);
expectTahsin(6, $r['summary']['peserta'], 'All parents including external and unassigned are included');
expectTahsin('external', $r['rows'][4]['quality'], 'External lack of data is explained');
expectTahsin('unassigned', $r['rows'][5]['quality'], 'Missing group is separate from alpha');
$r = tahsinAssembleReport($external, $filters, 'ustadz', 1, $now);
expectTahsin(4, $r['summary']['peserta'], 'Teacher cannot access unassigned/external parents');
$r = tahsinAssembleReport($external, $filters, 'ustadz', 2, $now);
expectTahsin(0, $r['summary']['peserta'], 'Teacher cannot access another teacher roster');
expectTahsin(['2026-10-11'], tahsinSessions('2026-10-01', '2026-10-12'), 'First Sunday is excluded');
expectTahsin([], tahsinSessions('2026-10-01', '2026-10-12', ['2026-10-11' => 'Libur']), 'Holiday is excluded');
expectTahsin([], tahsinSessions('2026-10-01', '2026-10-12', ['2026-10-11' => null]), 'Holiday does not need a description to be excluded');
$holidayRecord = $data; $holidayRecord['holidays']['2026-10-12'] = 'Libur pengganti'; $holidayRecord['records'][1]['tanggal'] = '2026-10-12';
$r = tahsinAssembleReport($holidayRecord, $filters, 'admin', 0, $now);
expectTahsin(0, $r['summary']['alpha'], 'Absence on a replacement-day holiday is not Alfa');
expectTahsin(1, $r['summary']['conflicts'], 'Holiday record needs date correction');
$holidayRecord['records'][] = fixtureRecord(5, 2, 'H', '2026-10-11');
$r = tahsinAssembleReport($holidayRecord, $filters, 'admin', 0, $now);
expectTahsin(2, $r['summary']['hadir'], 'Valid day record can satisfy the same week without deleting holiday source');
$dual = $empty;
$dual['halaqoh'][] = ['id' => 2, 'ustadz_id' => 2, 'nama_halaqoh' => 'Kelompok Dua', 'nama_ustadz' => 'Guru Dua'];
$dual['roster'][] = array_replace($dual['roster'][0], ['halaqoh_id' => 2, 'ustadz_id' => 2, 'nama_halaqoh' => 'Kelompok Dua', 'nama_ustadz' => 'Guru Dua']);
$dual['history'][] = array_replace($dual['history'][0], ['halaqoh_id' => 2, 'ustadz_id' => 2, 'nama_halaqoh' => 'Kelompok Dua', 'nama_ustadz' => 'Guru Dua']);
$r = tahsinAssembleReport($dual, $filters, 'ustadz', 2, $now);
expectTahsin(1, $r['summary']['conflicts'], 'Both teachers can see their own ambiguous placement');
expectTahsin(0, $r['summary']['alpha'], 'Ambiguous placement never produces Alpha');
expectTahsin('2026-08-30', tahsinSessionDate('2026-09-01'), 'Month boundary replacement belongs to previous month');
expectTahsin('2025-12-28', tahsinSessionDate('2026-01-01'), 'Year boundary replacement belongs to previous year');
$boundary = $data; $boundary['records'] = [fixtureRecord(1, 1, 'H', '2026-09-01')];
$r = tahsinAssembleReport($boundary, tahsinFilters(['start_date' => '2026-08-01', 'end_date' => '2026-08-31'], $now), 'admin', 0, $now);
expectTahsin(1, $r['summary']['hadir'], 'Report filters use session date, not replacement date');
$allDone = $data; $allDone['records'][] = fixtureRecord(3, 3, 'I'); $allDone['records'][] = fixtureRecord(4, 4, 'S');
$r = tahsinAssembleReport($allDone, $filters, 'admin', 0, $now);
expectTahsin(100.0, $r['summary']['coverage_percent'], 'Complete session reaches 100 percent');
expectTahsin(0, tahsinPendingFromReport($r, $now)[0]['belum_diisi'], 'Reminder ends when every entry is complete');
expectTahsin(1, count(tahsinAssembleReport($data, tahsinFilters(['start_date' => '2026-10-11', 'end_date' => '2026-10-12', 'search' => 'Anak Dua'], $now), 'admin', 0, $now)['rows']), 'Child search does not multiply parents');
foreach ([[], ['status' => ''], ['status' => 'H', 'jenis_materi' => 'Iqro']] as $invalid) {
    try { attendanceNormalizePayload($invalid); throw new RuntimeException('Invalid payload was accepted'); }
    catch (AttendanceValidationException $e) { $checks++; }
}
expectTahsin('A', attendanceNormalizePayload(['status' => 'A'])['status'], 'Explicit A is still supported');
expectTahsin(null, tahsinDate('2026-02-30'), 'Invalid calendar dates are rejected');
expectTahsin('0%', tahsinPercent(0), 'Real zero remains zero');
expectTahsin('100%', tahsinPercent(100), 'Percent formatting preserves zero digits');
expectTahsin('محمد', tahsinTextLimit('محمد علي', 4), 'Text limits do not cut Unicode characters');
expectTahsin(true, tahsinTextContains('École Tahsin', 'école'), 'Unicode names search remains case insensitive without mbstring');
require_once __DIR__ . '/../includes/tahsin_reminder_api.php';
class TahsinAuthProbePDO extends PDO { public function __construct() {} }
$originalKey = getenv('N8N_API_KEY');
$originalGet = $_GET;
$_GET = [];
putenv('N8N_API_KEY');
ob_start(); tahsinReminderApi(new TahsinAuthProbePDO(), true); $guard = json_decode(ob_get_clean(), true);
expectTahsin(503, http_response_code(), 'Missing reminder secret fails closed without querying PDO');
expectTahsin(false, isset($guard['data']), 'Missing secret exposes no participant data');
putenv('N8N_API_KEY=guard-test-secret'); $_GET = ['key' => 'incorrect'];
ob_start(); tahsinReminderApi(new TahsinAuthProbePDO(), true); $guard = json_decode(ob_get_clean(), true);
expectTahsin(401, http_response_code(), 'Wrong reminder secret fails closed without querying PDO');
expectTahsin(false, isset($guard['data']), 'Wrong secret exposes no participant data');
if ($originalKey === false) putenv('N8N_API_KEY'); else putenv('N8N_API_KEY=' . $originalKey);
$_GET = $originalGet; http_response_code(200);
echo "PASS {$checks} Tahsin regression assertions\n";
