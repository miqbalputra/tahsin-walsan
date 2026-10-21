<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/family_helper.php';
$opts = getopt('', ['file:', 'mapping:', 'apply']);
try {
    if (empty($opts['file'])) throw new RuntimeException('Penggunaan: php scripts/import-family.php --file=keluarga-tahsin.json [--mapping=pilihan.json] [--apply]');
    $data = json_decode(file_get_contents($opts['file']), true, 512, JSON_THROW_ON_ERROR);
    $choices = isset($opts['mapping']) ? json_decode(file_get_contents($opts['mapping']), true, 512, JSON_THROW_ON_ERROR) : [];
    familyValidatePackage($data);
    require __DIR__ . '/../config/database.php';
    $result = array_key_exists('apply', $opts) ? familyImportApply($pdo, $data, $choices) : familyImportPlan($pdo, $data, $choices);
    echo json_encode(['mode' => array_key_exists('apply', $opts) ? 'apply' : 'dry-run', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
