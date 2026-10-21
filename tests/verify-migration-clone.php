<?php
/** Run only inside a private PHP verifier sharing an owned recovery container's network. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/migration_safety_helper.php';
require_once __DIR__ . '/../includes/tahsin_report_helper.php';
require_once __DIR__ . '/../includes/tahsin_migration_helper.php';
require_once __DIR__ . '/../includes/autosave_migration_helper.php';
require_once __DIR__ . '/../includes/family_migration_helper.php';
date_default_timezone_set('Asia/Jakarta');
$guard = json_decode(file_get_contents('/work/clone-guard.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents('/work/source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($guard['purpose'] ?? '') !== 'tahsin-owned-recovery' || !str_starts_with($guard['container'] ?? '', 'tahsin-recovery-')) throw new RuntimeException('Owned isolated recovery guard required.');
$database = $manifest['baseline']['database']; safetyIdentifier($database);
$pdo = safetyConnect(['host' => '127.0.0.1', 'port' => '3306', 'database' => $database, 'username' => 'root', 'password' => file_get_contents('/work/recovery-password')]);
$actualUuid = $pdo->query('SELECT @@server_uuid')->fetchColumn();
if ($actualUuid !== $guard['target_uuid'] || $actualUuid === $guard['source_uuid'] || $pdo->query('SELECT @@hostname')->fetchColumn() !== $guard['target_hostname']) throw new RuntimeException('Target differs from isolated clone; refusing all schema changes.');
if (strtoupper((string)$pdo->query('SELECT @@event_scheduler')->fetchColumn()) === 'ON') throw new RuntimeException('Recovery event scheduler must stay off.');
$expected = $manifest['baseline'];

function preservedLegacyRows(PDO $pdo, array $expected): array
{
    $result = [];
    foreach ($expected['tables'] as $name => $table) {
        $columns = implode(',', array_map('safetyIdentifier', $table['columns']));
        $order = implode(',', array_map('safetyIdentifier', $table['primary_key']));
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $rows = $pdo->query('SELECT ' . $columns . ' FROM ' . safetyIdentifier($name) . ' ORDER BY ' . $order);
        $hash = hash_init('sha256'); $count = 0;
        try { while ($row = $rows->fetch()) { hash_update($hash, safetyRowLine($row)); $count++; } }
        finally { $rows->closeCursor(); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
        if ($count !== $table['rows'] || hash_final($hash) !== $table['sha256']) throw new RuntimeException('Legacy values changed in owned clone: ' . $name);
        $result[$name] = ['rows' => $count, 'preserved' => true];
    }
    return $result;
}

preservedLegacyRows($pdo, $expected);
$range = $pdo->query('SELECT MIN(tanggal) AS first_date,MAX(tanggal) AS last_date FROM presensi')->fetch();
$now = tahsinNow();
$filters = $range['first_date'] ? tahsinFilters(['start_date' => tahsinSessionDate($range['first_date']), 'end_date' => $range['last_date']], $now) : null;
$beforeReport = $filters ? tahsinFetchReport($pdo, $filters, 'admin', 0, $now)['summary'] : null;
// Only the guarded recovery clone receives these additive schema operations. No family data is imported.
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
$preserved = preservedLegacyRows($pdo, $expected);
$afterReport = $filters ? tahsinFetchReport($pdo, $filters, 'admin', 0, $now)['summary'] : null;
$reportKeys = ['hadir', 'sakit', 'izin', 'alpha', 'total_presensi', 'conflicts'];
if ($filters) foreach ($reportKeys as $key) if ($beforeReport[$key] !== $afterReport[$key]) throw new RuntimeException('Historical report changed in clone: ' . $key);
$historyCount = $pdo->query('SELECT COUNT(*) FROM tahsin_roster_history')->fetchColumn();
tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
preservedLegacyRows($pdo, $expected);
if ($pdo->query('SELECT COUNT(*) FROM tahsin_roster_history')->fetchColumn() !== $historyCount) throw new RuntimeException('Repeated schema application duplicated roster history.');
if ((int)$pdo->query('SELECT COUNT(*) FROM family_students')->fetchColumn() !== 0) throw new RuntimeException('Clone verification must not import family identities.');
$result = ['ok' => true, 'scope' => 'isolated-recovery-only', 'legacy_tables' => $preserved,
    'historical_report' => $afterReport ? array_intersect_key($afterReport, array_flip($reportKeys)) : null,
    'schema_repeatable' => true, 'family_imported' => false, 'production_changed' => false];
safetyJson('/evidence/clone-migration.json', $result);
echo json_encode(['ok' => true, 'legacy_tables' => count($preserved), 'historical_report_preserved' => true], JSON_THROW_ON_ERROR) . "\n";
