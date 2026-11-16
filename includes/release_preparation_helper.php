<?php
require_once __DIR__.'/backup_helper.php';
require_once __DIR__.'/participant_merge_helper.php';
require_once __DIR__.'/participant_import_helper.php';
require_once __DIR__.'/attendance_form_helper.php';
foreach (['tahsin','autosave','family'] as $releaseMigration) require_once __DIR__.'/'.$releaseMigration.'_migration_helper.php';

/** Hash original columns, including bytes and NULL, in deterministic PK order. */
function releaseDatabaseSnapshot(PDO $pdo,?array $projection=null): array
{
    $out=[];
    foreach ($projection===null?discoverBackupTables($pdo):array_keys($projection) as $table) {
        $columns=$projection[$table]['columns']??tableColumns($pdo,$table);
        $sql='SELECT '.implode(',',array_map('attendanceHistoryQuote',$columns)).' FROM '.attendanceHistoryQuote($table).tableOrderClause($pdo,$table,$columns);
        $rows=[]; $count=0; $hash=hash_init('sha256'); $hasPrimaryKey=(bool)tablePrimaryKeyColumns($pdo,$table);
        foreach ($pdo->query($sql) as $row) {
            $values=[]; foreach ($columns as $column) $values[]=$row[$column]===null?null:(string)$row[$column];
            $encoded=serialize($values); $count++;
            if ($hasPrimaryKey) hash_update($hash,strlen($encoded).':'.$encoded); else $rows[]=$encoded;
        }
        if ($rows) { sort($rows,SORT_STRING); foreach ($rows as $encoded) hash_update($hash,strlen($encoded).':'.$encoded); }
        $out[$table]=['columns'=>$columns,'rows'=>$count,'sha256'=>hash_final($hash)];
    }
    ksort($out); return $out;
}

/** Metadata migrations only; no participant, child, membership or attendance edits. */
function releaseInstallJournals(PDO $pdo): void
{
    tahsinApplyMigration($pdo); autosaveApplyMigration($pdo); familyApplyMigration($pdo);
    participantImportApplyMigration($pdo); participantApplyMigration($pdo); participantMergeApplyMigration($pdo);
    attendanceHistoryApplyMigration($pdo); attendanceFormApplyMigration($pdo); dataQualityApplyMigration($pdo);
    attendanceHistoryApplyMigration($pdo);
    if (!dataQualitySchemaReady($pdo) || !attendanceHistoryReady($pdo) || !participantMergeSchemaReady($pdo)) throw new RuntimeException('Jurnal belum siap setelah migrasi.');
}

/** Restore an actual backup only into a newly created, empty staging schema. */
function releaseVerifyClone(PDO $source,PDO $stage,string $directory): array
{
    $sourceName=(string)$source->query('SELECT DATABASE()')->fetchColumn(); $stageName=(string)$stage->query('SELECT DATABASE()')->fetchColumn();
    if ($sourceName===$stageName || !preg_match('/^tahsin_release_stage_[a-z0-9_]+$/D',$stageName) || $stage->query('SHOW TABLES')->fetchColumn()!==false) throw new RuntimeException('Verifikasi wajib memakai database staging kosong terpisah.');
    appRequireTransactionalTables($source); $stage->exec("SET time_zone='+07:00'");
    $before=releaseDatabaseSnapshot($source); $tables=discoverBackupTables($source);
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Folder privat verifikasi tidak tersedia.');
    $backup=$directory.'/before-release-'.bin2hex(random_bytes(8)).'.zip';
    $result=createBackupZip($source,$backup,$tables,'\\N');
    if (!$result['ok']) throw new RuntimeException('Backup pra-rilis gagal. Produksi belum dimigrasi.');
    foreach ($tables as $table) {
        $ddl=$source->query('SHOW CREATE TABLE '.attendanceHistoryQuote($table))->fetch(PDO::FETCH_ASSOC)['Create Table'];
        // SHOW CREATE may qualify self-references; they must stay inside staging.
        $ddl=str_replace(attendanceHistoryQuote($sourceName).'.',attendanceHistoryQuote($stageName).'.',$ddl);
        $stage->exec($ddl);
    }
    $restore=executeMergeRestore($stage,$backup,discoverBackupTables($stage),'\\N');
    if ($restore['error']!==null || releaseDatabaseSnapshot($stage,$before)!==$before) throw new RuntimeException('Pemulihan backup belum sama dengan sumber. Produksi belum dimigrasi.');
    releaseInstallJournals($stage);
    if (releaseDatabaseSnapshot($stage,$before)!==$before) throw new RuntimeException('Migrasi staging mengubah nilai lama. Produksi belum dimigrasi.');
    if (releaseDatabaseSnapshot($source,$before)!==$before) throw new RuntimeException('Sumber berubah selama pemeriksaan. Ulangi verifikasi dengan backup baru sebelum apply.');
    return ['verified'=>true,'source_database'=>$sourceName,'stage_database'=>$stageName,'backup'=>$backup,'backup_sha256'=>hash_file('sha256',$backup),'baseline'=>$before,'verified_at'=>date('c')];
}
