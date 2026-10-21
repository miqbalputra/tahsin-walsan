<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/run-tahsin-tests.php';
require_once __DIR__ . '/../includes/tahsin_reminder_helper.php';
$reminderStart = $checks;
$data = tahsinFixture();
$data['tracked_from'] = null; $data['schema_ready'] = false; $data['history'] = [];
$data['records'] = [fixtureRecord(1, 1, 'H'), fixtureRecord(2, 2, 'A')];
$overview = tahsinReminderOverview($data, $now);
expectTahsin('success', $overview['status'], 'Weekly check works without reporting migration');
expectTahsin(1, $overview['count'], 'Entirely missing teacher group is not filtered out by unavailable history');
expectTahsin(2, $overview['data'][0]['missing_presensi'], 'Current roster identifies unfilled participants');
expectTahsin(2, $overview['data'][0]['complete_input'], 'Saved Alfa and complete Hadir both satisfy input');
expectTahsin(false, $overview['data'][0]['data_verified'], 'Current roster is never labeled historical proof');
expectTahsin('current_roster', $overview['data'][0]['roster_basis'], 'Current roster provenance is explicit');
expectTahsin(4, $overview['summary']['checked_participant_sessions'], 'Coverage includes every current participant');
expectTahsin(['H' => 1, 'S' => 0, 'I' => 0, 'A' => 1], $overview['summary']['attendance_counts'], 'Saved status counts include Alfa');
$report = tahsinAssembleReport($data, $filters, 'admin', 0, $now, '2026-10-11');
expectTahsin([], tahsinPendingFromReport($report, $now), 'Default historical group list remains strict');
$manual = tahsinPendingOverviewFromReport($report, $now, 'admin', '2026-10-11');
expectTahsin(1, $manual['summary']['pending_teachers'], 'Manual monitoring can show current teacher without history');
expectTahsin(true, $manual['sessions'][0]['current_roster_available'], 'Manual current-session badge distinguishes active roster');
expectTahsin(4, $manual['sessions'][0]['roster_count'], 'Manual active roster count agrees with reminder API');
$data['records'] = [];
$overview = tahsinReminderOverview($data, $now);
expectTahsin(4, $overview['data'][0]['missing_presensi'], 'Teacher with no saved rows is reminded');
expectTahsin('belum_sama_sekali', $overview['data'][0]['status_isi'], 'No teacher action remains visibly unsubmitted');
expectTahsin(0, $overview['summary']['attendance_counts']['A'], 'No teacher action never creates Alfa');
$data['records'] = [fixtureRecord(1, 1, 'H'), fixtureRecord(2, 2, 'A'), fixtureRecord(3, 3, 'S'), fixtureRecord(4, 4, 'I')];
$overview = tahsinReminderOverview($data, $now);
expectTahsin(0, $overview['count'], 'Every saved H/S/I/A clears reminder when required fields are complete');
expectTahsin(true, $overview['inspection_complete'], 'Empty pending list has proof that roster was actually checked');
expectTahsin(4, $overview['summary']['complete_participant_sessions'], 'Complete state is backed by all four entries');
expectTahsin(['H' => 1, 'S' => 1, 'I' => 1, 'A' => 1], $overview['summary']['attendance_counts'], 'All explicit statuses reported separately');
$data['records'][0]['hasil_talaqqi'] = null;
$overview = tahsinReminderOverview($data, $now);
expectTahsin(1, $overview['data'][0]['missing_capaian'], 'Saved Hadir without achievement still needs reminder');
expectTahsin(0, $overview['data'][0]['missing_presensi'], 'Missing achievement is distinct from absent attendance');
$data['roster'] = []; $data['records'] = [];
$overview = tahsinReminderOverview($data, $now);
expectTahsin(false, $overview['inspection_complete'], 'No roster must not imply universal completeness');
expectTahsin('unavailable', $overview['summary']['roster_basis'], 'Missing roster has an explicit unavailable state');
$data = tahsinFixture(); $data['tracked_from'] = null; $data['schema_ready'] = false; $data['history'] = [];
expectTahsin('skip', tahsinReminderOverview($data, new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Asia/Jakarta')))['status'], 'First-Sunday holiday week does not revive previous session');
$data['holidays']['2026-10-11'] = 'Libur';
expectTahsin('skip', tahsinReminderOverview($data, $now)['status'], 'Explicit holiday week has no reminder');
$data['holidays'] = [];
expectTahsin('skip', tahsinReminderOverview($data, new DateTimeImmutable('2026-10-11 07:29:59', new DateTimeZone('Asia/Jakarta')))['status'], 'Session is not chased before 07.30');
expectTahsin('success', tahsinReminderOverview($data, new DateTimeImmutable('2026-10-11 07:30:00', new DateTimeZone('Asia/Jakarta')))['status'], 'Current session becomes checkable at cutoff');
$later = new DateTimeImmutable('2026-10-19 10:00:00', new DateTimeZone('Asia/Jakarta'));
$data['records'] = [fixtureRecord(1, 1, 'H', '2026-10-11')];
$overview = tahsinReminderOverview($data, $later, true);
expectTahsin(['2026-10-18'], array_unique(array_column($overview['data'], 'tanggal_tahsin')), 'Unavailable history never produces a guessed backlog');
$data = tahsinFixture();
expectTahsin('session_history', tahsinReminderOverview($data, $now)['data'][0]['roster_basis'], 'Verified history still takes precedence');

foreach ([[], ['status' => '']] as $blank) {
    try { attendanceNormalizeSubmission($blank, '2026-10-11', false, $now); throw new RuntimeException('Blank autosave accepted'); }
    catch (AttendanceValidationException $e) { $checks++; }
    $normalized = attendanceNormalizeSubmission($blank, '2026-10-11', true, $now);
    expectTahsin('A', $normalized['status'], 'Explicit finalization converts unselected participant to Alfa');
    expectTahsin(true, $normalized['automatic_alpha'], 'Automatic Alfa is distinguishable for race protection');
}
try {
    attendanceNormalizeSubmission([], '2026-10-11', true, new DateTimeImmutable('2026-10-11 07:29:59', new DateTimeZone('Asia/Jakarta')));
    throw new RuntimeException('Premature automatic Alfa accepted');
} catch (AttendanceValidationException $e) { $checks++; }
expectTahsin('A', attendanceNormalizeSubmission([], '2026-10-11', true, new DateTimeImmutable('2026-10-11 07:30:00', new DateTimeZone('Asia/Jakarta')))['status'], 'Finalization cutoff includes 07.30 precisely');
try { attendanceNormalizeSubmission(['status' => 'H'], '2026-10-11', true, $now); throw new RuntimeException('Incomplete Hadir converted to Alfa'); }
catch (AttendanceValidationException $e) { $checks++; }
expectTahsin('reset', attendanceNormalizeSubmission(['intent' => 'reset'], '2026-10-11', true, $now)['intent'], 'Explicit reset remains distinct from an unselected participant');
echo 'PASS ' . ($checks - $reminderStart) . " weekly reminder/finalization assertions\n";
